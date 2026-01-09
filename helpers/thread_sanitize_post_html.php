<?php
//======================================
// File: public/helpers/sanitize_post_html.php
// Description: Sanitize user post HTML (safe allowlist) without external libs.
//======================================

if (!function_exists('sjms_sanitize_post_html')) {

  function sjms_sanitize_post_html($html) {
    $html = (string)$html;

    // If the editor sent encoded HTML (e.g. &lt;img&gt;), decode once
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Quick normalize
    $html = trim($html);
    if ($html === '') return '';

    // Allowed tags and attributes
    $allowedTags = [
      'p' => [],
      'br' => [],
      'strong' => [],
      'em' => [],
      'u' => [],
      'blockquote' => [],
      'ul' => [],
      'ol' => [],
      'li' => [],
      'code' => [],
      'pre' => [],
      'span' => ['style'],
      'a' => ['href','title','target','rel'],
      'img' => ['src','alt','title','style','width','height','loading','decoding'],
    ];

    // Allow only these CSS props (and only safe values)
    $allowedCssProps = ['max-height','max-width','width','height'];

    // Optional: restrict remote img hosts
    $imgHostWhitelist = [
      'media.tenor.com',
      'tenor.com',
      'media.giphy.com',
      'i.imgur.com',
      'cdn.discordapp.com',
      'nhlwatercooler.com',
      'www.nhlwatercooler.com',
    ];

    libxml_use_internal_errors(true);

    $doc = new DOMDocument();
    // Wrap so we can extract innerHTML cleanly
    $wrapped = '<!doctype html><html><body><div id="__wrap__">' . $html . '</div></body></html>';
    $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $wrap = $doc->getElementById('__wrap__');
    if (!$wrap) return '';

    // Remove comments
    $xpath = new DOMXPath($doc);
    foreach ($xpath->query('//comment()') as $c) {
      $c->parentNode->removeChild($c);
    }

    // Walk all elements inside wrapper
    $nodes = [];
    foreach ($wrap->getElementsByTagName('*') as $el) $nodes[] = $el;

    foreach ($nodes as $el) {
      $tag = strtolower($el->nodeName);

      // Drop forbidden containers outright
      if (in_array($tag, ['script','style','iframe','object','embed','svg','math','form','input','button','textarea'], true)) {
        $el->parentNode->removeChild($el);
        continue;
      }

      // Not allowed tag: unwrap (keep text/children)
      if (!isset($allowedTags[$tag])) {
        unwrap_node($el);
        continue;
      }

      // Clean attributes
      $allowedAttrs = $allowedTags[$tag];
      if ($el->hasAttributes()) {
        $remove = [];
        foreach ($el->attributes as $attr) {
          $name = strtolower($attr->name);

          // Remove all on* handlers
          if (strpos($name, 'on') === 0) { $remove[] = $name; continue; }

          if (!in_array($name, $allowedAttrs, true)) {
            $remove[] = $name;
          }
        }
        foreach ($remove as $name) $el->removeAttribute($name);
      }

      // URL sanitization
      if ($tag === 'a') {
        $href = trim((string)$el->getAttribute('href'));
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($href === '' || is_bad_url($href)) {
          // no valid link -> unwrap the <a>
          unwrap_node($el);
          continue;
        }

        // Force safe link behavior
        $el->setAttribute('target', '_blank');
        $el->setAttribute('rel', 'nofollow noopener noreferrer');
      }

      if ($tag === 'img') {
        $src = trim((string)$el->getAttribute('src'));
        $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($src === '' || is_bad_url($src)) {
          $el->parentNode->removeChild($el);
          continue;
        }

        // Allow relative URLs on your site
        if (!is_relative_url($src)) {
          $host = parse_url($src, PHP_URL_HOST);
          $host = strtolower((string)$host);
          if ($host && !in_array($host, $imgHostWhitelist, true)) {
            // Not trusted host -> remove image
            $el->parentNode->removeChild($el);
            continue;
          }
        }

        // Optional: add modern perf attrs
        $el->setAttribute('loading', 'lazy');
        $el->setAttribute('decoding', 'async');
      }

      // Style sanitization
      if ($el->hasAttribute('style')) {
        $style = (string)$el->getAttribute('style');
        $cleanStyle = sanitize_style($style, $allowedCssProps);
        if ($cleanStyle === '') $el->removeAttribute('style');
        else $el->setAttribute('style', $cleanStyle);
      }
    }

    // Extract sanitized innerHTML of wrapper
    $out = '';
    foreach ($wrap->childNodes as $child) {
      $out .= $doc->saveHTML($child);
    }

    $out = trim($out);

    // If nothing meaningful remains, return empty
    if (trim(strip_tags($out)) === '' && stripos($out, '<img') === false) {
      return '';
    }

    return $out;
  }

  function unwrap_node(DOMNode $node) {
    $parent = $node->parentNode;
    if (!$parent) return;

    while ($node->firstChild) {
      $parent->insertBefore($node->firstChild, $node);
    }
    $parent->removeChild($node);
  }

  function is_relative_url($url) {
    return (bool)preg_match('~^/[^/]|^[^:]+$~', $url); // "/path" or "path"
  }

  function is_bad_url($url) {
    $u = ltrim($url);
    return preg_match('~^(javascript:|vbscript:|data:)~i', $u);
  }

  function sanitize_style($style, array $allowedProps) {
    $style = trim((string)$style);
    if ($style === '') return '';

    $parts = preg_split('/\s*;\s*/', $style);
    $keep = [];

    foreach ($parts as $decl) {
      if ($decl === '' || strpos($decl, ':') === false) continue;
      [$prop, $val] = array_map('trim', explode(':', $decl, 2));
      $prop = strtolower($prop);

      if (!in_array($prop, $allowedProps, true)) continue;

      // Block url(), expression(), etc
      if (preg_match('~url\s*\(|expression\s*\(~i', $val)) continue;

      // Allow only simple size values: number + px/%/em/rem/vh/vw or "auto"
      if (!preg_match('~^(auto|0|[0-9]+(\.[0-9]+)?(px|%|em|rem|vh|vw)?)$~i', $val)) continue;

      $keep[] = $prop . ':' . $val;
    }

    return implode(';', $keep);
  }
}
