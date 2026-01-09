/* assets/js/advsvg-gar.js */
(function () {
  function num(v, d) { v = parseFloat(v); return isFinite(v) ? v : d; }
  function esc(s) {
    s = (s == null) ? '' : String(s);
    return s.replace(/[&<>"']/g, function (c) {
      return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]);
    });
  }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

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
        '.adv-tooltip{position:fixed;z-index:999999;max-width:380px;padding:10px 12px;' +
        'border:1px solid rgba(255,255,255,.18);border-radius:10px;' +
        'background:rgba(10,18,28,.92);backdrop-filter:blur(6px);' +
        'color:rgba(229,231,235,.95);font:650 12px/1.25 system-ui,-apple-system,Segoe UI,Roboto,Arial;' +
        'box-shadow:0 10px 30px rgba(0,0,0,.35);pointer-events:none}' +
        '.adv-tooltip__name{font-size:13px;font-weight:900;margin:0 0 6px}' +
        '.adv-tooltip__row{display:flex;justify-content:space-between;gap:12px;margin:3px 0}' +
        '.adv-tooltip__k{opacity:.85;font-weight:800}' +
        '.adv-tooltip__v{font-weight:900}';
      document.head.appendChild(st);
    }

    return tip;
  }

  function parseRows(el) {
    var raw = el.getAttribute('data-rows') || '[]';
    try { var rows = JSON.parse(raw); return Array.isArray(rows) ? rows : []; }
    catch (e) { return []; }
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
    var particles = { 'da':1,'de':1,'del':1,'della':1,'der':1,'di':1,'du':1,'la':1,'le':1,'lo':1,'van':1,'von':1,'st.':1,'st':1,'ter':1,'ten':1,'den':1 };
    var last = parts[parts.length - 1];
    var prev = parts[parts.length - 2].toLowerCase();
    if (particles[prev]) return parts[parts.length - 2] + ' ' + last;
    return last;
  }

  function playerLabel(name) {
    return isMobile() ? lastNameOnly(name) : (name || '');
  }

  // Keep YOUR font sizes exactly.
  function layoutVars() {
    var mobile = isMobile();
    var padB = mobile ? 170 : 155;

    return {
      W: 10000,
      rowH: mobile ? 312 : 292,
      barH: mobile ? 256 : 246,

      padT: mobile ? 200 : 100,
      padB: padB,
      padL: mobile ? 110 : 160,
      padR: 74,

      titleSize: mobile ? 230 : 128,
      tickSize:  mobile ? 218 : 117,
      nameSize:  mobile ? 280 : 176,

      insideNamePad: 14,
      insideNameOpacity: mobile ? 0.99 : 0.96,
      insideNameHalo: true
    };
  }

  function ticks(min, max, n) {
    var out = [];
    if (n < 2) return out;
    for (var i = 0; i <= n; i++) out.push(min + (i / n) * (max - min));
    return out;
  }

  function dedupeByPlayerId(rows) {
    var map = {};
    var out = [];
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i] || {};
      var pid = (r.player_id == null) ? null : String(r.player_id);
      if (!pid) { out.push(r); continue; }
      var toi = parseInt(r.toi_5v5 || 0, 10); if (!isFinite(toi)) toi = 0;
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

  function renderGar(el) {
    var rows = dedupeByPlayerId(parseRows(el));
    if (!rows.length) { el.innerHTML = ''; return; }

    var minV = Infinity, maxV = -Infinity;
    for (var i = 0; i < rows.length; i++) {
      var v = num(rows[i].gar, 0);
      if (v < minV) minV = v;
      if (v > maxV) maxV = v;
    }

    var span = (maxV - minV);
    if (!isFinite(span) || span <= 0) span = 1;
    var pad = span * 0.12;
    minV -= pad; maxV += pad;
    if (minV > 0) minV = 0;
    if (maxV < 0) maxV = 0;

    var L = layoutVars();
    var W = L.W;
    var rowH = L.rowH;
    var barH = L.barH;
    var padL = L.padL, padR = L.padR;

    var padT = 40;
    var padB = Math.max(140, L.tickSize + 40);

    // --- NEW: blank row after each player (including last) ---
    var rowGap = parseInt(el.getAttribute('data-row-gap') || String(rowH), 10);
    if (!isFinite(rowGap) || rowGap < 0) rowGap = rowH;

    var plotW = W - padL - padR;

    var plotHBars  = rows.length * rowH + Math.max(0, rows.length - 1) * rowGap;
    var plotHTotal = rows.length * (rowH + rowGap);
    var H = padT + padB + plotHTotal;

    function xToPx(x) {
      var t = (x - minV) / (maxV - minV);
      if (!isFinite(t)) t = 0;
      return padL + t * plotW;
    }
    function yMid(idx) { return padT + idx * (rowH + rowGap) + rowH * 0.5; }

    function rect(x, y, w, h, cls, extra) {
      if (w < 0) { x += w; w = -w; }
      return '<rect class="' + cls + '" x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '"' + (extra || '') + '/>';
    }
    function line(x1, y1, x2, y2, cls) {
      return '<line class="' + cls + '" x1="' + x1 + '" y1="' + y1 + '" x2="' + x2 + '" y2="' + y2 + '"/>';
    }
    function text(x, y, cls, str, anchor, extra) {
      return '<text class="' + cls + '" x="' + x + '" y="' + y + '" text-anchor="' + (anchor || 'start') + '"' + (extra || '') + '>' + str + '</text>';
    }

    var title = el.getAttribute('data-title') || 'Goals Above Replacement (GAR)';
    var legendHtml =
      legItem('adv-leg__swatch--ev',  'EV (5v5)') +
      legItem('adv-leg__swatch--pp',  'PP') +
      legItem('adv-leg__swatch--sh',  'SH') +
      legItem('adv-leg__swatch--pen', 'Penalties');

    var plotHost = buildFigure(el, 'gar', title, legendHtml);

    var svg = [];
    svg.push('<svg class="advsvg advsvg--gar" viewBox="0 0 ' + W + ' ' + H + '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' + esc(title) + '">');

    svg.push('<style>');
    svg.push('.adv-grid{stroke:rgba(148,163,184,.18);stroke-width:1}');
    svg.push('.adv-zero{stroke:rgba(229,231,235,.45);stroke-width:2}');
    svg.push('.adv-tick{fill:rgba(229,231,235,.72);font:800 ' + L.tickSize + 'px system-ui,-apple-system,Segoe UI,Roboto,Arial}');
    svg.push('.adv-name-in{fill:rgba(229,231,235,' + L.insideNameOpacity + ');font:900 ' + L.nameSize + 'px system-ui,-apple-system,Segoe UI,Roboto,Arial}');
    svg.push('.adv-name-in--halo{paint-order:stroke;stroke:rgba(0,0,0,.65);stroke-width:5;stroke-linejoin:round}');
    svg.push('.seg{shape-rendering:geometricPrecision}');
    svg.push('.hit{fill:transparent;cursor:pointer}');
    svg.push('.seg--ev{fill:rgba(59,130,246,.85)}');
    svg.push('.seg--pp{fill:rgba(236,72,153,.80)}');
    svg.push('.seg--sh{fill:rgba(20,184,166,.78)}');
    svg.push('.seg--pen{fill:rgba(250,204,21,.82)}');
    svg.push('</style>');

    var xt = ticks(minV, maxV, 6);
    for (i = 0; i < xt.length; i++) {
      var xv = xt[i];
      var xp = xToPx(xv);
      svg.push(line(xp, padT, xp, padT + plotHBars, 'adv-grid'));
      svg.push(text(xp, padT + plotHBars + 26, 'adv-tick', esc(xv.toFixed(2)), 'middle'));
    }

    var x0 = xToPx(0);
    svg.push(line(x0, padT, x0, padT + plotHBars, 'adv-zero'));

    svg.push('<g class="bars">');

    for (i = 0; i < rows.length; i++) {
      var r = rows[i] || {};
      var yM = yMid(i);
      var yTop = yM - barH / 2;

      var nameFull = (r.name || ('Player ' + (r.player_id || i)));
      var nameLbl = playerLabel(nameFull);
      var pos = (r.pos || '').toUpperCase();
      var war = (r.war == null || !isFinite(parseFloat(r.war))) ? '' : String(parseFloat(r.war).toFixed(2));
      var gar = num(r.gar, 0);

      var comps = [
        { v: num(r.ev, 0),  cls: 'seg seg--ev'  },
        { v: num(r.pp, 0),  cls: 'seg seg--pp'  },
        { v: num(r.sh, 0),  cls: 'seg seg--sh'  },
        { v: num(r.pen, 0), cls: 'seg seg--pen' }
      ];

      var curPos = 0;
      for (var j = 0; j < comps.length; j++) {
        if (comps[j].v <= 0) continue;
        var xA = xToPx(curPos);
        var xB = xToPx(curPos + comps[j].v);
        svg.push(rect(xA, yTop, xB - xA, barH, comps[j].cls, ' rx="3" ry="3"'));
        curPos += comps[j].v;
      }

      var curNeg = 0;
      for (j = 0; j < comps.length; j++) {
        if (comps[j].v >= 0) continue;
        var xC = xToPx(curNeg);
        var xD = xToPx(curNeg + comps[j].v);
        svg.push(rect(xC, yTop, xD - xC, barH, comps[j].cls, ' rx="3" ry="3"'));
        curNeg += comps[j].v;
      }

      svg.push(
        '<rect class="hit" x="' + padL + '" y="' + (yTop - 10) + '" width="' + plotW + '" height="' + (barH + 20) + '"' +
        ' data-name="' + esc(nameFull) + '"' +
        ' data-pos="' + esc(pos) + '"' +
        ' data-gar="' + esc(gar.toFixed(2)) + '"' +
        ' data-war="' + esc(war) + '"' +
        ' data-ev="' + esc(num(r.ev,0).toFixed(2)) + '"' +
        ' data-pp="' + esc(num(r.pp,0).toFixed(2)) + '"' +
        ' data-sh="' + esc(num(r.sh,0).toFixed(2)) + '"' +
        ' data-pen="' + esc(num(r.pen,0).toFixed(2)) + '"' +
        '/>'
      );

      var insideX = x0 + L.insideNamePad;
      var maxX = padL + plotW - 8;
      if (insideX > maxX) insideX = maxX;
      var insideCls = 'adv-name-in' + (L.insideNameHalo ? ' adv-name-in--halo' : '');
      svg.push(text(insideX, yM + (L.nameSize * 0.35), insideCls, esc(nameLbl), 'start'));
    }

    svg.push('</g></svg>');
    plotHost.innerHTML = svg.join('');

    var tip = ensureTip();
    var svgEl = plotHost.querySelector('svg');

    function showTip(hit, x, y) {
      var nm  = hit.getAttribute('data-name') || '';
      var pos = hit.getAttribute('data-pos') || '';
      var garV = hit.getAttribute('data-gar') || '';
      var warV = hit.getAttribute('data-war') || '';
      var ev  = hit.getAttribute('data-ev') || '';
      var pp  = hit.getAttribute('data-pp') || '';
      var sh  = hit.getAttribute('data-sh') || '';
      var pen = hit.getAttribute('data-pen') || '';

      var html = '';
      html += '<div class="adv-tooltip__name">' + esc(nm) + (pos ? (' <span style="opacity:.7">(' + esc(pos) + ')</span>') : '') + '</div>';
      html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">GAR</span><span class="adv-tooltip__v">' + esc(garV || '—') + '</span></div>';
      if (warV) html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">WAR</span><span class="adv-tooltip__v">' + esc(warV) + '</span></div>';
      html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">EV</span><span class="adv-tooltip__v">' + esc(ev) + '</span></div>';
      html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">PP</span><span class="adv-tooltip__v">' + esc(pp) + '</span></div>';
      html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">SH</span><span class="adv-tooltip__v">' + esc(sh) + '</span></div>';
      html += '<div class="adv-tooltip__row"><span class="adv-tooltip__k">Pen</span><span class="adv-tooltip__v">' + esc(pen) + '</span></div>';

      tip.innerHTML = html;
      tip.style.display = 'block';

      var vx = x + 14, vy = y + 14;
      var r = tip.getBoundingClientRect();
      var maxX = window.innerWidth - r.width - 10;
      var maxY = window.innerHeight - r.height - 10;
      tip.style.left = clamp(vx, 10, maxX) + 'px';
      tip.style.top  = clamp(vy, 10, maxY) + 'px';
    }

    function hideTip() { tip.style.display = 'none'; }

    svgEl.addEventListener('mousemove', function (e) {
      var t = e.target;
      if (!t || !t.classList || !t.classList.contains('hit')) { hideTip(); return; }
      showTip(t, e.clientX, e.clientY);
    });
    svgEl.addEventListener('mouseleave', hideTip);

    var lastTap = null;
    svgEl.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.classList || !t.classList.contains('hit')) { hideTip(); lastTap = null; return; }
      if (lastTap === t && tip.style.display === 'block') { hideTip(); lastTap = null; return; }
      lastTap = t;
      showTip(t, e.clientX, e.clientY);
    }, false);
  }

  function boot(root) {
    root = root || document;
    var els = root.querySelectorAll('.js-adv-gar');
    for (var i = 0; i < els.length; i++) renderGar(els[i]);
  }

  window.SJMS_GAR_BOOT = boot;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { boot(document); });
  } else {
    boot(document);
  }
})();
