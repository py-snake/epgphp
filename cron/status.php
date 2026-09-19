<?php
// cron/status.php - health readout. Same cron token as import.php:
//   GET /cron/status.php?token=CRONTOKEN (see config.php 'token')

header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . '/../lib/config.php';
$cfg = epg_load_config(dirname(__DIR__));
require __DIR__ . '/../lib/epg.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/providers.php';

epg_require_cron($cfg);

$out = array('status' => 'ok', 'providers' => array(), 'db_bytes' => null);
try {
  $pdo = epg_open_db($cfg['db_path']);
  $out['db_bytes'] = is_file($cfg['db_path']) ? filesize($cfg['db_path']) : 0;
  foreach (epg_provider_defs() as $pid => $def) {
    list(, $t_prog) = epg_provider_tables($pid);
    $rows = null;
    try {
      $rows = (int)$pdo->query("SELECT COUNT(*) FROM $t_prog")->fetchColumn();
    } catch (Exception $e) {
      $rows = null; // never imported
    }
    $li = epg_meta_get($pdo, $pid, 'last_import', '');
    $out['providers'][$pid] = array(
      'rows' => $rows,
      'last_import' => $li !== '' ? date('c', (int)$li) : null,
      'last_error' => epg_meta_get($pdo, $pid, 'last_error', ''),
      'programmes' => epg_meta_get($pdo, $pid, 'programmes', null),
    );
  }
} catch (Exception $e) {
  http_response_code(500);
  $out = array('status' => 'error', 'error' => $e->getMessage());
}
epg_json($out);
