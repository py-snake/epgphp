<?php
// pages/detail.php - /musor/{SLUG}/{start_utc} : programme detail (replaces AJAX).
// Needs: $CFG, $WSTATE, $pdo, $ROUTE['slug'], $ROUTE['start'].

$slug = strtoupper((string)(isset($ROUTE['slug']) ? $ROUTE['slug'] : ''));
$start = (int)(isset($ROUTE['start']) ? $ROUTE['start'] : 0);
$names = web_channel_names($pdo, $WSTATE['provider']);
$name = isset($names[$slug]) ? $names[$slug] : $slug;

list($row, $used) = web_find_programme($pdo, $CFG, $slug, $start);
// carry display state back out (otherwise leaving this page drops e.g. refresh)
$GLOBALS['WCARRY'] = array(
  'zoom' => web_zoom_raw(),
  'refresh' => isset($_GET['refresh']) ? (string)$_GET['refresh'] : null,
  'offset' => isset($_GET['offset']) ? (string)$_GET['offset'] : null,
  'font' => web_font_raw(),
  'watch' => (isset($_GET['watch']) && trim((string)$_GET['watch']) !== '')
    ? trim((string)$_GET['watch']) : null,
);
$self = $row ? detail_path($slug, $start) : '/';
$viewlinks = array('h' => $self, 'v' => $self);

if (!$row) {
  http_response_code(404);
  $page = array('title' => '404 - Nincs ilyen műsor | EPG',
    'desc' => '', 'canonical' => $base_url . '/', 'h1' => 'TVműsor');
  echo '<h2 class="section-title">404</h2><p>Nincs ilyen műsor.</p>';
  return;
}

$page = array(
  'title' => $row['title'] . ' (' . $name . ') | EPG',
  'desc' => mb_substr($row['title'] . ' - ' . $row['descr'], 0, 160, 'UTF-8'),
  'canonical' => $base_url . $self,
  'h1' => $row['title'],
);

$now = epg_now();
$cls = epg_classify($row['start_utc'], $row['stop_utc'], $now);
echo '<div class="detail">';
echo '<h2>' . h($row['title']) . '</h2>';
echo '<p><a href="' . h(u(channel_path($slug), array(), null, 'now')) . '">' . h($name) . '</a> · '
  . h(date('Y-m-d', $row['start_utc'])) . ' '
  . h(web_hm($row['start_utc'])) . '-' . h(web_hm($row['stop_utc'])) . ' · ';
echo $cls === 'live' ? '<span class="live-tag">Most megy</span>'
  : ($cls === 'past' ? 'Véget ért' : 'Következik');
echo '</p>';
if ($row['subtitle'] !== '') {
  echo '<p><i>' . h($row['subtitle']) . '</i></p>';
}
if ($row['descr'] !== '') {
  echo '<p>' . h($row['descr']) . '</p>';
}
$meta = array();
if ($row['episode'] !== '') {
  $meta[] = 'Epizód: ' . $row['episode'];
}
if ($row['category'] !== '') {
  $meta[] = 'Kategória: ' . $row['category'];
}
if ($row['year'] !== '') {
  $meta[] = 'Év: ' . $row['year'];
}
if ($row['star'] !== '') {
  $meta[] = 'Értékelés: ' . $row['star'];
}
if (count($meta)) {
  echo '<p>' . h(implode(' | ', $meta)) . '</p>';
}
echo '</div>';

list($prev, $next) = web_neighbours($pdo, $used, $slug, $start);
echo '<p class="prevnext">';
if ($prev) {
  echo '<a href="' . h(u(detail_path($slug, $prev['start_utc']))) . '">&lt; Előző: '
    . h($prev['title']) . '</a> ';
}
if ($next) {
  echo '<a href="' . h(u(detail_path($slug, $next['start_utc']))) . '">Következő: '
    . h($next['title']) . ' &gt;</a>';
}
echo '</p>';
echo '<p><a href="' . h(u(channel_path($slug, date('Y-m-d', $row['start_utc'])), array(), null, 'now')) . '">'
  . h($name) . ' aznapi műsora</a></p>';
