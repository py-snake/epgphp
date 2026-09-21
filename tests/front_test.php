<?php
// tests/front_test.php - provider registry, auth gates, server-side front API.

require_once dirname(__DIR__) . '/lib/epg.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/providers.php';
require_once dirname(__DIR__) . '/lib/epg_front.php';

function t_front_cfg($over = array()) {
  return array_merge(array(
    'token' => 'CRON', 'site_token' => 'SITE', 'site_cookie' => 'tvm_t',
    'site_cookie_days' => 30, 'providers' => array('ripper', 'hungary1'),
    'tz' => 'Europe/Budapest',
  ), $over);
}

function t_front_db_with($provider, $title, $start_utc, $stop_utc, $chan = 'RTL') {
  $d = t_tmpdir();
  $pdo = new PDO('sqlite:' . $d . '/t.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  epg_init_provider_schema($pdo, $provider);
  $pdo->prepare("INSERT INTO programs_$provider"
    . ' (channel,start_utc,stop_utc,title) VALUES (?,?,?,?)')
    ->execute(array($chan, $start_utc, $stop_utc, $title));
  return $pdo;
}

function test_providers_registry() {
  $defs = epg_provider_defs();
  foreach (array('ripper', 'epglat', 'hungary1', 'iptvepg', 'freeepg', 'porthu') as $id) {
    t_ok(isset($defs[$id]), "provider registered: $id");
    t_ok(!empty($defs[$id]['urls']), "$id has feed URLs");
  }
  require_once __DIR__ . '/bootstrap.php';
  $cfg = t_project_config();
  foreach ($cfg['providers'] as $pid) {
    t_ok(isset($defs[$pid]), "enabled provider has module: $pid");
  }
}

function test_auth_cron_token() {
  $cfg = t_front_cfg();
  $_REQUEST['token'] = 'CRON';
  t_true(epg_cron_auth($cfg), 'correct cron token accepted');
  $_REQUEST['token'] = 'wrong';
  t_false(epg_cron_auth($cfg), 'wrong cron token rejected');
  unset($_REQUEST['token']);
  t_false(epg_cron_auth($cfg), 'missing cron token rejected');
}

function test_auth_site_token_and_cookie() {
  $cfg = t_front_cfg();
  unset($_REQUEST['token'], $_COOKIE['tvm_t']);
  t_false(epg_site_auth($cfg), 'private site blocks anonymous');
  $_REQUEST['token'] = 'wrong';
  t_false(epg_site_auth($cfg), 'wrong site token rejected');
  $_REQUEST['token'] = 'SITE';
  t_true(epg_site_auth($cfg), 'correct site token accepted');
  t_ok(!empty($_COOKIE['tvm_t']), 'auth cookie set');
  // token value itself must NOT leak into the cookie
  t_ok(strpos($_COOKIE['tvm_t'], 'SITE') === false, 'raw token not in cookie');
  unset($_REQUEST['token']);
  t_true(epg_site_auth($cfg), 'cookie alone grants access');
  unset($_COOKIE['tvm_t']);
  // public instance: empty site_token = open
  t_true(epg_site_auth(t_front_cfg(array('site_token' => ''))), 'public site open');
}

function test_front_channel_order() {
  // ?ch= is an ordered CSV: TV2,RTL must render in that order, not ABC
  $now = time();
  $d = t_tmpdir();
  $pdo = new PDO('sqlite:' . $d . '/t.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  epg_init_provider_schema($pdo, 'ripper');
  $ins = $pdo->prepare('INSERT INTO programs_ripper'
    . ' (channel,start_utc,stop_utc,title) VALUES (?,?,?,?)');
  $ins->execute(array('RTL', $now - 600, $now + 600, 'Hirado'));
  $ins->execute(array('TV2', $now - 600, $now + 600, 'Hirek'));
  list($groups) = epg_front_day($pdo, t_front_cfg(), date('Y-m-d'), 'TV2,RTL', 'ripper');
  t_eq(array_keys($groups), array('TV2', 'RTL'), 'requested order wins');
}

function test_front_explicit_provider() {
  $now = time();
  $pdo = t_front_db_with('ripper', 'Hirado', $now - 600, $now + 600);
  list($groups, , , , $used, $nodata) =
    epg_front_day($pdo, t_front_cfg(), date('Y-m-d'), '', 'ripper');
  t_false((bool)$nodata, 'data found');
  t_eq($used, 'ripper', 'explicit provider used');
  t_ok(isset($groups['RTL']), 'channel group present');
}

function test_front_fallback_chain() {
  $now = time();
  $pdo = t_front_db_with('hungary1', 'Hirado', $now - 600, $now + 600);
  epg_init_provider_schema($pdo, 'ripper'); // empty preferred table
  list($groups, , , , $used, $nodata) =
    epg_front_day($pdo, t_front_cfg(), date('Y-m-d'), '', 'ripper');
  t_false((bool)$nodata, 'fallback finds data');
  t_eq($used, 'hungary1', 'empty ripper falls back to hungary1');
  t_ok(isset($groups['RTL']), 'fallback groups present');
}

function test_front_no_data() {
  $pdo = t_front_db_with('ripper', 'Old', 1000000000, 1000003600);
  epg_init_provider_schema($pdo, 'hungary1');
  list($groups, , , , , $nodata) =
    epg_front_day($pdo, t_front_cfg(), date('Y-m-d'), '', null);
  t_true((bool)$nodata, 'no_data flag when all providers empty for window');
  t_eq(count($groups), 0, 'no groups on no_data');
}

function test_front_live_anchor_single() {
  $now = time();
  $d = t_tmpdir();
  $pdo = new PDO('sqlite:' . $d . '/t.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  epg_init_provider_schema($pdo, 'ripper');
  $ins = $pdo->prepare('INSERT INTO programs_ripper'
    . ' (channel,start_utc,stop_utc,title) VALUES (?,?,?,?)');
  $ins->execute(array('RTL', $now - 3600, $now + 3600, 'Live1'));
  $ins->execute(array('RTL', $now + 3600, $now + 7200, 'Next1'));
  $ins->execute(array('TV2', $now - 1800, $now + 1800, 'Live2'));
  list($groups) = epg_front_day($pdo, t_front_cfg(), date('Y-m-d'), '', 'ripper');
  $anchors = 0;
  $live = 0;
  foreach ($groups as $rows) {
    foreach ($rows as $r) {
      if ($r['cls'] === 'live') {
        $live++;
      }
      if (!empty($r['anchor'])) {
        $anchors++;
      }
    }
  }
  t_eq($live, 2, 'two live programmes classified');
  t_eq($anchors, 1, 'exactly one id="now" anchor per page');
}

function test_http_browser_headers() {
  $ua = epg_http_user_agent();
  t_ok(strpos($ua, 'Mozilla/5.0') === 0, 'browser UA, not a bot token');
  t_ok(strpos($ua, 'Chrome/') !== false, 'Chrome-style UA');
  t_ok(strpos($ua, 'epg-viewer') === false, 'no bot UA leaks out');
  $hdr = epg_http_browser_headers();
  $joined = implode("\n", $hdr);
  t_ok(strpos($joined, 'Accept:') !== false, 'Accept header present');
  t_ok(strpos($joined, 'Accept-Language:') !== false, 'Accept-Language present');
}
