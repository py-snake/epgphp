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
  t_eq($w[0]['mode'], 'prefix', 'default is start-match');
  t_eq($w[0]['text'], 'hirado', 'normalized');
  $w = web_watch_parse('e:Híradó');
  t_eq(count($w), 1, 'single item, no comma split');
  t_eq($w[0]['mode'], 'exact', 'e: exact');
  $w = web_watch_parse("e:X\nE:Y\np:Z\nP:W");
  t_eq(count($w), 4, 'four lines');
  t_true($w[0]['mode'] === 'exact' && $w[1]['mode'] === 'exact', 'e:/E: exact');
  t_true($w[2]['mode'] === 'partial' && $w[3]['mode'] === 'partial', 'p:/P: partial');
  $w = web_watch_parse(' e:Híradó ');
  t_eq(count($w), 1, 'surrounding space trimmed');
  $w = web_watch_parse('\\e:X');
  t_eq(count($w), 1, 'escaped item kept');
  t_eq($w[0]['mode'], 'prefix', 'escaped follows default');
  t_eq($w[0]['text'], 'e:x', 'escape stripped, literal kept');
  t_eq(web_watch_parse(''), array(), 'empty in empty out');
  $many = implode("\n", array_fill(0, 51, 'Abc'));
  t_eq(count(web_watch_parse($many)), 50, 'capped at 50');
  // textarea form: newline-separated (unix + windows line endings)
  $w = web_watch_parse("e:Híradó\np:Időjárás\np:Jelentés\ne:Egyszer");
  t_eq(count($w), 4, 'four lines');
  t_true($w[0]['mode'] === 'exact' && $w[3]['mode'] === 'exact', 'e: lines exact');
  t_true($w[1]['mode'] === 'partial' && $w[2]['mode'] === 'partial', 'p: lines partial');
  t_eq($w[1]['text'], 'idojaras', 'second line normalized');
  $w = web_watch_parse("e:Híradó\r\np:Mese\r\n");
  t_eq(count($w), 2, 'CRLF lines');
  // commas, dashes and other punctuation stay inside titles
  $w = web_watch_parse("e:Columbo, gyilkosság két tételben\np:Sissi - A királyné");
  t_eq(count($w), 2, 'comma does not split');
  t_eq($w[0]['mode'], 'exact', 'first still exact');
  t_eq($w[0]['text'], 'columbo, gyilkossag ket tetelben', 'comma kept in title');
  t_eq($w[1]['mode'], 'partial', 'second partial');
  t_eq($w[1]['text'], 'sissi - a kiralyne', 'dash kept in title');
}

function test_watch_match() {
  $row = array('title' => 'Reggeli Híradó', 'subtitle' => 'Időjárás');
  t_true(web_watch_match($row, web_watch_parse('e:Reggeli Híradó')), 'exact full title');
  t_true(web_watch_match($row, web_watch_parse('REGGELI HÍRADÓ')), 'bare matches full title');
  t_true(web_watch_match($row, web_watch_parse('Reggeli')), 'bare matches title start');
  t_false(web_watch_match($row, web_watch_parse('Híradó')), 'bare rejects non-start');
  t_false(web_watch_match($row, web_watch_parse('e:Híradó')), 'exact rejects substring');
  t_true(web_watch_match($row, web_watch_parse('p:Híradó')), 'partial hits substring');
  t_false(web_watch_match($row, web_watch_parse('e:Időjárás')), 'subtitle never matched (exact)');
  t_false(web_watch_match($row, web_watch_parse('p:járás')), 'subtitle never matched (partial)');
  t_false(web_watch_match($row, web_watch_parse('e:Sport')), 'no false positive');
  t_false(web_watch_match($row, array()), 'empty watch never hits');
  // punctuation inside titles matches literally
  $row2 = array('title' => 'Columbo: Gyilkosság két tételben', 'subtitle' => '');
  t_true(web_watch_match($row2,
    web_watch_parse('e:Columbo: Gyilkosság két tételben')), 'exact with colon+comma');
  t_true(web_watch_match($row2, web_watch_parse('p:két tételben')), 'partial with space');
  t_false(web_watch_match(array('title' => '', 'subtitle' => 'X'),
    web_watch_parse('p:X')), 'empty title never hits');
  // the requested rule: start matches, end may differ; non-start misses
  $row3 = array('title' => 'Országos híradó magyar nyelven', 'subtitle' => '');
  t_true(web_watch_match($row3, web_watch_parse('Országos híradó')), 'start hits');
  t_false(web_watch_match($row3, web_watch_parse('Híradó')), 'non-start misses');
  t_true(web_watch_match($row3, web_watch_parse('országos HÍRADÓ')), 'start ignores case+accent');
}

function test_watch_wildcard() {
  $row = array('title' => 'Columbo: Gyilkosság két tételben', 'subtitle' => '');
  t_true(web_watch_match($row, web_watch_parse('p:Columbo*tételben')), 'partial star spans');
  t_true(web_watch_match($row, web_watch_parse('e:Columbo*')), 'exact trailing star');
  t_true(web_watch_match($row, web_watch_parse('e:*tételben')), 'exact leading star');
  t_true(web_watch_match($row, web_watch_parse('e:Columbo*tételben')), 'exact middle star');
  t_true(web_watch_match(array('title' => 'Sissi - A királyné', 'subtitle' => ''),
    web_watch_parse('p:Sissi * királyné')), 'spaced star spans');
  t_true(web_watch_match($row, web_watch_parse('p:*')), 'lone star matches');
  t_true(web_watch_match($row, web_watch_parse('Columbo*')), 'bare star spans tail');
  t_false(web_watch_match($row, web_watch_parse('e:Columbo * X')), 'star does not invent text');
  t_false(web_watch_match($row, web_watch_parse('p:xyz*abc')), 'star needs both sides');
  // escaped star stays literal
  t_false(web_watch_match($row, web_watch_parse('p:Columbo \\* tételben')),
    'escaped star is literal');
  t_true(web_watch_match(array('title' => 'A * B', 'subtitle' => ''),
    web_watch_parse('e:A \\* B')), 'escaped star matches literal star');
  // regex metachars in titles stay literal (no injection)
  t_false(web_watch_match($row, web_watch_parse('p:Columbo (.*)')), 'parens literal');
  t_true(web_watch_match(array('title' => 'Sissi - A királyné', 'subtitle' => ''),
    web_watch_parse('p:Sissi - A')), 'dash literal');
}

function test_watch_hit_class() {
  require_once dirname(__DIR__) . '/pages/_render.php';
  $GLOBALS['CFG'] = array('site_token' => '', 'cookies' => false,
    'default_provider' => 'ripper', 'providers' => array('ripper'),
    'tz' => 'Europe/Budapest', 'base_path' => '');
  $GLOBALS['WSTATE'] = array('provider' => 'ripper', 'view' => 'h');
  $GLOBALS['WCARRY'] = array();
  web_init($GLOBALS['CFG']);
  $_GET = array('watch' => "e:Columbo\np:Mese");
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
