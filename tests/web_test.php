<?php
// tests/web_test.php - frontend helpers (lib/web.php) + HTTP smoke tests.
// URL-only state: no cookies, no JavaScript (ld+json exempt: data, not code).

require_once dirname(__DIR__) . '/lib/epg.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/web.php';

function t_web_globals($cfg_over = array(), $get = array(), $wstate = null) {
  $cfg = array_merge(array(
    'site_token' => 'SITE', 'cookies' => false, 'default_provider' => 'ripper',
    'providers' => array('ripper', 'hungary1'), 'tz' => 'Europe/Budapest',
  ), $cfg_over);
  $GLOBALS['CFG'] = $cfg;
  $GLOBALS['WSTATE'] = $wstate !== null ? $wstate
    : array('provider' => 'ripper', 'view' => 'h');
  $GLOBALS['WCARRY'] = array();
  $_GET = $get;
  $_REQUEST = $get; // CLI does not merge GET into REQUEST like the SAPI does
  web_init($cfg); // timezone etc., like index.php
  return $cfg;
}

function test_web_date_hour_validation() {
  t_web_globals();
  t_eq(web_valid_date('2026-09-18'), '2026-09-18', 'valid date kept');
  t_eq(web_valid_date('2026-13-40'), web_today(), 'invalid date -> today');
  t_eq(web_valid_hour('20'), 20, 'valid hour');
  t_true(web_valid_hour('99') === null, 'invalid hour -> null');
  t_true(web_valid_hour('') === null, 'empty hour -> null (no -00 URLs)');
  t_eq(web_hm(gmmktime(18, 25, 0, 9, 18, 2026)), '20:25', 'local HH:MM (+0200)');
}

function test_web_url_builder() {
  t_web_globals(array(), array('token' => 'SITE'));
  // private pure-URL: token carried on every link
  $url = u('/grid/film/');
  t_ok(strpos($url, 'token=SITE') !== false, 'token propagated');
  t_ok(strpos($url, 'view=h') === false, 'default view dropped for clean URLs');
  $url = u('/grid/film/', array('view' => 'v'));
  t_ok(strpos($url, 'view=v') !== false, 'explicit non-default view kept');
  // public instance: no token anywhere
  t_web_globals(array('site_token' => ''), array());
  t_ok(strpos(u('/'), 'token=') === false, 'public links carry no token');
  // fragment support (auto-jump to live)
  t_ok(substr(u('/x', array(), null, 'now'), -4) === '#now', 'fragment appended');
  t_ok(strpos(u('/x', array('a' => 'b'), null, 'now'), '?a=b#now') !== false,
    'fragment after query');
  // carry: filter state survives link-to-link
  $GLOBALS['WCARRY'] = array('ch' => 'RTL,TV2');
  t_ok(strpos(u('/grid/film/'), 'ch=RTL') !== false, 'ch carried');
  t_ok(strpos(u('/grid/film/', array('ch' => null)), 'ch=') === false,
    'null drops carried key');
}

function test_web_cat_filter() {
  t_web_globals();
  t_eq(web_cat_keywords('bogus'), false, 'unknown category rejected');
  t_true(web_cat_keywords('mind') === null, 'mind matches all');
  $row = array('category' => 'Akció Sorozat', 'subtitle' => '');
  t_true(web_cat_match($row, web_cat_keywords('film')), 'genre counts as film');
  $row = array('category' => 'Hírműsor', 'subtitle' => '');
  t_false(web_cat_match($row, web_cat_keywords('film')), 'news not a film');
  t_true(web_cat_match($row, web_cat_keywords('mind')), 'mind matches all');
}

function test_web_auth_pure_url() {
  // cookies=false: cookie never read nor written, token must ride the URL
  $cfg = t_web_globals(array('cookies' => false), array('token' => 'SITE'));
  unset($_COOKIE['tvm_site']);
  t_true(epg_site_auth($cfg), '?token= grants access');
  t_false(isset($_COOKIE['tvm_site']), 'no cookie written in pure-URL mode');
  t_web_globals(array('cookies' => false), array());
  $_COOKIE['tvm_site'] = hash_hmac('sha256', 'site-ok', 'SITE');
  t_false(epg_site_auth($cfg), 'cookie alone NOT accepted in pure-URL mode');
  unset($_COOKIE['tvm_site']);
}

function test_epg_grid_alignment() {
  require_once dirname(__DIR__) . '/pages/_render.php';
  t_web_globals(array('site_token' => ''), array());
  $T = function ($h, $m) {
    return gmmktime($h, $m, 0, 9, 18, 2026) - 7200; // CEST wall clock
  };
  $now = $T(3, 0);
  $mk = function ($t, $s, $e) use ($T) {
    return array('start_utc' => $T($s[0], $s[1]), 'stop_utc' => $T($e[0], $e[1]),
      'title' => $t, 'subtitle' => '', 'descr' => '', 'category' => '',
      'rating' => '', 'star' => '', 'icon' => '', 'year' => '', 'episode' => '');
  };
  $groups = array(
    'RTL' => array(
      $mk('A', array(0, 10), array(1, 20)),
      $mk('B', array(2, 25), array(3, 35)),
      $mk('D', array(3, 40), array(3, 41)), // 1-minute show
    ),
    'TV2' => array(
      $mk('X', array(1, 5), array(2, 10)),
      $mk('Y', array(2, 40), array(3, 20)),
    ),
  );
  // full-day timeline: 24 hour cells, minute-exact strips
  $html = epg_table_h($groups, array('RTL' => 'RTL', 'TV2' => 'TV2'),
    $now, '2026-09-18');
  t_eq(substr_count($html, '<table class="hours"'), 1, 'hours table present');
  t_eq(substr_count($html, '<table class="epgtable"'), 1, 'timeline table present');
  t_eq(substr_count($html, 'id="now"'), 1, 'single #now anchor');
  $strips_of = function ($html, $title) {
    // one chunk per strip: titles never cross a strip boundary this way
    $chunks = preg_split('#<div class="prog-item#', $html);
    array_shift($chunks);
    foreach ($chunks as $c) {
      if (preg_match('#<a[^>]*>' . preg_quote($title, '#') . '</a>#', $c)
        && preg_match('#style="left:([\\d.]+)%;width:([\\d.]+)%;"#', $c, $m)) {
        return array((float)$m[1], (float)$m[2]);
      }
    }
    return array(null, null);
  };
  // B 02:25 at 145/1440 = 10.07%, 70min = 4.86% wide
  list($lb, $wb) = $strips_of($html, 'B');
  t_ok(abs($lb - 10.07) < 0.02, "B starts at 10.07% (got $lb)");
  t_ok(abs($wb - 4.86) < 0.02, "B is 70min = 4.86% wide (got $wb)");
  list($ly) = $strips_of($html, 'Y');
  t_ok(abs($ly - 11.11) < 0.02, "Y starts at 11.11% (got $ly)");
  // 1-minute show: sliver with hover title
  list($ld, $wd) = $strips_of($html, 'D');
  t_ok($wd !== null && $wd < 0.2, "1-min show is a sliver (got $wd)");
  t_ok(abs($ld - 15.28) < 0.02, "1-min show at 15.28% (got $ld)");
  t_ok(strpos($html, 'title="03:40-03:41') !== false,
    'sliver carries time on hover');
  // now markers: one per row, all at exactly now% = 12.5
  preg_match_all('#<div class="now-marker" style="left:([\\d.]+)%;#', $html, $mk2);
  t_eq(count($mk2[1]), 2, 'one now marker per row');
  t_ok(abs((float)$mk2[1][0] - 12.5) < 0.01 && abs((float)$mk2[1][1] - 12.5) < 0.01,
    'markers aligned at 12.5% on both rows');
}

function test_epg_fullday_timeline() {
  require_once dirname(__DIR__) . '/pages/_render.php';
  t_web_globals(array('site_token' => ''), array());
  $T = function ($h, $m) {
    return gmmktime($h, $m, 0, 9, 18, 2026) - 7200;
  };
  $mk = function ($t, $s, $e) use ($T) {
    return array('start_utc' => $T($s[0], $s[1]), 'stop_utc' => $T($e[0], $e[1]),
      'title' => $t, 'subtitle' => '', 'descr' => '', 'category' => '',
      'rating' => '', 'star' => '', 'icon' => '', 'year' => '', 'episode' => '');
  };
  // the whole day is always rendered: 24 header cells, late shows included
  $html = epg_table_h(
    array('RTL' => array($mk('M', array(22, 0), array(23, 30)))),
    array('RTL' => 'RTL'), $T(11, 30), '2026-09-18');
  for ($hh = 0; $hh < 24; $hh++) {
    t_ok(strpos($html, '>' . sprintf('%02d', $hh) . '<') !== false,
      "hour $hh in header");
  }
  t_ok(strpos($html, '>M</a>') !== false, 'late show rendered');
  t_ok(strpos($html, 'nowt') !== false, 'header carries now time tag');
}

function test_epg_twodays_timeline() {
  require_once dirname(__DIR__) . '/pages/_render.php';
  t_web_globals(array('site_token' => ''), array());
  $T = function ($h, $m) {
    return gmmktime($h, $m, 0, 9, 18, 2026) - 7200;
  };
  $mk = function ($t, $s, $e) use ($T) {
    return array('start_utc' => $T($s[0], $s[1]), 'stop_utc' => $T($e[0], $e[1]),
      'title' => $t, 'subtitle' => '', 'descr' => '', 'category' => '',
      'rating' => '', 'star' => '', 'icon' => '', 'year' => '', 'episode' => '');
  };
  $tm = $mk('T2', array(1, 0), array(2, 0));
  $tm['start_utc'] += 86400;
  $tm['stop_utc'] += 86400;
  $html = epg_table_h(
    array('RTL' => array($mk('M', array(22, 0), array(23, 30)), $tm)),
    array('RTL' => 'RTL'), $T(11, 30), '2026-09-18', 2);
  // 48 hour cells, tomorrow dimmed + dated at its midnight
  t_eq(substr_count($html, 'class="nextday"'), 24, 'tomorrow cells marked');
  t_ok(strpos($html, '09-19') !== false, 'tomorrow date labeled');
  // tomorrow 01:00 sits past the middle: (25*60)/2880 = 52.08%
  // (matched on the div tag itself, so it cannot span into neighbours)
  if (preg_match('#style="left:([\d.]+)%;width:([\d.]+)%;" title="01:00-02:00#',
    $html, $m)) {
    t_ok(abs((float)$m[1] - 52.08) < 0.03, 'tomorrow show past middle (got ' . $m[1] . ')');
  } else {
    t_ok(false, 'tomorrow show rendered');
  }
}

function test_web_font() {
  t_web_globals(array(), array());
  t_eq(web_font_raw(), null, 'absent = default');
  foreach (array('1', '2', '3', '4', '5') as $lv) {
    t_web_globals(array(), array('font' => $lv));
    t_eq(web_font_raw(), $lv, "level $lv valid");
  }
  t_web_globals(array(), array('font' => '9'));
  t_eq(web_font_raw(), null, 'garbage ignored');
  // carried in links when set (via WCARRY, like home/channel pages do)
  t_web_globals(array(), array());
  $GLOBALS['WCARRY'] = array();
  t_ok(strpos(u('/x'), 'font=') === false, 'default dropped from URLs');
  t_web_globals(array(), array('font' => '1'));
  $GLOBALS['WCARRY'] = array('font' => web_font_raw());
  t_ok(strpos(u('/x'), 'font=1') !== false, 'small carried in URLs');
}

function test_web_zoom() {  t_web_globals(array(), array());
  t_eq(web_zoom_px(), 6000, 'default zoom 6000px');
  t_web_globals(array(), array('zoom' => '1'));
  t_eq(web_zoom_px(), 3600, 'zoom 1 = 3600px');
  t_web_globals(array(), array('zoom' => '3'));
  t_eq(web_zoom_px(), 9000, 'zoom 3 = 9000px');
  t_web_globals(array(), array('zoom' => '9'));
  t_eq(web_zoom_px(), 6000, 'garbage zoom falls back');
  t_eq(web_zoom_raw(), null, 'garbage zoom not carried');
  require_once dirname(__DIR__) . '/pages/_render.php';
  $html = epg_table_h(array(), array(), time(), '2026-09-18');
  // empty message path has no wrap; render one row to check inline width
  t_web_globals(array(), array('zoom' => '3'));
  $T = time();
  $html = epg_table_h(
    array('RTL' => array(array('start_utc' => $T - 100, 'stop_utc' => $T + 100,
      'title' => 'X', 'subtitle' => '', 'descr' => '', 'category' => '',
      'rating' => '', 'star' => '', 'icon' => '', 'year' => '', 'episode' => ''))),
    array('RTL' => 'RTL'), $T, date('Y-m-d', $T));
  t_ok(strpos($html, 'min-width:9000px') !== false, 'zoom reflected in wrap');
}

function test_prog_tooltip() {
  require_once dirname(__DIR__) . '/pages/_render.php';
  t_web_globals(array(), array());
  date_default_timezone_set('Europe/Budapest');
  $r = array('start_utc' => gmmktime(10, 0, 0, 9, 18, 2026),
    'stop_utc' => gmmktime(11, 0, 0, 9, 18, 2026),
    'title' => 'Cím', 'subtitle' => 'Alcím', 'episode' => 'S1 E2',
    'category' => 'Akció', 'rating' => '12', 'year' => '2024', 'star' => '7.5',
    'descr' => 'Leírás szöveg.');
  $tip = prog_tooltip($r);
  $lines = explode("\n", $tip);
  t_eq($lines[0], '12:00-13:00', 'tooltip line 1: time');
  t_eq($lines[1], 'Cím', 'tooltip line 2: title');
  t_ok(in_array('Alcím', $lines), 'tooltip has subtitle');
  t_ok(in_array('Epizód: S1 E2', $lines), 'tooltip has episode');
  t_ok(in_array('Leírás szöveg.', $lines), 'tooltip ends with description');
  t_ok(strpos($tip, '12+') !== false, 'tooltip has age meta');
  // minimal row: no empty lines
  $r2 = array('start_utc' => 1, 'stop_utc' => 2, 'title' => 'X',
    'subtitle' => '', 'episode' => '', 'category' => '', 'rating' => '',
    'year' => '', 'star' => '', 'descr' => '');
  t_eq(prog_tooltip($r2), "01:00-01:00\nX", 'minimal tooltip has no blanks');
}

function test_web_channel_sort() {
  t_eq(web_channel_sort_key('9 Tv'), '09 tv', 'digits sort first');
  t_ok(web_channel_sort_key('RTL') > web_channel_sort_key('9 Tv'), 'alpha after digits');
  t_web_globals(array(), array());
  $d = t_tmpdir();
  $pdo = new PDO('sqlite:' . $d . '/t.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  epg_init_provider_schema($pdo, 's');
  $ins = $pdo->prepare('INSERT INTO channels_s (slug,name,logo,xmltv_id) VALUES (?,?,?,?)');
  $ins->execute(array('Z', 'Zebra', '', 'Z'));
  $ins->execute(array('N9', '9 Lives', '', 'N9'));
  $ins->execute(array('A', 'apple', '', 'A'));
  $sorted = web_sorted_channels($pdo, 's');
  t_eq(array_keys($sorted), array('N9', 'A', 'Z'), 'numbers first, then ABC by name');
}

function test_web_move_ch() {
  t_eq(web_move_ch(array('A', 'B', 'C'), 1, 'up'), array('B', 'A', 'C'), 'middle up');
  t_eq(web_move_ch(array('A', 'B', 'C'), 1, 'down'), array('A', 'C', 'B'), 'middle down');
  t_eq(web_move_ch(array('A', 'B'), 0, 'up'), array('A', 'B'), 'top edge no-op');
  t_eq(web_move_ch(array('A', 'B'), 1, 'down'), array('A', 'B'), 'bottom edge no-op');
}

function test_web_refresh_mins() {
  t_web_globals(array(), array());
  t_eq(web_refresh_mins(array('refresh_mins' => 5)), 5, 'config default');
  t_eq(web_refresh_mins(array()), 0, 'missing default = off');
  $_GET['refresh'] = '10';
  t_eq(web_refresh_mins(array('refresh_mins' => 5)), 10, '?refresh= wins');
  $_GET['refresh'] = '0';
  t_eq(web_refresh_mins(array('refresh_mins' => 5)), 0, '?refresh=0 disables');
  $_GET['refresh'] = '999';
  t_eq(web_refresh_mins(array('refresh_mins' => 5)), 5, 'absurd value ignored');
  $_GET['refresh'] = 'abc';
  t_eq(web_refresh_mins(array('refresh_mins' => 5)), 5, 'garbage ignored');
  unset($_GET['refresh']);
}

function test_epg_vertical_levels() {
  require_once dirname(__DIR__) . '/pages/_render.php';
  t_web_globals(array('site_token' => ''), array());
  $now = time();
  $mkrow = function ($t) use ($now) {
    return array('start_utc' => $now - 100, 'stop_utc' => $now + 100,
      'title' => $t, 'subtitle' => 'Alcim', 'descr' => 'Hosszu leiras ide.',
      'category' => '', 'rating' => '', 'star' => '', 'icon' => '',
      'year' => '', 'episode' => 'S1 E2');
  };
  $groups = array();
  foreach (array('A', 'B', 'C', 'D', 'E') as $s) {
    $groups[$s] = array($mkrow('T' . $s));
  }
  $names = array_combine(array_keys($groups), array_keys($groups));
  // one wide table, no wrapping into stacked tables
  $html = epg_list_v($groups, $names, $now, '1');
  t_eq(substr_count($html, '<table class="vgrid"'), 1, 'single table for 5 channels');
  t_ok(substr_count($html, 'width="20%"') >= 10, 'equal 20% columns (5ch header+cells)');
  t_ok(strpos($html, 'min-width:') !== false, 'table has min width for scroll');
  // level 1: time + title only (visible content, not tooltip attrs)
  t_ok(strpos($html, '<span class="ep">') === false, 'small: no episode');
  t_ok(strpos($html, '<i>Alcim</i>') === false, 'small: no subtitle');
  // level 2 (default): episode info, no description
  $html = epg_list_v($groups, $names, $now, null);
  t_ok(strpos($html, '<i>Alcim</i>') !== false, 'medium: subtitle shown');
  t_ok(strpos($html, '<span class="ep">Epizód: S1 E2</span>') !== false, 'medium: episode shown');
  t_ok(strpos($html, '<span class="epg-desc">') === false, 'medium: no description');
  // level 3: description too
  $html = epg_list_v($groups, $names, $now, '3');
  t_ok(strpos($html, '<span class="epg-desc">Hosszu leiras ide.</span>') !== false,
    'large: description shown');
  // tooltip like the horizontal view
  t_ok(strpos($html, 'title="') !== false, 'hover tooltip present');
  // regression: no duplicated title attribute leaking as visible text
  t_ok(strpos($html, '"> title="') === false, 'no stray title text');
}

function test_web_abs_built() {
  // subfolder: base must appear exactly once after the domain
  $cfg = array('base_path' => '/tvsite');
  $base_url = 'https://example.com/tvsite';
  t_eq(web_abs_built($cfg, $base_url, '/tvsite/?ch=RTL'),
    'https://example.com/tvsite/?ch=RTL', 'no doubled base');
  // domain root: unchanged
  $cfg = array('base_path' => '');
  $base_url = 'https://example.com';
  t_eq(web_abs_built($cfg, $base_url, '/?ch=RTL'),
    'https://example.com/?ch=RTL', 'root install intact');
}

function test_web_merge_days() {
  $mk = function ($s) {
    return array('start_utc' => $s, 'stop_utc' => $s + 100, 'title' => 'T' . $s);
  };
  // overlapping 00:00-04:00 region appears in both day queries
  $g1 = array('RTL' => array($mk(100), $mk(200)), 'TV2' => array($mk(150)));
  $g2 = array('RTL' => array($mk(200), $mk(300)), 'M1' => array($mk(250)));
  $m = web_merge_days($g1, $g2);
  t_eq(array_keys($m), array('RTL', 'TV2', 'M1'), 'channels merged');
  $starts = array();
  foreach ($m['RTL'] as $r) {
    $starts[] = $r['start_utc'];
  }
  t_eq($starts, array(100, 200, 300), 'overlap deduped + sorted');
}

function test_html_validator_catches_real_bugs() {
  // the exact historical shape: duplicate title INSIDE the tag plus the
  // leaked copy as visible text right after it
  $bad = '<ul class="progs"><li class="past" title="22:00 Show"> title="22:00 Show">22:00 T</li></ul>';
  $probs = t_html_problems($bad);
  $joined = implode(';', $probs);
  t_ok(strpos($joined, 'leaked attribute text') !== false, 'leaked text detected');
  // duplicate attributes inside one tag are also flagged
  $probs = t_html_problems('<li title="a" title="b">T</li>');
  $joined = implode(';', $probs);
  t_ok(strpos($joined, 'duplicate attribute') !== false, 'dupe attr detected');
  // unbalanced tags
  $probs = t_html_problems('<div><table><tr><td>x</td></tr>');
  t_ok(count($probs) > 0, 'unbalanced table detected');
  // clean markup passes silently
  t_eq(t_html_problems('<div><p><a href="/x">T</a><br><img src="i.png"></p></div>'),
    array(), 'valid markup passes');
}

function test_web_subfolder_mode() {  // domain root: unchanged behavior
  t_eq(web_base(array()), '', 'empty base');
  t_eq(web_base(array('base_path' => '')), '', 'explicit empty base');
  t_eq(web_base(array('base_path' => '/tv')), '/tv', 'subfolder base kept');
  t_eq(web_base(array('base_path' => 'tv/')), '/tv', 'base normalized');
  // request path stripping
  $_SERVER['REQUEST_URI'] = '/tv/grid/film/?x=1';
  unset($_GET['p']);
  t_eq(web_request_path(array('base_path' => '/tv')), '/grid/film/',
    'base prefix stripped');
  $_SERVER['REQUEST_URI'] = '/tv';
  t_eq(web_request_path(array('base_path' => '/tv')), '/', 'bare base -> home');
  $_SERVER['REQUEST_URI'] = '/other/x';
  t_true(web_request_path(array('base_path' => '/tv')) === null,
    'outside install -> null (router 404s)');
  $_SERVER['REQUEST_URI'] = '/grid/film/';
  t_eq(web_request_path(array()), '/grid/film/', 'root mode untouched');
  $_GET['p'] = '/grid/film/';
  t_eq(web_request_path(array('base_path' => '/tv')), '/grid/film/',
    '?p= fallback bypasses base');
  unset($_GET['p']);
  // link + asset prefixing
  t_web_globals(array('base_path' => '/tv', 'site_token' => ''), array());
  t_ok(strpos(u('/grid/film/'), '/tv/grid/film/') === 0, 'u() prefixed');
  t_eq(web_asset('/style.css'), '/tv/style.css', 'asset prefixed');
  t_web_globals(array('site_token' => ''), array());
  t_eq(u('/'), '/', 'root mode: no prefix');
}

function test_web_provider_stats() {  $d = t_tmpdir();
  $pdo = new PDO('sqlite:' . $d . '/t.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $st = web_provider_stats($pdo, 'ripper');
  t_eq($st['channels'], 0, 'unimported provider: zero channels');
  t_true($st['first'] === null, 'unimported provider: no dates');
  epg_init_provider_schema($pdo, 'ripper');
  $now = time();
  $ins = $pdo->prepare('INSERT INTO programs_ripper'
    . ' (channel,start_utc,stop_utc,title) VALUES (?,?,?,?)');
  $ins->execute(array('RTL', $now - 3600, $now + 3600, 'A'));
  $ins->execute(array('TV2', $now + 86400, $now + 90000, 'B'));
  epg_meta_set($pdo, 'ripper', 'last_import', (string)$now);
  $st = web_provider_stats($pdo, 'ripper');
  t_eq($st['programmes'], 2, 'programme count');
  t_ok($st['first'] < $st['last'], 'first < last');
  $cov = web_date_coverage($pdo, 'ripper');
  t_eq(count($cov), 2, 'two covered dates');
  t_eq(array_sum($cov), 2, 'coverage sums to programmes');
  $cc = web_channel_counts($pdo, 'ripper', date('Y-m-d', $now));
  t_ok(isset($cc['RTL']), 'today channel counted');
  $progs = web_day_programmes($pdo, 'ripper', 'RTL', date('Y-m-d', $now));
  t_eq(count($progs), 1, 'day programme list');
  t_eq($progs[0]['title'], 'A', 'programme title');
}

function test_web_evening_marker() {  t_web_globals();
  // port.hu eveningStartTime 19:50 local
  t_eq(web_evening_start('2026-09-18'),
    gmmktime(17, 50, 0, 9, 18, 2026), 'evening starts 19:50+02:00');
}

// --- HTTP smoke: isolated docroot + tiny seeded db, real router ---

function t_web_docroot() {
  static $site = null;
  if ($site !== null) {
    return $site;
  }
  if (!function_exists('curl_init')) {
    return false;
  }
  $root = dirname(__DIR__);
  $d = t_tmpdir() . '/webroot';
  mkdir($d, 0777, true);
  foreach (array('lib', 'pages', 'templates', 'api', 'cron', 'config') as $n) {
    if (!file_exists($root . '/' . $n)) {
      continue; // optional dir (e.g. removed empty config/)
    }
    mkdir($d . '/' . $n, 0777, true);
    $it = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($root . '/' . $n, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
      $rel = substr($f->getPathname(), strlen($root . '/' . $n));
      if ($f->isDir()) {
        mkdir($d . '/' . $n . $rel, 0777, true);
      } else {
        copy($f->getPathname(), $d . '/' . $n . $rel);
      }
    }
  }
  foreach (array('index.php', 'style.css', 'robots.txt') as $f) {
    copy($root . '/' . $f, $d . '/' . $f);
  }
  require_once __DIR__ . '/bootstrap.php';
  $cfg = t_project_config();
  $cfg['token'] = 'CRONTEST';
  $cfg['site_token'] = 'SITETEST';
  $cfg['cookies'] = false;
  $cfg['db_path'] = $d . '/epg.sqlite';
  $cfg['providers'] = array('ripper');
  mkdir($d . '/var', 0777, true);
  file_put_contents($d . '/config.php', '<?php return ' . var_export($cfg, true) . ';');
  // seed: 2 channels, programmes around "now" (container clock = data date)
  $now = time();
  $fmt = function ($ts) {
    return gmdate('YmdHis', $ts) . ' +0000';
  };
  $xml = '<?xml version="1.0" encoding="UTF-8"?><tv>'
    . '<channel id="RTL.hu"><display-name lang="hu">RTL</display-name></channel>'
    . '<channel id="TV2.hu"><display-name lang="hu">TV2</display-name></channel>'
    . '<programme start="' . $fmt($now - 3600) . '" stop="' . $fmt($now + 3600)
    . '" channel="RTL.hu"><title lang="hu">Live Show</title>'
    . '<desc lang="hu">Leiras.</desc><category lang="hu">Akció</category></programme>'
    . '<programme start="' . $fmt($now + 3600) . '" stop="' . $fmt($now + 7200)
    . '" channel="TV2.hu"><title lang="hu">News</title>'
    . '<desc lang="hu">Hirek.</desc></programme></tv>';
  file_put_contents($d . '/seed.xml', $xml);
  require_once $root . '/lib/epg.php';
  $pdo = new PDO('sqlite:' . $cfg['db_path']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  epg_import_file($d . '/seed.xml', $pdo,
    array('wipe' => true, 'provider' => 'ripper'));
  @unlink($d . '/seed.xml');
  $port = 8923;
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

function t_web_curl($url, &$code, &$headers = null) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);
  curl_setopt($ch, CURLOPT_HEADER, true);
  $resp = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  unset($ch);
  $headers = substr($resp, 0, $hsize);
  return substr($resp, $hsize);
}

function test_web_home_nojs_nocookie() {
  $s = t_web_docroot();
  if ($s === false) {
    echo "  SKIP (no curl or server)\n";
    return;
  }
  $body = t_web_curl($s['base'] . '/?token=SITETEST', $code, $headers);
  t_eq($code, 200, 'home 200');
  t_false(stripos($headers, 'Set-Cookie') !== false, 'no cookies set');
  t_ok(stripos($headers, 'no-store') !== false, 'Cache-Control: no-store');
  t_ok(stripos($headers, 'Pragma: no-cache') !== false, 'Pragma: no-cache');
  // strip the SEO ld+json data block, then no executable traces may remain
  $stripped = preg_replace('#<script type="application/ld\+json">.*?</script>#s', '', $body);
  foreach (array('<script', 'onclick', 'onchange', 'onload', 'fetch(') as $bad) {
    t_false(stripos($stripped, $bad) !== false, "no JS trace: $bad");
  }
  t_ok(strpos($body, 'Live Show') !== false, 'live programme rendered');
  t_ok(strpos($body, 'id="now"') !== false, 'server-side #now anchor');
  t_ok(strpos($body, 'token=SITETEST') !== false, 'token rides links');
}

function test_web_views_and_routes() {
  $s = t_web_docroot();
  if ($s === false) {
    echo "  SKIP (no curl or server)\n";
    return;
  }
  $body = t_web_curl($s['base'] . '/?token=SITETEST&view=v', $code);
  t_eq($code, 200, 'vertical home 200');
  t_ok(strpos($body, '<ul class="progs"') !== false, 'vertical list rendered');
  $body = t_web_curl($s['base'] . '/tvmusor/RTL?token=SITETEST', $code);
  t_eq($code, 200, 'channel page 200');
  t_ok(strpos($body, 'Live Show') !== false, 'channel shows programme');
  t_web_curl($s['base'] . '/tvmusor/NOPE?token=SITETEST', $code);
  t_eq($code, 404, 'unknown channel 404');
  $body = t_web_curl($s['base'] . '/sitemapxml.php?token=SITETEST', $code);
  t_eq($code, 200, 'sitemap 200');
  t_ok(strpos($body, '<urlset') !== false, 'sitemap xml body');
  $body = t_web_curl($s['base'] . '/api/epg.php?token=SITETEST', $code, $headers);
  t_eq($code, 200, 'api 200');
  t_ok(stripos($headers, 'no-store') !== false, 'api no-store');
  $body = t_web_curl($s['base'] . '/cron/status.php?token=CRONTEST', $code, $headers);
  t_eq($code, 200, 'status 200');
  t_ok(stripos($headers, 'no-store') !== false, 'status no-store');
  // NOTE: cron/import.php is never curled here - it would start a live import.
  t_web_curl($s['base'] . '/?token=WRONG', $code);
  t_eq($code, 403, 'wrong site token 403');
}
