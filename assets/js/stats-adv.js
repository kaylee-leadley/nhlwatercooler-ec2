/* assets/js/stats-adv.js
 *
 * Advanced Stats page renderer (optimized):
 * - Fetch once, normalize once, sort once
 * - Lazy render / paginate to avoid lag with 600+ players
 *
 * Desktop:
 * - Sortable table (click headers)
 *
 * Mobile:
 * - Cards + mobile sort UI
 *
 * Expects:
 *   #stats-team, #stats-state, #stats-season, #stats-min-gp
 *   #stats-table (with th[data-sort-key])
 *   #adv-meta, #stats-empty
 *   #adv-cards (cards container)
 *
 * APIs:
 *   /api/stats_adv_players.php
 *   /api/overall_impact_score.php
 */

(function () {
  function $(id) { return document.getElementById(id); }

  function esc(s) {
    s = (s == null) ? '' : String(s);
    return s.replace(/[&<>"']/g, function (c) {
      return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]);
    });
  }

  function num(v) {
    var n = parseFloat(v);
    return isFinite(n) ? n : null;
  }

  function iNum(v) {
    var n = parseInt(v, 10);
    return isFinite(n) ? n : null;
  }

  function fmtDec(v, d) {
    var n = num(v);
    if (n == null) return '—';
    d = (d == null) ? 2 : d;
    return n.toFixed(d);
  }

  function fmtPct(v) {
    var n = num(v);
    if (n == null) return '—';
    return n.toFixed(1);
  }

  function fmtToi(sec) {
    var s = parseInt(sec, 10);
    if (!isFinite(s) || s <= 0) return '—';
    var m = Math.floor(s / 60);
    var r = s % 60;
    return m + ':' + (r < 10 ? ('0' + r) : r);
  }

  function apiUrl(path, paramsObj) {
    var p = new URLSearchParams();
    for (var k in paramsObj) {
      if (!Object.prototype.hasOwnProperty.call(paramsObj, k)) continue;
      var v = paramsObj[k];
      if (v == null) continue;
      if (String(v) === '') continue;
      p.set(k, String(v));
    }
    return path + '?' + p.toString();
  }

  function fetchJson(url) {
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (txt) {
          var data = null;
          try { data = JSON.parse(txt); } catch (e) {}
          if (!r.ok) {
            var msg = (data && (data.detail || data.error)) ? (data.detail || data.error) : txt;
            throw new Error('HTTP ' + r.status + ' ' + url + ': ' + msg);
          }
          if (!data) throw new Error('Non-JSON from ' + url + ': ' + txt.slice(0, 200));
          return data;
        });
      });
  }

  function setLoading(tbody) {
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="99" style="text-align:center;padding:18px">Loading…</td></tr>';
  }

  function setError(tbody, msg) {
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="99" style="text-align:center;padding:18px">Error: ' + esc(msg) + '</td></tr>';
  }

  function setEmpty(tbody, emptyEl, msg) {
    if (tbody) tbody.innerHTML = '';
    if (emptyEl) {
      emptyEl.hidden = false;
      emptyEl.textContent = msg || 'No stats found for this selection.';
    }
  }

  function hideEmpty(emptyEl) {
    if (emptyEl) emptyEl.hidden = true;
  }

  /* =========================================================
   * Pagination / lazy rendering
   * ======================================================= */

  var PAGE_SIZE = 120;

  function isMobileView() {
    return window.matchMedia && window.matchMedia('(max-width: 860px)').matches;
  }

  function ensureLoadMoreButton(ctx) {
    var btn = document.getElementById('adv-load-more');
    if (btn) return btn;

    btn = document.createElement('button');
    btn.id = 'adv-load-more';
    btn.type = 'button';
    btn.className = 'button-ghost';
    btn.textContent = 'Load more';
    btn.style.margin = '14px auto 0';
    btn.style.display = 'none';

    btn.addEventListener('click', function () {
      renderNextPage(ctx);
    });

    // Prefer inserting after cards container
    if (ctx.cardsEl && ctx.cardsEl.parentNode) {
      ctx.cardsEl.parentNode.insertBefore(btn, ctx.cardsEl.nextSibling);
    } else if (ctx.table && ctx.table.parentNode) {
      ctx.table.parentNode.appendChild(btn);
    } else {
      document.body.appendChild(btn);
    }

    return btn;
  }

  function ensureSentinel(ctx) {
    var s = document.getElementById('adv-sentinel');
    if (s) return s;

    s = document.createElement('div');
    s.id = 'adv-sentinel';
    s.style.height = '1px';
    s.style.width = '100%';

    var btn = ensureLoadMoreButton(ctx);
    btn.parentNode.insertBefore(s, btn); // sentinel right before button
    return s;
  }

  function wireAutoLoad(ctx) {
    if (!('IntersectionObserver' in window)) return;

    if (ctx._io) {
      try { ctx._io.disconnect(); } catch (e) {}
      ctx._io = null;
    }

    var sentinel = ensureSentinel(ctx);

    ctx._io = new IntersectionObserver(function (entries) {
      if (!entries || !entries.length) return;
      if (!entries[0].isIntersecting) return;

      var btn = ensureLoadMoreButton(ctx);
      if (btn && btn.style.display !== 'none') {
        renderNextPage(ctx);
      }
    }, { root: null, rootMargin: '700px 0px', threshold: 0 });

    ctx._io.observe(sentinel);
  }

  /* =========================================================
   * Sorting config (table + mobile share this)
   * ======================================================= */

  var SORT_OPTIONS = [
    { key: 'impact',  label: 'Impact' },
    { key: 'cf60',    label: 'CF/60' },
    { key: 'ca60',    label: 'CA/60' },
    { key: 'cfpct',   label: 'CF%' },

    { key: 'xgf60',   label: 'xGF/60' },
    { key: 'xga60',   label: 'xGA/60' },
    { key: 'xgfpct',  label: 'xGF%' },

    { key: 'scf60',   label: 'SCF/60' },
    { key: 'hdcf60',  label: 'HDCF/60' },

    { key: 'gar',     label: 'GAR' },
    { key: 'war',     label: 'WAR' }
  ];

  function sortDefaultDir(key) {
    if (key === 'player' || key === 'pos') return 'asc';
    return 'desc';
  }

  function getSortValue(row, key) {
    if (!row) return null;
    switch (key) {
      case 'player': return (row.player_name || '').toLowerCase();
      case 'pos':    return (row.player_pos || '');
      case 'gp':     return row.gp_num;
      case 'toi':    return row.toi_num;
      case 'impact': return row.impact_num;

      case 'cf60':   return row.cf60_num;
      case 'ca60':   return row.ca60_num;
      case 'cfpct':  return row.cfpct_num;

      case 'xgf60':  return row.xgf60_num;
      case 'xga60':  return row.xga60_num;
      case 'xgfpct': return row.xgfpct_num;

      case 'scf60':  return row.scf60_num;
      case 'hdcf60': return row.hdcf60_num;

      case 'gar':    return row.gar_num;
      case 'war':    return row.war_num;
    }
    return null;
  }

  function compareMaybe(a, b, dir) {
    var d = (dir === 'asc') ? 1 : -1;

    var aNum = (typeof a === 'number' && isFinite(a));
    var bNum = (typeof b === 'number' && isFinite(b));

    if (aNum && bNum) return (a - b) * d;

    if (a == null && b == null) return 0;
    if (a == null) return 1;
    if (b == null) return -1;

    return String(a).localeCompare(String(b)) * d;
  }

  function sortRows(rows, sortKey, sortDir) {
    rows.sort(function (ra, rb) {
      var a = getSortValue(ra, sortKey);
      var b = getSortValue(rb, sortKey);

      var cmp = compareMaybe(a, b, sortDir);
      if (cmp !== 0) return cmp;

      var cmp2 = compareMaybe(ra.toi_num, rb.toi_num, 'desc');
      if (cmp2 !== 0) return cmp2;

      return compareMaybe((ra.player_name || '').toLowerCase(), (rb.player_name || '').toLowerCase(), 'asc');
    });
  }

  /* =========================================================
   * Impact map
   * ======================================================= */

  function buildImpactMap(impactJson) {
    var map = Object.create(null);
    if (!impactJson || !impactJson.ok || !Array.isArray(impactJson.rows)) return map;

    for (var i = 0; i < impactJson.rows.length; i++) {
      var r = impactJson.rows[i] || {};
      var pid = (r.player_id == null) ? '' : String(r.player_id);
      if (!pid) continue;

      var val = null;
      if (r.impact_avg != null && isFinite(parseFloat(r.impact_avg))) val = parseFloat(r.impact_avg);
      else if (r.impact_wavg != null && isFinite(parseFloat(r.impact_wavg))) val = parseFloat(r.impact_wavg);
      else if (r.impact_score != null && isFinite(parseFloat(r.impact_score))) val = parseFloat(r.impact_score);

      map[pid] = val;
    }
    return map;
  }

  /* =========================================================
   * Normalize API rows into render rows
   * ======================================================= */

  function normalizeRows(playersJson, impactMap) {
    var rows = (playersJson && playersJson.ok && Array.isArray(playersJson.rows)) ? playersJson.rows : [];
    var out = [];

    for (var i = 0; i < rows.length; i++) {
      var r = rows[i] || {};
      var pid = (r.player_id == null) ? '' : String(r.player_id);
      if (!pid) continue;

      var team = String(r.team_abbr || '').toUpperCase();
      var teamLower = team ? team.toLowerCase() : '';

      var name = r.player_name || (pid ? ('Player #' + pid) : 'Player');
      var pos  = r.player_pos || '';

      var gp = iNum(r.gp);
      var toi = iNum(r.toi_used);

      var impact = (impactMap && impactMap[pid] != null && isFinite(impactMap[pid])) ? impactMap[pid] : null;

      out.push({
        player_id: pid,
        team_abbr: team,
        team_lower: teamLower,

        player_name: name,
        player_pos: pos,

        official_image_src: r.official_image_src || '',
        jersey_number: r.jersey_number || '',

        gp_num: gp,
        toi_num: toi,
        impact_num: (impact != null && isFinite(impact)) ? impact : null,

        cf60_num:   num(r.cf60),
        ca60_num:   num(r.ca60),
        cfpct_num:  num(r.cfpct),

        xgf60_num:  num(r.xgf60),
        xga60_num:  num(r.xga60),
        xgfpct_num: num(r.xgfpct),

        scf60_num:  num(r.scf60),
        hdcf60_num: num(r.hdcf60),

        gar_num:    num(r.gar_total),
        war_num:    num(r.war_total),

        raw: r
      });
    }

    return out;
  }

  /* =========================================================
   * Auto GP gate (default visibility)
   * Hide players unless GP >= max(20, 50% of team games)
   *
   * Team games are estimated as max(GP) observed for that team
   * in the returned rows.
   *
   * Applies only when min_gp dropdown is "All" (0).
   * ======================================================= */

  function buildTeamMaxGpMap(rows) {
    var m = Object.create(null);
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      var t = r.team_abbr || '';
      var gp = (typeof r.gp_num === 'number' && isFinite(r.gp_num)) ? r.gp_num : 0;
      if (!t) continue;
      if (m[t] == null || gp > m[t]) m[t] = gp;
    }
    return m;
  }

  function autoGpThresholdForTeam(teamMaxGp) {
    var games = (typeof teamMaxGp === 'number' && isFinite(teamMaxGp)) ? teamMaxGp : 0;
    var half = Math.ceil(games * 0.5);
    return Math.max(20, half);
  }

  function applyAutoGpGate(rows, filters) {
    var userMin = parseInt(filters.min_gp || 0, 10);
    if (isFinite(userMin) && userMin > 0) {
      return { rows: rows, applied: false, info: null };
    }

    var teamMax = buildTeamMaxGpMap(rows);

    var selectedTeam = String(filters.team || 'all').toLowerCase();
    var singleTeamUpper = (selectedTeam && selectedTeam !== 'all') ? selectedTeam.toUpperCase() : '';
    var singleThreshold = null;

    if (singleTeamUpper && teamMax[singleTeamUpper] != null) {
      singleThreshold = autoGpThresholdForTeam(teamMax[singleTeamUpper]);
    }

    var out = [];
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      var t = r.team_abbr || '';
      var gp = (typeof r.gp_num === 'number' && isFinite(r.gp_num)) ? r.gp_num : 0;

      var th = autoGpThresholdForTeam(teamMax[t] || 0);
      if (gp >= th) out.push(r);
    }

    return {
      rows: out,
      applied: true,
      info: { singleThreshold: singleThreshold }
    };
  }

  /* =========================================================
   * Render helpers (append)
   * ======================================================= */

  function makeCardHtml(row, filters) {
    var metaBits = [];
    if (row.player_pos) metaBits.push(row.player_pos);
    if (row.team_abbr) metaBits.push(row.team_abbr);
    if (row.jersey_number) metaBits.push('#' + String(row.jersey_number));
    metaBits.push(String((filters.state || '5v5')).toUpperCase());

    var img = row.official_image_src ? String(row.official_image_src) : '';

    var html = '';
    html += '<article class="player-card adv-player-card' + (row.team_lower ? (' team-' + esc(row.team_lower)) : '') + '" data-player-id="' + esc(row.player_id) + '" data-team="' + esc(row.team_lower) + '">';

    html += '  <div class="player-card__header">';
    if (img) {
      html += '    <div class="player-card__avatar"><img loading="lazy" src="' + esc(img) + '" alt="' + esc(row.player_name) + '"></div>';
    } else {
      html += '    <div class="player-card__avatar" aria-hidden="true"></div>';
    }
    html += '    <div class="player-card__summary">';
    html += '      <div class="player-card__name">' + esc(row.player_name) + '</div>';
    html += '      <div class="player-card__meta">' + esc(metaBits.join(' · ')) + '</div>';
    html += '    </div>';
    html += '  </div>';

    html += '  <div class="player-card__body">';
    html += '    <div class="player-card__row"><span class="label">GP</span><span class="value">' + esc(String(row.gp_num ?? '—')) + '</span></div>';
    html += '    <div class="player-card__row"><span class="label">TOI</span><span class="value">' + esc(fmtToi(row.toi_num)) + '</span></div>';
    html += '    <div class="player-card__row"><span class="label">Impact</span><span class="value">' + (row.impact_num == null ? '—' : esc(fmtDec(row.impact_num, 3))) + '</span></div>';
    html += '    <div class="player-card__row"><span class="label">CF%</span><span class="value">' + esc(fmtPct(row.cfpct_num)) + '</span></div>';
    html += '    <div class="player-card__row"><span class="label">xGF%</span><span class="value">' + esc(fmtPct(row.xgfpct_num)) + '</span></div>';
    html += '    <div class="player-card__row"><span class="label">SCF/60</span><span class="value">' + esc(fmtDec(row.scf60_num, 2)) + '</span></div>';
    html += '    <div class="player-card__row"><span class="label">HDCF/60</span><span class="value">' + esc(fmtDec(row.hdcf60_num, 2)) + '</span></div>';
    html += '    <div class="player-card__row"><span class="label">GAR</span><span class="value">' + esc(fmtDec(row.gar_num, 3)) + '</span></div>';
    html += '    <div class="player-card__row"><span class="label">WAR</span><span class="value">' + esc(fmtDec(row.war_num, 3)) + '</span></div>';
    html += '  </div>';

    html += '  <div class="adv-card__toggle-wrap">';
    html += '    <button type="button" class="adv-card__toggle" data-action="toggle-more">More stats</button>';
    html += '  </div>';
    html += '  <div class="adv-card__more" hidden></div>';

    html += '  <input type="hidden" class="adv-card__filters" name="adv_card_filters[]" value="' + esc(JSON.stringify({
      team: filters.team,
      state: filters.state,
      season: filters.season,
      min_gp: filters.min_gp
    })) + '">';

    html += '</article>';
    return html;
  }

  function makeTableRowHtml(row) {
    var LOGO_BASE = '/assets/img/logos';

    var logoHtml = row.team_abbr
      ? '<img class="stats-teamlogo" src="' + LOGO_BASE + '/' + esc(row.team_abbr) + '.png" alt="' + esc(row.team_abbr) + '" loading="lazy" onerror="this.style.display=\\\'none\\\'">'
      : '';

    var html = '';
    html += '<tr class="stats-row' + (row.team_lower ? (' team-' + esc(row.team_lower)) : '') + '" data-player-id="' + esc(row.player_id) + '">';
    html += '<td class="col-logo stats-cell stats-cell--logo">' + logoHtml + '</td>';
    html += '<td class="col-player stats-cell stats-cell--player" data-sort="' + esc((row.player_name || '').toLowerCase()) + '">' + esc(row.player_name) + '</td>';
    html += '<td class="col-pos stats-cell stats-cell--pos" data-sort="' + esc(row.player_pos || '') + '">' + esc(row.player_pos || '') + '</td>';
    html += '<td class="col-gp stats-cell stats-cell--gp" data-sort="' + esc(String(row.gp_num ?? '')) + '">' + esc(String(row.gp_num ?? '—')) + '</td>';
    html += '<td class="col-gp stats-cell stats-cell--toi" data-sort="' + esc(String(row.toi_num ?? '')) + '">' + esc(fmtToi(row.toi_num)) + '</td>';

    if (row.impact_num == null) html += '<td class="col-impact stats-cell stats-cell--impact" data-sort="-999999">—</td>';
    else html += '<td class="col-impact stats-cell stats-cell--impact" data-sort="' + row.impact_num.toFixed(6) + '">' + esc(fmtDec(row.impact_num, 3)) + '</td>';

    html += '<td class="col-gp stats-cell" data-sort="' + esc(String(row.cf60_num ?? -999999)) + '">' + esc(fmtDec(row.cf60_num, 2)) + '</td>';
    html += '<td class="col-gp stats-cell" data-sort="' + esc(String(row.ca60_num ?? -999999)) + '">' + esc(fmtDec(row.ca60_num, 2)) + '</td>';
    html += '<td class="col-gp stats-cell" data-sort="' + esc(String(row.cfpct_num ?? -999999)) + '">' + esc(fmtPct(row.cfpct_num)) + '</td>';

    html += '<td class="col-gp stats-cell" data-sort="' + esc(String(row.xgf60_num ?? -999999)) + '">' + esc(fmtDec(row.xgf60_num, 2)) + '</td>';
    html += '<td class="col-gp stats-cell" data-sort="' + esc(String(row.xga60_num ?? -999999)) + '">' + esc(fmtDec(row.xga60_num, 2)) + '</td>';
    html += '<td class="col-gp stats-cell" data-sort="' + esc(String(row.xgfpct_num ?? -999999)) + '">' + esc(fmtPct(row.xgfpct_num)) + '</td>';

    html += '<td class="col-gp stats-cell" data-sort="' + esc(String(row.scf60_num ?? -999999)) + '">' + esc(fmtDec(row.scf60_num, 2)) + '</td>';
    html += '<td class="col-gp stats-cell" data-sort="' + esc(String(row.hdcf60_num ?? -999999)) + '">' + esc(fmtDec(row.hdcf60_num, 2)) + '</td>';

    html += '<td class="col-gp stats-cell" data-sort="' + esc(String(row.gar_num ?? -999999)) + '">' + esc(fmtDec(row.gar_num, 3)) + '</td>';
    html += '<td class="col-gp stats-cell" data-sort="' + esc(String(row.war_num ?? -999999)) + '">' + esc(fmtDec(row.war_num, 3)) + '</td>';

    html += '</tr>';
    return html;
  }

  function clearRender(ctx) {
    if (ctx.tbody) ctx.tbody.innerHTML = '';
    if (ctx.cardsEl) ctx.cardsEl.innerHTML = '';
    ctx._renderIndex = 0;

    var btn = ensureLoadMoreButton(ctx);
    btn.style.display = 'none';
    btn.textContent = 'Load more';
  }

  function updateLoadMoreUi(ctx) {
    var btn = ensureLoadMoreButton(ctx);
    var total = (ctx._rowsAll && ctx._rowsAll.length) ? ctx._rowsAll.length : 0;
    var shown = ctx._renderIndex || 0;

    if (shown >= total) {
      btn.style.display = 'none';
      return;
    }

    btn.style.display = 'block';
    btn.textContent = 'Load more (' + shown + '/' + total + ')';
  }

  function renderNextPage(ctx) {
    if (!ctx || !ctx._rowsAll) return;
    var rowsAll = ctx._rowsAll;
    var start = ctx._renderIndex || 0;
    var end = Math.min(start + PAGE_SIZE, rowsAll.length);
    if (start >= end) {
      updateLoadMoreUi(ctx);
      return;
    }

    var chunk = rowsAll.slice(start, end);
    ctx._renderIndex = end;

    // Only render one mode (major perf win)
    var mobile = isMobileView();

    if (mobile) {
      if (ctx.cardsEl) {
        var htmlCards = '';
        for (var i = 0; i < chunk.length; i++) htmlCards += makeCardHtml(chunk[i], ctx._filters);
        ctx.cardsEl.insertAdjacentHTML('beforeend', htmlCards);
      }
    } else {
      if (ctx.tbody) {
        var htmlRows = '';
        for (var j = 0; j < chunk.length; j++) htmlRows += makeTableRowHtml(chunk[j]);
        ctx.tbody.insertAdjacentHTML('beforeend', htmlRows);
      }
    }

    updateLoadMoreUi(ctx);
  }

  /* =========================================================
   * Meta + links
   * ======================================================= */

  function updateUiAndLinks(team, state, season, minGp) {
    var teamLabel = $('stats-team-label');
    if (teamLabel) teamLabel.textContent = (team === 'all') ? 'All Teams' : String(team).toUpperCase();

    var titleH2 = document.querySelector('.stats-panel__title');
    if (titleH2) {
      var spanHtml = '<span id="stats-team-label">' + esc((team === 'all') ? 'All Teams' : String(team).toUpperCase()) + '</span>';
      titleH2.innerHTML = spanHtml + ' — ' + esc(String(state).toUpperCase()) + ' Advanced Stats';
    }

    var toggleAdvanced = document.querySelector('.stats-toggle a.stats-toggle__button--active');
    var toggleStats = document.querySelector('.stats-toggle a[href^="stats.php"]');
    var toggleStandings = document.querySelector('.stats-toggle a[href^="standings.php"]');

    var pAdv = new URLSearchParams();
    pAdv.set('team', team);
    pAdv.set('state', state);
    if (season) pAdv.set('season', season);
    if (minGp && parseInt(minGp, 10) > 0) pAdv.set('min_gp', String(parseInt(minGp, 10)));

    if (toggleAdvanced) toggleAdvanced.setAttribute('href', 'stats_adv.php?' + pAdv.toString());
    if (toggleStats) toggleStats.setAttribute('href', 'stats.php?team=' + encodeURIComponent(team));
    if (toggleStandings) toggleStandings.setAttribute('href', 'standings.php?team=' + encodeURIComponent(team));
  }

  function updateMeta(metaEl, count, team, state, season, minGp, autoGateInfo) {
    if (!metaEl) return;

    var teamLabel = (team === 'all') ? 'All Teams' : String(team || '').toUpperCase();
    var stateLabel = String(state || '').toUpperCase();
    var seasonLabel = season ? (' • ' + season) : '';
    var gpLabel = (minGp && parseInt(minGp, 10) > 0) ? (' • GP≥' + parseInt(minGp, 10)) : '';

    var autoLabel = '';
    if ((!minGp || parseInt(minGp, 10) === 0) && autoGateInfo && autoGateInfo.singleThreshold) {
      autoLabel = ' • Auto GP≥' + parseInt(autoGateInfo.singleThreshold, 10);
    }

    metaEl.textContent = count + ' players • ' + teamLabel + ' • ' + stateLabel + seasonLabel + gpLabel + autoLabel;
  }

  /* =========================================================
   * Mobile sort UI
   * ======================================================= */

  function ensureMobileSortUI(containerEl, sortState, onChange) {
    if (!containerEl) return null;

    var existing = containerEl.querySelector('.adv-mobile-sort');
    if (existing) return existing;

    var wrap = document.createElement('div');
    wrap.className = 'adv-mobile-sort';

    // label (use label for accessibility)
    var lbl = document.createElement('label');
    lbl.textContent = 'Sort:';
    lbl.style.fontSize = '0.85rem';
    lbl.style.opacity = '0.85';

    // select (no inline styling; CSS should handle)
    var sel = document.createElement('select');
    sel.className = 'adv-mobile-sort__select';
    sel.id = 'adv-mobile-sort-key';
    sel.name = 'adv_sort_key';
    sel.setAttribute('aria-label', 'Sort advanced stats by');

    lbl.setAttribute('for', sel.id);

    for (var i = 0; i < SORT_OPTIONS.length; i++) {
      var opt = document.createElement('option');
      opt.value = SORT_OPTIONS[i].key;
      opt.textContent = SORT_OPTIONS[i].label;
      sel.appendChild(opt);
    }
    sel.value = sortState.key;

    // dir toggle (keep minimal inline; could be CSS too)
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'adv-mobile-sort__dir';
    btn.textContent = (sortState.dir === 'asc') ? '↑' : '↓';

    sel.addEventListener('change', function () {
      onChange(sel.value, null);
    });

    btn.addEventListener('click', function () {
      onChange(null, (sortState.dir === 'asc') ? 'desc' : 'asc');
    });

    wrap.appendChild(lbl);
    wrap.appendChild(sel);
    wrap.appendChild(btn);

    containerEl.appendChild(wrap);

    return wrap;
  }

  function syncMobileSortUI(uiEl, sortState) {
    if (!uiEl) return;
    var sel = uiEl.querySelector('select');
    var btn = uiEl.querySelector('button');
    if (sel) sel.value = sortState.key;
    if (btn) btn.textContent = (sortState.dir === 'asc') ? '↑' : '↓';
  }

  /* =========================================================
   * Table header click sorting
   * ======================================================= */

  function updateHeaderSortMarkers(table, sortState) {
    if (!table) return;
    var ths = table.querySelectorAll('thead th[data-sort-key]');
    for (var i = 0; i < ths.length; i++) {
      var th = ths[i];
      th.classList.remove('sort-asc', 'sort-desc');
      if ((th.getAttribute('data-sort-key') || '') === sortState.key) {
        th.classList.add(sortState.dir === 'asc' ? 'sort-asc' : 'sort-desc');
      }
    }
  }

  function wireHeaderSort(table, sortState, onSortChange) {
    if (!table) return;
    var ths = table.querySelectorAll('thead th[data-sort-key]');
    for (var i = 0; i < ths.length; i++) {
      (function () {
        var th = ths[i];
        var key = th.getAttribute('data-sort-key');
        if (!key) return;

        th.style.cursor = 'pointer';
        th.addEventListener('click', function () {
          if (sortState.key === key) sortState.dir = (sortState.dir === 'asc') ? 'desc' : 'asc';
          else {
            sortState.key = key;
            sortState.dir = sortDefaultDir(key);
          }
          onSortChange(sortState.key, sortState.dir);
        });
      })();
    }
  }

  /* =========================================================
   * Main load + render
   * ======================================================= */

  var _loadToken = 0;

  function loadAndRender(ctx, filters) {
    var token = ++_loadToken;

    setLoading(ctx.tbody);
    hideEmpty(ctx.emptyEl);
    if (ctx.cardsEl) ctx.cardsEl.innerHTML = '';

    // reset paging state
    ctx._rowsAll = null;
    ctx._renderIndex = 0;
    ctx._filters = filters;

    // URL state
    var p = new URLSearchParams(window.location.search);
    p.set('team', filters.team);
    p.set('state', filters.state);

    if (filters.season) p.set('season', filters.season);
    else p.delete('season');

    var mg = parseInt(filters.min_gp || 0, 10);
    if (isFinite(mg) && mg > 0) p.set('min_gp', String(mg));
    else p.delete('min_gp');

    history.replaceState(null, '', window.location.pathname + '?' + p.toString());

    updateUiAndLinks(filters.team, filters.state, filters.season, mg);

    var playersUrl = apiUrl('/api/stats_adv_players.php', {
      team: filters.team,
      state: filters.state,
      season: filters.season,
      min_gp: (mg > 0 ? mg : ''),
      limit: 5000
    });

    var impactUrl = apiUrl('/api/overall_impact_score.php', {
      team: filters.team,
      state_key: filters.state,
      season: filters.season,
      min_gp: (mg > 0 ? mg : ''),
      limit: 5000
    });

    Promise.all([
      fetchJson(playersUrl),
      fetchJson(impactUrl).catch(function (e) {
        console.warn('[adv-stats] impact failed (continuing without impact):', e);
        return { ok: false, rows: [] };
      })
    ]).then(function (pair) {
      if (token !== _loadToken) return;

      var playersJson = pair[0];
      var impactJson = pair[1];

      if (!playersJson || !playersJson.ok) {
        var msg = (playersJson && (playersJson.detail || playersJson.error)) ? (playersJson.detail || playersJson.error) : 'Players API failed';
        setError(ctx.tbody, msg);
        if (ctx.metaEl) ctx.metaEl.textContent = '';
        if (ctx.cardsEl) ctx.cardsEl.innerHTML = '';
        return;
      }

      var impactMap = buildImpactMap(impactJson);
      var rows = normalizeRows(playersJson, impactMap);

      // Default GP gate when dropdown is "All"
      var gate = applyAutoGpGate(rows, filters);
      rows = gate.rows;

      if (!rows.length) {
        var emptyMsg = gate.applied
          ? 'No players meet the default GP threshold (≥ max(20, 50% of team games)).'
          : 'No stats found for this selection.';
        setEmpty(ctx.tbody, ctx.emptyEl, emptyMsg);
        if (ctx.metaEl) ctx.metaEl.textContent = '';
        if (ctx.cardsEl) ctx.cardsEl.innerHTML = '';
        return;
      }

      // sort once, store
      sortRows(rows, ctx.sort.key, ctx.sort.dir);
      ctx._rowsAll = rows;

      // clear both outputs and render first page only (huge perf win)
      clearRender(ctx);

      // Update meta (total count, not just page count)
      updateMeta(ctx.metaEl, rows.length, filters.team, filters.state, filters.season, mg, gate.applied ? gate.info : null);

      // Initial page
      renderNextPage(ctx);

      // Keep UI synced
      syncMobileSortUI(ctx.mobileSortUI, ctx.sort);
      updateHeaderSortMarkers(ctx.table, ctx.sort);

      // Auto-load as user scrolls (still has Load More button)
      wireAutoLoad(ctx);

    }).catch(function (e) {
      if (token !== _loadToken) return;
      console.error('[adv-stats] load failed', e);
      setError(ctx.tbody, e && e.message ? e.message : String(e));
      if (ctx.metaEl) ctx.metaEl.textContent = '';
      if (ctx.cardsEl) ctx.cardsEl.innerHTML = '';
    });
  }

  /* =========================================================
   * Boot
   * ======================================================= */

  function boot() {
    var table = $('stats-table');
    if (!table) return;

    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    var cardsEl = $('adv-cards');
    var emptyEl = $('stats-empty');
    var metaEl = $('adv-meta');

    var teamSel = $('stats-team');
    var stateSel = $('stats-state');
    var seasonSel = $('stats-season');
    var gpSel = $('stats-min-gp');

    // sort state
    var sortState = { key: 'impact', dir: 'desc' };

    var header = document.querySelector('.stats-panel__header');
    var mobileSortUI = ensureMobileSortUI(header, sortState, function (newKey, newDir) {
      if (newKey) {
        sortState.key = newKey;
        sortState.dir = sortDefaultDir(newKey);
      }
      if (newDir) sortState.dir = newDir;

      loadAndRender(ctx, currentFilters());
    });

    function currentFilters() {
      return {
        team: teamSel ? teamSel.value : 'all',
        state: stateSel ? stateSel.value : '5v5',
        season: seasonSel ? seasonSel.value : '',
        min_gp: gpSel ? gpSel.value : '0'
      };
    }

    var ctx = {
      table: table,
      tbody: tbody,
      cardsEl: cardsEl,
      emptyEl: emptyEl,
      metaEl: metaEl,
      sort: sortState,
      mobileSortUI: mobileSortUI,

      _rowsAll: null,
      _renderIndex: 0,
      _filters: null,
      _io: null
    };

    wireHeaderSort(table, sortState, function () {
      loadAndRender(ctx, currentFilters());
    });

    loadAndRender(ctx, currentFilters());

    [teamSel, stateSel, seasonSel, gpSel].forEach(function (sel) {
      if (!sel) return;
      sel.addEventListener('change', function () {
        loadAndRender(ctx, currentFilters());
      });
    });

    // If viewport crosses breakpoint, re-render from stored rows without refetch
    if (window.matchMedia) {
      var mq = window.matchMedia('(max-width: 860px)');
      if (mq && mq.addEventListener) {
        mq.addEventListener('change', function () {
          if (!ctx._rowsAll) return;
          clearRender(ctx);
          renderNextPage(ctx);
          wireAutoLoad(ctx);
        });
      }
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
