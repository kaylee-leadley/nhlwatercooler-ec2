/* assets/js/stats-player-cards.js
 * Click a stats row to expand a player detail card (lazy-loaded).
 */
(function () {
  function boot() {
    var table = document.getElementById('stats-table');
    if (!table) return;

    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    tbody.addEventListener('click', function (e) {
      // Ignore clicks on interactive elements
      if (e.target.closest('a,button,input,select,textarea,label')) return;

      var tr = e.target.closest('tr.stats-row');
      if (!tr || !tbody.contains(tr)) return;

      var pid = tr.getAttribute('data-player-id');
      if (!pid) return;

      // Toggle if already inserted
      var next = tr.nextElementSibling;
      if (next && next.classList.contains('player-card-row')) {
        next.hidden = !next.hidden;
        tr.classList.toggle('is-expanded', !next.hidden);
        return;
      }

      // Insert a details row
      var colCount =
        (tr.children && tr.children.length) ||
        table.querySelectorAll('thead th').length ||
        1;

      var detailTr = document.createElement('tr');
      detailTr.className = 'player-card-row';

      var detailTd = document.createElement('td');
      detailTd.colSpan = colCount;
      detailTd.className = 'player-card-cell';
      detailTd.innerHTML = '<div class="player-card player-card--loading">Loading…</div>';

      detailTr.appendChild(detailTd);
      tr.after(detailTr);
      tr.classList.add('is-expanded');

      fetch('/api/stats_player_card.php?id=' + encodeURIComponent(pid), {
        credentials: 'same-origin'
      })
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.text();
        })
        .then(function (html) {
          detailTd.innerHTML = html;
        })
        .catch(function (err) {
          console.error('[stats-player-cards] failed:', err);
          detailTd.innerHTML =
            '<div class="player-card player-card--error">Could not load player details.</div>';
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
