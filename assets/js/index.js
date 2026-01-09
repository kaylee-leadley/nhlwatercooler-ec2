// assets/js/index.js

document.addEventListener('DOMContentLoaded', () => {
  const threadGrid   = document.querySelector('.thread-grid');
  const sentinel     = document.getElementById('thread-sentinel');
  const loadMoreBtn  = document.getElementById('thread-load-more');
  const searchInput  = document.getElementById('thread-search');
  const teamSelect   = document.getElementById('team');

  function slugifyTeam(s) {
    return String(s || '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/(^-|-$)/g, '');
  }

  function getLeagueFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const league = (params.get('league') || 'nhl').toLowerCase();
    return (league === 'ncaa' || league === 'ncaah') ? 'ncaa' : 'nhl';
  }

  function normalizeNcaaTeamName(val) {
    val = String(val || '').trim();
    if (val === 'Army') return 'Army West Point';
    if (val === 'Rochester Inst.') return 'RIT';
    if (val === 'Connecticut') return 'uconn';
    if (val === 'Penn State') return 'Penn St.';
    return val;
  }

  function setThemeClasses({ league, teamSlug }) {
    const body = document.body;
    if (!body) return;

    const remove = [];
    body.classList.forEach((c) => {
      if (c.startsWith('theme-')) remove.push(c);
    });
    remove.forEach((c) => body.classList.remove(c));

    if (league === 'ncaa') {
      body.classList.add(teamSlug ? ('theme-team-' + teamSlug) : 'theme-ncaa-all');
    } else {
      body.classList.add(teamSlug ? ('theme-team-' + teamSlug) : 'theme-team-all');
    }

    try {
      sessionStorage.setItem('themeLeague', league);
      sessionStorage.setItem('themeTeam', teamSlug || '');
    } catch (e) {}
  }

  function syncThemeToServer(themeSlug) {
    fetch('/api/index_header_set_theme.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ theme: themeSlug || '' })
    }).catch(() => {});
  }

  function getTeamValueForLeague(league) {
    if (!teamSelect) return { teamRaw: 'ALL', isAll: true, teamSlug: '' };

    let val = String(teamSelect.value || '').trim();
    if (league === 'ncaa') val = normalizeNcaaTeamName(val);

    const isAll = /^all$/i.test(val) || val === '';
    const teamRaw = isAll ? 'ALL' : val;
    const teamSlug = isAll ? '' : slugifyTeam(val);

    return { teamRaw, isAll, teamSlug };
  }

  /**
   * Index is authoritative:
   * - If dropdown is ALL => force generic theme AND clear persisted theme (client + server)
   * - If dropdown is a team => apply/persist that team (client + server)
   */
  function reconcileIndexTheme() {
    if (!teamSelect) return;

    const league = getLeagueFromUrl();
    const { isAll, teamSlug } = getTeamValueForLeague(league);

    if (isAll) {
      // ALL should never show a sticky home-team theme
      setThemeClasses({ league, teamSlug: '' });

      // Clear persisted theme
      try {
        sessionStorage.setItem('themeLeague', league);
        sessionStorage.setItem('themeTeam', '');
      } catch (e) {}

      // Clear server theme session
      syncThemeToServer('');
    } else {
      // User-selected theme stays sticky
      setThemeClasses({ league, teamSlug });
      syncThemeToServer(teamSlug);
    }
  }

  // Run on initial load
  reconcileIndexTheme();

  // Run when returning via back/forward cache (so themes don't "stick" incorrectly)
  window.addEventListener('pageshow', () => {
    reconcileIndexTheme();
  });

  function applyFiltersAndReload() {
    const params = new URLSearchParams(window.location.search);
    const league = getLeagueFromUrl();

    params.set('league', league);

    if (searchInput) {
      const val = searchInput.value.trim();
      if (val) params.set('search', val);
      else params.delete('search');
    }

    const { teamRaw, isAll, teamSlug } = getTeamValueForLeague(league);

    params.set('team', isAll ? 'ALL' : teamRaw);

    setThemeClasses({ league, teamSlug: isAll ? '' : teamSlug });
    syncThemeToServer(isAll ? '' : teamSlug);

    window.location.href = window.location.pathname + '?' + params.toString();
  }

  if (teamSelect) {
    teamSelect.addEventListener('change', () => {
      applyFiltersAndReload();
    });
  }

  if (searchInput) {
    searchInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        applyFiltersAndReload();
      }
    });
  }

  // -------------------------------
  // Lazy-load threads
  // -------------------------------
  if (!threadGrid) return;

  const PAGE_SIZE = 20;
  let offset = parseInt(threadGrid.dataset.initialCount || '0', 10) || 0;

  let isLoading = false;
  let isDone    = false;

  function getCurrentFilters() {
    const params = new URLSearchParams(window.location.search);
    const search = params.get('search') || '';
    const team   = params.get('team') || 'ALL';
    const league = (params.get('league') || 'nhl').toLowerCase();
    return { search, team, league };
  }

  async function loadMoreThreads() {
    if (isLoading || isDone) return;

    isLoading = true;
    if (loadMoreBtn) {
      loadMoreBtn.disabled = true;
      loadMoreBtn.textContent = 'Loading…';
    }

    try {
      const { search, team, league } = getCurrentFilters();

      const params = new URLSearchParams();
      params.set('offset', String(offset));
      params.set('limit', String(PAGE_SIZE));
      params.set('league', league);

      if (team && team !== 'ALL') params.set('team', team);
      if (search) params.set('search', search);

      const res = await fetch('api/threads_lazy.php?' + params.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      if (!res.ok) return;

      const data = await res.json();
      if (!data || !data.ok) return;

      const count = parseInt(data.count || 0, 10) || 0;
      const pageSize = parseInt(data.page_size || PAGE_SIZE, 10) || PAGE_SIZE;

      if (count <= 0 || !data.html) {
        isDone = true;
        if (sentinel) sentinel.style.display = 'none';
        if (loadMoreBtn) loadMoreBtn.hidden = true;
        return;
      }

      const temp = document.createElement('div');
      temp.innerHTML = data.html;
      while (temp.firstChild) {
        threadGrid.appendChild(temp.firstChild);
      }

      offset += count;

      if (count < pageSize) {
        isDone = true;
        if (sentinel) sentinel.style.display = 'none';
        if (loadMoreBtn) loadMoreBtn.hidden = true;
      } else {
        if (loadMoreBtn) loadMoreBtn.hidden = false;
      }
    } catch (err) {
      console.error('threads_lazy exception', err);
    } finally {
      isLoading = false;
      if (loadMoreBtn && !isDone) {
        loadMoreBtn.disabled = false;
        loadMoreBtn.textContent = 'Load more threads';
      }
    }
  }

  if (loadMoreBtn) {
    loadMoreBtn.addEventListener('click', () => loadMoreThreads());
  }

  if (sentinel && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting && !isLoading && !isDone) {
          loadMoreThreads();
        }
      });
    }, { rootMargin: '200px' });

    observer.observe(sentinel);
  }
});
