<?php
// tests/epg_test.php - unit tests for lib/epg.php (dates, episodes, slugs,
// streaming import of all 4 feed styles, day window, classify, prune, backup).

require_once dirname(__DIR__) . '/lib/epg.php';

// One XML exercising every feed dialect:
//  - ripper:  +0200, lang=hu, icon, onscreen, category, rating, star-rating
//  - hungary1: +0000, no lang, xmltv_ns
//  - freeepg std: +0000, <category>
//  - freeepg rytec: +0000, lang=de, category-as-sub-title
function t_sample_xml() {
  return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<!DOCTYPE tv SYSTEM "xmltv.dtd">' . "\n"
    . '<tv generator-info-name="test">' . "\n"
    . '  <channel id="RTL.hu"><display-name lang="hu">RTL</display-name>'
    . '<icon src="https://www.tvmustra.hu/logok/csatikon/rtl.gif" />'
    . '<url>http://www.tvmustra.hu</url></channel>' . "\n"
    . '  <channel id="RTL (HD).hu"><display-name>RTL (HD).hu</display-name></channel>' . "\n"
    . '  <channel id="Cool.hu"><display-name>Cool.hu</display-name></channel>' . "\n"
    . '  <programme start="20260918022500 +0200" stop="20260918033500 +0200" channel="RTL.hu">'
    . '<title lang="hu">Chicago Med</title><desc lang="hu">Will es Natalie.</desc>'
    . '<icon src="https://www.tvmustra.hu/800_kepek/a/b.jpg" />'
    . '<episode-num system="onscreen">S4 E9</episode-num>'
    . '<category lang="hu">Sorozat</category></programme>' . "\n"
    . '  <programme start="20260918040000 +0000" stop="20260918050000 +0000" channel="RTL (HD).hu">'
    . '<title>Reggeli</title><desc>Hirek.</desc>'
    . '<episode-num system="xmltv_ns">0.70.0</episode-num></programme>' . "\n"
    . '  <programme start="20260918040000 +0000" stop="20260918050000 +0000" channel="Cool.hu">'
    . '<title lang="de">Die Tester</title><desc lang="de">Audi vs Benz.</desc>'
    . '<sub-title lang="de">Sport</sub-title></programme>' . "\n"
    . '  <programme start="20260918050000 +0000" stop="20260918060000 +0000" channel="Cool.hu">'
    . '<title>Teleshop</title><desc>Vasarlas.</desc><category>Shop</category></programme>' . "\n"
    . '  <programme start="20260918070000 +0200" stop="20260918073000 +0200" channel="RTL.hu">'
    . '<title lang="hu">Film</title>'
    . '<category lang="hu">Film</category>'
    . '<rating system="HU"><value>12</value></rating>'
    . '<star-rating system="Imdb"><value>7.5</value></star-rating></programme>' . "\n"
    . '</tv>' . "\n";
}

function t_test_db($provider_suffix = '') {
  $d = t_tmpdir();
  $pdo = new PDO('sqlite:' . $d . '/t.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  return array($pdo, $d);
}

function test_epg_parse_dates() {
  $a = epg_parse_xmltv_date('20260918002500 +0000');
  $b = epg_parse_xmltv_date('20260918022500 +0200');
  t_true($a !== false && $b !== false, 'both tz forms parse');
  t_eq($a, $b, '00:25Z == 02:25+0200 (same instant)');
  t_eq($a, gmmktime(0, 25, 0, 9, 18, 2026), 'UTC instant correct');
  t_false(epg_parse_xmltv_date('not-a-date'), 'garbage returns false');
  // missing offset defaults to Budapest (+0200 in September)
  t_eq(epg_parse_xmltv_date('20260918022500'), $b, 'missing offset assumes local');
}

function test_epg_episode_normalization() {
  t_eq(epg_normalize_episode('3.8.0', 'xmltv_ns'), 'S4 E9', 'xmltv_ns 0-based -> S4 E9');
  t_eq(epg_normalize_episode('0.70.0', 'xmltv_ns'), 'S1 E71', 'xmltv_ns first season');
  t_eq(epg_normalize_episode('S2 E139', 'onscreen'), 'S2 E139', 'onscreen passthrough');
  t_eq(epg_normalize_episode('', 'xmltv_ns'), '', 'empty stays empty');
}

function test_epg_channel_mapping() {
  // fully dynamic: every feed spelling normalizes without any curated map
  $map = array();
  t_eq(epg_map_channel('RTL.hu', $map), 'RTL', 'ripper id');
  t_eq(epg_map_channel('RTL (HD).hu', $map), 'RTL', 'hungary1 id');
  t_eq(epg_map_channel('Cool.TV.hu', $map), 'COOL', 'dotted id');
  t_eq(epg_map_channel('COOL.hu', $map), 'COOL', 'freeepg id');
  t_eq(epg_canonical_slug('RTL.KETTŐ.hu'), 'RTL_KETTO', 'accent+dot normalize');
  t_eq(epg_canonical_slug('Duna TV (HD).hu'), 'DUNA', 'HD tag + _TV collapse');
  t_eq(epg_canonical_slug('Cool (HD).hu'), 'COOL', 'spaced HD id');
  t_eq(epg_canonical_slug('m1.HD.hu'), 'M1', 'm1 HD id');
  t_eq(epg_canonical_slug('RTL+.hu'), 'RTLPLUS', 'plus kept: RTL+ is not RTL');
  t_ok(epg_canonical_slug('RTL+.hu') !== epg_canonical_slug('RTL.hu'),
    'RTL+ and RTL stay separate channels');
}

function test_epg_import_all_dialects() {
  list($pdo, $d) = t_test_db();
  file_put_contents($d . '/s.xml', t_sample_xml());
  $st = epg_import_file($d . '/s.xml', $pdo, array(
    'wipe' => true, 'provider' => 't',
  ));
  t_eq($st['programmes'], 5, 'all 5 programmes imported');
  t_eq($st['skipped'], 0, 'nothing skipped');

  // provider tables exist, legacy tables untouched
  $tables = array();
  foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table'") as $r) {
    $tables[] = $r['name'];
  }
  t_ok(in_array('programs_t', $tables), 'programs_t table created');
  t_ok(in_array('channels_t', $tables), 'channels_t table created');
  t_false(in_array('programs', $tables), 'legacy programs table NOT created');

  // ripper-style rich row
  $row = $pdo->query("SELECT * FROM programs_t WHERE title='Chicago Med'")
    ->fetch(PDO::FETCH_ASSOC);
  t_eq($row['channel'], 'RTL', 'dotted id mapped to SLUG');
  t_eq($row['episode'], 'S4 E9', 'onscreen episode kept');
  t_eq($row['category'], 'Sorozat', 'category kept');
  t_ok(strpos($row['icon'], '800_kepek') !== false, 'tvmustra icon kept');

  // hungary1-style xmltv_ns row
  $row = $pdo->query("SELECT * FROM programs_t WHERE title='Reggeli'")
    ->fetch(PDO::FETCH_ASSOC);
  t_eq($row['episode'], 'S1 E71', 'xmltv_ns converted');
  t_eq($row['channel'], 'RTL', 'spaced HD id mapped to same SLUG');

  // rytec-style sub-title row
  $row = $pdo->query("SELECT * FROM programs_t WHERE title='Die Tester'")
    ->fetch(PDO::FETCH_ASSOC);
  t_eq($row['subtitle'], 'Sport', 'rytec sub-title stored');

  // rating / star-rating row
  $row = $pdo->query("SELECT * FROM programs_t WHERE title='Film'")
    ->fetch(PDO::FETCH_ASSOC);
  t_eq($row['rating'], '12', 'HU age rating stored');
  t_eq($row['star'], '7.5', 'IMDb star stored');

  // gzip path: same file through compress.zlib wrapper
  $gz = gzencode(t_sample_xml(), 6);
  file_put_contents($d . '/s.xml.gz', $gz);
  $st2 = epg_import_file($d . '/s.xml.gz', $pdo, array(
    'map' => array(), 'wipe' => true, 'provider' => 't2',
  ));
  t_eq($st2['programmes'], 5, '.gz import works');
}

function test_epg_import_records_meta() {
  list($pdo, $d) = t_test_db();
  file_put_contents($d . '/s.xml', t_sample_xml());
  epg_import_file($d . '/s.xml', $pdo, array('wipe' => true, 'provider' => 'm'));
  t_ok(epg_meta_get($pdo, 'm', 'last_import', '') !== '', 'last_import recorded');
  t_eq(epg_meta_get($pdo, 'm', 'programmes', ''), '5', 'programme count recorded');
  t_eq(epg_meta_get($pdo, 'm', 'missing', 'dflt'), 'dflt', 'meta default works');
}

function test_epg_day_window_and_classify() {
  list($from, $to, $d) = epg_day_bounds('2026-09-18');
  // 00:00 -> +1d 04:00 Budapest (+0200) == 22:00Z prev day -> 02:00Z next day
  t_eq($from, gmmktime(22, 0, 0, 9, 17, 2026), 'window start = 00:00+02:00');
  t_eq($to, gmmktime(2, 0, 0, 9, 19, 2026), 'window end = next-day 04:00+02:00');
  list(, , $bad) = epg_day_bounds('not-a-date');
  t_eq($bad, date('Y-m-d'), 'invalid date falls back to today');

  $now = epg_parse_xmltv_date('20260918030000 +0200');
  t_eq(epg_classify($now - 7200, $now - 3600, $now), 'past', 'ended -> past');
  t_eq(epg_classify($now - 3600, $now + 3600, $now), 'live', 'running -> live');
  t_eq(epg_classify($now + 3600, $now + 7200, $now), 'future', 'upcoming -> future');

  // end-to-end: query returns all 5 rows inside the window
  list($pdo, $dd) = t_test_db();
  file_put_contents($dd . '/s.xml', t_sample_xml());
  epg_import_file($dd . '/s.xml', $pdo, array('wipe' => true, 'provider' => 'q'));
  list($groups) = epg_query_day($pdo, '2026-09-18', null, 'Europe/Budapest', 'q');
  $n = 0;
  foreach ($groups as $rows) {
    $n += count($rows);
  }
  t_eq($n, 5, 'window query finds all rows');
  list($none) = epg_query_day($pdo, '2020-01-01', null, 'Europe/Budapest', 'q');
  t_eq(count($none), 0, 'empty window outside data');
}

function test_epg_filter_slugs() {
  $out = epg_filter_slugs('RTL, tv2, BOGUS, rtl', array('RTL', 'TV2'));
  t_eq($out, array('RTL', 'TV2'), 'CSV validated, unknown dropped, dupes removed');
  t_eq(epg_filter_slugs(array('cool'), array('COOL')), array('COOL'),
    'input uppercased before allow-list compare');
}

function test_epg_prune_bounds_growth() {
  list($pdo, $d) = t_test_db();
  epg_init_provider_schema($pdo, 'p');
  $now = epg_parse_xmltv_date('20260918120000 +0200');
  $ins = $pdo->prepare('INSERT INTO programs_p (channel,start_utc,stop_utc,title)'
    . ' VALUES (?,?,?,?)');
  $ins->execute(array('RTL', $now - 40 * 86400, $now - 40 * 86400 + 3600, 'ancient'));
  $ins->execute(array('RTL', $now - 86400, $now - 86400 + 3600, 'yesterday'));
  $ins->execute(array('RTL', $now + 3600, $now + 7200, 'today'));
  $ins->execute(array('RTL', $now + 40 * 86400, $now + 40 * 86400 + 3600, 'far'));
  $del = epg_prune($pdo, 30, 8, $now, 'p');
  t_eq($del, 2, 'prune deletes ancient + far-future');
  $left = $pdo->query("SELECT title FROM programs_p ORDER BY start_utc")
    ->fetchAll(PDO::FETCH_COLUMN);
  t_eq($left, array('yesterday', 'today'), 'only in-window rows survive');
}

function test_epg_backup_gz_streams() {
  list($pdo, $d) = t_test_db();
  file_put_contents($d . '/s.xml', t_sample_xml());
  epg_import_file($d . '/s.xml', $pdo, array('wipe' => true, 'provider' => 'b'));
  $db = $d . '/t.sqlite';
  $snap = epg_backup_gz($pdo, $db, $d . '/bak', 2);
  t_ok(is_file($snap) && filesize($snap) > 0, 'snapshot written');
  t_ok(filesize($snap) < filesize($db), 'snapshot smaller than db');
  // round-trip: gunzip == original
  $orig = file_get_contents($db);
  $back = file_get_contents('compress.zlib://' . $snap);
  t_eq(md5($back), md5($orig), 'snapshot round-trips byte-identical');
  // rotation: pre-seed 2 stale snapshots, newest $keep survive
  // (filenames carry minute granularity, so same-minute runs share one file)
  file_put_contents($d . '/bak/epg-20200101-0000.sqlite.gz', 'old1');
  file_put_contents($d . '/bak/epg-20200201-0000.sqlite.gz', 'old2');
  epg_backup_gz($pdo, $db, $d . '/bak', 2);
  $left = glob($d . '/bak/epg-*.sqlite.gz');
  t_eq(count($left), 2, 'rotation keeps 2');
  t_ok(!is_file($d . '/bak/epg-20200101-0000.sqlite.gz'), 'oldest rotated out');
  t_ok(is_file($snap), 'fresh snapshot survives rotation');
}

function test_epg_meta_usable_on_fresh_db() {
  // regression: cron's catch-block records last_error via meta BEFORE any
  // import ever ran. Without epg_init_meta() that recording itself 500s.
  list($pdo) = t_test_db();
  epg_init_meta($pdo);
  epg_meta_set($pdo, 'ripper', 'last_error', 'boom');
  t_eq(epg_meta_get($pdo, 'ripper', 'last_error', ''), 'boom',
    'error recording works on fresh db');
}

function test_epg_busy_timeout() {
  // readers must survive a concurrent import write-lock instead of
  // instantly failing (which the frontend mistook for "no data")
  $d = t_tmpdir();
  $db = $d . '/t.sqlite';
  $writer = epg_open_db($db);
  epg_init_provider_schema($writer, 't');
  $reader = epg_open_db($db);
  t_eq((int)$reader->query("PRAGMA busy_timeout")->fetchColumn(), 30000,
    'busy timeout armed on open');
  $writer->exec('BEGIN EXCLUSIVE'); // what COMMIT/VACUUM hold: blocks readers
  $writer->exec("INSERT INTO programs_t (channel,start_utc,stop_utc,title)"
    . " VALUES ('RTL',1,2,'X')");
  $impatient = new PDO('sqlite:' . $db);
  $impatient->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $impatient->exec('PRAGMA busy_timeout = 0');
  $busy = false;
  try {
    $impatient->query('SELECT COUNT(*) FROM programs_t')->fetchColumn();
  } catch (Exception $e) {
    $busy = stripos($e->getMessage(), 'locked') !== false;
  }
  t_true($busy, 'zero-timeout reader hits the lock (the old failure mode)');
  $writer->exec('ROLLBACK');
  t_eq((int)$reader->query("SELECT COUNT(*) FROM programs_t")->fetchColumn(), 0,
    'reader works after release');
}

function test_epg_bad_provider_rejected() {  list($pdo) = t_test_db();
  $thrown = false;
  try {
    epg_provider_tables('../evil');
  } catch (InvalidArgumentException $e) {
    $thrown = true;
  }
  t_true($thrown, 'provider id validated (SQL injection guard)');
}

function test_epg_film_url_migration() {
  // live DBs created before film_url existed: init must upgrade in place
  // (no wipe), and reads must survive even before the upgrade runs.
  list($pdo) = t_test_db();
  // old schema: every column except film_url
  $pdo->exec('CREATE TABLE programs_m (channel TEXT, start_utc INTEGER,'
    . ' stop_utc INTEGER, title TEXT, subtitle TEXT, descr TEXT, year TEXT,'
    . ' icon TEXT, episode TEXT, category TEXT, rating TEXT, star TEXT,'
    . ' UNIQUE(channel, start_utc))');
  $pdo->exec("INSERT INTO programs_m (channel,start_utc,stop_utc,title)"
    . " VALUES ('RTL',1789959600,1789963200,'Reggeli')");
  epg_init_provider_schema($pdo, 'm');
  $cols = array();
  foreach ($pdo->query('PRAGMA table_info(programs_m)') as $r) {
    $cols[] = $r['name'];
  }
  t_ok(in_array('film_url', $cols), 'film_url added to old table');
  list($groups) = epg_query_day($pdo, '2026-09-21', null, 'Europe/Budapest', 'm');
  t_ok(isset($groups['RTL']), 'migrated table still queries');
  t_ok(empty($groups['RTL'][0]['film_url']), 'missing link reads as empty');
  // pre-upgrade read path: table without the column must not fail the guide
  $pdo->exec('CREATE TABLE programs_m2 (channel TEXT, start_utc INTEGER,'
    . ' stop_utc INTEGER, title TEXT, subtitle TEXT, descr TEXT, year TEXT,'
    . ' icon TEXT, episode TEXT, category TEXT, rating TEXT, star TEXT,'
    . ' UNIQUE(channel, start_utc))');
  $pdo->exec("INSERT INTO programs_m2 (channel,start_utc,stop_utc,title)"
    . " VALUES ('RTL',1789959600,1789963200,'Reggeli')");
  list($groups) = epg_query_day($pdo, '2026-09-21', null, 'Europe/Budapest', 'm2');
  t_ok(isset($groups['RTL']), 'unmigrated table still serves');
  t_ok(empty($groups['RTL'][0]['film_url']), 'unmigrated link reads as empty');
}
