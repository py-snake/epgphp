<?php
// tests/watch_test.php - watchlist (?watch=): parse, match, card highlight.

require_once dirname(__DIR__) . '/lib/epg.php';
require_once dirname(__DIR__) . '/lib/web.php';

function test_watch_norm() {
  t_eq(web_watch_norm('Híradó'), 'hirado', 'accent+case folded');
  t_eq(web_watch_norm('  Reggeli   Műsor  '), 'reggeli musor', 'spaces collapsed');
  t_eq(web_watch_norm('RTL KETTŐ'), 'rtl ketto', 'double accent');
}

function test_watch_parse() {
  $w = web_watch_parse('Híradó');
  t_eq(count($w), 1, 'single item');
  t_true($w[0]['exact'], 'default is exact');
  t_eq($w[0]['text'], 'hirado', 'normalized');
  $w = web_watch_parse('e:Híradó, E:X, p:Columbo, P:Y');
  t_eq(count($w), 4, 'four items');
  t_true($w[0]['exact'] && $w[1]['exact'], 'e:/E: exact');
  t_true(!$w[2]['exact'] && !$w[3]['exact'], 'p:/P: partial');
  t_eq($w[2]['text'], 'columbo', 'partial text');
  $w = web_watch_parse(' e:Híradó ,, ');
  t_eq(count($w), 1, 'empties dropped');
  $w = web_watch_parse('\\e:X');
  t_eq(count($w), 1, 'escaped item kept');
  t_true($w[0]['exact'], 'escaped is exact');
  t_eq($w[0]['text'], 'e:x', 'escape stripped, literal kept');
  t_eq(web_watch_parse(''), array(), 'empty in empty out');
  $many = implode(',', array_fill(0, 51, 'Abc'));
  t_eq(count(web_watch_parse($many)), 50, 'capped at 50');
  // textarea form: newline-separated (unix + windows line endings)
  $w = web_watch_parse("e:Híradó\np:Időjárás\np:Jelentés\ne:Egyszer");
  t_eq(count($w), 4, 'four lines');
  t_true($w[0]['exact'] && $w[3]['exact'], 'e: lines exact');
  t_true(!$w[1]['exact'] && !$w[2]['exact'], 'p: lines partial');
  t_eq($w[1]['text'], 'idojaras', 'second line normalized');
  $w = web_watch_parse("e:Híradó\r\np:Mese\r\n");
  t_eq(count($w), 2, 'CRLF lines');
  $w = web_watch_parse("e:Híradó,p:Mese\ne:Egyszer");
  t_eq(count($w), 3, 'mixed comma and newline');
}

function test_watch_match() {
  $row = array('title' => 'Reggeli Híradó', 'subtitle' => 'Időjárás');
  t_true(web_watch_match($row, web_watch_parse('e:Reggeli Híradó')), 'exact full title');
  t_true(web_watch_match($row, web_watch_parse('REGGELI HÍRADÓ')), 'exact ignores case+accent');
  t_false(web_watch_match($row, web_watch_parse('e:Híradó')), 'exact rejects substring');
  t_true(web_watch_match($row, web_watch_parse('p:Híradó')), 'partial hits substring');
  t_true(web_watch_match($row, web_watch_parse('e:Időjárás')), 'exact hits subtitle');
  t_true(web_watch_match($row, web_watch_parse('p:járás')), 'partial hits subtitle');
  t_false(web_watch_match($row, web_watch_parse('e:Sport')), 'no false positive');
  t_false(web_watch_match($row, array()), 'empty watch never hits');
}

function test_watch_hit_class() {
  require_once dirname(__DIR__) . '/pages/_render.php';
  $GLOBALS['CFG'] = array('site_token' => '', 'cookies' => false,
    'default_provider' => 'ripper', 'providers' => array('ripper'),
    'tz' => 'Europe/Budapest', 'base_path' => '');
  $GLOBALS['WSTATE'] = array('provider' => 'ripper', 'view' => 'h');
  $GLOBALS['WCARRY'] = array();
  web_init($GLOBALS['CFG']);
  $_GET = array('watch' => 'e:Columbo,p:Mese');
  $now = time();
  $mk = function ($t) use ($now) {
    return array('start_utc' => $now - 100, 'stop_utc' => $now + 3600,
      'title' => $t, 'subtitle' => '', 'descr' => '', 'category' => '',
      'rating' => '', 'star' => '', 'icon' => '', 'year' => '', 'episode' => '',
      'film_url' => '');
  };
  $groups = array('RTL' => array($mk('Columbo'), $mk('Híradó')));
  $names = array('RTL' => 'RTL');
  $h = epg_table_h($groups, $names, $now, date('Y-m-d', $now));
  t_eq(substr_count($h, 'prog-item live hit'), 1, 'horizontal: one hit card');
  t_eq(substr_count($h, 'prog-item live"'), 1, 'horizontal: other card plain');
  t_ok(strpos($h, '/musor/RTL/') !== false, 'horizontal: title still links local');
  $v = epg_list_v($groups, $names, $now, '2');
  t_eq(substr_count($v, '<li class="live hit"'), 1, 'vertical: one hit card');
  t_eq(substr_count($v, '<li class="live"'), 1, 'vertical: other card plain');
  $_GET = array();
}
