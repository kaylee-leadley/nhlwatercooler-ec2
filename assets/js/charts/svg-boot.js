// assets/js/charts/svg-boot.js

(function () {
  function boot(scope) {
    scope = scope || document;

    if (typeof window.SJMS_IMPACT_BOOT === 'function') window.SJMS_IMPACT_BOOT(scope);
    if (typeof window.SJMS_XGQ_BOOT === 'function')    window.SJMS_XGQ_BOOT(scope);
    if (typeof window.SJMS_GAR_BOOT === 'function')    window.SJMS_GAR_BOOT(scope);
    if (typeof window.SJMS_WAR_BOOT === 'function')    window.SJMS_WAR_BOOT(scope);
  }

  window.SJMS_ADV_CHARTS_BOOT = boot;
})();
