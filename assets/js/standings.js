// assets/js/standings.js
document.addEventListener('DOMContentLoaded', () => {
  const viewSelect = document.getElementById('standings-view');
  const root       = document.getElementById('standings-root');
  const emptyState = document.getElementById('standings-empty');

  if (!viewSelect || !root) {
    console.warn('Standings JS: missing DOM elements');
    return;
  }

  let allTeams    = [];
  let currentView = viewSelect.value || 'wildcard';

  // --- Team class helpers (row theming) ---
  // If your CSS is like body.team-ana { ... }, make it also apply to ".team-ana"
  // e.g. body.team-ana, .team-ana { ... }
  const TEAM_CLASS_MAP = {
    BOS:'team-bos', BUF:'team-buf', DET:'team-det', FLA:'team-fla', MTL:'team-mtl', OTT:'team-ott', TBL:'team-tbl', TOR:'team-tor',
    CAR:'team-car', CBJ:'team-cbj', NJD:'team-njd', NYI:'team-nyi', NYR:'team-nyr', PHI:'team-phi', PIT:'team-pit', WSH:'team-wsh',
    CHI:'team-chi', COL:'team-col', DAL:'team-dal', MIN:'team-min', NSH:'team-nsh', STL:'team-stl', UTA:'team-uta', WPG:'team-wpg',
    ANA:'team-ana', CGY:'team-cgy', EDM:'team-edm', LAK:'team-lak', SJS:'team-sjs', SEA:'team-sea', VAN:'team-van', VGK:'team-vgk',
  };

  function teamClass(teamCode) {
    const t = String(teamCode || '').toUpperCase();
    return TEAM_CLASS_MAP[t] || ('team-' + t.toLowerCase());
  }

  // Handy key for "taken" sets (wildcard view)
  function teamKey(t) {
    return `${t.team}`;
  }

  /**
   * NHL-style tiebreaker comparator.
   *
   * Order:
   *  1) Points (PTS)
   *  2) Fewer games played (higher points%) -> fewer GP first
   *  3) Regulation Wins (RW)
   *  4) Regulation + OT Wins (ROW)
   *  5) Total Wins (W)
   *  6) Goal Differential (DIFF)
   *  7) Goals For (GF)
   *  8) Team code (alpha) fallback
   */
  function comparator(a, b) {
    if (b.points !== a.points) return b.points - a.points;
    if (a.games !== b.games) return a.games - b.games;
    if (b.rw !== a.rw) return b.rw - a.rw;
    if (b.row !== a.row) return b.row - a.row;
    if (b.wins !== a.wins) return b.wins - a.wins;
    if (b.diff !== a.diff) return b.diff - a.diff;
    if (b.gf !== a.gf) return b.gf - a.gf;
    return a.team.localeCompare(b.team);
  }

  // --- Formatting helpers for optional fields ---
  const dash = () => '-';
  const fmtRecord = (w, l, o) => {
    if (Number.isFinite(w) && Number.isFinite(l) && Number.isFinite(o)) return `${w}-${l}-${o}`;
    return '-';
  };

  // Build a table with FULL columns + mobile-card hooks (data-label + cell classes)
  function buildTable(headerLabel, rows, startRank, opts = {}) {
    const {
      highlightTop = null,       // number of rows to highlight (WC spots)
      tableClass = '',
    } = opts;

    const section = document.createElement('section');
    section.className = 'standings-group';

    const title = document.createElement('div');
    title.className = 'standings-group__title';
    title.textContent = headerLabel;
    section.appendChild(title);

    const wrapper = document.createElement('div');
    wrapper.className = 'stats-table-wrapper standings-group__table';
    section.appendChild(wrapper);

    const table = document.createElement('table');
    table.className = `stats-table ${tableClass}`.trim();
    wrapper.appendChild(table);

    const thead = document.createElement('thead');
    thead.innerHTML = `
      <tr>
        <th>Pos</th>
        <th>Team</th>
        <th>GP</th>
        <th>W</th>
        <th>L</th>
        <th>OTL</th>
        <th>PTS</th>
        <th>RW</th>
        <th>ROW</th>
        <th>SOW</th>
        <th>SOL</th>
        <th>HOME</th>
        <th>AWAY</th>
        <th>GF</th>
        <th>GA</th>
        <th>DIFF</th>
        <th>L10</th>
        <th>STRK</th>
      </tr>
    `;
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    table.appendChild(tbody);

    rows.forEach((t, idx) => {
      const tr = document.createElement('tr');

      const isWCSpot = (highlightTop !== null && idx < highlightTop);
      tr.className = [
        'standings-table__row',
        teamClass(t.team),
        isWCSpot ? 'standings-table__row--wc' : '',
      ].filter(Boolean).join(' ');

      const rank = (startRank || 1) + idx;

      // Optional fields (only show if API returns them; otherwise '-')
      const home = (t.home && typeof t.home === 'string') ? t.home : (
        Number.isFinite(t.home_w) ? fmtRecord(t.home_w, t.home_l, t.home_otl) : dash()
      );
      const away = (t.away && typeof t.away === 'string') ? t.away : (
        Number.isFinite(t.away_w) ? fmtRecord(t.away_w, t.away_l, t.away_otl) : dash()
      );

      const l10  = (t.l10 && typeof t.l10 === 'string') ? t.l10 : dash();
      const strk = (t.strk && typeof t.strk === 'string') ? t.strk : dash();

      tr.innerHTML = `
        <td class="standings-cell standings-cell--rank" data-label="Pos"><span class="standings-rankbadge">${rank}</span></td>
        <td class="standings-cell standings-cell--team" data-label="Team">
          <div class="standings-teamline">
            <span class="standings-teamabbr">${t.team}</span>
          </div>
        </td>

        <td class="standings-cell standings-cell--gp" data-label="GP">${t.games}</td>
        <td class="standings-cell standings-cell--w" data-label="W">${t.wins}</td>
        <td class="standings-cell standings-cell--l" data-label="L">${t.losses}</td>
        <td class="standings-cell standings-cell--otl" data-label="OTL">${t.otl}</td>
        <td class="standings-cell standings-cell--pts" data-label="PTS"><strong>${t.points}</strong></td>

        <td class="standings-cell standings-cell--rw" data-label="RW">${Number.isFinite(t.rw) ? t.rw : 0}</td>
        <td class="standings-cell standings-cell--row" data-label="ROW">${Number.isFinite(t.row) ? t.row : 0}</td>
        <td class="standings-cell standings-cell--sow" data-label="SOW">${Number.isFinite(t.sow) ? t.sow : 0}</td>
        <td class="standings-cell standings-cell--sol" data-label="SOL">${Number.isFinite(t.sol) ? t.sol : 0}</td>

        <td class="standings-cell standings-cell--home" data-label="HOME">${home}</td>
        <td class="standings-cell standings-cell--away" data-label="AWAY">${away}</td>

        <td class="standings-cell standings-cell--gf" data-label="GF">${t.gf}</td>
        <td class="standings-cell standings-cell--ga" data-label="GA">${t.ga}</td>
        <td class="standings-cell standings-cell--diff" data-label="DIFF">${t.diff}</td>

        <td class="standings-cell standings-cell--l10" data-label="L10">${l10}</td>
        <td class="standings-cell standings-cell--strk" data-label="STRK">${strk}</td>
      `;
      tbody.appendChild(tr);
    });

    return section;
  }

  /**
   * WILDCARD VIEW (only view that applies playoff-cut logic)
   * - 2 conference blocks
   * - inside each: 2 division tables (top 3 only) + wildcard table (remaining, highlight top 2)
   */
  function renderWildcard() {
    root.innerHTML = '';

    if (!allTeams.length) {
      if (emptyState) emptyState.hidden = false;
      return;
    }
    if (emptyState) emptyState.hidden = true;

    const conferences = [
      { key: 'EAST', label: 'Eastern Conference', divs: ['ATL', 'METRO'] },
      { key: 'WEST', label: 'Western Conference', divs: ['CENTRAL', 'PAC'] },
    ];

    const labelMap = {
      ATL: 'ATLANTIC',
      METRO: 'METROPOLITAN',
      CENTRAL: 'CENTRAL',
      PAC: 'PACIFIC',
    };

    conferences.forEach(conf => {
      const confTeams = allTeams
        .filter(t => String(t.conference || '').toUpperCase() === conf.key)
        .sort(comparator);

      if (!confTeams.length) return;

      const confArticle = document.createElement('article');
      confArticle.className = 'standings-conf';

      const h2 = document.createElement('h2');
      h2.className = 'standings-conf__title';
      h2.textContent = conf.label;
      confArticle.appendChild(h2);

      const groupsContainer = document.createElement('div');
      groupsContainer.className = 'standings-conf__groups';
      confArticle.appendChild(groupsContainer);

      const taken = new Set();

      // Divisions: top 3 each
      conf.divs.forEach(divCode => {
        const divTeams = confTeams
          .filter(t => String(t.division || '').toUpperCase() === divCode)
          .sort(comparator);

        if (!divTeams.length) return;

        const top3 = divTeams.slice(0, 3);
        top3.forEach(t => taken.add(teamKey(t)));

        const headerLabel = labelMap[divCode] || divCode;
        groupsContainer.appendChild(buildTable(headerLabel, top3, 1));
      });

      // Wild card: remaining teams in that conference
      const wildTeams = confTeams
        .filter(t => !taken.has(teamKey(t)))
        .sort(comparator);

      if (wildTeams.length) {
        groupsContainer.appendChild(buildTable('WILD CARD', wildTeams, 1, { highlightTop: 2 }));
      }

      root.appendChild(confArticle);
    });
  }

  /**
   * LEAGUE VIEW
   * - 1 table total
   */
  function renderLeague() {
    root.innerHTML = '';
    if (!allTeams.length) {
      if (emptyState) emptyState.hidden = false;
      return;
    }
    if (emptyState) emptyState.hidden = true;

    const sorted = [...allTeams].sort(comparator);
    root.appendChild(buildTable('LEAGUE', sorted, 1));
  }

  /**
   * CONFERENCE VIEW
   * - 2 tables total (East + West)
   * - no division logic, no wildcard logic
   */
  function renderConference() {
    root.innerHTML = '';
    if (!allTeams.length) {
      if (emptyState) emptyState.hidden = false;
      return;
    }
    if (emptyState) emptyState.hidden = true;

    const east = allTeams
      .filter(t => String(t.conference || '').toUpperCase() === 'EAST')
      .sort(comparator);

    const west = allTeams
      .filter(t => String(t.conference || '').toUpperCase() === 'WEST')
      .sort(comparator);

    if (east.length) root.appendChild(buildTable('EASTERN CONFERENCE', east, 1));
    if (west.length) root.appendChild(buildTable('WESTERN CONFERENCE', west, 1));
  }

  /**
   * DIVISION VIEW
   * - 4 tables total (Atlantic/Metro/Central/Pacific)
   * - each table contains ALL teams in that division (including those in WC race)
   * - no wildcard cut logic
   */
  function renderDivision() {
    root.innerHTML = '';
    if (!allTeams.length) {
      if (emptyState) emptyState.hidden = false;
      return;
    }
    if (emptyState) emptyState.hidden = true;

    const divisions = [
      { key: 'ATL',     label: 'ATLANTIC' },
      { key: 'METRO',   label: 'METROPOLITAN' },
      { key: 'CENTRAL', label: 'CENTRAL' },
      { key: 'PAC',     label: 'PACIFIC' },
    ];

    divisions.forEach(d => {
      const rows = allTeams
        .filter(t => String(t.division || '').toUpperCase() === d.key)
        .sort(comparator);

      if (rows.length) root.appendChild(buildTable(d.label, rows, 1));
    });
  }

  function renderByView() {
    if (currentView === 'wildcard') return renderWildcard();
    if (currentView === 'league') return renderLeague();
    if (currentView === 'conference') return renderConference();
    if (currentView === 'division') return renderDivision();

    // fallback
    return renderWildcard();
  }

  async function fetchStandings() {
    try {
      const res = await fetch(`/api/standings_api.php?view=${encodeURIComponent(currentView)}`, {
        headers: { 'Accept': 'application/json' },
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();

      if (!data.ok || !Array.isArray(data.teams)) {
        allTeams = [];
      } else {
        allTeams = data.teams.map(t => ({
          team:       t.team,
          conference: t.conference,
          division:   t.division,

          games:      Number(t.games),
          wins:       Number(t.wins),
          losses:     Number(t.losses),   // regulation losses only
          otl:        Number(t.otl),      // OTL + SOL
          points:     Number(t.points),

          rw:         Number(t.rw ?? 0),
          row:        Number(t.row ?? 0),
          sow:        Number(t.sow ?? 0),
          sol:        Number(t.sol ?? 0),

          gf:         Number(t.gf),
          ga:         Number(t.ga),
          diff:       Number(t.diff),

          // Optional extras (if your API adds them later)
          home:   (t.home ?? null),
          away:   (t.away ?? null),
          l10:    (t.l10 ?? null),
          strk:   (t.strk ?? null),

          // Optional split numbers if you choose to return them
          home_w: Number.isFinite(Number(t.home_w)) ? Number(t.home_w) : NaN,
          home_l: Number.isFinite(Number(t.home_l)) ? Number(t.home_l) : NaN,
          home_otl: Number.isFinite(Number(t.home_otl)) ? Number(t.home_otl) : NaN,
          away_w: Number.isFinite(Number(t.away_w)) ? Number(t.away_w) : NaN,
          away_l: Number.isFinite(Number(t.away_l)) ? Number(t.away_l) : NaN,
          away_otl: Number.isFinite(Number(t.away_otl)) ? Number(t.away_otl) : NaN,
        }));
      }

      renderByView();
    } catch (err) {
      console.error('Failed to fetch standings:', err);
      allTeams = [];
      if (emptyState) emptyState.hidden = false;
      root.innerHTML = '';
    }
  }

  // Initial load
  fetchStandings();

  // View switcher – changes grouping only
  viewSelect.addEventListener('change', () => {
    currentView = viewSelect.value || 'wildcard';

    const params = new URLSearchParams(window.location.search);
    params.set('view', currentView);
    const newUrl = `${window.location.pathname}?${params.toString()}`;
    window.history.replaceState({}, '', newUrl);

    // No re-fetch needed unless your API varies by view.
    // If your API returns identical data always, just render:
    renderByView();

    // If your API *does* vary by view, swap to:
    // fetchStandings();
  });
});
