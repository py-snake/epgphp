<?php
// api/epg.php - JSON API for non-PHP clients / other use cases.
// The PHP site itself should NOT call this over HTTP; it must require
// lib/epg_front.php directly (same process, no token round-trip).
//
//   GET /api/epg.php?token=<site_token>&provider=ripper&date=2026-09-18&ch=RTL,TV2
// site_token enforced only when config.php sets one (private instance).

header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . '/../lib/config.php';
$cfg = epg_load_config(dirname(__DIR__));
require __DIR__ . '/../lib/epg.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/providers.php';
require __DIR__ . '/../lib/epg_front.php';

epg_require_site_json($cfg);

$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$ch = isset($_GET['ch']) ? $_GET['ch'] : (isset($_GET['ch[]']) ? $_GET['ch[]'] : '');
$provider = isset($_GET['provider']) ? $_GET['provider'] : null;

try {
  $pdo = epg_open_db($cfg['db_path']);
  list($groups, $from, $to, $date_ok, $used, $no_data) =
    epg_front_day($pdo, $cfg, $date, $ch, $provider);
  if ($no_data) {
    http_response_code(503);
    epg_json(array('status' => 'no_data', 'date' => $date_ok,
      'provider_used' => $used));
    exit;
  }
  epg_json(array(
    'status' => 'ok',
    'date' => $date_ok,
    'window' => array('from' => $from, 'to' => $to),
    'provider_used' => $used,
    'fallback' => ($provider !== null && $provider !== '' && $provider !== $used),
    'channels' => $groups,
  ));
} catch (Exception $e) {
  http_response_code(500);
  epg_json(array('status' => 'error', 'error' => $e->getMessage()));
}
