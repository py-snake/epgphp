<?php
// pages/sitemap.php - dynamic XML sitemap (UTF-8). Raw output, no templates.
// Priorities: / 1.0 hourly, channel pages 0.8 daily (+tomorrow 0.7),
// settings 0.4 weekly.
header('Content-Type: application/xml; charset=UTF-8');
$today = web_today();
$dt = new DateTime($today, new DateTimeZone('Europe/Budapest'));
$dt->modify('+1 day');
$tomorrow = $dt->format('Y-m-d');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
$urls = array(
  array('/', $today, 'hourly', '1.0'),
  array('/settings', $today, 'weekly', '0.4'),
);
try {
  foreach (web_channel_slugs($pdo, $WSTATE['provider']) as $slug) {
    $urls[] = array(channel_path($slug), $today, 'daily', '0.8');
    $urls[] = array(channel_path($slug, $tomorrow), $tomorrow, 'daily', '0.7');
  }
} catch (Exception $e) {
  // empty db: base URLs only
}
foreach ($urls as $u) {
  echo '  <url><loc>' . h($base_url . $u[0]) . '</loc>'
    . '<lastmod>' . h($u[1]) . '</lastmod>'
    . '<changefreq>' . h($u[2]) . '</changefreq>'
    . '<priority>' . h($u[3]) . '</priority></url>' . "\n";
}
echo '</urlset>' . "\n";
