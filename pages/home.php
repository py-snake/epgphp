<?php
// pages/home.php - / : the guide, maximized. Top nav bar + EPG grid only.
// Every option (provider/category/channels/date/view) lives on /settings and
// travels here as URL params. No forms, no lists, no clutter.
// Needs: $CFG, $WSTATE, $pdo. Sets: $page, $viewlinks, $WCARRY.

$cat = isset($_GET['cat']) ? strtolower((string)$_GET['cat']) : 'mind';
$kw = web_cat_keywords($cat);
if ($kw === false) {
  $cat = 'mind';
  $kw = null;
}
$date = web_valid_date(isset($_GET['date']) ? $_GET['date'] : null);

$sel = web_selected_channels($pdo, $WSTATE['provider'], $date, $redir); // $redir handled by router
if (!count($sel)) {
  $sel = web_default_channels($pdo, $WSTATE['provider'], 12);
}
$names = web_channel_names($pdo, $WSTATE['provider']);

$GLOBALS['WCARRY'] = array(
  'cat' => $cat === 'mind' ? null : $cat,
  'ch' => implode(',', $sel),
  'date' => $date === web_today() ? null : $date,
  'zoom' => web_zoom_raw(),
  'refresh' => isset($_GET['refresh']) ? (string)$_GET['refresh'] : null,
  'offset' => isset($_GET['offset']) ? (string)$_GET['offset'] : null,
  'font' => web_font_raw(),
  'watch' => (isset($_GET['watch']) && trim((string)$_GET['watch']) !== '')
    ? trim((string)$_GET['watch']) : null,
);
$viewlinks = array('h' => '/', 'v' => '/');

$canon = $base_url . '/';
if ($cat !== 'mind') {
  $canon .= '?cat=' . $cat;
}
$rm = web_refresh_mins($CFG);
$page = array(
  'title' => ($cat === 'mind' ? 'TV műsor ma - Részletes online műsorújság'
    : h(web_cat_label($cat)) . ' - TV műsor') . ' | EPG',
  'desc' => 'TV műsor, csatornák részletes műsora egy helyen.',
  'canonical' => $canon,
  'h1' => '',
  'app' => true, // both views fill the viewport, scroll inside epg-fill
  'refresh' => $rm > 0 ? $rm * 60 : null,
);

list($groups) = epg_front_day($pdo, $CFG, $date, $sel, $WSTATE['provider']);
$days = 1;
if ($date === web_today()) {
  // today: append tomorrow, so both days show in one view
  // (deduped: the two day-windows overlap 00:00 -> 04:00)
  $tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));
  list($groups2) = epg_front_day($pdo, $CFG, $tomorrow, $sel, $WSTATE['provider']);
  $groups = web_merge_days($groups, $groups2);
  $days = 2;
}
foreach ($groups as $slug => $rows) {
  $groups[$slug] = array_values(array_filter($rows, function ($r) use ($kw) {
    return web_cat_match($r, $kw);
  }));
  if (!count($groups[$slug])) {
    unset($groups[$slug]);
  }
}
$now = epg_now();
if ($WSTATE['view'] === 'v') {
  echo epg_list_v($groups, $names, $now);
} else {
  echo epg_table_h($groups, $names, $now, $date, $days);
}
