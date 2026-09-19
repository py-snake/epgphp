<?php
// pages/channel.php - /tvmusor/{SLUG}[/{date}] : single-channel day view.
// Needs: $CFG, $WSTATE, $pdo, $ROUTE['slug']. Sets: $page, $viewlinks, $WCARRY.

$slug = strtoupper((string)(isset($ROUTE['slug']) ? $ROUTE['slug'] : ''));
$names = web_channel_names($pdo, $WSTATE['provider']);
$all_slugs = web_channel_slugs($pdo, $WSTATE['provider']);
if (!in_array($slug, $all_slugs, true)) {
  http_response_code(404);
  $page = array('title' => '404 - Nincs ilyen csatorna | EPG',
    'desc' => '', 'canonical' => $base_url . '/', 'h1' => 'TVműsor');
  $viewlinks = array('h' => '/', 'v' => '/');
  echo '<h2 class="section-title">404</h2><p>Nincs ilyen csatorna.</p>';
  echo render_chan_index($all_slugs, $names);
  return;
}
$date = web_valid_date(isset($_GET['date']) ? $_GET['date'] : (isset($ROUTE['date']) ? $ROUTE['date'] : null));
$name = isset($names[$slug]) ? $names[$slug] : $slug;

$GLOBALS['WCARRY'] = array(
  'zoom' => web_zoom_raw(),
  'refresh' => isset($_GET['refresh']) ? (string)$_GET['refresh'] : null,
);
$self = channel_path($slug, $date === web_today() ? null : $date);
$viewlinks = array('h' => $self, 'v' => $self);

$rm = web_refresh_mins($CFG);
$page = array(
  'title' => $name . ' mai műsora, ' . $date . ' | EPG',
  'desc' => $name . ' csatorna részletes napi műsora: ' . $date . '.',
  'canonical' => $base_url . $self,
  'h1' => 'TVműsor - ' . $name,
  'app' => true, // both views fill the viewport, scroll inside epg-fill
  'refresh' => $rm > 0 ? $rm * 60 : null,
);

$base_cb = function ($d) use ($slug) {
  return channel_path($slug, $d);
};
echo render_datenav($base_cb, $date);

list($groups) = epg_front_day($pdo, $CFG, $date, array($slug), $WSTATE['provider']);
$now = time();
if ($WSTATE['view'] === 'v') {
  echo epg_list_v($groups, $names, $now);
} else {
  echo epg_table_h($groups, $names, $now, $date);
}

echo '<h2 class="section-title">További csatornák</h2>';
echo render_chan_index($all_slugs, $names);
