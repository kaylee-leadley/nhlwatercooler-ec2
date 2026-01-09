// assets/js/index-live-scores.js
console.log(
  "%c[Live Scores] JS loaded (deferred + efficient + backfill + hydrate gate)",
  "color: cyan; font-weight: bold"
);

document.addEventListener("DOMContentLoaded", () => {
  if (window.__LIVE_SCORES_RUNNING) return;
  window.__LIVE_SCORES_RUNNING = true;

  // Base interval while games are live
  const POLL_INTERVAL_MS_LIVE = 30000;

  // Slower interval when nothing is live (reduce server load)
  const POLL_INTERVAL_MS_IDLE = 90000;

  // Delay first poll so the page can paint (big perceived perf win)
  const INITIAL_DELAY_MS = 2500;

  // Don’t waste time on huge sets.
  const MAX_POLL_GAMES = 40;

  // Backfill older unresolved games in small batches
  const BACKFILL_BATCH = 40;

  let lastOrderSignature = "";

  // --------------------------------------------------------
  // DOM CACHE (rebuildable) — supports "load more" cards too
  // --------------------------------------------------------
  const grid = document.querySelector(".thread-grid");
  if (!grid) return;

  const todayYmd = grid.dataset.today || "";
  const yesterdayYmd = grid.dataset.yesterday || "";

  // Map: gameId -> { card, awayScoreEl, homeScoreEl, pillEl }
  let CARD_ELEMENTS = {};

  function ensureCardDefaults(card, idx) {
    if (!card.dataset.originalOrder) card.dataset.originalOrder = String(idx);
    if (!card.dataset.liveStatus) card.dataset.liveStatus = "other";

    const pill = card.querySelector(".thread-card__pill");
    if (pill && !pill.dataset.initialText) {
      pill.dataset.initialText = (pill.textContent || "").trim();
    }
  }

  function buildCardElementsMap() {
    const cards = Array.from(grid.querySelectorAll(".thread-card"));
    cards.forEach((card, idx) => ensureCardDefaults(card, idx));

    const map = {};
    cards.forEach((card) => {
      const rawId = card.dataset.msfGameId || card.dataset.ncaaGameId;
      if (!rawId) return;

      const id = String(rawId);
      map[id] = {
        card,
        away: card.querySelector(".thread-card__score-num--away"),
        home: card.querySelector(".thread-card__score-num--home"),
        pill: card.querySelector(".thread-card__pill"),
      };
    });

    CARD_ELEMENTS = map;
  }

  // --------------------------------------------------------
  // FINAL CLASS APPLY/REMOVE
  // --------------------------------------------------------
  function removeOutcomeClasses(awayAbbr, homeAbbr, awayScore, homeScore) {
    awayAbbr?.classList.remove(
      "thread-card__abbr--win",
      "thread-card__abbr--loss",
      "thread-card__abbr--tie"
    );
    homeAbbr?.classList.remove(
      "thread-card__abbr--win",
      "thread-card__abbr--loss",
      "thread-card__abbr--tie"
    );
    awayScore?.classList.remove(
      "thread-card__score-num--win",
      "thread-card__score-num--loss",
      "thread-card__score-num--tie"
    );
    homeScore?.classList.remove(
      "thread-card__score-num--win",
      "thread-card__score-num--loss",
      "thread-card__score-num--tie"
    );
  }

  function applyOutcomeClasses(awayAbbr, homeAbbr, awayScore, homeScore, aVal, hVal) {
    removeOutcomeClasses(awayAbbr, homeAbbr, awayScore, homeScore);

    const a = Number(aVal);
    const h = Number(hVal);
    if (!Number.isFinite(a) || !Number.isFinite(h)) return;

    if (a > h) {
      awayAbbr?.classList.add("thread-card__abbr--win");
      awayScore?.classList.add("thread-card__score-num--win");
      homeAbbr?.classList.add("thread-card__abbr--loss");
      homeScore?.classList.add("thread-card__score-num--loss");
    } else if (h > a) {
      homeAbbr?.classList.add("thread-card__abbr--win");
      homeScore?.classList.add("thread-card__score-num--win");
      awayAbbr?.classList.add("thread-card__abbr--loss");
      awayScore?.classList.add("thread-card__score-num--loss");
    } else {
      awayAbbr?.classList.add("thread-card__abbr--tie");
      homeAbbr?.classList.add("thread-card__abbr--tie");
      awayScore?.classList.add("thread-card__score-num--tie");
      homeScore?.classList.add("thread-card__score-num--tie");
    }
  }

  // --------------------------------------------------------
  // On-load scrub: remove server-rendered outcome classes
  // for ANYTHING that isn't explicitly Final.
  // (Prevents incorrect styling before first poll/backfill)
  // --------------------------------------------------------
  function resetOutcomeClassesOnLoad() {
    const cards = Array.from(grid.querySelectorAll(".thread-card"));

    for (const card of cards) {
      const awayAbbr = card.querySelector(".thread-card__abbr--away");
      const homeAbbr = card.querySelector(".thread-card__abbr--home");
      const awayScore = card.querySelector(".thread-card__score-num--away");
      const homeScore = card.querySelector(".thread-card__score-num--home");

      const pillText =
        card.querySelector(".thread-card__pill")?.textContent?.trim().toLowerCase() || "";

      if (pillText !== "final") {
        removeOutcomeClasses(awayAbbr, homeAbbr, awayScore, homeScore);
      }
    }
  }

  buildCardElementsMap();
  resetOutcomeClassesOnLoad();

  // If your "load more" code appends cards, this auto-picks them up.
  // We'll also trigger backfill again when new cards appear.
  let backfillQueued = false;
  const mo = new MutationObserver((mutations) => {
    // Ignore reorder/moves: only respond when something was actually ADDED
    let hasAdded = false;
    for (const m of mutations) {
      if (m.addedNodes && m.addedNodes.length) {
        hasAdded = true;
        break;
      }
    }
    if (!hasAdded) return;

    buildCardElementsMap();
    resetOutcomeClassesOnLoad(); // scrub newly-added cards too

    if (!backfillQueued) {
      backfillQueued = true;
      setTimeout(() => {
        backfillQueued = false;
        backfillOldGames().catch(() => {});
      }, 350);
    }
  });
  mo.observe(grid, { childList: true });

  // --------------------------------------------------------
  // Helpers: "unresolved" fallback
  // --------------------------------------------------------
  function cardLooksUnresolved(card) {
    const away =
      card.querySelector(".thread-card__score-num--away")?.textContent?.trim() || "";
    const home =
      card.querySelector(".thread-card__score-num--home")?.textContent?.trim() || "";
    const pill =
      card.querySelector(".thread-card__pill")?.textContent?.trim().toLowerCase() || "";

    if (!away || !home) return true;
    if (!pill || pill === "\u00a0") return true;
    if (pill !== "final") return true;
    return false;
  }

  // --------------------------------------------------------
  // NCAA helpers (if your endpoint returns status/period/minutes/seconds)
  // --------------------------------------------------------
  function fmtClock(minutes, seconds) {
    const m = Number(minutes);
    const s = Number(seconds);
    if (!Number.isFinite(m) || !Number.isFinite(s)) return "";
    const ss = String(Math.max(0, s)).padStart(2, "0");
    return `${Math.max(0, m)}:${ss}`;
  }

  function prettyPeriod(p) {
    const x = String(p || "").toUpperCase().trim();
    if (!x) return "";
    if (x === "1ST") return "1st";
    if (x === "2ND") return "2nd";
    if (x === "3RD") return "3rd";
    if (x === "OT") return "OT";
    if (x === "SO") return "SO";
    if (x === "INT" || x === "INTERMISSION") return "INT";
    return x;
  }

  function buildNcaaLabel(score) {
    // Prefer server-provided label if present (your PHP builds "1st – 8:16")
    const provided = String(score?.label || "").trim();
    if (provided) return provided;

    const p = prettyPeriod(score?.period);
    const c = fmtClock(score?.minutes, score?.seconds);
    if (p && c) return `${p} \u2013 ${c}`;
    if (p) return p;
    if (c) return c;
    return "";
  }

  // --------------------------------------------------------
  // Collect pollable game IDs
  // - Primary: today games (both leagues)
  // - Fallback: yesterday games that look unresolved
  // - Limit total polled IDs to MAX_POLL_GAMES
  // --------------------------------------------------------
  function collectPollableGameIds() {
    console.log("Polling");
    const cards = Array.from(grid.querySelectorAll(".thread-card"));

    const nhl = [];
    const ncaa = [];

    const pushId = (c) => {
      if (nhl.length + ncaa.length >= MAX_POLL_GAMES) return;

      if (c.dataset.msfGameId) {
        const id = parseInt(c.dataset.msfGameId, 10);
        if (id && !nhl.includes(id)) nhl.push(id);
      } else if (c.dataset.ncaaGameId) {
        const id = String(c.dataset.ncaaGameId);
        if (id && !ncaa.includes(id)) ncaa.push(id);
      }
    };

    // 1) Always prioritize TODAY games
    for (const c of cards) {
      if (nhl.length + ncaa.length >= MAX_POLL_GAMES) break;
      if (c.dataset.isToday === "1") pushId(c);
    }

    // 2) Then fill remaining slots with YESTERDAY unresolved games
    for (const c of cards) {
      if (nhl.length + ncaa.length >= MAX_POLL_GAMES) break;

      if (c.dataset.gameDate === yesterdayYmd && cardLooksUnresolved(c)) {
        pushId(c);
      }
    }

    // 3) If still nothing, do the same scan for yesterday (edge cases)
    if (!nhl.length && !ncaa.length) {
      for (const c of cards) {
        if (nhl.length + ncaa.length >= MAX_POLL_GAMES) break;
        if (c.dataset.gameDate === yesterdayYmd) pushId(c);
      }
    }

    return { nhl, ncaa };
  }

  // --------------------------------------------------------
  // BACKFILL: collect any unresolved games older than today
  // (not just yesterday) so they can be colored once and drop out.
  // --------------------------------------------------------
  function collectOldUnresolvedGameIds(maxTotal = BACKFILL_BATCH) {
    const cards = Array.from(grid.querySelectorAll(".thread-card"));
    const nhl = [];
    const ncaa = [];

    const pushId = (c) => {
      if (nhl.length + ncaa.length >= maxTotal) return;

      if (c.dataset.msfGameId) {
        const id = parseInt(c.dataset.msfGameId, 10);
        if (id && !nhl.includes(id)) nhl.push(id);
      } else if (c.dataset.ncaaGameId) {
        const id = String(c.dataset.ncaaGameId);
        if (id && !ncaa.includes(id)) ncaa.push(id);
      }
    };

    for (const c of cards) {
      if (nhl.length + ncaa.length >= maxTotal) break;

      const d = (c.dataset.gameDate || "").trim(); // "YYYY-MM-DD"
      if (!d || !todayYmd) continue;

      // strictly older than today
      if (d < todayYmd && cardLooksUnresolved(c)) {
        pushId(c);
      }
    }

    return { nhl, ncaa };
  }

  // --------------------------------------------------------
  // Apply scores to DOM
  // - Works for NHL + NCAA
  // - NCAA id fallback includes contestId
  // --------------------------------------------------------
  function applyLiveScores(games, tag = "") {
    const entries = Array.isArray(games)
      ? games.map((g) => {
          const gid =
            g?.game_id ??
            g?.id ??
            g?.contestId ??
            g?.contest_id ??
            g?.contestID ??
            g?.gameId ??
            g?.gameID ??
            "";
          return [String(gid || ""), g];
        })
      : Object.entries(games || {}).map(([k, v]) => [String(k), v]);

    let anyLive = false;

    for (const [id, score] of entries) {
      if (!id) continue;

      const elements = CARD_ELEMENTS[id];
      if (!elements) continue;

      const { card, away, home, pill } = elements;

      // Find abbr spans at apply-time (most reliable)
      const awayAbbr = card.querySelector(".thread-card__abbr--away");
      const homeAbbr = card.querySelector(".thread-card__abbr--home");

      // Scores
      if (away) away.textContent = (score.away ?? "") === null ? "" : String(score.away ?? "");
      if (home) home.textContent = (score.home ?? "") === null ? "" : String(score.home ?? "");

      // State
      let isFinal = !!score?.is_final;
      let isLive = !!score?.is_live;
      let isIntermission = !!score?.is_intermission;
      let label = String(score?.label || "");

      // NCAA fallback state (if booleans missing)
      if (tag === "NCAA" && (!("is_live" in score) || !("is_final" in score))) {
        const status = String(score?.status || "").toUpperCase().trim();
        isFinal = status === "F";
        isLive = status === "I" || status === "L";
        const p = String(score?.period || "").toUpperCase().trim();
        isIntermission = !isFinal && (p === "INT" || p === "INTERMISSION" || p.includes("INT"));
        label = buildNcaaLabel(score);
      }

      // NCAA: if booleans exist but label is blank, build label from raw fields
      if (tag === "NCAA" && (!label || !label.trim())) {
        label = buildNcaaLabel(score);
      }

      // Win/loss when final OR when the game date is before today
      const cardDate = (card?.dataset?.gameDate || "").trim(); // "YYYY-MM-DD"
      const isBeforeToday = cardDate && todayYmd && cardDate < todayYmd;

      const aNum = Number(score.away);
      const hNum = Number(score.home);
      const hasNumericScores = Number.isFinite(aNum) && Number.isFinite(hNum);

      if (hasNumericScores && (isFinal || isBeforeToday)) {
        applyOutcomeClasses(awayAbbr, homeAbbr, away, home, aNum, hNum);
      } else {
        removeOutcomeClasses(awayAbbr, homeAbbr, away, home);
      }

      // Pill
      if (pill) {
        if (isFinal) {
          pill.textContent = "Final";
          pill.classList.add("thread-card__pill--final");
        } else if (isIntermission) {
          pill.textContent = label || "INT";
          pill.classList.remove("thread-card__pill--final");
        } else if (isLive) {
          pill.textContent = label || "LIVE";
          pill.classList.remove("thread-card__pill--final");
        } else {
          pill.textContent = pill.dataset.initialText || "Game Day";
          pill.classList.remove("thread-card__pill--final");
        }
      }

      card.dataset.liveStatus = isLive || isIntermission ? "live" : "other";
      if (isLive || isIntermission) anyLive = true;
    }

    return anyLive;
  }

  // --------------------------------------------------------
  // Reorder cards safely (only near top)
  // --------------------------------------------------------
  function reorderCards() {
    // Don’t reorder while the user is scrolled down — prevents “page 1 disappears”
    if (window.scrollY > 250) return;

    const cards = Array.from(grid.querySelectorAll(".thread-card"));
    cards.forEach((card, idx) => ensureCardDefaults(card, idx));

    const sorted = cards.sort((a, b) => {
      const aRank = a.dataset.liveStatus === "live" ? 0 : 1;
      const bRank = b.dataset.liveStatus === "live" ? 0 : 1;
      if (aRank !== bRank) return aRank - bRank;

      return (
        (parseInt(a.dataset.originalOrder, 10) || 0) -
        (parseInt(b.dataset.originalOrder, 10) || 0)
      );
    });

    const sig = sorted
      .map((c) => c.dataset.msfGameId || c.dataset.ncaaGameId || "")
      .join("|");
    if (sig === lastOrderSignature) return;
    lastOrderSignature = sig;

    const frag = document.createDocumentFragment();
    sorted.forEach((c) => frag.appendChild(c));
    grid.appendChild(frag);
  }

  // --------------------------------------------------------
  // BACKFILL RUNNER (runs in batches until none left)
  // --------------------------------------------------------
  let backfillInFlight = false;

  async function backfillOldGames() {
    if (backfillInFlight) return;
    backfillInFlight = true;

    try {
      for (let i = 0; i < 6; i++) {
        const { nhl, ncaa } = collectOldUnresolvedGameIds(BACKFILL_BATCH);
        if (!nhl.length && !ncaa.length) break;

        const nhlPromise = nhl.length
          ? fetch(`/api/index_api_current_scores.php?game_ids=${encodeURIComponent(nhl.join(","))}`, {
              cache: "no-store",
            })
              .then((r) => (r.ok ? r.json() : null))
              .catch(() => null)
          : Promise.resolve(null);

        const ncaaPromise = ncaa.length
          ? fetch(`/api/index_api_ncaa_current_scores.php?game_ids=${encodeURIComponent(ncaa.join(","))}`, {
              cache: "no-store",
            })
              .then((r) => (r.ok ? r.json() : null))
              .catch(() => null)
          : Promise.resolve(null);

        const [nhlData, ncaaData] = await Promise.all([nhlPromise, ncaaPromise]);

        if (nhlData?.ok) applyLiveScores(nhlData.games, "NHL");
        if (ncaaData?.ok) applyLiveScores(ncaaData.games, "NCAA");

        await new Promise((res) => setTimeout(res, 150));
      }

      reorderCards();
    } finally {
      backfillInFlight = false;
    }
  }

  // --------------------------------------------------------
  // Polling loop (deferred + adaptive interval)
  // --------------------------------------------------------
  let pollTimer = null;
  let currentInterval = POLL_INTERVAL_MS_LIVE;

  function scheduleNextPoll() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = setTimeout(fetchAndApplyLiveScores, currentInterval);
  }

  async function fetchAndApplyLiveScores() {
    if (document.visibilityState !== "visible") {
      scheduleNextPoll();
      return;
    }

    const { nhl, ncaa } = collectPollableGameIds();
    if (!nhl.length && !ncaa.length) {
      currentInterval = POLL_INTERVAL_MS_IDLE;
      scheduleNextPoll();
      return;
    }

    const nhlPromise = nhl.length
      ? fetch(`/api/index_api_current_scores.php?game_ids=${encodeURIComponent(nhl.join(","))}`, {
          cache: "no-store",
        })
          .then((r) => (r.ok ? r.json() : null))
          .catch(() => null)
      : Promise.resolve(null);

    const ncaaPromise = ncaa.length
      ? fetch(`/api/index_api_ncaa_current_scores.php?game_ids=${encodeURIComponent(ncaa.join(","))}`, {
          cache: "no-store",
        })
          .then(async (r) => {
            if (!r.ok) return null;
            const raw = await r.text();
            try {
              return JSON.parse(raw);
            } catch {
              console.error("[NCAA JSON PARSE ERROR]");
              console.error("[NCAA RAW RESPONSE BEGIN]\n" + raw + "\n[NCAA RAW RESPONSE END]");
              return null;
            }
          })
          .catch(() => null)
      : Promise.resolve(null);

    const [nhlData, ncaaData] = await Promise.all([nhlPromise, ncaaPromise]);

    let anyLive = false;

    if (nhlData?.ok) anyLive = applyLiveScores(nhlData.games, "NHL") || anyLive;
    if (ncaaData?.ok) anyLive = applyLiveScores(ncaaData.games, "NCAA") || anyLive;

    // HYDRATE GATE: enable outcome coloring only after we've applied real data at least once
    if ((nhlData?.ok || ncaaData?.ok) && !document.body.classList.contains("live-scores--ready")) {
      document.body.classList.add("live-scores--ready");
    }

    reorderCards();

    currentInterval = anyLive ? POLL_INTERVAL_MS_LIVE : POLL_INTERVAL_MS_IDLE;
    scheduleNextPoll();
  }

  // --------------------------------------------------------
  // START (deferred to let page paint)
  // --------------------------------------------------------
  const startPolling = async () => {
    // 1) One-time (and “load more”) backfill for older unresolved games
    await backfillOldGames();

    // 2) Normal polling for today + yesterday unresolved/live
    fetchAndApplyLiveScores();
  };

  if ("requestIdleCallback" in window) {
    requestIdleCallback(() => setTimeout(startPolling, INITIAL_DELAY_MS));
  } else {
    setTimeout(startPolling, INITIAL_DELAY_MS);
  }

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") {
      currentInterval = POLL_INTERVAL_MS_LIVE;
      backfillOldGames().catch(() => {});
      fetchAndApplyLiveScores();
    }
  });
});
