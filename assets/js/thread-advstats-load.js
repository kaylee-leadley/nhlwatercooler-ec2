(function () {
  function boot() {
    var shell = document.getElementById('thread-adv-stats');
    if (!shell) return;

    var gid = parseInt(shell.getAttribute('data-game-id') || '0', 10);
    if (!gid) return;

    // prevent double-start if boot called twice
    if (shell.getAttribute('data-advstats-started') === '1') return;
    shell.setAttribute('data-advstats-started', '1');

    var inflight = false;
    var done = false;
    var tries = 0;

    var MAX_TRIES = 10;        // ~ 2-3 minutes total
    var BASE_DELAY = 2500;     // 2.5s
    var MAX_DELAY = 30000;     // 30s
    var JITTER = 0.25;         // +/- 25%

    function hasRealAdvancedStats(scope) {
      if (!scope) return false;

      // Something “real” exists:
      // - any team block
      // - any chart not marked empty
      // - quadrant with points
      var anyTeam  = scope.querySelector('.adv-team');
      var anyChart = scope.querySelector('.adv-chart:not([data-adv-empty="1"])');

      var quad = scope.querySelector('.adv-block--quadrant .js-xgq-svg');
      var hasQuad = false;
      if (quad) {
        var pts = quad.getAttribute('data-points') || '';
        hasQuad = !!(pts && pts !== '[]' && pts !== '""');
      }

      return !!(anyTeam || anyChart || hasQuad);
    }

    function nextDelayMs() {
      // 2.5s, 5s, 10s, 20s, 30s...
      var d = BASE_DELAY * Math.pow(2, Math.max(0, tries - 1));
      d = Math.min(d, MAX_DELAY);

      var j = d * JITTER;
      d = d + (Math.random() * (2 * j) - j);

      return Math.max(500, Math.round(d));
    }

    function callBooters() {
      // 🔍 Safe wrappers to expose real errors
      try {
        if (typeof window.SJMS_XGQ_BOOT === 'function')
          window.SJMS_XGQ_BOOT(shell);
        else
          console.warn('[AdvStats] SJMS_XGQ_BOOT missing or not a function:', window.SJMS_XGQ_BOOT);
      } catch (e) {
        console.error('[AdvStats] SJMS_XGQ_BOOT crashed:', e);
      }

      try {
        if (typeof window.SJMS_ADV_CHARTS_BOOT === 'function')
          window.SJMS_ADV_CHARTS_BOOT(shell);
        else
          console.warn('[AdvStats] SJMS_ADV_CHARTS_BOOT missing or not a function:', window.SJMS_ADV_CHARTS_BOOT);
      } catch (e) {
        console.error('[AdvStats] SJMS_ADV_CHARTS_BOOT crashed:', e);
      }
    }

    function buildUrl(isRetry) {
      var u = '/api/thread_adv_stats_block.php?game_id=' + encodeURIComponent(gid);

      // only bust cache when retrying
      if (isRetry) u += '&_r=' + Date.now();

      return u;
    }

    function finishOk() {
      done = true;
      shell.setAttribute('data-advstats-loaded', '1');
      shell.removeAttribute('data-advstats-loading');
      shell.removeAttribute('data-advstats-gaveup');
    }

    function giveUp() {
      done = true;
      shell.removeAttribute('data-advstats-loading');
      shell.setAttribute('data-advstats-gaveup', '1');
      // don’t overwrite whatever HTML is there; just stop retrying
      console.warn('[AdvStats] gave up after', tries, 'tries');
    }

    function attemptFetch(isRetry) {
      if (done || inflight) return;
      inflight = true;

      shell.setAttribute('data-advstats-loading', '1');

      fetch(buildUrl(!!isRetry), {
        credentials: 'same-origin',
        // For best odds vs server/proxy caching on retries:
        cache: isRetry ? 'no-store' : 'no-cache'
      })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          inflight = false;
          if (done) return;

          shell.innerHTML = html || '';
          callBooters();

          // Success condition: charts/teams exist after boot
          if (hasRealAdvancedStats(shell)) {
            finishOk();
            return;
          }

          // Not ready yet -> retry
          tries++;
          if (tries >= MAX_TRIES) {
            giveUp();
            return;
          }

          setTimeout(function () {
            attemptFetch(true);
          }, nextDelayMs());
        })
        .catch(function (err) {
          inflight = false;
          if (done) return;

          console.error('[AdvStats] fetch failed:', err);

          tries++;
          if (tries >= MAX_TRIES) {
            // Only show the "unavailable" message if we have nothing at all in the shell
            if (!shell.innerHTML) {
              shell.innerHTML = '<div class="adv-loading">Advanced stats unavailable.</div>';
            }
            giveUp();
            return;
          }

          setTimeout(function () {
            attemptFetch(true);
          }, nextDelayMs());
        });
    }

    function start() {
      // If already present (SSR or prior load), don’t fetch.
      if (hasRealAdvancedStats(shell)) {
        finishOk();
        return;
      }
      attemptFetch(false);
    }

    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          if (e.isIntersecting) {
            io.disconnect();
            start();
          }
        });
      }, { rootMargin: '300px 0px' });
      io.observe(shell);
    } else {
      start(); // fallback
    }
  }

  if (document.readyState === 'loading')
    document.addEventListener('DOMContentLoaded', boot);
  else
    boot();
})();
