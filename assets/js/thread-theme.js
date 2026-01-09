// assets/js/thread-theme.js
// Thread pages: keep the user's chosen theme-team-* sticky.
// - Never overwrite a saved (user-chosen) theme.
// - If thread is auto-themed (home/away fallback), DO NOT persist it to sessionStorage or server,
//   so it won't "leak" back to index.php.
// - If PHP emitted a theme from the session (user choice), it can be persisted/synced.

document.addEventListener('DOMContentLoaded', () => {
  const body = document.body;
  if (!body || !body.classList.contains('page-thread')) return;

  // League (routing/layout) is still league-nhl / league-ncaa
  const isNcaa = body.classList.contains('league-ncaa');
  const isNhl  = body.classList.contains('league-nhl') || !isNcaa;

  // -------------------------------
  // Helpers
  // -------------------------------
  function slugify(s) {
    return String(s || '')
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/(^-|-$)/g, '');
  }

  function clearThemeClasses() {
    const remove = [];
    body.classList.forEach((c) => {
      if (c.startsWith('theme-')) remove.push(c);
    });
    remove.forEach((c) => body.classList.remove(c));
  }

  /**
   * Apply theme slug to <body>.
   * opts.persist:
   *   - true  => write sessionStorage (user-chosen / session-based theme)
   *   - false => display-only (thread auto-theme)
   */
  function applyThemeSlug(slug, opts) {
    opts = opts || {};
    const persist = !!opts.persist;

    clearThemeClasses();

    if (slug) {
      body.classList.add('theme-team-' + slug);
    } else {
      body.classList.add(isNcaa ? 'theme-ncaa-all' : 'theme-team-all');
    }

    if (!persist) return;

    try {
      sessionStorage.setItem('themeLeague', isNcaa ? 'ncaa' : 'nhl');
      sessionStorage.setItem('themeTeam', slug || '');
    } catch (e) {}
  }

  function syncThemeToServer(slug) {
    fetch('/api/index_header_set_theme.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ theme: slug || '' })
    }).catch(() => {});
  }

  function getBodyThemeSlug() {
    const m = body.className.match(/\btheme-team-([a-z0-9-]+)\b/);
    return m ? m[1] : '';
  }

  // header.php should emit: <body ... data-theme-source="session|override|default">
  // session  = user-chosen persisted theme (OK to persist)
  // override = thread-only fallback (NOT OK to persist)
  // default  = theme-team-all/theme-ncaa-all (no need to persist)
  function getThemeSource() {
    return String(body.getAttribute('data-theme-source') || '').trim();
  }

  // -------------------------------
  // Priority order:
  // 1) sessionStorage themeTeam (user-chosen, sticky)
  // 2) PHP-emitted theme-team-*:
  //      - if source=session => persist/sync (keeps things consistent)
  //      - if source=override => display-only (NO persist)
  // 3) fallback: infer from thread-root data attrs (display-only, NO persist)
  // -------------------------------
  let storedSlug = '';
  try {
    storedSlug = (sessionStorage.getItem('themeTeam') || '').trim();
  } catch (e) {}

  const phpSlug = getBodyThemeSlug();
  const themeSource = getThemeSource();

  // 1) Always respect saved theme
  if (storedSlug) {
    applyThemeSlug(storedSlug, { persist: true });
    syncThemeToServer(storedSlug);
    return;
  }

  // 2) PHP theme (only persist if it came from the session/user choice)
  if (phpSlug) {
    if (themeSource === 'session') {
      applyThemeSlug(phpSlug, { persist: true });
      syncThemeToServer(phpSlug);
    }
    // override/default => keep visually, but do not persist
    return;
  }

  // 3) Fallback only if nothing is set anywhere (DISPLAY ONLY)
  const root = document.getElementById('thread-root');
  const home = slugify(root && root.dataset ? root.dataset.homeTeam : '');
  const away = slugify(root && root.dataset ? root.dataset.awayTeam : '');

  const fallback = home || away || '';
  applyThemeSlug(fallback, { persist: false });
  // IMPORTANT: do NOT sync fallback to server
});
