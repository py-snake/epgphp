<?php
// tests/porthu_test.php - port.hu tvapi provider (lib/providers/porthu.php).
// Offline: hand-made fixtures, no network (init_json/day_json seams).

require_once dirname(__DIR__) . '/lib/epg.php';
require_once dirname(__DIR__) . '/lib/web.php';
require_once dirname(__DIR__) . '/lib/providers/porthu.php';

function t_porthu_db() {
  $d = t_tmpdir();
  $pdo = new PDO('sqlite:' . $d . '/t.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  return array($pdo, $d);
}

function t_porthu_init_fixture() {
  return json_encode(array(
    'channels' => array(
      array('id' => 'tvchannel-5', 'name' => 'RTL',
        'logo' => 'https://media.port.hu/images/001/707/100x60/301.jpg'),
      array('id' => 'tvchannel-241', 'name' => 'RTL KETTŐ', 'logo' => ''),
      array('id' => 'tvchannel-82', 'name' => 'Animal Planet', 'logo' => ''),
      array('id' => 'tvchannel-231', 'name' => 'Animal Planet HD', 'logo' => ''),
    ),
    'daysDate' => array('2026-09-21T00:00:00+02:00', '2026-09-22T00:00:00+02:00'),
  ));
}

function t_porthu_day_fixture() {
  return json_encode(array(
    'date_from' => '2026-09-21T00:00:00+02:00',
    'date_to' => '2026-09-22T04:00:00+02:00',
    'channels' => array(
      array('id' => 'tvchannel-5', 'programs' => array(
        array('id' => 'event-tv-1-5', 'title' => 'Reggeli',
          'episode_title' => '180. rész',
          'short_description' => '180. rész',
          'description' => null,
          'start_ts' => gmmktime(3, 0, 0, 9, 21, 2026),
          'end_datetime' => '2026-09-21T06:00:00+02:00',
          'film_url' => '/adatlap/film/tv/reggeli/event-tv-1-5/episode-3899563',
          'restriction' => array('age_limit' => 12, 'category' => 'szolgaltato-musor'),
          'is_repeat' => false, 'type' => 'past'),
        array('id' => 'event-tv-2-5', 'title' => 'Morbius',
          'episode_title' => null,
          'short_description' => 'amerikai akció-horror, sci-fi, 2022',
          'description' => null,
          'start_ts' => gmmktime(18, 5, 0, 9, 21, 2026),
          'end_datetime' => '2026-09-21T23:15:00+02:00',
          'restriction' => array('age_limit' => 16, 'category' => 'film'),
          'is_repeat' => false, 'type' => 'evening'),
        array('id' => 'event-tv-3-5', 'title' => 'Meccs',
          'episode_title' => null,
          'short_description' => 'foci',
          'description' => null,
          'start_ts' => gmmktime(19, 0, 0, 9, 21, 2026),
          'end_datetime' => '2026-09-21T21:00:00+02:00',
          'restriction' => array('age_limit' => 0, 'category' => 'sportmusor'),
          'is_repeat' => true, 'type' => 'evening'),
      )),
      array('id' => 'tvchannel-241', 'programs' => array(
        array('id' => 'event-tv-1-241', 'title' => 'Mese',
          'episode_title' => 'A nagy kaland',
          'short_description' => '6. rész',
          'description' => null,
          'start_ts' => gmmktime(5, 0, 0, 9, 21, 2026),
          'end_datetime' => '2026-09-21T07:30:00+02:00',
          'restriction' => array('age_limit' => 6, 'category' => 'gyermek-musor'),
          'is_repeat' => false, 'type' => 'past'),
      )),
    ),
  ));
}

function test_porthu_def() {
  $def = epg_provider_porthu();
  t_eq($def['id'], 'porthu', 'provider id');
  t_ok(!empty($def['label']), 'label present');
  t_ok(!empty($def['urls']), 'init URL present');
  t_ok(strpos($def['urls'][0], 'port.hu/tvapi/init-new') !== false, 'init URL points at tvapi');
  t_true(is_callable($def['custom_import']), 'custom_import callable');
}

function test_porthu_slugs() {
  $used = array();
  t_eq(epg_porthu_slug('RTL', $used), 'RTL', 'plain name');
  t_eq(epg_porthu_slug('RTL KETTŐ', $used), 'RTL_KETTO', 'accent+space like ripper');
  t_eq(epg_porthu_slug('m1', $used), 'M1', 'lowercase name');
  t_eq(epg_porthu_slug('Hír TV', $used), 'HIR', '_TV collapse like xmltv');
  t_eq(epg_porthu_slug('Animal Planet', $used), 'ANIMAL_PLANET', 'first wins base');
  t_eq(epg_porthu_slug('Animal Planet HD', $used), 'ANIMAL_PLANET_2', 'HD dupe suffixed');
}

function test_porthu_category_filter_match() {
  // translated categories must hit the existing frontend keyword filters
  foreach (array(
    array('film', 'film'),
    array('filmsorozat', 'film'),
    array('sportmusor', 'sport'),
    array('gyermek-musor', 'gyerekeknek'),
    array('zenei-musor', 'zene'),
    array('dokumentumfilm', 'termeszet'),
    array('ismeretterjeszto-musor', 'termeszet'),
  ) as $pair) {
    list($raw, $cat) = $pair;
    $kw = web_cat_keywords($cat);
    t_true($kw !== false, "known cat $cat");
    t_true(web_cat_match(array('category' => epg_porthu_category($raw),
      'subtitle' => ''), $kw), "$raw matches $cat filter");
  }
  t_eq(epg_porthu_category(''), '', 'empty stays empty');
}

function test_porthu_prog_row() {
  $row = epg_porthu_prog_row(array(
    'title' => 'Reggeli', 'episode_title' => '180. rész',
    'short_description' => '180. rész', 'description' => null,
    'start_ts' => gmmktime(3, 0, 0, 9, 21, 2026),
    'end_datetime' => '2026-09-21T06:00:00+02:00',
    'restriction' => array('age_limit' => 12, 'category' => 'szolgaltato-musor')));
  t_eq($row['start_utc'], gmmktime(3, 0, 0, 9, 21, 2026), 'start_ts is UTC instant');
  t_eq($row['stop_utc'], gmmktime(4, 0, 0, 9, 21, 2026), 'end_datetime +02:00 parsed');
  t_eq($row['subtitle'], '180. rész', 'episode_title -> subtitle');
  t_eq($row['episode'], '180. rész', 'rész-note -> episode');
  t_eq($row['rating'], '12', 'age limit kept');
  // genre blurb without episode info lands in descr, year extracted
  $row = epg_porthu_prog_row(array(
    'title' => 'Morbius', 'short_description' => 'amerikai akció-horror, sci-fi, 2022',
    'start_ts' => gmmktime(18, 5, 0, 9, 21, 2026),
    'end_datetime' => '2026-09-21T23:15:00+02:00',
    'restriction' => array('age_limit' => 0, 'category' => 'film')));
  t_eq($row['episode'], '', 'genre blurb is not an episode');
  t_ok(strpos($row['descr'], 'akció-horror') !== false, 'genre blurb kept in descr');
  t_eq($row['year'], '2022', 'trailing year extracted');
  t_eq($row['rating'], '', 'age 0 (all ages) shows no badge');
  // unusable rows skipped
  t_true(epg_porthu_prog_row(array('title' => 'X')) === null, 'no start -> null');
  t_true(epg_porthu_prog_row(array('start_ts' => 100)) === null, 'no title -> null');
}

function test_porthu_day_urls() {
  $ids = array('tvchannel-5', 'tvchannel-3', 'tvchannel-21');
  $urls = epg_porthu_day_urls($ids, '2026-09-21', 2);
  t_eq(count($urls), 2, '3 ids in batches of 2 -> 2 URLs');
  t_ok(strpos($urls[0], 'channel_id%5B%5D=tvchannel-5') !== false
    || strpos($urls[0], 'channel_id[]=tvchannel-5') !== false, 'first batch carries id');
  t_ok(strpos($urls[0], 'date=2026-09-21') !== false, 'date param present');
}

function test_porthu_offline_import() {
  list($pdo, $d) = t_porthu_db();
  $st = epg_import_porthu($pdo, epg_provider_porthu(), array(
    'provider' => 'porthut',
    'init_json' => t_porthu_init_fixture(),
    'day_json' => array('2026-09-21' => t_porthu_day_fixture()),
    'days' => array('2026-09-21'),
  ));
  t_eq($st['channels'], 4, '4 channels imported');
  t_eq($st['programmes'], 4, '4 programmes imported');
  $tables = array();
  foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table'") as $r) {
    $tables[] = $r['name'];
  }
  t_ok(in_array('channels_porthut', $tables), 'channels_porthut created');
  t_ok(in_array('programs_porthut', $tables), 'programs_porthut created');
  $names = array();
  foreach ($pdo->query('SELECT slug, name, xmltv_id FROM channels_porthut') as $r) {
    $names[$r['slug']] = array($r['name'], $r['xmltv_id']);
  }
  t_eq($names['RTL_KETTO'], array('RTL KETTŐ', 'tvchannel-241'), 'accent name + port id kept');
  t_ok(isset($names['ANIMAL_PLANET_2']), 'HD dupe slug suffixed');
  // query layer serves the rows in the port.hu overnight window
  list($groups) = epg_query_day($pdo, '2026-09-21', null, 'Europe/Budapest', 'porthut');
  t_ok(isset($groups['RTL']) && count($groups['RTL']) === 3, 'RTL day has 3 shows');
  $row = $pdo->query("SELECT * FROM programs_porthut WHERE title='Morbius'")
    ->fetch(PDO::FETCH_ASSOC);
  t_eq($row['year'], '2022', 'year stored');
  t_ok(strpos($row['category'], 'film') !== false, 'category stored');
  $row = $pdo->query("SELECT film_url FROM programs_porthut WHERE title='Reggeli'")
    ->fetch(PDO::FETCH_ASSOC);
  t_eq($row['film_url'], '/adatlap/film/tv/reggeli/event-tv-1-5/episode-3899563',
    'adatlap link stored');
  // re-import upserts instead of duplicating
  $st2 = epg_import_porthu($pdo, epg_provider_porthu(), array(
    'provider' => 'porthut',
    'init_json' => t_porthu_init_fixture(),
    'day_json' => array('2026-09-21' => t_porthu_day_fixture()),
    'days' => array('2026-09-21'),
  ));
  t_eq($st2['programmes'], 4, 're-import same count');
  $n = $pdo->query('SELECT COUNT(*) FROM programs_porthut')->fetchColumn();
  t_eq((int)$n, 4, 'upsert: no duplicates');
  // bad payloads fail loudly (cron marks the provider, keeps old tables)
  $thrown = false;
  try {
    epg_import_porthu($pdo, epg_provider_porthu(), array(
      'provider' => 'porthut', 'init_json' => 'not json', 'days' => array()));
  } catch (Exception $e) {
    $thrown = true;
  }
  t_true($thrown, 'bad init JSON throws');
  // delay option accepted (offline: no HTTP, so no sleeping either way)
  $st3 = epg_import_porthu($pdo, epg_provider_porthu(), array(
    'provider' => 'porthut',
    'init_json' => t_porthu_init_fixture(),
    'day_json' => array('2026-09-21' => t_porthu_day_fixture()),
    'days' => array('2026-09-21'),
    'delay_ms' => 0,
  ));
  t_eq($st3['programmes'], 4, 'delay_ms option accepted');
}

function test_porthu_time_links_out_title_links_local() {
  // card time -> port.hu adatlap (new window), card title -> local detail
  require_once dirname(__DIR__) . '/pages/_render.php';
  $GLOBALS['CFG'] = array('site_token' => '', 'cookies' => false,
    'default_provider' => 'porthut', 'providers' => array('porthut'),
    'tz' => 'Europe/Budapest', 'base_path' => '');
  $GLOBALS['WSTATE'] = array('provider' => 'porthut', 'view' => 'h');
  $GLOBALS['WCARRY'] = array();
  web_init($GLOBALS['CFG']);
  $_GET = array();
  $now = gmmktime(4, 0, 0, 9, 21, 2026);
  $mk = function ($title, $film_url) use ($now) {
    return array('start_utc' => $now - 100, 'stop_utc' => $now + 3600,
      'title' => $title, 'subtitle' => '', 'descr' => '', 'category' => '',
      'rating' => '12', 'star' => '', 'icon' => '', 'year' => '', 'episode' => '',
      'film_url' => $film_url);
  };
  $groups = array('RTL' => array($mk('Linked', '/adatlap/film/tv/x/event-1/movie-1')));
  $names = array('RTL' => 'RTL');
  $date = '2026-09-21';
  $h = epg_table_h($groups, $names, $now, $date);
  t_ok(strpos($h, '<div class="prog-time"><a href="https://port.hu/adatlap/film/tv/x/event-1/movie-1" target="_blank">') !== false,
    'horizontal time links to port.hu adatlap in new window');
  t_ok(strpos($h, '/musor/RTL/') !== false, 'horizontal title links local detail');
  $v = epg_list_v($groups, $names, $now, '2');
  t_ok(strpos($v, '<span class="ptime"><a href="https://port.hu/adatlap/film/tv/x/event-1/movie-1" target="_blank">') !== false,
    'vertical time links to port.hu adatlap in new window');
  t_ok(strpos($v, '/musor/RTL/') !== false, 'vertical title links local detail');
  // XMLTV rows without a link keep plain time text
  $groups = array('RTL' => array($mk('Plain', '')));
  $h = epg_table_h($groups, $names, $now, $date);
  t_ok(strpos($h, '<div class="prog-time">' . h(web_hm($now - 100)) . '</div>') !== false,
    'time without link stays plain text');
}
