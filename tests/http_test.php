<?php
// tests/http_test.php - live endpoint tests via php -S + curl.
// Skipped when curl ext is missing. Spins an isolated docroot copy so the
// real repo var/ is never touched.

function t_http_get($url, &$code) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  unset($ch); // (no curl_close(): deprecated no-op since PHP 8.0/8.5)
  return $body;
}

function t_http_site() {
  static $site = null;
  if ($site !== null) {
    return $site;
  }
  if (!function_exists('curl_init')) {
    return false; // runner reports skip
  }
  $root = dirname(__DIR__);
  $d = t_tmpdir() . '/docroot';
  mkdir($d, 0777, true);
  // copy code (not var/ payloads); skip anything absent on a fresh clone
  foreach (array('lib', 'cron', 'api', 'bin') as $n) {
    $src = $root . '/' . $n;
    $dst = $d . '/' . $n;
    if (is_file($src)) {
      copy($src, $dst);
    } else {
      mkdir($dst, 0777, true);
      $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST);
      foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($src));
        if ($f->isDir()) {
          mkdir($dst . $rel, 0777, true);
        } else {
          copy($f->getPathname(), $dst . $rel);
        }
      }
    }
  }
  // test config: known tokens, isolated db
  require_once __DIR__ . '/bootstrap.php';
  $cfg = t_project_config();
  $cfg['token'] = 'CRONTEST';
  $cfg['site_token'] = 'SITETEST';
  $cfg['db_path'] = $d . '/var/epg.sqlite';
  $cfg['lock_file'] = $d . '/var/import.lock';
  $cfg['log_file'] = $d . '/var/import.log';
  $cfg['backup_dir'] = $d . '/var/backup';
  $cfg['providers'] = array('ripper');
  mkdir($d . '/var', 0777, true);
  file_put_contents($d . '/config.php',
    '<?php return ' . var_export($cfg, true) . ';');
  // seed one provider table with a live programme
  require_once $root . '/lib/epg.php';
  $pdo = new PDO('sqlite:' . $cfg['db_path']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  epg_init_provider_schema($pdo, 'ripper');
  $now = time();
  $pdo->prepare('INSERT INTO programs_ripper'
    . ' (channel,start_utc,stop_utc,title,subtitle,descr) VALUES (?,?,?,?,?,?)')
    ->execute(array('RTL', $now - 600, $now + 600, 'Hirado', '', 'Hirek'));
  // serve
  $port = 8917;
  exec(sprintf('php -S 127.0.0.1:%d -t %s >/dev/null 2>&1 & echo $!',
    $port, escapeshellarg($d)) . "\n", $out);
  $pid = (int)trim(implode('', $out));
  $up = false;
  for ($i = 0; $i < 50; $i++) {
    $f = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
    if ($f) {
      fclose($f);
      $up = true;
      break;
    }
    usleep(100000);
  }
  if (!$up) {
    return false;
  }
  $site = array('base' => "http://127.0.0.1:$port", 'pid' => $pid);
  register_shutdown_function(function () use ($pid) {
    @exec('kill ' . (int)$pid . ' 2>/dev/null');
  });
  return $site;
}

function test_http_status_auth() {
  $s = t_http_site();
  if ($s === false) {
    echo "  SKIP (no curl or server)\n";
    return;
  }
  $body = t_http_get($s['base'] . '/cron/status.php?token=wrong', $code);
  t_eq($code, 403, 'status: wrong cron token -> 403');
  $body = t_http_get($s['base'] . '/cron/status.php?token=CRONTEST', $code);
  t_eq($code, 200, 'status: correct cron token -> 200');
  $j = json_decode($body, true);
  t_ok(is_array($j) && $j['status'] === 'ok', 'status body ok');
  t_ok(strpos($body, "\n") !== false, 'status body pretty-printed');
  t_eq($j['providers']['ripper']['rows'], 1, 'status reports seeded row');
}

function test_http_api_site_gate_and_data() {
  $s = t_http_site();
  if ($s === false) {
    echo "  SKIP (no curl or server)\n";
    return;
  }
  t_http_get($s['base'] . '/api/epg.php?date=2026-09-18', $code);
  t_eq($code, 403, 'api: anonymous blocked on private instance');
  $body = t_http_get($s['base'] . '/api/epg.php?token=SITETEST&date='
    . date('Y-m-d'), $code);
  t_eq($code, 200, 'api: site token -> 200');
  $j = json_decode($body, true);
  t_eq($j['status'], 'ok', 'api body ok');
  t_eq($j['provider_used'], 'ripper', 'api uses seeded provider');
  t_ok(isset($j['channels']['RTL']), 'api returns RTL group');
  t_eq($j['channels']['RTL'][0]['cls'], 'live', 'api classifies live');
}

function test_http_api_fallback_flag() {
  $s = t_http_site();
  if ($s === false) {
    echo "  SKIP (no curl or server)\n";
    return;
  }
  $body = t_http_get($s['base'] . '/api/epg.php?token=SITETEST&provider=hungary1&date='
    . date('Y-m-d'), $code);
  // hungary1 table missing in isolated db -> falls back to ripper
  $j = json_decode($body, true);
  t_eq($code, 200, 'api: unknown/empty provider falls back, still 200');
  t_eq($j['provider_used'], 'ripper', 'fallback resolved to ripper');
  t_true($j['fallback'], 'fallback flag set');
}

function test_http_import_token_gate() {
  $s = t_http_site();
  if ($s === false) {
    echo "  SKIP (no curl or server)\n";
    return;
  }
  // wrong token must die BEFORE any fetch/import (no network touched)
  t_http_get($s['base'] . '/cron/import.php?token=wrong', $code);
  t_eq($code, 403, 'import: wrong cron token -> 403');
  // unknown provider id: no fetch, exercises lock+prune+backup+JSON path
  $body = t_http_get(
    $s['base'] . '/cron/import.php?token=CRONTEST&provider=nosuch', $code);
  $j = json_decode($body, true);
  t_eq($code, 200, 'import: unknown provider -> 200 (skipped, not fatal)');
  t_eq($j['providers']['nosuch']['status'], 'skipped', 'unknown provider skipped');
}
