/* assets/js/xg-quadrant.js */
/**
 * Main interactive quadrant renderer (points + tooltip + zoom/pan).
 *
 * Targets: .js-xgq-svg
 * Exposes: window.SJMS_XGQ_BOOT(root)
 *
 * Interactions:
 *  - Wheel / trackpad: zoom in/out (about cursor)
 *  - Drag: pan
 *  - Double-click: reset
 *  - Touch: one-finger drag to pan, two-finger pinch to zoom
 *  - Mobile: + / − / Reset overlay controls + double-tap reset + tap point to show tooltip
 *
 * Notes:
 *  - This renderer owns the element’s innerHTML (it writes one <svg>).
 *  - For initial bounds, autoscale uses ONLY non-goalie points (and optional min TOI),
 *    so removed goalies won’t stretch the left/right blank area.
 */
(function () {
  function num(v, d) {
    v = parseFloat(v);
    return isFinite(v) ? v : d;
  }

  function esc(s) {
    s = (s == null) ? '' : String(s);
    return s.replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]);
    });
  }

  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

  function fmt(v, dec) {
    v = parseFloat(v);
    if (!isFinite(v)) return '—';
    return v.toFixed(dec == null ? 2 : dec);
  }

  function toiFmt(sec) {
    sec = parseInt(sec, 10);
    if (!isFinite(sec) || sec <= 0) return '';
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ':' + (s < 10 ? '0' + s : s);
  }

  function median(arr) {
    if (!arr || !arr.length) return NaN;
    var a = arr.slice().sort(function (x, y) { return x - y; });
    var mid = Math.floor(a.length / 2);
    if (a.length % 2) return a[mid];
    return (a[mid - 1] + a[mid]) / 2;
  }

  // One tooltip for whole page
  function ensureTooltip() {
    var tip = document.getElementById('xgq-tooltip');
    if (tip) return tip;

    tip = document.createElement('div');
    tip.id = 'xgq-tooltip';
    tip.className = 'xgq-tooltip';
    tip.style.display = 'none';
    document.body.appendChild(tip);

    // minimal CSS injected once
    var cssId = 'xgq-tooltip-css';
    if (!document.getElementById(cssId)) {
      var st = document.createElement('style');
      st.id = cssId;
      st.textContent =
        '.xgq-tooltip{position:fixed;z-index:999999;max-width:280px;padding:10px 12px;' +
        'border:1px solid rgba(255,255,255,.18);border-radius:10px;' +
        'background:rgba(10,18,28,.92);backdrop-filter:blur(6px);' +
        'color:rgba(229,231,235,.95);font:600 12px/1.2 system-ui,-apple-system,Segoe UI,Roboto,Arial;' +
        'box-shadow:0 10px 30px rgba(0,0,0,.35);pointer-events:none}' +
        '.xgq-tooltip__name{font-size:13px;font-weight:800;margin:0 0 6px}' +
        '.xgq-tooltip__team{opacity:.8;font-weight:700}' +
        '.xgq-tooltip__row{display:flex;justify-content:space-between;gap:10px;margin:3px 0}' +
        '.xgq-tooltip__k{opacity:.85;font-weight:700}' +
        '.xgq-tooltip__v{font-weight:900}' +
        '.xgq-tooltip__muted{opacity:.75;font-weight:700;margin-top:6px}';
      document.head.appendChild(st);
    }

    return tip;
  }

  // Convert screen (client) to SVG user coords using currentCTM inverse
  function clientToSvg(svgEl, clientX, clientY) {
    var pt = svgEl.createSVGPoint();
    pt.x = clientX;
    pt.y = clientY;
    var ctm = svgEl.getScreenCTM();
    if (!ctm) return { x: 0, y: 0 };
    var inv = ctm.inverse();
    var p = pt.matrixTransform(inv);
    return { x: p.x, y: p.y };
  }

  function renderQuadrant(el) {
  // prevent double-render (important for AJAX)
  if (el.dataset.rendered === '1') return;
  el.dataset.rendered = '1';

  var isCoarse = false;
  try {
    isCoarse = !!(window.matchMedia && window.matchMedia('(pointer:coarse)').matches);
  } catch (e) { isCoarse = false; }

  // ---------- load points
  var pts = [];
  try { pts = JSON.parse(el.getAttribute('data-points') || '[]'); } catch (e) { pts = []; }
  if (!pts || !pts.length) { el.innerHTML = ''; return; }

  // If you want autoscale, set data-auto-scale="1"
  var autoScale = (el.getAttribute('data-auto-scale') === '1' || el.dataset.autoScale === '1');

  // Absolute padding in xG/60 units beyond min/max
  var padAbs = num(el.getAttribute('data-auto-pad'), 0.28);
  if (!isFinite(padAbs) || padAbs < 0) padAbs = 0.28;

  // ✅ Mobile usually needs more “breathing room”
  if (isCoarse && padAbs < 0.40) padAbs = 0.40;

  // Optional: min TOI (seconds) used ONLY for autoscale bounds
  var minToiScale = parseInt(el.getAttribute('data-min-toi-scale') || '0', 10);
  if (!isFinite(minToiScale) || minToiScale < 0) minToiScale = 0;

  function posNorm(p) {
    var s = (p && p.pos != null) ? String(p.pos).trim().toUpperCase() : '';
    if (s === 'N/A') s = 'NA';
    return s;
  }

  function isBadPos(p) {
    if (!p) return true;
    var pos = posNorm(p);
    return (p.is_goalie === 1) || pos === 'G' || pos === 'NA' || pos === 'NULL' || pos === '';
  }

  function shouldSkipForScale(p, minToi) {
    if (!p) return true;
    if (isBadPos(p)) return true;

    var toi = (p.toi != null) ? parseInt(p.toi, 10) : 0;
    if (minToi && toi > 0 && toi < minToi) return true;

    return false;
  }

  // ---------- gather values for scaling (skipping goalies + pos NA)
  var xs = [], ys = [];
  var maxR = 18;

  for (var i = 0; i < pts.length; i++) {
    var p0 = pts[i];
    if (shouldSkipForScale(p0, minToiScale)) continue;

    var x0 = num(p0.xga, NaN);
    var y0 = num(p0.xgf, NaN);
    if (!isFinite(x0) || !isFinite(y0)) continue;

    xs.push(x0); ys.push(y0);

    var r0 = num(p0.r, 18);
    if (!isFinite(r0)) r0 = 18;
    r0 = clamp(r0, 10, 30);
    if (r0 > maxR) maxR = r0;
  }

  // fallback: if everything got filtered out, scale off all valid numeric points
  if (!xs.length) {
    for (i = 0; i < pts.length; i++) {
      var p1 = pts[i];
      if (!p1) continue;
      var x1 = num(p1.xga, NaN);
      var y1 = num(p1.xgf, NaN);
      if (!isFinite(x1) || !isFinite(y1)) continue;
      xs.push(x1); ys.push(y1);

      var r1 = num(p1.r, 18);
      if (!isFinite(r1)) r1 = 18;
      r1 = clamp(r1, 10, 30);
      if (r1 > maxR) maxR = r1;
    }
  }

  if (!xs.length) { el.innerHTML = ''; return; }

  function arrMin(a) { var m = Infinity; for (var k = 0; k < a.length; k++) if (a[k] < m) m = a[k]; return m; }
  function arrMax(a) { var m = -Infinity; for (var k = 0; k < a.length; k++) if (a[k] > m) m = a[k]; return m; }

  // ---------- SVG layout constants (mobile-friendly tweaks)
  var mobileNarrow = (isCoarse && (el.clientWidth && el.clientWidth < 520));
  var W = mobileNarrow ? 980 : 1200;
  var H = mobileNarrow ? 880 : 780;

  var padL = mobileNarrow ? 95 : 140;
  var padR = mobileNarrow ? 55 : 80;
  var padT = mobileNarrow ? 70 : 80;
  var padB = mobileNarrow ? 120 : 130;

  var plotW = W - padL - padR;
  var plotH = H - padT - padB;

  // marker padding in DATA units so circles don’t clip at the edges
  function markerPadXUnits(spanX) { return (spanX * (maxR + (mobileNarrow ? 36 : 28)) / plotW); }
  function markerPadYUnits(spanY) { return (spanY * (maxR + (mobileNarrow ? 36 : 28)) / plotH); }

  // ---------- bounds (explicit overrides allowed)
  // IMPORTANT:
  // - Plot bounds can go slightly < 0 to keep markers visible (prevents “cropped players”)
  // - Tick bounds are clamped to 0 so labels never show negatives
  var xMin = num(el.dataset.xMin, NaN);
  var xMax = num(el.dataset.xMax, NaN);
  var yMin = num(el.dataset.yMin, NaN);
  var yMax = num(el.dataset.yMax, NaN);

  if (!isFinite(xMin)) xMin = 0;
  if (!isFinite(xMax)) xMax = 1.2;
  if (!isFinite(yMin)) yMin = 0;
  if (!isFinite(yMax)) yMax = 1.2;

  var xMinPlot = xMin, xMaxPlot = xMax;
  var yMinPlot = yMin, yMaxPlot = yMax;

  var xMinTick = Math.max(0, xMinPlot);
  var yMinTick = Math.max(0, yMinPlot);
  var xMaxTick = xMaxPlot;
  var yMaxTick = yMaxPlot;

  // ---------- autoscale using true min/max + absolute padding (+ marker padding)
  // Only do autoscale if any explicit bounds are missing (so manual overrides win).
  var haveExplicitAll =
    isFinite(num(el.dataset.xMin, NaN)) && isFinite(num(el.dataset.xMax, NaN)) &&
    isFinite(num(el.dataset.yMin, NaN)) && isFinite(num(el.dataset.yMax, NaN));

  if (autoScale && !haveExplicitAll) {
    var minX = arrMin(xs), maxX2 = arrMax(xs);
    var minY2 = arrMin(ys), maxY2 = arrMax(ys);

    var spanX = (maxX2 - minX);
    var spanY = (maxY2 - minY2);

    if (!isFinite(spanX) || spanX <= 0) spanX = 0.40;
    if (!isFinite(spanY) || spanY <= 0) spanY = 0.40;

    // avoid microscopic charts
    var MIN_SPAN_X = 0.40;
    var MIN_SPAN_Y = 0.40;

    if (spanX < MIN_SPAN_X) {
      var cx0 = (minX + maxX2) / 2;
      minX = cx0 - MIN_SPAN_X / 2;
      maxX2 = cx0 + MIN_SPAN_X / 2;
      spanX = MIN_SPAN_X;
    }
    if (spanY < MIN_SPAN_Y) {
      var cy0 = (minY2 + maxY2) / 2;
      minY2 = cy0 - MIN_SPAN_Y / 2;
      maxY2 = cy0 + MIN_SPAN_Y / 2;
      spanY = MIN_SPAN_Y;
    }

    var extraX = padAbs + markerPadXUnits(spanX);
    var extraY = padAbs + markerPadYUnits(spanY);

    // ✅ allow slightly negative mins for PLOT so circles don’t clip
    xMinPlot = (minX - extraX);
    xMaxPlot = (maxX2 + extraX);
    yMinPlot = (minY2 - extraY);
    yMaxPlot = (maxY2 + extraY);

    // ✅ ticks never show negatives
    xMinTick = Math.max(0, xMinPlot);
    yMinTick = Math.max(0, yMinPlot);
    xMaxTick = xMaxPlot;
    yMaxTick = yMaxPlot;
  }

  // Split lines: provided or median
  var xSplit = (el.dataset.xSplit != null && el.dataset.xSplit !== '')
    ? num(el.dataset.xSplit, (xMinPlot + xMaxPlot) / 2)
    : median(xs);

  var ySplit = (el.dataset.ySplit != null && el.dataset.ySplit !== '')
    ? num(el.dataset.ySplit, (yMinPlot + yMaxPlot) / 2)
    : median(ys);

  if (!isFinite(xSplit)) xSplit = (xMinPlot + xMaxPlot) / 2;
  if (!isFinite(ySplit)) ySplit = (yMinPlot + yMaxPlot) / 2;

  // Local coords in plot space (0..plotW / 0..plotH)
  // Reverse X axis (left = higher xGA, right = lower xGA)
  function xToLocal(x) {
    var denom = (xMaxPlot - xMinPlot);
    if (!isFinite(denom) || denom === 0) denom = 1;
    var t = (xMaxPlot - x) / denom;
    return t * plotW;
  }
  function yToLocal(y) {
    var denom = (yMaxPlot - yMinPlot);
    if (!isFinite(denom) || denom === 0) denom = 1;
    var t = (y - yMinPlot) / denom;
    return (1 - t) * plotH;
  }

  function line(x1, y1, x2, y2, cls) {
    return '<line x1="' + x1 + '" y1="' + y1 + '" x2="' + x2 + '" y2="' + y2 + '" class="' + cls + '"/>';
  }
  function text(x, y, cls, str, anchor, extra) {
    return '<text class="' + cls + '" x="' + x + '" y="' + y + '" text-anchor="' + (anchor || 'start') + '"' + (extra || '') + '>' + str + '</text>';
  }
  function ticks(min, max, n) {
    var out = [];
    if (n < 2) return out;
    for (var j = 0; j <= n; j++) out.push(min + (j / n) * (max - min));
    return out;
  }

  // ---------- SVG build
  var uid = 'xgq' + Math.random().toString(16).slice(2);
  var gridN = 8;

  var title = esc(el.getAttribute('data-title') || 'Expected Goals For vs. Against - 5v5');

  var tickFont = mobileNarrow ? 12 : 14;
  var labelFont = mobileNarrow ? 16 : 18;
  var titleFont = mobileNarrow ? 24 : 34;

  var svg = [];
  svg.push(
    '<svg class="xgq__svg" viewBox="0 0 ' + W + ' ' + H + '" ' +
    'xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" ' +
    'preserveAspectRatio="xMidYMid meet" ' +
    'style="width:100%;height:auto;display:block;max-width:100%;margin:0 auto;">'
  );

  svg.push('<defs>');
  svg.push('<clipPath id="' + uid + '-clip"><rect x="0" y="0" width="' + plotW + '" height="' + plotH + '" rx="10" ry="10"/></clipPath>');
  svg.push(
    '<linearGradient id="' + uid + '-bg" x1="0%" y1="100%" x2="100%" y2="0%">' +
      '<stop offset="0%"   stop-color="#630000" stop-opacity="0.98"/>' +
      '<stop offset="22%"  stop-color="#c12a00" stop-opacity="0.92"/>' +
      '<stop offset="50%"  stop-color="#c9b100" stop-opacity="0.78"/>' +
      '<stop offset="76%"  stop-color="#3fa800" stop-opacity="0.90"/>' +
      '<stop offset="100%" stop-color="#023020" stop-opacity="0.98"/>' +
    '</linearGradient>'
  );
  svg.push('</defs>');

  svg.push('<style>');
  svg.push('.xgq-grid{stroke:rgba(255,255,255,.18);stroke-width:1;stroke-dasharray:6 8}');
  svg.push('.xgq-axis{stroke:rgba(255,255,255,.42);stroke-width:2}');
  svg.push('.xgq-tick{fill:rgba(255,255,255,.92);font:900 ' + tickFont + 'px system-ui,-apple-system,Segoe UI,Roboto,Arial}');
  svg.push('.xgq-label{fill:rgba(255,255,255,.92);font:900 ' + labelFont + 'px system-ui,-apple-system,Segoe UI,Roboto,Arial}');
  svg.push('.xgq-title{fill:rgba(255,255,255,.96);font:900 ' + titleFont + 'px system-ui,-apple-system,Segoe UI,Roboto,Arial}');
  svg.push('.xgq-border{fill:none;stroke:rgba(255,255,255,.22);stroke-width:2}');
  svg.push('.xgq-ptg{cursor:pointer}');
  svg.push('.xgq-ptg:hover .xgq-ring2{stroke:rgba(255,255,255,.85)}');
  svg.push('.xgq-ptg:hover .xgq-ring1{stroke:rgba(255,255,255,.35)}');
  svg.push('.xgq-img{pointer-events:none}');
  svg.push('.xgq-panhit{fill:transparent;cursor:grab}');
  svg.push('.xgq-panhit.dragging{cursor:grabbing}');
  svg.push('</style>');

  // title + labels outside the zoom group
  svg.push(text(W / 2, 55, 'xgq-title', title, 'middle'));
  svg.push(text(W / 2, H - 25, 'xgq-label', 'Expected Goals Against (lower is better)', 'middle'));

  // rotate y-label; nudge on mobile
  var yLabelX = mobileNarrow ? 22 : 30;
  svg.push('<g transform="translate(' + yLabelX + ' ' + (padT + plotH / 2) + ') rotate(-90)">' +
    '<text class="xgq-label" text-anchor="middle">Expected Goals For (higher is better)</text>' +
  '</g>');

  // tick labels (STATIC) — use TICK bounds (clamped at 0)
  var xTicks = ticks(xMinTick, xMaxTick, 7);
  for (i = 0; i < xTicks.length; i++) {
    var xv = xTicks[i];
    var xp = padL + xToLocal(xv);
    svg.push(text(xp, padT + plotH + 28, 'xgq-tick', fmt(xv, 2), 'middle'));
  }

  var yTicks = ticks(yMinTick, yMaxTick, 6);
  for (i = 0; i < yTicks.length; i++) {
    var yv = yTicks[i];
    var yp = padT + yToLocal(yv);
    svg.push(text(padL - 15, yp + 5, 'xgq-tick', fmt(yv, 2), 'end'));
  }

  // plot root group (translated to plot origin)
  svg.push('<g class="xgq-plot" transform="translate(' + padL + ' ' + padT + ')">');

  // border
  svg.push('<rect class="xgq-border" x="0" y="0" width="' + plotW + '" height="' + plotH + '" rx="10" ry="10"/>');

  // clip area and zoomable viewport group
  svg.push('<g clip-path="url(#' + uid + '-clip)">');
  svg.push('<g class="xgq-vp">');

  // background gradient
  svg.push('<rect x="0" y="0" width="' + plotW + '" height="' + plotH + '" fill="url(#' + uid + '-bg)"/>');

  // grid
  for (i = 0; i <= gridN; i++) {
    var gx = (i / gridN) * plotW;
    var gy = (i / gridN) * plotH;
    svg.push(line(gx, 0, gx, plotH, 'xgq-grid'));
    svg.push(line(0, gy, plotW, gy, 'xgq-grid'));
  }

  // split axes
  var xSplitPx = xToLocal(xSplit);
  var ySplitPx = yToLocal(ySplit);
  svg.push(line(xSplitPx, 0, xSplitPx, plotH, 'xgq-axis'));
  svg.push(line(0, ySplitPx, plotW, ySplitPx, 'xgq-axis'));

  // pan hit (BEHIND points so hover works)
  svg.push('<rect class="xgq-panhit" x="0" y="0" width="' + plotW + '" height="' + plotH + '"></rect>');

  // points
  svg.push('<g class="xgq-pts">');
  for (i = 0; i < pts.length; i++) {
    var p = pts[i];
    if (!p) continue;
    if (isBadPos(p)) continue; // filter pos NA + goalie

    var xga = num(p.xga, NaN);
    var xgf = num(p.xgf, NaN);
    if (!isFinite(xga) || !isFinite(xgf)) continue;

    var cx = xToLocal(xga);
    var cy = yToLocal(xgf);

    var r = num(p.r, 18);
    if (!isFinite(r)) r = 18;
    r = clamp(r, 10, 30);

    // mobile: make taps easier
    var hitPad = mobileNarrow ? 30 : 18;

    // logos back: accept multiple possible keys
    var imgRaw = (p.logo_url || p.logo || p.img || p.team_logo || p.logoUrl || p.teamLogoUrl || p.team_logo_url || '');
    var img = imgRaw ? esc(imgRaw) : '';

    svg.push('<g class="xgq-ptg" data-i="' + i + '">');
    svg.push('<circle class="xgq-ring1" cx="' + cx + '" cy="' + cy + '" r="' + (r + 14) + '" fill="rgba(0,0,0,.10)" stroke="rgba(255,255,255,.14)" stroke-width="2"/>');
    svg.push('<circle class="xgq-ring2" cx="' + cx + '" cy="' + cy + '" r="' + (r + 4) + '" fill="rgba(0,0,0,.08)" stroke="rgba(255,255,255,.30)" stroke-width="2"/>');

    if (img) {
      var clipId = uid + '-pclip-' + i;
      svg.push('<defs><clipPath id="' + clipId + '"><circle cx="' + cx + '" cy="' + cy + '" r="' + r + '"/></clipPath></defs>');
      svg.push('<image class="xgq-img" href="' + img + '" xlink:href="' + img + '" x="' + (cx - r) + '" y="' + (cy - r) + '" width="' + (2 * r) + '" height="' + (2 * r) + '" clip-path="url(#' + clipId + ')"/>');
    } else {
      svg.push('<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="rgba(255,255,255,.75)"/>');
    }

    // hit target for tooltip/hover/tap
    svg.push('<circle class="xgq-pt-hit" data-i="' + i + '" cx="' + cx + '" cy="' + cy + '" r="' + (r + hitPad) + '" fill="transparent"/>');
    svg.push('</g>');
  }
  svg.push('</g>'); // pts

  svg.push('</g>'); // vp
  svg.push('</g>'); // clip
  svg.push('</g>'); // plot
  svg.push('</svg>');

  el.innerHTML = svg.join('');

  // ---------- wrapper overflow fixes
  try {
    el.style.width = '100%';
    el.style.maxWidth = '100%';
    el.style.overflow = 'hidden';
  } catch (e) {}

  // ---------- zoom/pan + tooltip wiring
  var svgEl = el.querySelector('svg');
  var vpG = el.querySelector('.xgq-vp');
  var panHit = el.querySelector('.xgq-panhit');
  if (!svgEl || !vpG || !panHit) return;

  try {
    svgEl.style.width = '100%';
    svgEl.style.height = 'auto';
    svgEl.style.display = 'block';
    svgEl.style.maxWidth = '100%';
    svgEl.style.margin = '0 auto';
    svgEl.setAttribute('width', '100%');
    svgEl.removeAttribute('height');
  } catch (e) {}

  try { panHit.style.touchAction = 'none'; } catch (e) {}

  // ✅ Better mobile default “zoom”
  var startScale = 1;
  if (isCoarse) startScale = mobileNarrow ? 0.84 : 0.92;

  var scale = startScale;
  var tx = 0;
  var ty = 0;

  function applyTransform() {
    vpG.setAttribute('transform', 'translate(' + tx + ' ' + ty + ') scale(' + scale + ')');
  }

  function clampPan() {
    var maxX = 0;
    var minX = -plotW * (scale - 1);
    var maxY = 0;
    var minY = -plotH * (scale - 1);

    if (scale <= 1) {
      if (isCoarse) {
        tx = (plotW * (1 - scale)) / 2;
        ty = (plotH * (1 - scale)) / 2;
      } else {
        tx = 0; ty = 0;
      }
      return;
    }

    tx = clamp(tx, minX, maxX);
    ty = clamp(ty, minY, maxY);
  }

  function plotLocalFromClient(clientX, clientY) {
    var p = clientToSvg(svgEl, clientX, clientY);
    return { x: p.x - padL, y: p.y - padT };
  }

  function zoomAboutPlotPoint(sx, sy, factor) {
    var oldS = scale;
    var newS = clamp(oldS * factor, 0.7, 8.0);
    if (newS === oldS) return;

    var lx = (sx - tx) / oldS;
    var ly = (sy - ty) / oldS;

    scale = newS;
    tx = sx - lx * scale;
    ty = sy - ly * scale;

    clampPan();
    applyTransform();
  }

  // Wheel zoom about cursor (desktop)
  panHit.addEventListener('wheel', function (e) {
    e.preventDefault();

    var loc = plotLocalFromClient(e.clientX, e.clientY);
    if (!loc) return;
    if (loc.x < 0 || loc.y < 0 || loc.x > plotW || loc.y > plotH) return;

    var z = Math.exp((-e.deltaY) * 0.0015);
    zoomAboutPlotPoint(loc.x, loc.y, z);
  }, { passive: false });

  // Drag/pinch (pointer events)
  var dragging = false;
  var dragStart = null;

  var pointers = {}; // id -> {x,y}
  var pinchStart = null;

  function dist2(a, b) {
    var dx = a.x - b.x;
    var dy = a.y - b.y;
    return Math.sqrt(dx * dx + dy * dy);
  }

  panHit.addEventListener('pointerdown', function (e) {
    pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
    panHit.setPointerCapture(e.pointerId);

    var ids = Object.keys(pointers);

    if (ids.length === 2) {
      dragging = false;
      dragStart = null;

      var pA = pointers[ids[0]];
      var pB = pointers[ids[1]];
      var d0 = dist2(pA, pB);
      if (d0 < 2) d0 = 2;

      var midClientX = (pA.x + pB.x) / 2;
      var midClientY = (pA.y + pB.y) / 2;
      var midLoc = plotLocalFromClient(midClientX, midClientY);

      pinchStart = {
        d0: d0,
        scale0: scale,
        tx0: tx,
        ty0: ty,
        midX: midLoc.x,
        midY: midLoc.y
      };

      panHit.classList.add('dragging');
      return;
    }

    if (ids.length === 1) {
      dragging = true;
      panHit.classList.add('dragging');

      var loc = plotLocalFromClient(e.clientX, e.clientY);
      dragStart = { sx: loc.x, sy: loc.y, tx: tx, ty: ty };
    }
  });

  panHit.addEventListener('pointermove', function (e) {
    if (pointers[e.pointerId]) {
      pointers[e.pointerId].x = e.clientX;
      pointers[e.pointerId].y = e.clientY;
    }

    var ids = Object.keys(pointers);

    if (pinchStart && ids.length >= 2) {
      var pA = pointers[ids[0]];
      var pB = pointers[ids[1]];
      var d1 = dist2(pA, pB);
      if (d1 < 2) d1 = 2;

      var ratio = d1 / pinchStart.d0;

      var PINCH_POWER = isCoarse ? 1.40 : 1.20;
      var boosted = Math.pow(ratio, PINCH_POWER);

      var oldS = pinchStart.scale0;
      var newS = clamp(oldS * boosted, 0.7, 8.0);
      if (newS === oldS) return;

      var sx = pinchStart.midX;
      var sy = pinchStart.midY;

      var lx = (sx - pinchStart.tx0) / oldS;
      var ly = (sy - pinchStart.ty0) / oldS;

      scale = newS;
      tx = sx - lx * scale;
      ty = sy - ly * scale;

      clampPan();
      applyTransform();
      return;
    }

    if (!dragging || !dragStart) return;

    var loc2 = plotLocalFromClient(e.clientX, e.clientY);
    tx = dragStart.tx + (loc2.x - dragStart.sx);
    ty = dragStart.ty + (loc2.y - dragStart.sy);

    clampPan();
    applyTransform();
  });

  function endPointer(e) {
    if (pointers[e.pointerId]) delete pointers[e.pointerId];

    var ids = Object.keys(pointers);
    if (ids.length < 2) pinchStart = null;

    if (ids.length === 0) {
      dragging = false;
      dragStart = null;
      panHit.classList.remove('dragging');
    } else if (ids.length === 1 && !dragging) {
      panHit.classList.remove('dragging');
    }

    try { panHit.releasePointerCapture(e.pointerId); } catch (err) {}
  }

  panHit.addEventListener('pointerup', endPointer);
  panHit.addEventListener('pointercancel', endPointer);

  panHit.addEventListener('dblclick', function () {
    scale = 1; tx = 0; ty = 0;
    clampPan();
    applyTransform();
  });

  if (isCoarse) {
    var lastTap = 0;
    panHit.addEventListener('pointerup', function () {
      var now = Date.now();
      if (now - lastTap < 300) {
        scale = startScale; tx = 0; ty = 0;
        clampPan();
        applyTransform();
        lastTap = 0;
      } else {
        lastTap = now;
      }
    });
  }

  // Mobile controls
  if (isCoarse) {
    try {
      var pos = (window.getComputedStyle ? window.getComputedStyle(el).position : '');
      if (!pos || pos === 'static') el.style.position = 'relative';
    } catch (e) { el.style.position = 'relative'; }

    var ctl = document.createElement('div');
    ctl.className = 'xgq-ctl';
    ctl.setAttribute('aria-label', 'Quadrant controls');
    ctl.innerHTML =
      '<button type="button" data-act="in" aria-label="Zoom in">+</button>' +
      '<button type="button" data-act="out" aria-label="Zoom out">−</button>' +
      '<button type="button" data-act="reset" aria-label="Reset view">Reset</button>';

    ctl.style.position = 'absolute';
    ctl.style.right = '10px';
    ctl.style.bottom = '10px';
    ctl.style.display = 'flex';
    ctl.style.gap = '8px';
    ctl.style.zIndex = '5';

    var btns = ctl.querySelectorAll('button');
    for (var bi = 0; bi < btns.length; bi++) {
      btns[bi].style.border = '1px solid rgba(255,255,255,.22)';
      btns[bi].style.background = 'rgba(10,18,28,.74)';
      btns[bi].style.color = 'rgba(255,255,255,.95)';
      btns[bi].style.borderRadius = '12px';
      btns[bi].style.padding = mobileNarrow ? '10px 12px' : '8px 12px';
      btns[bi].style.font = '900 14px system-ui,-apple-system,Segoe UI,Roboto,Arial';
      btns[bi].style.backdropFilter = 'blur(6px)';
    }

    el.appendChild(ctl);

    function zoomAboutCenter(factor) {
      zoomAboutPlotPoint(plotW / 2, plotH / 2, factor);
    }

    ctl.addEventListener('click', function (e) {
      var act = e.target && e.target.getAttribute && e.target.getAttribute('data-act');
      if (!act) return;

      e.preventDefault();
      e.stopPropagation();

      if (act === 'in') zoomAboutCenter(1.30);
      if (act === 'out') zoomAboutCenter(0.78);
      if (act === 'reset') { scale = startScale; tx = 0; ty = 0; clampPan(); applyTransform(); }
    });
  }

  // ---------- Tooltip (unchanged from your version)
  var tip = ensureTooltip();
  var activeIdx = null;

  function pickNum(p, keys) {
    for (var ii = 0; ii < keys.length; ii++) {
      var k = keys[ii];
      if (p && Object.prototype.hasOwnProperty.call(p, k)) {
        var v = num(p[k], NaN);
        if (isFinite(v)) return v;
      }
    }
    return NaN;
  }

  function pickStr(p, keys) {
    for (var ii = 0; ii < keys.length; ii++) {
      var k = keys[ii];
      if (p && Object.prototype.hasOwnProperty.call(p, k)) {
        var s = (p[k] == null) ? '' : String(p[k]);
        s = s.trim();
        if (s !== '') return s;
      }
    }
    return '';
  }

  function pickToiSeconds(p) {
    var toi = pickNum(p, ['toi', 'TOI', 'toi_seconds', 'TOI_seconds', 'toiSec', 'toi_sec', 'ev_toi', 'toi_ev']);
    if (isFinite(toi)) return parseInt(toi, 10);

    var s = pickStr(p, ['toi_str', 'TOI_str', 'toiFmt', 'toi_formatted', 'toi_text', 'TOI_text']);
    if (s && /^[0-9]+:[0-9]{2}$/.test(s)) {
      var parts = s.split(':');
      return (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10);
    }
    return 0;
  }

  function tipHide() {
    tip.style.display = 'none';
    activeIdx = null;
  }

  function tipShowForIdx(idx, clientX, clientY) {
    var p = pts[idx];
    if (!p) return;

    var name = esc(pickStr(p, ['name','player_name','label','player','fullName','full_name','first_last']));
    var team = esc(pickStr(p, ['team','team_abbr','abbr','teamAbbr','team_abbreviation']));

    var xgf60 = pickNum(p, ['xgf60','xGF60','xgf_60','xGF_60','xGFper60','xgf_per60','xgf','xGF']);
    var xga60 = pickNum(p, ['xga60','xGA60','xga_60','xGA_60','xGAper60','xga_per60','xga','xGA']);

    var xgfp  = pickNum(p, ['xgf_pct','xGF_pct','xGF%','xgf%','xGFpct','xgfPct','xgfPercent','xGFPercent']);

    if (!isFinite(xgfp) && isFinite(xgf60) && isFinite(xga60) && (xgf60 + xga60) > 0) {
      xgfp = 100 * xgf60 / (xgf60 + xga60);
    }
    if (!isFinite(xgfp)) {
      var xgfTot = pickNum(p, ['xgf_total','xGF_total','xgfTot','xGFTot','xgf_sum','xGF_sum']);
      var xgaTot = pickNum(p, ['xga_total','xGA_total','xgaTot','xGATot','xga_sum','xGA_sum']);
      if (isFinite(xgfTot) && isFinite(xgaTot) && (xgfTot + xgaTot) > 0) {
        xgfp = 100 * xgfTot / (xgfTot + xgaTot);
      }
    }

    var toi = pickToiSeconds(p);
    var hdr = name + (team ? ' (' + team + ')' : '');

    tip.innerHTML =
      '<div class="xgq-tooltip__name">' + hdr + '</div>' +
      '<div class="xgq-tooltip__row"><div class="xgq-tooltip__k">xGF/60</div><div class="xgq-tooltip__v">' + (isFinite(xgf60) ? fmt(xgf60, 2) : '—') + '</div></div>' +
      '<div class="xgq-tooltip__row"><div class="xgq-tooltip__k">xGA/60</div><div class="xgq-tooltip__v">' + (isFinite(xga60) ? fmt(xga60, 2) : '—') + '</div></div>' +
      '<div class="xgq-tooltip__row"><div class="xgq-tooltip__k">xGF%</div><div class="xgq-tooltip__v">' + (isFinite(xgfp) ? fmt(xgfp, 1) : '—') + '</div></div>' +
      '<div class="xgq-tooltip__row"><div class="xgq-tooltip__k">TOI</div><div class="xgq-tooltip__v">' + (toi > 0 ? toiFmt(toi) : '—') + '</div></div>';

    tip.style.display = 'block';
    tip.style.left = (clientX + 12) + 'px';
    tip.style.top  = (clientY + 12) + 'px';
  }

  svgEl.addEventListener('pointerover', function (e) {
    if (isCoarse) return;
    var t = e.target;
    if (!t || !t.classList || !t.classList.contains('xgq-pt-hit')) return;
    var idx = parseInt(t.getAttribute('data-i') || '0', 10);
    activeIdx = idx;
    tipShowForIdx(idx, e.clientX, e.clientY);
  });

  svgEl.addEventListener('pointermove', function (e) {
    if (activeIdx == null) return;
    if (isCoarse) return;
    tip.style.left = (e.clientX + 12) + 'px';
    tip.style.top  = (e.clientY + 12) + 'px';
  });

  svgEl.addEventListener('pointerout', function (e) {
    if (isCoarse) return;
    var t = e.target;
    if (!t || !t.classList || !t.classList.contains('xgq-pt-hit')) return;
    tipHide();
  });

  svgEl.addEventListener('mouseleave', function () {
    if (!isCoarse) tipHide();
  });

  if (isCoarse) {
    svgEl.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.classList) return;

      if (t.classList.contains('xgq-pt-hit')) {
        var idx = parseInt(t.getAttribute('data-i') || '0', 10);
        if (activeIdx === idx && tip.style.display === 'block') {
          tipHide();
          return;
        }
        activeIdx = idx;
        tipShowForIdx(idx, e.clientX, e.clientY);
        return;
      }

      tipHide();
    });
  }

  // init
  clampPan();
  applyTransform();
}


  // Boot that can run on the whole document OR a container you just injected
  function boot(root) {
    root = root || document;
    var els = root.querySelectorAll('.js-xgq-svg');
    for (var i = 0; i < els.length; i++) renderQuadrant(els[i]);
  }

  window.SJMS_XGQ_BOOT = boot;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { boot(document); });
  } else {
    boot(document);
  }
})();
