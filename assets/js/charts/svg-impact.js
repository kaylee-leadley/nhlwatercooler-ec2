/* assets/js/advsvg-impact.js
 *
 * Impact chart (HTML/CSS bars, not SVG).
 *
 * UPDATED:
 * - If PHP has already computed OIS (ois_score / ois_c* / ois_z* / ois_weight),
 *   this file becomes a pure renderer (no recompute) so JS cannot drift.
 * - If those fields are missing, it falls back to the legacy JS computation.
 */

(function () {
  console.log("Load Impact (HTML bars) — process metrics");

  function num(v, d) { v = parseFloat(v); return isFinite(v) ? v : d; }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

  function esc(s) {
    s = (s == null) ? '' : String(s);
    return s.replace(/[&<>"']/g, function (c) {
      return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]);
    });
  }

  /* ------------------------------------------------------------
   * Tooltip (shared)
   * ---------------------------------------------------------- */
  function ensureTip() {
    var tip = document.getElementById('adv-tooltip');
    if (tip) return tip;

    tip = document.createElement('div');
    tip.id = 'adv-tooltip';
    tip.className = 'adv-tooltip';
    tip.style.display = 'none';
    document.body.appendChild(tip);

    var cssId = 'adv-tooltip-css';
    if (!document.getElementById(cssId)) {
      var st = document.createElement('style');
      st.id = cssId;
      st.textContent =
        '.adv-tooltip{position:fixed;z-index:999999;max-width:420px;padding:10px 12px;' +
        'border:1px solid rgba(255,255,255,.18);border-radius:10px;' +
        'background:rgba(10,18,28,.92);backdrop-filter:blur(6px);' +
        'color:rgba(229,231,235,.95);font:650 12px/1.25 system-ui,-apple-system,Segoe UI,Roboto,Arial;' +
        'box-shadow:0 10px 30px rgba(0,0,0,.35);pointer-events:none}' +
        '.adv-tooltip__name{font-size:13px;font-weight:900;margin:0 0 6px}' +
        '.adv-tooltip__row{display:flex;justify-content:space-between;gap:12px;margin:3px 0}' +
        '.adv-tooltip__k{opacity:.85;font-weight:800}' +
        '.adv-tooltip__v{font-weight:900;white-space:nowrap}' +
        '.adv-tooltip__muted{opacity:.75;font-weight:750;margin-top:6px}';
      document.head.appendChild(st);
    }
    return tip;
  }

  function showTip(tip, html, clientX, clientY) {
    tip.innerHTML = html;
    tip.style.display = 'block';

    var vx = clientX + 14;
    var vy = clientY + 14;

    var r = tip.getBoundingClientRect();
    var maxX = window.innerWidth - r.width - 10;
    var maxY = window.innerHeight - r.height - 10;

    tip.style.left = clamp(vx, 10, maxX) + 'px';
    tip.style.top  = clamp(vy, 10, maxY) + 'px';
  }

  function hideTip(tip) { tip.style.display = 'none'; }

  /* ------------------------------------------------------------
   * Inject baseline CSS (once)
   * ---------------------------------------------------------- */
  function ensureBaseCSS() {
    var cssId = 'adv-impact-css';
    if (document.getElementById(cssId)) return;

    var st = document.createElement('style');
    st.id = cssId;
    st.textContent =
      '.adv-impact{position:relative;' +
      '--impact-axis-h:46px;--impact-row-gap:18px;--impact-overall-h:44px;--impact-split-h:34px;' +
      '--impact-gap:8px;--impact-name-size:18px;--impact-tick-size:12px;--impact-name-pad:10px;' +
      '}' +

      /* chart + axis */
      '.adv-impact__chart{position:relative;overflow:visible !important;}' +
      '.adv-impact__grid{position:absolute;left:0;top:0;right:0;bottom:0;pointer-events:none;z-index:0}' +
      '.adv-impact__gridline{position:absolute;top:0;bottom:0;width:1px;background:rgba(148,163,184,.18)}' +
      '.adv-impact__zeroline{position:absolute;top:0;bottom:0;width:2px;background:rgba(229,231,235,.45)}' +
      '.adv-impact__axis{position:relative;height:var(--impact-axis-h);z-index:5}' +
      '.adv-impact__ticklbl{position:absolute;bottom:6px;transform:translateX(-50%);' +
      'color:rgba(229,231,235,.72);font:800 var(--impact-tick-size)/1 system-ui,-apple-system,Segoe UI,Roboto,Arial;' +
      'white-space:nowrap}' +

      /* rows */
      '.adv-impact__rows{position:relative;display:flex;flex-direction:column;gap:var(--impact-row-gap);overflow:visible !important;z-index:5}' +
      '.adv-impact__row{position:relative;display:flex;flex-direction:column;gap:var(--impact-gap);overflow:visible !important;}' +

      /* tracks */
      '.adv-impact__track{position:relative;width:100%;border-radius:6px;' +
      'background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);' +
      'overflow:visible !important;}' +

      /* split bars can still clip */
      '.adv-impact__split{height:var(--impact-split-h);overflow:hidden !important;}' +

      /* overall track should NEVER clip labels */
      '.adv-impact__overall{height:var(--impact-overall-h);overflow:visible !important;isolation:isolate;}' +

      /* clip box for rounding ONLY (bars go inside this) */
      '.adv-impact__clip{position:absolute;left:0;top:0;right:0;bottom:0;' +
      'overflow:hidden;border-radius:6px;z-index:10;pointer-events:none;}' +

      /* overall bars live inside clip */
      '.adv-impact__bar{position:absolute;top:0;bottom:0;border-radius:6px;' +
      'border:1px solid rgba(255,255,255,.14);box-sizing:border-box;z-index:20}' +
      '.adv-impact__bar--pos{background:rgba(34,197,94,.55)}' +
      '.adv-impact__bar--neg{background:rgba(239,68,68,.55)}' +

      /* NAME is an overlay ABOVE bars (and not clipped) */
      '.adv-impact__name{position:absolute;top:50%;transform:translateY(-50%);' +
      'z-index:9999 !important;' +
      'color:rgba(229,231,235,.95);font:900 var(--impact-name-size)/1 system-ui,-apple-system,Segoe UI,Roboto,Arial;' +
      'letter-spacing:.01em;white-space:nowrap;pointer-events:none;' +
      'text-shadow:0 2px 10px rgba(0,0,0,.60);' +
      'max-width:calc(50% - (var(--impact-name-pad) + 10px));' +
      'overflow:hidden;text-overflow:ellipsis;}' +

      /* hug the spine: pos extends LEFT, neg extends RIGHT */
      '.adv-impact__name--pos{left:50%;transform:translate(-100%,-50%);' +
      'margin-left:calc(-1 * var(--impact-name-pad));text-align:right;}' +
      '.adv-impact__name--neg{left:50%;transform:translate(0,-50%);' +
      'margin-left:var(--impact-name-pad);text-align:left;}' +

      /* split segment containers */
      '.adv-impact__segs{position:absolute;top:0;bottom:0;width:100%;display:flex;gap:0}' +
      '.adv-impact__segs--pos{left:50%;justify-content:flex-start}' +
      '.adv-impact__segs--neg{right:50%;flex-direction:row-reverse;justify-content:flex-start}' +
      '.adv-impact__seg{height:100%}' +

      /* segment colors */
      '.adv-impact__seg--xg-pos{background:rgba(59,130,246,.85)}' +
      '.adv-impact__seg--sc-pos{background:rgba(34,211,238,.78)}' +
      '.adv-impact__seg--cf-pos{background:rgba(250,204,21,.82)}' +
      '.adv-impact__seg--g-pos{background:rgba(249,115,22,.75)}' +
      '.adv-impact__seg--pen-pos{background:rgba(168,85,247,.70)}' +

      '.adv-impact__seg--xg-neg{background:rgba(59,130,246,.38)}' +
      '.adv-impact__seg--sc-neg{background:rgba(34,211,238,.34)}' +
      '.adv-impact__seg--cf-neg{background:rgba(250,204,21,.40)}' +
      '.adv-impact__seg--g-neg{background:rgba(249,115,22,.33)}' +
      '.adv-impact__seg--pen-neg{background:rgba(168,85,247,.34)}' +

      /* hit layer UNDER the name */
      '.adv-impact__hit{position:absolute;left:0;top:0;right:0;bottom:0;cursor:pointer;z-index:200}' +

      /* legend swatches fallback */
      '.adv-leg__swatch--overall-pos{background:rgba(34,197,94,.55)}' +
      '.adv-leg__swatch--overall-neg{background:rgba(239,68,68,.55)}' +
      '.adv-leg__swatch--xg{background:rgba(59,130,246,.85)}' +
      '.adv-leg__swatch--sc{background:rgba(34,211,238,.78)}' +
      '.adv-leg__swatch--cf{background:rgba(250,204,21,.82)}' +
      '.adv-leg__swatch--g{background:rgba(249,115,22,.75)}' +
      '.adv-leg__swatch--pen{background:rgba(168,85,247,.70)}';

    document.head.appendChild(st);
  }

  /* ------------------------------------------------------------
   * Data + helpers
   * ---------------------------------------------------------- */
  function parseRows(el) {
    var raw = el.getAttribute('data-rows') || '[]';
    try {
      var rows = JSON.parse(raw);
      return Array.isArray(rows) ? rows : [];
    } catch (e) { return []; }
  }

  function isMobile() {
    return window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
  }

  function lastNameOnly(full) {
    full = (full || '').trim();
    if (!full) return '';
    full = full.replace(/\s+#?\d+$/, '').trim();

    var parts = full.split(/\s+/);
    if (parts.length === 1) return parts[0];

    var suffix = /^(jr\.?|sr\.?|ii|iii|iv|v)$/i;
    if (parts.length > 2 && suffix.test(parts[parts.length - 1])) parts.pop();

    var particles = {
      'da':1,'de':1,'del':1,'della':1,'der':1,'di':1,'du':1,'la':1,'le':1,'lo':1,
      'van':1,'von':1,'st.':1,'st':1,'ter':1,'ten':1,'den':1
    };

    var last = parts[parts.length - 1];
    var prev = parts[parts.length - 2].toLowerCase();
    if (particles[prev]) return parts[parts.length - 2] + ' ' + last;
    return last;
  }

  function playerLabel(name) {
    return isMobile() ? lastNameOnly(name) : (name || '');
  }

  function fmtToi(sec) {
    sec = parseInt(sec, 10);
    if (!isFinite(sec) || sec <= 0) return '';
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ':' + (s < 10 ? '0' + s : s);
  }

  function meanStd(vals) {
    var n = vals.length;
    if (!n) return { mean: 0, std: 1 };
    var sum = 0, i;
    for (i = 0; i < n; i++) sum += vals[i];
    var mu = sum / n;
    var v = 0;
    for (i = 0; i < n; i++) v += (vals[i] - mu) * (vals[i] - mu);
    v = v / Math.max(1, (n - 1));
    var sd = Math.sqrt(v);
    if (!isFinite(sd) || sd < 1e-9) sd = 1;
    return { mean: mu, std: sd };
  }

  function percentile(sorted, p) {
    var n = sorted.length;
    if (!n) return null;
    if (p <= 0) return sorted[0];
    if (p >= 1) return sorted[n - 1];
    var pos = (n - 1) * p;
    var lo = Math.floor(pos), hi = Math.ceil(pos);
    if (lo === hi) return sorted[lo];
    var t = pos - lo;
    return sorted[lo] + (sorted[hi] - sorted[lo]) * t;
  }

  function dedupeByPlayerId(rows) {
    var map = {};
    var out = [];
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i] || {};
      var pid = (r.player_id == null) ? null : String(r.player_id);
      if (!pid) { out.push(r); continue; }

      var toi = parseInt(r.toi_5v5 || 0, 10);
      if (!isFinite(toi)) toi = 0;

      if (!map[pid]) map[pid] = { r: r, toi: toi };
      else if (toi > map[pid].toi) map[pid] = { r: r, toi: toi };
    }
    for (var k in map) if (Object.prototype.hasOwnProperty.call(map, k)) out.push(map[k].r);
    return out;
  }

  function buildFigure(el, kind, title, legendHtml) {
    var html = '';
    html += '<div class="adv-fig adv-fig--' + esc(kind) + '">';
    html +=   '<div class="adv-fig__title">' + esc(title) + '</div>';
    if (legendHtml) html += '<div class="adv-fig__legend">' + legendHtml + '</div>';
    html +=   '<div class="adv-fig__plot"></div>';
    html += '</div>';
    el.innerHTML = html;
    return el.querySelector('.adv-fig__plot');
  }

  function legItem(cls, label) {
    return (
      '<span class="adv-leg">' +
        '<span class="adv-leg__swatch ' + cls + '"></span>' +
        '<span class="adv-leg__label">' + esc(label) + '</span>' +
      '</span>'
    );
  }

  /* ------------------------------------------------------------
   * Empty-prune: prevent blank chart “cards”
   * ---------------------------------------------------------- */
  function impactCardFor(el) {
    if (!el) return null;
    return (el.closest ? el.closest('.adv-chart') : null) || el;
  }

  function pruneEmptyImpact(el) {
    if (!el) return;
    var card = impactCardFor(el);
    card.innerHTML = '';
    card.style.display = 'none';
    card.setAttribute('data-adv-empty', '1');
  }

  function unpruneImpact(el) {
    if (!el) return;
    var card = impactCardFor(el);
    card.style.display = '';
    card.removeAttribute('data-adv-empty');
  }

  function cleanupAdvStats(scope) {
    scope = scope || document;

    var teams = scope.querySelectorAll('.adv-team');
    for (var i = teams.length - 1; i >= 0; i--) {
      var team = teams[i];
      if (!team) continue;
      if (!team.querySelector('.adv-fig')) {
        if (team.parentNode) team.parentNode.removeChild(team);
      }
    }

    var secs = scope.querySelectorAll('.thread-adv-stats');
    for (i = secs.length - 1; i >= 0; i--) {
      var sec = secs[i];
      if (!sec) continue;

      var remainingTeams = sec.querySelectorAll('.adv-team');

      var quad = sec.querySelector('.adv-block--quadrant .js-xgq-svg');
      var hasQuad = false;
      if (quad) {
        var pts = quad.getAttribute('data-points') || '';
        hasQuad = (pts && pts !== '[]' && pts !== '""');
      }

      if ((!remainingTeams || remainingTeams.length === 0) && !hasQuad) {
        if (sec.parentNode) sec.parentNode.removeChild(sec);
      }
    }
  }

  /* ------------------------------------------------------------
   * Scale helpers
   * ---------------------------------------------------------- */
  function pctHalf(v, maxAbs) {
    if (!isFinite(v) || !isFinite(maxAbs) || maxAbs <= 0) return 0;
    return clamp(50 * (Math.abs(v) / maxAbs), 0, 50);
  }

  function leftForNeg(v, maxAbs) {
    var w = pctHalf(v, maxAbs);
    return (50 - w);
  }

  /* ------------------------------------------------------------
   * Right-click export: render FIGURE (title+legend+plot) to PNG
   * ---------------------------------------------------------- */
  function openFigureAsImageInNewTab(figEl, titleText) {
    if (!window.html2canvas) {
      alert('html2canvas not loaded (needed to export image).');
      return;
    }
    if (!figEl) return;

    var tip = document.getElementById('adv-tooltip');
    var tipWas = tip && tip.style.display === 'block';
    if (tip) tip.style.display = 'none';

    var bg = '#0b1220';

    var rect = figEl.getBoundingClientRect();
    var pxW = Math.max(360, Math.round(rect.width));

    var wrap = document.createElement('div');
    wrap.style.position = 'fixed';
    wrap.style.left = '0';
    wrap.style.top = '0';
    wrap.style.zIndex = '-1';
    wrap.style.pointerEvents = 'none';
    wrap.style.background = bg;
    wrap.style.overflow = 'visible';

    var basePad = 16;
    wrap.style.padding = basePad + 'px';
    wrap.style.width = (pxW + basePad * 2) + 'px';

    var clone = figEl.cloneNode(true);
    clone.style.width = pxW + 'px';
    clone.style.maxWidth = 'none';
    clone.style.overflow = 'visible';

    var names = clone.querySelectorAll('.adv-impact__name');
    for (var i = 0; i < names.length; i++) {
      names[i].style.maxWidth = 'none';
      names[i].style.overflow = 'visible';
      names[i].style.textOverflow = 'clip';
      names[i].style.whiteSpace = 'nowrap';
    }

    var unclippers = clone.querySelectorAll(
      '.adv-impact, .adv-impact__chart, .adv-impact__rows, .adv-impact__row, .adv-impact__overall'
    );
    for (i = 0; i < unclippers.length; i++) {
      unclippers[i].style.overflow = 'visible';
    }

    wrap.appendChild(clone);
    document.body.appendChild(wrap);

    function computeOverflowPadding() {
      var baseRect = clone.getBoundingClientRect();
      var extraL = 0;
      var extraR = 0;

      var watch = clone.querySelectorAll('.adv-impact__name');
      for (var j = 0; j < watch.length; j++) {
        var r = watch[j].getBoundingClientRect();
        extraL = Math.max(extraL, baseRect.left - r.left);
        extraR = Math.max(extraR, r.right - baseRect.right);
      }

      var safety = 14;

      return {
        padL: Math.ceil(basePad + extraL + safety),
        padR: Math.ceil(basePad + extraR + safety),
        padT: basePad,
        padB: basePad
      };
    }

    var pads = computeOverflowPadding();
    wrap.style.padding = pads.padT + 'px ' + pads.padR + 'px ' + pads.padB + 'px ' + pads.padL + 'px';
    wrap.style.width = (pxW + pads.padL + pads.padR) + 'px';

    wrap.getBoundingClientRect();

    var wrect = wrap.getBoundingClientRect();
    var capW = Math.ceil(wrect.width);
    var capH = Math.ceil(wrect.height);

    window.html2canvas(wrap, {
      backgroundColor: bg,
      scale: Math.min(2, (window.devicePixelRatio || 1)),
      useCORS: true,
      scrollX: 0,
      scrollY: 0,
      windowWidth: capW,
      windowHeight: capH
    }).then(function (canvas) {
      canvas.toBlob(function (blob) {
        if (!blob) return;

        var url = URL.createObjectURL(blob);

        var w = window.open('', '_blank');
        if (!w) {
          window.location.href = url;
          return;
        }

        var safe = String(titleText || 'impact')
          .trim()
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/(^-|-$)/g, '') || 'impact';

        w.document.open();
        w.document.write(
          '<!doctype html><html><head><meta charset="utf-8">' +
          '<title>' + safe + '</title>' +
          '<style>html,body{margin:0;background:' + bg + ';}img{display:block;max-width:100%;height:auto;margin:0 auto;}</style>' +
          '</head><body>' +
          '<img src="' + url + '" alt="' + safe + '">' +
          '<script>window.addEventListener("beforeunload",function(){try{URL.revokeObjectURL("' + url + '")}catch(e){}});<\/script>' +
          '</body></html>'
        );
        w.document.close();
      }, 'image/png', 1.0);
    }).catch(function (err) {
      console.error('Impact export failed', err);
      alert('Could not render figure to image.');
    }).finally(function () {
      if (wrap && wrap.parentNode) wrap.parentNode.removeChild(wrap);
      if (tip && tipWas) tip.style.display = 'block';
    });
  }

  /* ------------------------------------------------------------
   * Render
   * ---------------------------------------------------------- */
  function renderImpact(el) {
    ensureBaseCSS();
    unpruneImpact(el);

    var rows = dedupeByPlayerId(parseRows(el));
    if (!rows.length) { pruneEmptyImpact(el); return; }

    var minToi = parseInt(el.getAttribute('data-min-toi') || '0', 10);
    if (!isFinite(minToi)) minToi = 0;

    var toiRef = parseInt(el.getAttribute('data-toi-ref') || '600', 10);
    if (!isFinite(toiRef) || toiRef <= 0) toiRef = 600;

    var W_XG  = num(el.getAttribute('data-w-xg'),  0.45);
    var W_SC  = num(el.getAttribute('data-w-sc'),  0.25);
    var W_CF  = num(el.getAttribute('data-w-cf'),  0.20);
    var W_G   = num(el.getAttribute('data-w-g'),   0.05);
    var W_PEN = num(el.getAttribute('data-w-pen'), 0.05);

    var kept = [];
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i] || {};

      var toi = parseInt(r.toi_5v5 || 0, 10);
      if (!isFinite(toi)) toi = 0;
      if (minToi && toi < minToi) continue;

      // We still parse these for tooltips / fallback compute
      var xgd = num(r.xgdiff60_5v5, NaN);
      if (!isFinite(xgd)) {
        var xgf = num(r.xgf60_5v5, NaN);
        var xga = num(r.xga60_5v5, NaN);
        if (isFinite(xgf) && isFinite(xga)) xgd = xgf - xga;
      }

      var scd = num(r.scdiff60_5v5, NaN);
      if (!isFinite(scd)) {
        var scf = num(r.scf60_5v5, NaN);
        var sca = num(r.sca60_5v5, NaN);
        if (isFinite(scf) && isFinite(sca)) scd = scf - sca;
      }

      var cfd = num(r.cfdiff60_5v5, NaN);
      if (!isFinite(cfd)) {
        var cf = num(r.cf60_5v5, NaN);
        var ca = num(r.ca60_5v5, NaN);
        if (isFinite(cf) && isFinite(ca)) cfd = cf - ca;
        else cfd = cf;
      }

      var gd = num(r.gdiff60_5v5, NaN);
      if (!isFinite(gd)) {
        var gf60 = num(r.gf60_5v5, NaN);
        var ga60 = num(r.ga60_5v5, NaN);
        if (isFinite(gf60) && isFinite(ga60)) gd = gf60 - ga60;
      }

      var pd = num(r.pendiff60_5v5, NaN);
      if (!isFinite(pd)) {
        var pDiff = num(r.pen_diff_5v5, NaN);
        if (isFinite(pDiff) && toi > 0) pd = pDiff * 3600.0 / toi;

        if (!isFinite(pd)) {
          var pDrawn = num(r.pen_drawn_5v5, NaN);
          var pTaken = num(r.pen_taken_5v5, NaN);
          if (isFinite(pDrawn) && isFinite(pTaken) && toi > 0) {
            pd = (pDrawn - pTaken) * 3600.0 / toi;
          }
        }
      }

      var okOther = isFinite(scd) || isFinite(cfd) || isFinite(gd) || isFinite(pd);
      if (!isFinite(xgd) || !okOther) continue;

      kept.push({ raw: r, toi: toi, xgd: xgd, scd: scd, cfd: cfd, gd: gd, pd: pd });
    }

    if (!kept.length) { pruneEmptyImpact(el); return; }

    /* ------------------------------------------------------------
     * Prefer PHP-computed OIS (single source of truth)
     * ---------------------------------------------------------- */
    var usingPhp = true;
    for (i = 0; i < kept.length; i++) {
      var rr = kept[i].raw || {};
      if (!isFinite(num(rr.ois_score, NaN))) { usingPhp = false; break; }
    }

    if (usingPhp) {
      for (i = 0; i < kept.length; i++) {
        rr = kept[i].raw || {};

        kept[i].score  = num(rr.ois_score, 0);
        kept[i].weight = num(rr.ois_weight, 1);
        kept[i].sumW   = num(rr.ois_sumW, 1);

        kept[i].cx = num(rr.ois_cx, 0);
        kept[i].cs = num(rr.ois_cs, 0);
        kept[i].cc = num(rr.ois_cc, 0);
        kept[i].cg = num(rr.ois_cg, 0);
        kept[i].cp = num(rr.ois_cp, 0);

        kept[i].posSum = num(rr.ois_possum, 0);
        kept[i].negSum = num(rr.ois_negsum, 0);

        // Use the exact raw component values that PHP used, if provided
        kept[i].xgd = isFinite(num(rr.ois_xgd, NaN)) ? num(rr.ois_xgd, kept[i].xgd) : kept[i].xgd;
        kept[i].scd = isFinite(num(rr.ois_scd, NaN)) ? num(rr.ois_scd, kept[i].scd) : kept[i].scd;
        kept[i].cfd = isFinite(num(rr.ois_cfd, NaN)) ? num(rr.ois_cfd, kept[i].cfd) : kept[i].cfd;
        kept[i].gd  = isFinite(num(rr.ois_gd,  NaN)) ? num(rr.ois_gd,  kept[i].gd)  : kept[i].gd;
        kept[i].pd  = isFinite(num(rr.ois_pd,  NaN)) ? num(rr.ois_pd,  kept[i].pd)  : kept[i].pd;

        kept[i].zx = num(rr.ois_zx, NaN);
        kept[i].zs = num(rr.ois_zs, NaN);
        kept[i].zc = num(rr.ois_zc, NaN);
        kept[i].zg = num(rr.ois_zg, NaN);
        kept[i].zp = num(rr.ois_zp, NaN);
      }
    } else {
      /* ------------------------------------------------------------
       * Legacy JS compute (fallback only)
       * ---------------------------------------------------------- */
      function collectFinite(key) {
        var a = [];
        for (var j = 0; j < kept.length; j++) {
          var v = kept[j][key];
          if (isFinite(v)) a.push(v);
        }
        return a;
      }

      var bx = meanStd(collectFinite('xgd'));
      var bs = meanStd(collectFinite('scd'));
      var bc = meanStd(collectFinite('cfd'));
      var bg = meanStd(collectFinite('gd'));
      var bp = meanStd(collectFinite('pd'));

      function z(v, b) { return (v - b.mean) / b.std; }

      for (i = 0; i < kept.length; i++) {
        var k = kept[i];

        var zx = isFinite(k.xgd) ? z(k.xgd, bx) : NaN;
        var zs = isFinite(k.scd) ? z(k.scd, bs) : NaN;
        var zc = isFinite(k.cfd) ? z(k.cfd, bc) : NaN;
        var zg = isFinite(k.gd)  ? z(k.gd,  bg) : NaN;
        var zp = isFinite(k.pd)  ? z(k.pd,  bp) : NaN;

        var w = Math.sqrt(Math.max(0, k.toi) / toiRef);
        w = clamp(w, 0.55, 1.20);

        var sumW = 0;
        var hasX = isFinite(zx);
        var hasS = isFinite(zs);
        var hasC = isFinite(zc);
        var hasG = isFinite(zg);
        var hasP = isFinite(zp);

        if (hasX) sumW += W_XG;
        if (hasS) sumW += W_SC;
        if (hasC) sumW += W_CF;
        if (hasG) sumW += W_G;
        if (hasP) sumW += W_PEN;
        if (sumW <= 0) sumW = 1;

        function contrib(zv, baseW, ok) {
          if (!ok) return 0;
          var ww = baseW / sumW;
          return (ww * zv) * w;
        }

        var cx  = contrib(zx, W_XG,  hasX);
        var cs  = contrib(zs, W_SC,  hasS);
        var cc  = contrib(zc, W_CF,  hasC);
        var cg  = contrib(zg, W_G,   hasG);
        var cp  = contrib(zp, W_PEN, hasP);

        var score = cx + cs + cc + cg + cp;

        var pos = 0, neg = 0;
        if (cx > 0) pos += cx; else neg += cx;
        if (cs > 0) pos += cs; else neg += cs;
        if (cc > 0) pos += cc; else neg += cc;
        if (cg > 0) pos += cg; else neg += cg;
        if (cp > 0) pos += cp; else neg += cp;

        k.zx = zx; k.zs = zs; k.zc = zc; k.zg = zg; k.zp = zp;
        k.weight = w;
        k.cx = cx; k.cs = cs; k.cc = cc; k.cg = cg; k.cp = cp;
        k.score = score;
        k.posSum = pos;
        k.negSum = neg;
        k.sumW = sumW;
      }
    }

    // ---- FLAT/EMPTY GUARD ----
    var EPS = 1e-6;
    var minScore = Infinity, maxScore = -Infinity, maxAbsRaw = 0, anyFinite = false;
    for (i = 0; i < kept.length; i++) {
      var scv = kept[i].score;
      if (!isFinite(scv)) continue;
      anyFinite = true;
      if (scv < minScore) minScore = scv;
      if (scv > maxScore) maxScore = scv;
      var av = Math.abs(scv);
      if (av > maxAbsRaw) maxAbsRaw = av;
    }

    if (!anyFinite || maxAbsRaw < EPS || Math.abs(maxScore - minScore) < EPS) {
      pruneEmptyImpact(el);
      return;
    }

    kept.sort(function (a, b) { return b.score - a.score; });

    var abs = [];
    for (i = 0; i < kept.length; i++) abs.push(Math.abs(kept[i].score));
    abs.sort(function (a, b) { return a - b; });

    var p95 = percentile(abs, 0.95);
    var maxAbs = (isFinite(p95) && p95 > 0) ? p95 : (abs[abs.length - 1] || 1);
    maxAbs *= 1.25;

    if (!isFinite(maxAbs) || maxAbs <= 0 || maxAbs < EPS) {
      pruneEmptyImpact(el);
      return;
    }

    var title = el.getAttribute('data-title') || 'Overall Impact Score (5v5)';

    // Legend: include PEN if any cp exists (or raw pd exists)
    var hasPen = false;
    for (i = 0; i < kept.length; i++) {
      if (Math.abs(kept[i].cp || 0) > 1e-9 || isFinite(kept[i].pd)) { hasPen = true; break; }
    }

    var legendHtml =
      legItem('adv-leg__swatch--overall-pos', 'Overall: Positive (right)') +
      legItem('adv-leg__swatch--overall-neg', 'Overall: Negative (left)') +
      legItem('adv-leg__swatch--xg',  'xG Diff/60') +
      legItem('adv-leg__swatch--sc',  'SC Diff/60') +
      legItem('adv-leg__swatch--cf',  'CF Diff/60') +
      legItem('adv-leg__swatch--g',   'Goal Diff/60') +
      (hasPen ? legItem('adv-leg__swatch--pen', 'Pen Diff/60') : '');

    var plotHost = buildFigure(el, 'impact', title, legendHtml);

    var chart = document.createElement('div');
    chart.className = 'adv-impact__chart adv-impact';
    plotHost.innerHTML = '';
    plotHost.appendChild(chart);

    chart.addEventListener('contextmenu', function (e) {
      if (e.shiftKey) return;
      e.preventDefault();
      e.stopPropagation();

      var fig = el.querySelector('.adv-fig');
      var t = el.getAttribute('data-title') || 'impact';
      openFigureAsImageInNewTab(fig || chart, t);
    });

    var grid = document.createElement('div');
    grid.className = 'adv-impact__grid';
    chart.appendChild(grid);

    var axis = document.createElement('div');
    axis.className = 'adv-impact__axis';
    chart.appendChild(axis);

    var rowsWrap = document.createElement('div');
    rowsWrap.className = 'adv-impact__rows';
    chart.appendChild(rowsWrap);

    var tickCount = parseInt(el.getAttribute('data-ticks') || '6', 10);
    if (!isFinite(tickCount) || tickCount < 2) tickCount = 6;

    for (i = 0; i <= tickCount; i++) {
      var tv = (-maxAbs) + (i / tickCount) * (2 * maxAbs);
      var left = ((tv + maxAbs) / (2 * maxAbs)) * 100;

      var gl = document.createElement('div');
      gl.className = 'adv-impact__gridline';
      gl.style.left = left + '%';
      grid.appendChild(gl);

      var lbl = document.createElement('div');
      lbl.className = 'adv-impact__ticklbl';
      lbl.style.left = left + '%';
      lbl.textContent = tv.toFixed(2);
      axis.appendChild(lbl);
    }

    var zl = document.createElement('div');
    zl.className = 'adv-impact__zeroline';
    zl.style.left = '50%';
    grid.appendChild(zl);

    function addSeg(parent, cls, val) {
      if (!isFinite(val) || val === 0) return;
      var wSeg = clamp(50 * (Math.abs(val) / maxAbs), 0, 50);
      if (wSeg <= 0) return;
      var seg = document.createElement('div');
      seg.className = 'adv-impact__seg ' + cls;
      seg.style.width = wSeg + '%';
      parent.appendChild(seg);
    }

    var frag = document.createDocumentFragment();

    for (i = 0; i < kept.length; i++) {
      var kk = kept[i];
      var rr2 = kk.raw || {};

      var nameFull = (rr2.name || ('Player ' + (rr2.player_id || i)));
      var nameLbl  = playerLabel(nameFull);

      var row = document.createElement('div');
      row.className = 'adv-impact__row';
      row.setAttribute('data-name', nameFull);
      row.setAttribute('data-pos', ((rr2.pos || '') + '').toUpperCase());
      row.setAttribute('data-score', (kk.score != null && isFinite(kk.score)) ? kk.score.toFixed(3) : '—');
      row.setAttribute('data-toi', String(kk.toi || 0));
      row.setAttribute('data-w', (kk.weight != null && isFinite(kk.weight)) ? kk.weight.toFixed(2) : '—');

      row.setAttribute('data-xgd', isFinite(kk.xgd) ? kk.xgd.toFixed(2) : '—');
      row.setAttribute('data-scd', isFinite(kk.scd) ? kk.scd.toFixed(2) : '—');
      row.setAttribute('data-cfd', isFinite(kk.cfd) ? kk.cfd.toFixed(2) : '—');
      row.setAttribute('data-gd',  isFinite(kk.gd)  ? kk.gd.toFixed(2)  : '—');
      row.setAttribute('data-pd',  isFinite(kk.pd)  ? kk.pd.toFixed(2)  : '—');

      row.setAttribute('data-zx', isFinite(kk.zx) ? kk.zx.toFixed(2) : '—');
      row.setAttribute('data-zs', isFinite(kk.zs) ? kk.zs.toFixed(2) : '—');
      row.setAttribute('data-zc', isFinite(kk.zc) ? kk.zc.toFixed(2) : '—');
      row.setAttribute('data-zg', isFinite(kk.zg) ? kk.zg.toFixed(2) : '—');
      row.setAttribute('data-zp', isFinite(kk.zp) ? kk.zp.toFixed(2) : '—');

      row.setAttribute('data-cx', (kk.cx != null && isFinite(kk.cx)) ? kk.cx.toFixed(3) : '0.000');
      row.setAttribute('data-cs', (kk.cs != null && isFinite(kk.cs)) ? kk.cs.toFixed(3) : '0.000');
      row.setAttribute('data-cc', (kk.cc != null && isFinite(kk.cc)) ? kk.cc.toFixed(3) : '0.000');
      row.setAttribute('data-cg', (kk.cg != null && isFinite(kk.cg)) ? kk.cg.toFixed(3) : '0.000');
      row.setAttribute('data-cp', (kk.cp != null && isFinite(kk.cp)) ? kk.cp.toFixed(3) : '0.000');

      row.setAttribute('data-possum', (kk.posSum != null && isFinite(kk.posSum)) ? kk.posSum.toFixed(3) : '0.000');
      row.setAttribute('data-negsum', (kk.negSum != null && isFinite(kk.negSum)) ? kk.negSum.toFixed(3) : '0.000');

      var overall = document.createElement('div');
      overall.className = 'adv-impact__track adv-impact__overall';

      var clip = document.createElement('div');
      clip.className = 'adv-impact__clip';
      overall.appendChild(clip);

      var s = kk.score;
      var wHalf = pctHalf(s, maxAbs);

      var bar = document.createElement('div');
      bar.className = 'adv-impact__bar ' + (s >= 0 ? 'adv-impact__bar--pos' : 'adv-impact__bar--neg');

      if (s >= 0) {
        bar.style.left = '50%';
        bar.style.width = wHalf + '%';
      } else {
        bar.style.left = leftForNeg(s, maxAbs) + '%';
        bar.style.width = wHalf + '%';
      }
      clip.appendChild(bar);

      var nm = document.createElement('div');
      nm.className = 'adv-impact__name ' + (s >= 0 ? 'adv-impact__name--pos' : 'adv-impact__name--neg');
      nm.textContent = nameLbl;
      overall.appendChild(nm);

      var hit = document.createElement('div');
      hit.className = 'adv-impact__hit';
      overall.appendChild(hit);

      var splitPos = document.createElement('div');
      splitPos.className = 'adv-impact__track adv-impact__split';

      var segsPos = document.createElement('div');
      segsPos.className = 'adv-impact__segs adv-impact__segs--pos';
      splitPos.appendChild(segsPos);

      var splitNeg = document.createElement('div');
      splitNeg.className = 'adv-impact__track adv-impact__split';

      var segsNeg = document.createElement('div');
      segsNeg.className = 'adv-impact__segs adv-impact__segs--neg';
      splitNeg.appendChild(segsNeg);

      if (kk.cx > 0) addSeg(segsPos, 'adv-impact__seg--xg-pos',  kk.cx);
      if (kk.cs > 0) addSeg(segsPos, 'adv-impact__seg--sc-pos',  kk.cs);
      if (kk.cc > 0) addSeg(segsPos, 'adv-impact__seg--cf-pos',  kk.cc);
      if (kk.cg > 0) addSeg(segsPos, 'adv-impact__seg--g-pos',   kk.cg);
      if (kk.cp > 0) addSeg(segsPos, 'adv-impact__seg--pen-pos', kk.cp);

      if (kk.cx < 0) addSeg(segsNeg, 'adv-impact__seg--xg-neg',  kk.cx);
      if (kk.cs < 0) addSeg(segsNeg, 'adv-impact__seg--sc-neg',  kk.cs);
      if (kk.cc < 0) addSeg(segsNeg, 'adv-impact__seg--cf-neg',  kk.cc);
      if (kk.cg < 0) addSeg(segsNeg, 'adv-impact__seg--g-neg',   kk.cg);
      if (kk.cp < 0) addSeg(segsNeg, 'adv-impact__seg--pen-neg', kk.cp);

      row.appendChild(overall);
      row.appendChild(splitPos);
      row.appendChild(splitNeg);

      frag.appendChild(row);
    }

    rowsWrap.appendChild(frag);

    var tip = ensureTip();
    var lastTap = null;

    function tooltipHtmlFromRow(rowEl) {
      var nm   = rowEl.getAttribute('data-name') || '';
      var pos  = rowEl.getAttribute('data-pos') || '';
      var sc   = rowEl.getAttribute('data-score') || '';
      var toi  = rowEl.getAttribute('data-toi') || '';
      var w    = rowEl.getAttribute('data-w') || '';

      var xgd  = rowEl.getAttribute('data-xgd') || '—';
      var scd  = rowEl.getAttribute('data-scd') || '—';
      var cfd  = rowEl.getAttribute('data-cfd') || '—';
      var gd   = rowEl.getAttribute('data-gd')  || '—';
      var pd   = rowEl.getAttribute('data-pd')  || '—';

      var zx   = rowEl.getAttribute('data-zx') || '—';
      var zs   = rowEl.getAttribute('data-zs') || '—';
      var zc   = rowEl.getAttribute('data-zc') || '—';
      var zg   = rowEl.getAttribute('data-zg') || '—';
      var zp   = rowEl.getAttribute('data-zp') || '—';

      var cx   = rowEl.getAttribute('data-cx') || '0';
      var cs2  = rowEl.getAttribute('data-cs') || '0';
      var cc   = rowEl.getAttribute('data-cc') || '0';
      var cg   = rowEl.getAttribute('data-cg') || '0';
      var cp   = rowEl.getAttribute('data-cp') || '0';

      var ps   = rowEl.getAttribute('data-possum') || '0';
      var ns   = rowEl.getAttribute('data-negsum') || '0';

      var html = '';
      html += '<div class="adv-tooltip__name">' + esc(nm) + (pos ? (' <span style="opacity:.7">(' + esc(pos) + ')</span>') : '') + '</div>';
      html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">Impact Score</span><span class="adv-tooltip__v">' + esc(sc || '—') + '</span></div>';
      html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">Positive sum</span><span class="adv-tooltip__v">' + esc(ps) + '</span></div>';
      html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">Negative sum</span><span class="adv-tooltip__v">' + esc(ns) + '</span></div>';

      html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">xG Diff/60</span><span class="adv-tooltip__v">' + esc(xgd) + ' <span style="opacity:.7">(z ' + esc(zx) + ', ' + esc(cx) + ')</span></span></div>';
      html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">SC Diff/60</span><span class="adv-tooltip__v">' + esc(scd) + ' <span style="opacity:.7">(z ' + esc(zs) + ', ' + esc(cs2) + ')</span></span></div>';
      html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">CF Diff/60</span><span class="adv-tooltip__v">' + esc(cfd) + ' <span style="opacity:.7">(z ' + esc(zc) + ', ' + esc(cc) + ')</span></span></div>';
      html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">Goal Diff/60</span><span class="adv-tooltip__v">' + esc(gd)  + ' <span style="opacity:.7">(z ' + esc(zg) + ', ' + esc(cg) + ')</span></span></div>';

      // Show penalties only if present
      if (pd !== '—' || (cp && cp !== '0' && cp !== '0.000')) {
        html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">Pen Diff/60</span><span class="adv-tooltip__v">' + esc(pd)  + ' <span style="opacity:.7">(z ' + esc(zp) + ', ' + esc(cp) + ')</span></span></div>';
      }

      var toiTxt = fmtToi(toi);
      if (toiTxt) html += '<div class="adv-tooltip__muted">TOI (5v5): ' + esc(toiTxt) + ' • weight ' + esc(w) + '</div>';
      return html;
    }

    chart.addEventListener('mousemove', function (e) {
      var t = e.target;
      if (!t) { hideTip(tip); return; }

      var rowEl = t.closest ? t.closest('.adv-impact__row') : null;
      if (!rowEl) { hideTip(tip); return; }

      var ok = (t.classList && t.classList.contains('adv-impact__hit')) ||
               (t.closest && t.closest('.adv-impact__overall'));
      if (!ok) { hideTip(tip); return; }

      showTip(tip, tooltipHtmlFromRow(rowEl), e.clientX, e.clientY);
    });

    chart.addEventListener('mouseleave', function () {
      hideTip(tip);
    });

    chart.addEventListener('click', function (e) {
      var t = e.target;
      var rowEl = t && t.closest ? t.closest('.adv-impact__row') : null;
      if (!rowEl) { hideTip(tip); lastTap = null; return; }

      var ok = (t.classList && t.classList.contains('adv-impact__hit')) ||
               (t.closest && t.closest('.adv-impact__overall'));
      if (!ok) { hideTip(tip); lastTap = null; return; }

      if (lastTap === rowEl && tip.style.display === 'block') {
        hideTip(tip);
        lastTap = null;
        return;
      }
      lastTap = rowEl;
      showTip(tip, tooltipHtmlFromRow(rowEl), e.clientX, e.clientY);
    }, false);
  }

  function boot(root) {
    root = root || document;

    var els = root.querySelectorAll('.js-adv-impactscore');
    for (var i = 0; i < els.length; i++) renderImpact(els[i]);

    setTimeout(function () { cleanupAdvStats(root); }, 0);
  }

  window.SJMS_IMPACT_BOOT = boot;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { boot(document); });
  } else {
    boot(document);
  }
})();
