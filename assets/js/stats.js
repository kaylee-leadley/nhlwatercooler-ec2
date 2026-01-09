// assets/js/stats.js
document.addEventListener('DOMContentLoaded', () => {
  const teamSelect     = document.getElementById('stats-team');
  const statsTeamLabel = document.getElementById('stats-team-label');
  const statsTable     = document.getElementById('stats-table');
  const statsTbody     = statsTable ? statsTable.querySelector('tbody') : null;
  const statsEmpty     = document.getElementById('stats-empty');

  if (!teamSelect || !statsTable || !statsTbody) return;

  let currentStats = [];
  let currentSort  = { key: '', dir: 'desc' };

  const LOGO_BASE = '/assets/img/logos'; // /assets/img/logos/COL.png

  function getCurrentTeamCode() {
    return (teamSelect.value || 'all').toLowerCase();
  }

  function updateTeamLabel() {
    const opt  = teamSelect.options[teamSelect.selectedIndex];
    const code = getCurrentTeamCode();
    statsTeamLabel.textContent =
      code === 'all'
        ? 'All Teams'
        : (opt ? opt.textContent.trim() : code.toUpperCase());
  }

  async function fetchStats(teamCode) {
    const url = `../api/stats_api.php?team=${encodeURIComponent(teamCode)}`;
    try {
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      console.log('stats-api result', data);
      return Array.isArray(data.stats) ? data.stats : [];
    } catch (err) {
      console.error('Failed to fetch stats:', err);
      return [];
    }
  }

  function renderStatsTable() {
    statsTbody.innerHTML = '';

    if (!currentStats.length) {
      if (statsEmpty) statsEmpty.hidden = false;
      return;
    }
    if (statsEmpty) statsEmpty.hidden = true;

    currentStats.forEach(item => {
      const first = (item.first_name || '').trim();
      const last  = (item.last_name || '').trim();
      const playerName = `${first} ${last}`.trim() || 'Unknown';

      // API example shows item.team = "COL"
      const teamAbbrUpper = String(item.team || '').trim().toUpperCase();
      const teamAbbrLower = teamAbbrUpper.toLowerCase();

      const jersey = String(item.jersey_number || '').trim();

      const tr = document.createElement('tr');
      tr.classList.add('stats-row');          // keep stats-player-cards.js compatibility
      tr.dataset.playerId = item.player_id;

      // add team class like standings (for gradient vars)
      if (teamAbbrLower) tr.classList.add(`team-${teamAbbrLower}`);

      const logoHtml = teamAbbrUpper
        ? `<img class="stats-teamlogo"
                src="${LOGO_BASE}/${teamAbbrUpper}.png"
                alt="${teamAbbrUpper}"
                loading="lazy"
                onerror="this.style.display='none'">`
        : '';

      // We always output the same column order for desktop.
      // Mobile CSS will rearrange into:
      // Header (2 rows): logo spans rows 1-2, name to the right
      // Stats (2 rows): 5 cols x 2 rows
      tr.innerHTML = `
        <td class="stats-cell stats-cell--logo" data-label="">
          ${logoHtml}
        </td>

        <td class="stats-cell stats-cell--player" data-label="Player">
          <div class="stats-playerwrap">
            <div class="stats-playername">${playerName}</div>
          </div>
        </td>

        <td class="stats-cell stats-cell--pos" data-label="POS">${item.position || ''}</td>
        <td class="stats-cell stats-cell--gp"  data-label="GP">${item.games ?? ''}</td>
        <td class="stats-cell stats-cell--g"   data-label="G">${item.goals ?? ''}</td>
        <td class="stats-cell stats-cell--a"   data-label="A">${item.assists ?? ''}</td>
        <td class="stats-cell stats-cell--pts" data-label="PTS"><strong>${item.points ?? ''}</strong></td>
        <td class="stats-cell stats-cell--sog" data-label="SOG">${item.shots ?? ''}</td>
        <td class="stats-cell stats-cell--pim" data-label="PIM">${item.pim ?? ''}</td>
        <td class="stats-cell stats-cell--team" data-label="TEAM">${teamAbbrUpper}</td>
        <td class="stats-cell stats-cell--jersey" data-label="#">${jersey}</td>
      `;

      statsTbody.appendChild(tr);
    });
  }

  function sortStats(key) {
    if (!key) return;

    if (currentSort.key === key) {
      currentSort.dir = currentSort.dir === 'asc' ? 'desc' : 'asc';
    } else {
      currentSort.key = key;
      if (key === 'player' || key === 'position') currentSort.dir = 'asc';
      else currentSort.dir = 'desc';
    }

    currentStats.sort((a, b) => {
      let av, bv;

      if (key === 'player') {
        av = `${a.first_name || ''} ${a.last_name || ''}`.trim();
        bv = `${b.first_name || ''} ${b.last_name || ''}`.trim();
      } else if (key === 'position') {
        av = a.position || '';
        bv = b.position || '';
      } else {
        av = a[key];
        bv = b[key];
      }

      const aNum = typeof av === 'number' || !isNaN(parseFloat(av));
      const bNum = typeof bv === 'number' || !isNaN(parseFloat(bv));

      let cmp;
      if (aNum && bNum) cmp = Number(av) - Number(bv);
      else cmp = String(av).localeCompare(String(bv));

      return currentSort.dir === 'asc' ? cmp : -cmp;
    });

    statsTable.querySelectorAll('th').forEach(th => {
      th.classList.remove('sort-asc', 'sort-desc');
      if (th.dataset.sortKey === currentSort.key) {
        th.classList.add(currentSort.dir === 'asc' ? 'sort-asc' : 'sort-desc');
      }
    });

    renderStatsTable();
  }

  async function loadStats() {
    updateTeamLabel();
    currentStats = await fetchStats(getCurrentTeamCode());

    currentSort = { key: '', dir: 'desc' };
    sortStats('points');
  }

  loadStats();

  teamSelect.addEventListener('change', () => {
    const teamCode = getCurrentTeamCode() || 'all';
    const basePath = window.location.pathname.replace(/\/+$/, '');
    window.location.href = `${basePath}?team=${encodeURIComponent(teamCode)}`;
  });

  statsTable.querySelectorAll('th[data-sort-key]').forEach(th => {
    th.addEventListener('click', () => sortStats(th.dataset.sortKey));
  });
});
