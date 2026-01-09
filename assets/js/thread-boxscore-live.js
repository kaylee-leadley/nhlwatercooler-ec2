/*======================================
  File: public/assets/js/thread-boxscore-live.js
  Description: Polls /api/thread_boxscore_poll.php to keep boxscore live (today + next day ET)
======================================*/
document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('boxscore-root');
  if (!root) return;

  const POLL_MS = 15000;

  const league = (root.dataset.league || '').toLowerCase();
  if (league !== 'nhl' && league !== 'ncaa') return;

  function nowEt() {
    // Create a Date representing the current time in ET (works reliably for comparisons in this script).
    const s = new Date().toLocaleString('en-US', { timeZone: 'America/New_York' });
    return new Date(s);
  }

  function parseEtStart(gameDate, gameTime) {
    if (!gameDate) return null;

    let t = (gameTime || '').trim();
    if (!t) t = '10:00:00';
    if (/^\d{2}:\d{2}$/.test(t)) t += ':00';

    const parts = `${gameDate} ${t}`.match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/);
    if (!parts) return null;

    const [, Y, M, D, hh, mm, ss] = parts;
    const etString = `${M}/${D}/${Y} ${hh}:${mm}:${ss}`;
    const d = new Date(etString);
    return isNaN(d.getTime()) ? null : d;
  }

  function ymdFromEtDate(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${dd}`;
  }

  function isTodayEt(gameDate) {
    if (!gameDate) return false;
    return gameDate === ymdFromEtDate(nowEt());
  }

  function isYesterdayEt(gameDate) {
    if (!gameDate) return false;
    const n = nowEt();
    n.setDate(n.getDate() - 1);
    return gameDate === ymdFromEtDate(n);
  }

  // STRICT-ish final detection: Final or Final Pending Review
  function isFinalHtml(html) {
    const h = String(html || '');
    return /<span[^>]*class="status"[^>]*>\s*Final(?:\s+Pending\s+Review)?\s*<\/span>/i.test(h);
  }

  function buildUrl() {
    const url = new URL('/api/thread_boxscore_poll.php', window.location.origin);
    url.searchParams.set('league', league);

    if (league === 'nhl') {
      const gid = root.dataset.msfGameId || '';
      if (!gid) return null;
      url.searchParams.set('msf_game_id', gid);
    } else {
      const cid = root.dataset.contestId || '';
      const gd  = root.dataset.gameDate || '';
      if (!cid || !gd) return null;
      url.searchParams.set('contest_id', cid);
      url.searchParams.set('game_date', gd);
    }

    url.searchParams.set('_ts', String(Date.now()));
    return url.toString();
  }

  const gameDate = (root.dataset.gameDate || '').trim();
  const gameTime = (root.dataset.gameTime || '').trim();
  const startEt  = parseEtStart(gameDate, gameTime);

  function shouldPollNow() {
    if (document.visibilityState !== 'visible') return false;

    // Poll on game day (ET) AND the next day (ET), per your preference.
    if (!isTodayEt(gameDate) && !isYesterdayEt(gameDate)) return false;

    // Only after start time (ET) if we can parse it (still allows “yesterday” refreshes)
    if (startEt) {
      const n = nowEt();
      if (n < startEt) return false;
    }

    // stop if final
    if (root.__isFinal) return false;

    return true;
  }

  let inFlight = false;
  let timer = null;

  async function pollOnce() {
    if (inFlight) return;
    if (!shouldPollNow()) return;

    const url = buildUrl();
    if (!url) return;

    inFlight = true;
    try {
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) return;

      const data = await res.json();
      if (!data || data.ok !== true || typeof data.html !== 'string') return;

      if (root.__lastHtml !== data.html) {
        root.innerHTML = data.html;
        root.__lastHtml = data.html;
      }

      if (isFinalHtml(root.__lastHtml)) {
        root.__isFinal = true;
        if (timer) {
          clearInterval(timer);
          timer = null;
        }
      }
    } catch (e) {
      // ignore
    } finally {
      inFlight = false;
    }
  }

  root.__lastHtml = root.innerHTML;
  root.__isFinal  = isFinalHtml(root.__lastHtml);

  pollOnce();
  timer = setInterval(pollOnce, POLL_MS);

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') pollOnce();
  });
});
