/* assets/js/stats-adv-player-cards.js
 *
 * Cards-first behavior for stats_adv.php:
 * - Delegated click handler on #adv-cards
 * - "More stats" toggles a hidden section
 * - First open fetches /api/stats_adv_player_more.php with same filters
 */

(function () {
  function esc(s) {
    s = (s == null) ? '' : String(s);
    return s.replace(/[&<>"']/g, function (c) {
      return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]);
    });
  }

  function fetchHtml(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      return r.text().then(function (txt) {
        if (!r.ok) throw new Error('HTTP ' + r.status + ': ' + txt);
        return txt;
      });
    });
  }

  function currentFilters() {
    var teamSel = document.getElementById('stats-team');
    var stateSel = document.getElementById('stats-state');
    var seasonSel = document.getElementById('stats-season');
    var gpSel = document.getElementById('stats-min-gp');

    return {
      team: teamSel ? teamSel.value : 'all',
      state: stateSel ? stateSel.value : '5v5',
      season: seasonSel ? seasonSel.value : '',
      min_gp: gpSel ? gpSel.value : '0'
    };
  }

  function buildUrl(pid) {
    var f = currentFilters();
    var p = new URLSearchParams();
    p.set('id', String(pid));
    p.set('team', String(f.team || 'all'));
    p.set('state', String(f.state || '5v5'));
    if (f.season) p.set('season', String(f.season));
    if (f.min_gp && parseInt(f.min_gp, 10) > 0) p.set('min_gp', String(parseInt(f.min_gp, 10)));
    return '/api/stats_adv_player_card.php?' + p.toString();
  }

  function boot() {
    var root = document.getElementById('adv-cards');
    if (!root) return;

    root.addEventListener('click', function (e) {
      var btn = e.target.closest('button.adv-card__toggle[data-action="toggle-more"]');
      if (!btn) return;

      var card = btn.closest('article[data-player-id]');
      if (!card) return;

      var pid = card.getAttribute('data-player-id');
      if (!pid) return;

      var more = card.querySelector('.adv-card__more');
      if (!more) return;

      // If already loaded, just toggle
      if (more.getAttribute('data-loaded') === '1') {
        var isHidden = more.hasAttribute('hidden');
        if (isHidden) {
          more.removeAttribute('hidden');
          btn.textContent = 'Less stats';
        } else {
          more.setAttribute('hidden', '');
          btn.textContent = 'More stats';
        }
        return;
      }

      // Load on first open
      btn.disabled = true;
      btn.textContent = 'Loading…';

      fetchHtml(buildUrl(pid))
        .then(function (html) {
          more.innerHTML = html;
          more.setAttribute('data-loaded', '1');
          more.removeAttribute('hidden');
          btn.textContent = 'Less stats';
        })
        .catch(function (err) {
          console.error('[adv-cards] more stats failed', err);
          more.innerHTML = '<div class="adv-card__section-title">More stats</div>' +
                           '<div class="player-card__body">' +
                           '<div class="player-card__row"><span class="label">Error</span><span class="value">' + esc(err.message || String(err)) + '</span></div>' +
                           '</div>';
          more.setAttribute('data-loaded', '1');
          more.removeAttribute('hidden');
          btn.textContent = 'Less stats';
        })
        .finally(function () {
          btn.disabled = false;
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
