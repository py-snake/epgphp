<?php
// cron/import.php - HTTP cron entry. GET or POST:
//   POST /cron/import.php?token=CRONTOKEN (see config.php 'token')
// One provider after another, each in its own transaction; a dead provider
// degrades to "partial", never takes the healthy tables down with it.

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

require __DIR__ . '/../lib/config.php';
$cfg = epg_load_config(dirname(__DIR__));
require __DIR__ . '/../lib/epg.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/providers.php';

epg_require_cron($cfg);

// Best effort on shared hosting: hosts with max_execution_time may still kill
// us mid-import; the per-provider transactions keep partial kills safe, and
// ?provider=<id> lets cron stagger providers across separate hits.
@set_time_limit(0);
@ignore_user_abort(true);

// ?provider=ripper imports one provider only (stagger cron jobs). Default: all.
$only = isset($_REQUEST['provider']) ? (string)$_REQUEST['provider'] : '';
$pids = $only !== '' ? array($only) : $cfg['providers'];

$lock_file = isset($cfg['lock_file']) ? $cfg['lock_file'] : (__DIR__ . '/../var/import.lock');
@mkdir(dirname($lock_file), 0777, true);
$lock = fopen($lock_file, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
  http_response_code(409);
  epg_json(array('status' => 'busy'));
  exit;
}

$log = isset($cfg['log_file']) ? $cfg['log_file'] : (__DIR__ . '/../var/import.log');
@mkdir(dirname($log), 0777, true);

$t0 = microtime(true);
$result = array('status' => 'ok', 'providers' => array(), 'errors' => array());
try {
  $map = array(); // dynamic: ids normalize to slugs, no curated map
  $pdo = epg_open_db($cfg['db_path']);
  epg_init_meta($pdo); // error-recording (last_error) needs this on fresh dbs
  try {
    $pdo->exec('PRAGMA journal_mode=WAL');
  } catch (Exception $e) {
    // some shared hosts forbid WAL (e.g. network fs): stay on journal mode
  }
  $defs = epg_provider_defs();

  foreach ($pids as $pid) {
    if (!isset($defs[$pid])) {
      $result['providers'][$pid] = array('status' => 'skipped', 'reason' => 'no module');
      continue;
    }
    $t1 = microtime(true);
    try {
      // JSON/API providers with their own importer (e.g. porthu):
      // custom_import(PDO, def, opts) returns stats like epg_import_file().
      if (!empty($defs[$pid]['custom_import']) && is_callable($defs[$pid]['custom_import'])) {
        $tot = call_user_func($defs[$pid]['custom_import'], $pdo, $defs[$pid], array(
          'timeout' => isset($cfg['fetch_timeout']) ? (int)$cfg['fetch_timeout'] : 60,
          'delay_ms' => isset($cfg['porthu_delay_ms']) ? (int)$cfg['porthu_delay_ms'] : 500,
          'provider' => $pid,
        ));
        epg_meta_set($pdo, $pid, 'last_error', '');
        $tot['status'] = 'ok';
        $tot['took_s'] = round(microtime(true) - $t1, 1);
        $result['providers'][$pid] = $tot;
        continue;
      }
      $fetch = epg_provider_fetch($pdo, $defs[$pid],
        isset($cfg['fetch_timeout']) ? (int)$cfg['fetch_timeout'] : 60);
      if (!$fetch['fresh']) {
        // 304 Not Modified on all URLs: keep tables, still counts as healthy
        epg_meta_set($pdo, $pid, 'last_import', (string)time());
        $result['providers'][$pid] = array('status' => 'not_modified');
        continue;
      }
      $tot = array('channels' => 0, 'programmes' => 0, 'skipped' => 0);
      $first_file = true;
      foreach ($fetch['files'] as $file) {
        $st = epg_import_file($file, $pdo, array(
          'map' => $map,
          'wipe' => $first_file, // wipe THIS provider's tables only
          'fallback_only' => false,
          'provider' => $pid,
        ));
        $tot['channels'] += $st['channels'];
        $tot['programmes'] += $st['programmes'];
        $tot['skipped'] += $st['skipped'];
        $first_file = false;
        @unlink($file);
      }
      epg_meta_set($pdo, $pid, 'last_error', '');
      $tot['status'] = 'ok';
      $tot['took_s'] = round(microtime(true) - $t1, 1);
      $result['providers'][$pid] = $tot;
    } catch (Exception $e) {
      epg_meta_set($pdo, $pid, 'last_error', $e->getMessage());
      $result['providers'][$pid] = array('status' => 'error', 'error' => $e->getMessage());
      $result['errors'][$pid] = $e->getMessage();
      $result['status'] = 'partial';
    }
  }

  // retention per provider table + vacuum + file-level gz snapshot (config.php)
  $pruned = array();
  if (!empty($cfg['prune_after_import'])) {
    foreach ($pids as $pid) {
      try {
        $pruned[$pid] = epg_prune($pdo,
          isset($cfg['keep_past']) ? (int)$cfg['keep_past'] : 30,
          isset($cfg['keep_future']) ? (int)$cfg['keep_future'] : 8,
          null, $pid);
      } catch (Exception $e) {
        $pruned[$pid] = 'error: ' . $e->getMessage();
      }
    }
    if (!empty($cfg['vacuum_after_prune']) && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
      try {
        $pdo->exec('VACUUM');
      } catch (Exception $e) {
        $result['vacuum_error'] = $e->getMessage(); // non-fatal, data is safe
      }
    }
  }
  $result['pruned'] = $pruned;
  if (!empty($cfg['backup_gz'])) {
    $snap = epg_backup_gz($pdo, $cfg['db_path'],
      isset($cfg['backup_dir']) ? $cfg['backup_dir'] : (__DIR__ . '/../var/backup'),
      isset($cfg['backup_keep']) ? (int)$cfg['backup_keep'] : 7);
    $result['backup'] = $snap;
  }
  if (count($result['errors']) && count($result['errors']) === count($pids)) {
    $result['status'] = 'error';
  }
} catch (Exception $e) {
  http_response_code(500);
  $result = array('status' => 'error', 'error' => $e->getMessage());
}
$result['took_s'] = round(microtime(true) - $t0, 1);
@file_put_contents($log, date('c') . ' ' . json_encode($result) . "\n", FILE_APPEND);
flock($lock, LOCK_UN);
fclose($lock);
epg_json($result);
