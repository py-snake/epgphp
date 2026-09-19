<?php
// tests/env_test.php - fail fast when the host cannot run this project.

function test_env_php_version() {
  t_ok(version_compare(PHP_VERSION, '7.0.0', '>='),
    'PHP >= 7.0 (have ' . PHP_VERSION . ')');
}

function test_env_required_extensions() {
  foreach (array('pdo_sqlite', 'xmlreader', 'zlib', 'mbstring', 'json') as $ext) {
    t_ok(extension_loaded($ext), "extension loaded: $ext");
  }
}

function test_env_project_layout() {
  $root = dirname(__DIR__);
  foreach (array(
    'config.example.php', 'lib/config.php', 'lib/epg.php', 'lib/auth.php',
    'lib/providers.php', 'lib/epg_front.php', 'lib/providers/ripper.php',
    'lib/providers/epglat.php', 'lib/providers/hungary1.php',
    'lib/providers/iptvepg.php', 'lib/providers/freeepg.php',
    'tests/bootstrap.php', 'tests/run.php',
    'cron/import.php', 'cron/status.php', 'api/epg.php', 'bin/import_epg.php',
  ) as $f) {
    t_ok(is_file($root . '/' . $f), "project file exists: $f");
  }
  // config.php itself is git-ignored (live secrets); example must exist
  t_ok(!is_file($root . '/config.php') || is_array(@include $root . '/config.php'),
    'config.php valid when present');
}

function test_env_config_tokens() {
  require_once __DIR__ . '/bootstrap.php';
  $cfg = t_project_config();
  t_ok(is_array($cfg), 'config.php returns array');
  t_ok(!empty($cfg['token']), 'cron token set');
  t_ok(!empty($cfg['site_token']), 'site token set (private instance)');
  t_ok($cfg['token'] !== $cfg['site_token'], 'cron and site tokens differ');
  t_ok(is_array($cfg['providers']) && count($cfg['providers']) > 0,
    'at least one provider enabled');
  foreach ($cfg['providers'] as $pid) {
    t_ok(is_file(dirname(__DIR__) . "/lib/providers/$pid.php"),
      "enabled provider has module: $pid");
  }
}

function test_env_tmp_writable() {
  $d = t_tmpdir();
  $f = $d . '/w';
  t_ok(@file_put_contents($f, 'x') === 1 && @unlink($f), 'temp dir writable');
}
