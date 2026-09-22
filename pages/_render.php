<?php
// pages/_render.php - shared HTML partials (functions return strings).
// Tables + forms only. No JavaScript, no cookies; state travels in URLs.

function render_catbar($active) {
  $cats = array('mind', 'film', 'gyerekeknek', 'sport', 'termeszet', 'zene');
  $s = '<div class="catbar">';
  foreach ($cats as $c) {
    // category switch resets to today, keeps the channel selection
    $s .= '<a href="' . h(u('/', array('cat' => $c === 'mind' ? null : $c, 'date' => null, 'h' => null))) . '"'
      . ($c === $active ? ' class="active"' : '') . '>'
      . h(web_cat_label($c)) . '</a>';
  }
  return $s . '</div>';
}

function channel_path($slug, $date = null) {
  $p = '/tvmusor/' . $slug;
  if ($date !== null && $date !== '') {
    $p .= '/' . $date;
  }
  return $p;
}

function detail_path($slug, $start_utc) {
  return '/musor/' . $slug . '/' . (int)$start_utc;
}

function render_datenav($base_path, $date) {
  $tz = new DateTimeZone('Europe/Budapest');
  $d = new DateTime($date, $tz);
  $prev = clone $d;
  $prev->modify('-1 day');
  $next = clone $d;
  $next->modify('+1 day');
  $s = '<p class="hoursan">Nap: '
    . '<a href="' . h(u($base_path($prev->format('Y-m-d')))) . '">Előző nap</a> | '
    . '<a href="' . h(u($base_path(web_today()))) . '">Ma</a> | '
    . '<a href="' . h(u($base_path($next->format('Y-m-d')))) . '">Következő nap</a>'
    . ' · ' . h(web_now_text()) . '</p>';
  return $s;
}

// GET filter form: channel checkboxes + date + submit. Token/provider/view
// travel as hidden fields (private instance, URL-only state).
function render_filter_form($action, $slugs, $sel, $names, $date) {
  $s = '<form method="get" action="' . h(web_base($GLOBALS['CFG']) . $action) . '" class="filterbox">';
  $s .= '<fieldset class="checks"><legend>Csatornák</legend>';
  foreach ($slugs as $slug) {
    $s .= '<label><input type="checkbox" name="ch[]" value="' . h($slug) . '"'
      . (in_array($slug, $sel, true) ? ' checked' : '') . '> '
      . h(isset($names[$slug]) ? $names[$slug] : $slug) . '</label>';
  }
  $s .= '</fieldset>';
  $s .= '<label>Dátum (ÉÉÉÉ-HH-NN): <input type="text" name="date" size="10" value="'
    . h($date) . '"></label> ';
  $s .= hidden_state_fields(array('ch', 'ch[]', 'date', 'show', 'detail'));
  $s .= '<input type="submit" value="Mutat">';
  $s .= '</form>';
  return $s;
}

function hidden_state_fields($skip = array()) {
  $s = '';
  foreach (array('provider', 'view', 'token', 'cat', 'zoom', 'theme', 'refresh', 'offset', 'font', 'watch') as $k) {
    if (in_array($k, $skip, true)) {
      continue;
    }
    if (isset($_GET[$k]) && $_GET[$k] !== '') {
      $s .= '<input type="hidden" name="' . h($k) . '" value="'
        . h((string)$_GET[$k]) . '">';
    }
  }
  return $s;
}

function render_chan_index($slugs, $names) {
  $s = '<ul class="chanindex">';
  foreach ($slugs as $slug) {
    $s .= '<li><a href="' . h(u(channel_path($slug), array(), null, 'now')) . '">'
      . h(isset($names[$slug]) ? $names[$slug] : $slug) . '</a></li>';
  }
  return $s . '</ul>';
}

// Rich hover tooltip: time, title, episode, subtitle, meta, then description.
// Newlines render as multiline tooltips in browsers (no JS needed).
function prog_tooltip($r) {
  $lines = array();
  $lines[] = web_hm($r['start_utc']) . '-' . web_hm($r['stop_utc']);
  $lines[] = $r['title'];
  if (!empty($r['subtitle'])) {
    $lines[] = $r['subtitle'];
  }
  if (!empty($r['episode'])) {
    $lines[] = 'Epizód: ' . $r['episode'];
  }
  $meta = array();
  if (!empty($r['category'])) {
    $meta[] = $r['category'];
  }
  if (!empty($r['rating'])) {
    $meta[] = $r['rating'] . '+';
  }
  if (!empty($r['year'])) {
    $meta[] = $r['year'];
  }
  if (!empty($r['star'])) {
    $meta[] = '*' . $r['star'];
  }
  if (count($meta)) {
    $lines[] = implode(' | ', $meta);
  }
  if (!empty($r['descr'])) {
    $lines[] = (string)$r['descr']; // full text, no truncation
  }
  return implode("\n", $lines);
}

// Programme start time: plain text, unless the provider stored a port.hu
// adatlap link (film_url) - then the time opens it in a new window while
// the title keeps pointing at the local detail page.
function prog_time_html($r) {
  $t = h(web_hm($r['start_utc']));
  $u = isset($r['film_url']) ? trim((string)$r['film_url']) : '';
  if ($u === '') {
    return $t;
  }
  if (strpos($u, 'http://') !== 0 && strpos($u, 'https://') !== 0) {
    $u = 'https://port.hu' . ($u !== '' && $u[0] !== '/' ? '/' . $u : $u);
  }
  return '<a href="' . h($u) . '" target="_blank">' . $t . '</a>';
}

// Horizontal view: tvmustra-style full-day timeline. Each channel row is a
// relative strip; programmes are absolutely positioned at exact left%/width%
// over 00-24 - every show of the day is rendered, the bottom scrollbar
// reveals them (no window, no JS). Rows cannot drift: every strip is anchored
// to its own row. The now-line is one absolute marker per row at the same
// left%, like tvmustra's .now-marker.
function epg_table_h($groups, $names, $now, $date, $days = 1) {
  if (!count($groups)) {
    return '<p>Nincs műsoradat erre a napra.</p>';
  }
  $days = max(1, min(2, (int)$days)); // 1-day or today+tomorrow
  $tz = new DateTimeZone('Europe/Budapest');
  $mid = new DateTime($date . ' 00:00:00', $tz);
  $ws = $mid->getTimestamp();
  $we = $ws + 86400 * $days;
  $wlen = 86400 * $days;
  $in_day = (date('Y-m-d', $now) === $date);
  $in_window = ($in_day && $now >= $ws && $now < $we);
  $pos = function ($ts) use ($ws, $wlen) {
    $p = ((int)$ts - $ws) / $wlen * 100;
    if ($p < 0) { $p = 0; }
    if ($p > 100) { $p = 100; }
    return round($p, 2);
  };
  $s = '';
  $s .= '<div class="epg-fill"><div class="epg-wrap" style="min-width:'
    . web_zoom_px() . 'px;">';
  if ($in_window) {
    // jump target: an invisible band from the now-line rightward, 66 viewport
    // widths wide. Minimal-scroll then parks the line ~1/3 from the left
    // (a wide strip alone would land its right edge at the screen edge).
    // autofocus re-runs the jump on EVERY page load (fresh nav AND reload -
    // plain fragments only fire on fresh navs), tabindex keeps it out of
    // keyboard tab order. Unknown attributes are ignored by old browsers.
    $s .= '<div id="now" tabindex="-1" autofocus="autofocus" style="position:absolute;top:0;left:' . $pos($now)
      . '%;width:66vw;height:12px;font-size:0;line-height:0;"></div>';
  }
  $s .= '<table class="epgtable" cellpadding="0" cellspacing="0">';
  // header: 24 equal hour cells per day (2nd day dimmed + dated)
  $nh = 24 * $days;
  $now_h = (int)date('G', $now);
  $s .= '<tr><td class="chan"></td><td class="tl"><table class="hours" cellpadding="0" cellspacing="0"><tr>';
  for ($dd = 0; $dd < $days; $dd++) {
    $day_label = date('m-d', $ws + $dd * 86400);
    for ($hh = 0; $hh < 24; $hh++) {
      $s .= '<td width="' . round(100 / $nh, 2) . '%"'
        . ($in_day && $dd === 0 && $hh === $now_h ? ' class="nowh"' : '')
        . ($dd > 0 ? ' class="nextday"' : '') . '>'
        . sprintf('%02d', $hh);
      if ($dd > 0 && $hh === 0) {
        $s .= '<br><span class="nowt">' . h($day_label) . '</span>';
      }
      if ($in_window && $dd === 0 && $hh === $now_h) {
        $s .= '<br><span class="nowt">▼ ' . h(web_hm($now)) . '</span>';
      }
      $s .= '</td>';
    }
  }
  $s .= '</tr></table></td></tr>';
  $marker = '';
  if ($in_window) {
    $marker = '<div class="now-marker" style="left:' . $pos($now) . '%;"></div>';
  }
  $ri = 0;
  $watch = function_exists('web_watch_parse')
    ? web_watch_parse(isset($_GET['watch']) ? $_GET['watch'] : '') : array();
  foreach ($groups as $slug => $rows) {
    $zb = ($ri % 2 === 0) ? 'zb-even' : 'zb-odd';
    $ri++;
    $s .= '<tr class="' . $zb . '"><td class="chan"><a href="' . h(u(channel_path($slug), array(), null, 'now')) . '">'
      . h(isset($names[$slug]) ? $names[$slug] : $slug) . '</a></td>'
      . '<td class="tl"><div class="tlrel">';
    foreach ($rows as $r) {
      if ($r['stop_utc'] <= $ws || $r['start_utc'] >= $we) {
        continue; // outside the day
      }
      $cs = max((int)$r['start_utc'], $ws);
      $ce = min((int)$r['stop_utc'], $we);
      if ($ce <= $cs) {
        continue;
      }
      $cls = epg_classify($r['start_utc'], $r['stop_utc'], $now);
      $hit = (count($watch) && function_exists('web_watch_match')
        && web_watch_match($r, $watch)) ? ' hit' : '';
      $w = round($pos($ce) - $pos($cs), 2);
      if ($w <= 0) {
        continue;
      }
      $s .= '<div class="prog-item ' . $cls . $hit . '"'
        . ' style="left:' . $pos($cs) . '%;width:' . $w . '%;"'
        . ' title="' . h(prog_tooltip($r)) . '">'
        . '<div class="prog-time">' . prog_time_html($r) . '</div>'
        . '<div class="prog-title"><a href="'
        . h(u(detail_path($slug, $r['start_utc']))) . '">'
        . h($r['title']) . '</a></div>'
        . '</div>';
    }
    $s .= $marker . '</div></td></tr>';
  }
  return $s . '</table></div></div>';
}

// Vertical view: ONE wide multi-column table (no wrapping). Every channel
// is a column; with many channels the page scrolls sideways. Detail level
// follows ?zoom=: 0|1 = time+title, 2 (default) = +episode/subtitle,
// 3|4 = +description. Hover tooltip identical to the horizontal view.
function epg_list_v($groups, $names, $now, $zoom = null) {
  if (!count($groups)) {
    return '<p>Nincs műsoradat erre a napra.</p>';
  }
  if ($zoom === null) {
    $zoom = function_exists('web_zoom_raw') ? web_zoom_raw() : null;
  }
  $level = ($zoom === '0' || $zoom === '1') ? 1 : (($zoom === '3' || $zoom === '4') ? 3 : 2);
  $colw_px = function_exists('web_zoom_col_px') ? web_zoom_col_px($zoom) : 170;
  $minw = max(640, count($groups) * $colw_px);
  $colw = round(100 / max(1, count($groups)), 2);
  $s = '<div class="epg-fill"><table class="vgrid" style="min-width:'
    . $minw . 'px;" cellpadding="0" cellspacing="0"><tr>';
  $ci = 0;
  foreach ($groups as $slug => $rows) {
    $zb = ($ci % 2 === 0) ? 'zb-even' : 'zb-odd';
    $ci++;
    $s .= '<th class="' . $zb . '" width="' . $colw . '%" id="ch-' . h($slug) . '"><a href="'
      . h(u(channel_path($slug), array(), null, 'now')) . '">'
      . h(isset($names[$slug]) ? $names[$slug] : $slug) . '</a></th>';
  }
  $s .= '</tr><tr>';
  $anchor_done = false;
  $watch = function_exists('web_watch_parse')
    ? web_watch_parse(isset($_GET['watch']) ? $_GET['watch'] : '') : array();
  $ci = 0;
  foreach ($groups as $slug => $rows) {
    $zb = ($ci % 2 === 0) ? 'zb-even' : 'zb-odd';
    $ci++;
    $s .= '<td class="' . $zb . '" width="' . $colw . '%"><ul class="progs">';
    foreach ($rows as $r) {
      $cls = epg_classify($r['start_utc'], $r['stop_utc'], $now);
      $hit = (count($watch) && function_exists('web_watch_match')
        && web_watch_match($r, $watch)) ? ' hit' : '';
      $anchor = '';
      if ($cls === 'live' && !$anchor_done) {
        $anchor = ' id="now"';
        $anchor_done = true;
      }
      $s .= '<li class="' . $cls . $hit . '"' . $anchor
        . ' title="' . h(prog_tooltip($r)) . '">'
        . '<span class="ptime">' . prog_time_html($r) . '</span> '
        . '<span class="ptitle"><a href="' . h(u(detail_path($slug, $r['start_utc']))) . '">'
        . h($r['title']) . '</a></span>';
      if ($level >= 2 && !empty($r['subtitle'])) {
        $s .= '<br><i>' . h($r['subtitle']) . '</i>';
      }
      if ($level >= 2 && !empty($r['episode'])) {
        $s .= '<br><span class="ep">Epizód: ' . h($r['episode']) . '</span>';
      }
      if ($level >= 3 && !empty($r['descr'])) {
        $d = (string)$r['descr'];
        if (function_exists('mb_strlen') && mb_strlen($d, 'UTF-8') > 250) {
          $d = mb_substr($d, 0, 250, 'UTF-8') . '…';
        } elseif (strlen($d) > 250) {
          $d = substr($d, 0, 250) . '…';
        }
        $s .= '<br><span class="epg-desc">' . h($d) . '</span>';
      }
      $s .= '</li>';
    }
    $s .= '</ul></td>';
  }
  return $s . '</tr></table></div>';
}
