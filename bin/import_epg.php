#!/usr/bin/env php
<?php
// bin/import_epg.php - CLI wrapper around the same provider-split importer
// that cron/import.php uses over HTTP. Local access = trusted, no token.
// Usage:
//   php bin/import_epg.php --src=/path/epg_ripper_HU1.xml.gz [--provider=ripper] [--db=var/epg.sqlite]
//   php bin/import_epg.php --src=a.xml.gz --src=b.xml.gz --provider=ripper --provider=hungary1
//   php bin/import_epg.php --src=https://epgshare01.online/epgshare01/epg_ripper_HU1.xml.gz
// Defaults (db, keep_past/future) come from config.php; flags override.

$opts = getopt('', array('src:', 'db:', 'provider:', 'keep-past:', 'keep-future:', 'no-wipe', 'no-prune', 'no-backup'));
$srcs = isset($opts['src']) ? (array)$opts['src'] : array();
$pids = isset($opts['provider']) ? (array)$opts['provider'] : array();

if (!count($srcs)) {
  fwrite(STDERR, "usage: php bin/import_epg.php --src=<file|url> [--provider=<id>] [--db=<sqlite>]\n");
  exit(1);
}

require __DIR__ . '/../lib/epg.php';
require __DIR__ . '/../lib/providers.php';

require __DIR__ . '/../lib/config.php';
$site_cfg = epg_load_config(dirname(__DIR__));
$map = array(); // dynamic: ids normalize to slugs, no curated map

$db_path = isset($opts['db']) ? $opts['db']
  : (isset($site_cfg['db_path']) ? $site_cfg['db_path'] : (__DIR__ . '/../var/epg.sqlite'));
$keep_past = isset($opts['keep-past']) ? (int)$opts['keep-past']
  : (isset($site_cfg['keep_past']) ? (int)$site_cfg['keep_past'] : 30);
$keep_future = isset($opts['keep-future']) ? (int)$opts['keep-future']
  : (isset($site_cfg['keep_future']) ? (int)$site_cfg['keep_future'] : 8);
$default_provider = isset($site_cfg['default_provider']) ? $site_cfg['default_provider'] : 'ripper';

@mkdir(dirname($db_path), 0777, true);
$pdo = epg_open_db($db_path);
epg_init_meta($pdo);

$wiped = array();
foreach ($srcs as $i => $src) {
  $pid = isset($pids[$i]) ? $pids[$i] : (isset($pids[0]) && count($srcs) === 1 ? $pids[0] : $default_provider);
  if ($i > 0 && !isset($pids[$i]) && count($pids) > 1) {
    $pid = $pids[min($i, count($pids) - 1)];
  }
  $local = $src;
  if (preg_match('#^https?://#i', $src)) {
    $tmp = sys_get_temp_dir() . '/epg_cli_' . $i . '_' . basename(parse_url($src, PHP_URL_PATH));
    fwrite(STDERR, "fetch $src -> $tmp\n");
    $ctx = stream_context_create(array('http' => array(
      'timeout' => 60,
      'header' => 'User-Agent: ' . epg_http_user_agent() . "\r\n"
        . "Accept: */*\r\n",
      'ignore_errors' => true,
    )));
    $data = @file_get_contents($src, false, $ctx);
    if ($data === false) {
      fwrite(STDERR, "WARN: fetch failed: $src, skipped\n");
      continue;
    }
    file_put_contents($tmp, $data);
    $local = $tmp;
  }
  if (!file_exists($local)) {
    fwrite(STDERR, "WARN: missing file: $local, skipped\n");
    continue;
  }
  $wipe = empty($opts['no-wipe']) && !isset($wiped[$pid]); // wipe each provider once
  fwrite(STDERR, "import $local provider=$pid " . ($wipe ? 'wipe' : 'append') . "...\n");
  $stats = epg_import_file($local, $pdo, array(
    'map' => $map,
    'wipe' => $wipe,
    'fallback_only' => false,
    'provider' => $pid,
    'progress' => function ($s) { fwrite(STDERR, "  ... " . $s['programmes'] . " progs\n"); },
  ));
  epg_meta_set($pdo, $pid, 'last_error', '');
  fwrite(STDERR, '  done: ' . json_encode($stats) . "\n");
  $wiped[$pid] = true;
}
if (empty($opts['no-prune'])) {
  foreach (array_keys($wiped) as $pid) {
    $d = epg_prune($pdo, $keep_past, $keep_future, null, $pid);
    fwrite(STDERR, "prune $pid: deleted $d rows outside -$keep_past/+$keep_future days\n");
  }
  if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec('VACUUM');
  }
}
if (empty($opts['no-backup']) && !empty($site_cfg['backup_gz'])) {
  $snap = epg_backup_gz($pdo, $db_path,
    isset($site_cfg['backup_dir']) ? $site_cfg['backup_dir'] : (__DIR__ . '/../var/backup'),
    isset($site_cfg['backup_keep']) ? (int)$site_cfg['backup_keep'] : 7);
  fwrite(STDERR, "backup: $snap\n");
}
fwrite(STDERR, "OK db=$db_path\n");
