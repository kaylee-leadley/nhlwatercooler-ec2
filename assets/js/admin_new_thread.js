// assets/js/admin_new_thread.js
document.addEventListener('DOMContentLoaded', function () {
  if (typeof $ === 'undefined') {
    return;
  }

  var $field = $('#description_html');
  if (!$field.length) {
    return;
  }

  // Pull colors from CSS custom properties so everything stays in sync
  var rootStyles = getComputedStyle(document.documentElement);

  function cssVar(name, fallback) {
    var v = rootStyles.getPropertyValue(name);
    v = v ? v.trim() : '';
    return v || fallback;
  }

  var sharkTeal   = cssVar('--shark-teal',   '#007b8a');
  var sharkOrange = cssVar('--shark-orange', '#f59b2a');
  var sharkGreen  = cssVar('--shark-green',  '#008f4f');
  var sharkDeep   = cssVar('--shark-deep',   '#003f51');
  var sharkSlate  = cssVar('--shark-slate',  '#62757f');
  var textMain    = cssVar('--text-main',    '#f5fbff');
  var bgMain      = cssVar('--bg-main',      '#00141c');

  $field.summernote({
    placeholder: 'Write your gameday intro, hype, or notes here…',
    height: 260,
    minHeight: 180,
    maxHeight: 500,

    // ✅ Add style dropdown for headings
    toolbar: [
      ['style', ['style']],                        // Paragraph / Heading dropdown
      ['font',  ['bold', 'italic', 'underline', 'clear', 'color']],
      ['para',  ['ul', 'ol', 'paragraph']],
      ['insert',['link']],
      ['view',  ['codeview']]
    ],

    // ✅ Restrict the styles it offers in that dropdown
    styleTags: [
      { title: 'Paragraph', tag: 'p',  className: '', value: 'p'  },
      { title: 'Heading 2', tag: 'h2', className: '', value: 'h2' },
      { title: 'Heading 3', tag: 'h3', className: '', value: 'h3' }
    ],

    // Restrict color palette to SJST colors
    colors: [[
      sharkTeal,
      sharkOrange,
      sharkGreen,
      sharkDeep,
      sharkSlate,
      textMain,
      bgMain
    ]]
  });
});
