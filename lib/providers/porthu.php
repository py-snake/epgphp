<?php
// lib/providers/porthu.php - port.hu tvapi (JSON, not XMLTV).
// Endpoints (HAR-verified 2026-09-21, AI.md section 10):
//   GET https://port.hu/tvapi/init-new
//     -> {channels: [{id: "tvchannel-5", name: "RTL", link, logo, ...}],
//         daysDate: ["2026-09-19T00:00:00+02:00", ...], ...}
//   GET https://port.hu/tvapi?channel_id[]=ID...&date=YYYY-MM-DD
//     -> {date_from, date_to, channels: [{id, programs: [...]}]}
// Program: {id, title, episode_title, short_description ("180. rész" or
// "amerikai akció-horror, sci-fi, 2022"), description (null in listings),
// start_ts (UTC unix), end_datetime (ISO +02:00), restriction:
// {age_limit, category}, is_repeat, ...}. The "type" field is only a daypart
// bucket (past/afternoon/evening); tense is classified at render time from
// start/stop like every other provider.
// No ETag/Last-Modified upstream, so every import refetches (init ~58KB,
// ~1.5MB per 40 channels per day). Programs upsert by (channel, start_utc);
// retention/prune bounds the tables afterwards. Politeness: a short pause
// (delay_ms, default 500) sleeps between API calls, never hammered in a
// burst - slower is better than banned. Old-PHP safe (>= 7.0).

function epg_provider_porthu() {
  return array(
    'id'             => 'porthu',
    'label'          => 'port.hu tvapi',
    'urls'           => array('https://port.hu/tvapi/init-new'),
    'tz_hint'        => '+0200',
    'custom_import'  => 'epg_import_porthu',
  );
}

function epg_porthu_api_base() {
  return 'https://port.hu/tvapi';
}

// Channel SLUG from the port.hu display name, via the same normalizer the
// XMLTV feeds use ("RTL KETTŐ" -> "RTL_KETTO", "m1" -> "M1"), so ?ch= slugs
// stay consistent across providers. $used tracks taken slugs; HD dupes
// ("Animal Planet" vs "Animal Planet HD") get a "_2" suffix, init order wins.
function epg_porthu_slug($name, array &$used) {
  $base = epg_canonical_slug((string)$name);
  $slug = $base;
  $i = 2;
  while (isset($used[$slug])) {
    $slug = $base . '_' . $i;
    $i++;
  }
  $used[$slug] = true;
  return $slug;
}

// port.hu restriction.category is a slug ("gyermek-musor", "sportmusor").
// Keep it raw, then append Hungarian genre words so the existing category
// filters (film/sport/gyerek/termeszet/zene) match. Accents kept: the
// matcher lowercases with mb_strtolower and strpos-matches.
function epg_porthu_category($raw) {
  $raw = trim((string)$raw);
  static $hu = array(
    'film' => 'film',
    'filmsorozat' => 'sorozat film',
    'gyermek-musor' => 'gyerek mese',
    'zenei-musor' => 'zene koncert',
    'sportmusor' => 'sport',
    'dokumentumfilm' => 'dokumentum természet ismeretterjesztő',
    'ismeretterjeszto-musor' => 'ismeretterjesztő természet dokumentum',
    'hir-politikai-musor' => 'hír',
    'hirmusor' => 'hír',
    'szabadidos-musor' => 'szórakoztató szabadidő',
    'szolgaltato-musor' => 'szórakoztató szolgáltató',
    'szorakoztato-musor' => 'szórakoztató',
    'reality-musor' => 'szórakoztató valóságshow',
    'gasztronomiai-musor' => 'gasztronómia',
    'vallasi-musor' => 'vallási',
    'muveszeti-musor' => 'művészeti',
    'indaplay-video' => 'videó',
  );
  if ($raw === '') {
    return '';
  }
  $extra = isset($hu[$raw]) ? $hu[$raw]
    : trim(str_replace(array('-', '_'), ' ', $raw));
  return $extra === '' || $extra === $raw ? $raw : $raw . ' ' . $extra;
}

// Trailing production year in short_description ("..., 2022").
function epg_porthu_year($short) {
  if (preg_match('/\b((?:19|20)\d{2})\s*$/', trim((string)$short), $m)) {
    return $m[1];
  }
  return '';
}

// Map one tvapi program to a programs-table row (channel filled by caller).
// Returns null when the row is unusable (no start, no title).
function epg_porthu_prog_row(array $p) {
  $start = isset($p['start_ts']) ? (int)$p['start_ts'] : 0;
  if ($start <= 0) {
    return null;
  }
  $stop = 0;
  if (!empty($p['end_datetime'])) {
    try {
      $dt = new DateTime((string)$p['end_datetime']);
      $stop = $dt->getTimestamp();
    } catch (Exception $e) {
      $stop = 0;
    }
  }
  if ($stop <= $start) {
    $stop = $start + 30 * 60;
  }
  $title = trim((string)(isset($p['title']) ? $p['title'] : ''));
  if ($title === '') {
    return null;
  }
  $short = trim((string)(isset($p['short_description']) ? $p['short_description'] : ''));
  $descr = trim((string)(isset($p['description']) ? $p['description'] : ''));
  $episode = '';
  if ($short !== '' && preg_match('/rész/iu', $short)) {
    $episode = $short; // "180. rész", "III / 16. rész"
  } elseif ($descr === '') {
    $descr = $short; // genre blurb ("amerikai akció-horror, sci-fi, 2022")
  }
  $restr = isset($p['restriction']) && is_array($p['restriction']) ? $p['restriction'] : array();
  $age = isset($restr['age_limit']) ? (int)$restr['age_limit'] : 0;
  // NOTE: key order matters - epg_import_porthu() inserts array_values()
  // positionally, so film_url must stay LAST here and in the INSERT lists.
  return array(
    'start_utc' => $start,
    'stop_utc' => $stop,
    'title' => $title,
    'subtitle' => trim((string)(isset($p['episode_title']) ? $p['episode_title'] : '')),
    'descr' => $descr,
    'year' => epg_porthu_year($short),
    'icon' => '',
    'episode' => $episode,
    'category' => epg_porthu_category(isset($restr['category']) ? $restr['category'] : ''),
    'rating' => $age > 0 ? (string)$age : '',
    'star' => '',
    'film_url' => trim((string)(isset($p['film_url']) ? $p['film_url'] : '')),
  );
}

// Day-fetch URLs: channels in batches (verified live: 40/batch works).
function epg_porthu_day_urls(array $ids, $date, $batch = 40) {
  $batch = max(1, (int)$batch);
  $urls = array();
  foreach (array_chunk(array_values($ids), $batch) as $chunk) {
    $q = array();
    foreach ($chunk as $id) {
      $q[] = 'channel_id[]=' . urlencode((string)$id);
    }
    $urls[] = epg_porthu_api_base() . '?'
      . implode('&', $q) . '&date=' . urlencode((string)$date);
  }
  return $urls;
}

// Full import: init (channels) + every daysDate day (programs).
// $opts: timeout (60), batch (40), delay_ms (500: pause between API calls,
// 0 disables), progress (callable, per day),
//   init_json (test seam: skip HTTP), day_json (test seam: date => raw JSON),
//   days (test seam: override day list), provider (set by cron wrapper).
// Returns stats like epg_import_file(): channels/programmes/skipped.
function epg_import_porthu(PDO $pdo, array $def, array $opts = array()) {
  $provider = isset($opts['provider']) ? (string)$opts['provider']
    : (isset($def['id']) ? (string)$def['id'] : 'porthu');
  $timeout = isset($opts['timeout']) ? (int)$opts['timeout'] : 60;
  $batch = isset($opts['batch']) ? (int)$opts['batch'] : 40;
  $delay_us = (isset($opts['delay_ms']) ? max(0, (int)$opts['delay_ms']) : 500) * 1000;
  $progress = isset($opts['progress']) && is_callable($opts['progress']) ? $opts['progress'] : null;
  list($t_chan, $t_prog) = epg_provider_tables($provider);
  epg_init_provider_schema($pdo, $provider);

  // ---- 1. channels ----
  if (array_key_exists('init_json', $opts)) {
    $init_raw = $opts['init_json'];
  } else {
    list($init_raw, $st) = epg_http_get(epg_porthu_api_base() . '/init-new', $timeout, '', '');
    if ($init_raw === false || $st >= 400) {
      throw new RuntimeException('porthu init fetch failed (' . $st . ')');
    }
  }
  $init = json_decode((string)$init_raw, true);
  if (!is_array($init) || !isset($init['channels']) || !is_array($init['channels'])) {
    throw new RuntimeException('porthu init: bad JSON');
  }
  $used = array();
  $id2slug = array();
  $chan_rows = array();
  foreach ($init['channels'] as $c) {
    if (!is_array($c) || empty($c['id']) || empty($c['name'])) {
      continue;
    }
    $slug = epg_porthu_slug($c['name'], $used);
    $id2slug[(string)$c['id']] = $slug;
    $chan_rows[] = array($slug, (string)$c['name'],
      isset($c['logo']) ? (string)$c['logo'] : '', (string)$c['id']);
  }
  if (!count($chan_rows)) {
    throw new RuntimeException('porthu init: no channels');
  }
  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $pdo->beginTransaction();
  $pdo->exec("DELETE FROM $t_chan");
  if ($driver === 'mysql') {
    $st_chan = $pdo->prepare("INSERT INTO `$t_chan` (slug,name,logo,xmltv_id) VALUES (?,?,?,?) " .
      "ON DUPLICATE KEY UPDATE name=VALUES(name), logo=VALUES(logo), xmltv_id=VALUES(xmltv_id)");
  } else {
    $st_chan = $pdo->prepare("INSERT OR REPLACE INTO \"$t_chan\" (slug,name,logo,xmltv_id) VALUES (?,?,?,?)");
  }
  foreach ($chan_rows as $r) {
    $st_chan->execute($r);
  }
  $pdo->commit();

  // ---- 2. days ----
  if (array_key_exists('days', $opts) && is_array($opts['days'])) {
    $days = $opts['days'];
  } else {
    $days = array();
    if (isset($init['daysDate']) && is_array($init['daysDate'])) {
      foreach ($init['daysDate'] as $iso) {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$iso, $m)
          && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
          $days[] = $m[1] . '-' . $m[2] . '-' . $m[3];
        }
      }
    }
  }
  $day_json = isset($opts['day_json']) && is_array($opts['day_json']) ? $opts['day_json'] : null;
  $ids = array_keys($id2slug);
  // table quoting per driver (MySQL treats "x" as a string literal)
  $tq = $driver === 'mysql' ? "`$t_prog`" : "\"$t_prog\"";

  if ($driver === 'mysql') {
    $mk_prog = function () use ($pdo, $tq) {
      return $pdo->prepare("INSERT INTO $tq
        (channel,start_utc,stop_utc,title,subtitle,descr,year,icon,episode,category,rating,star,film_url)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE stop_utc=VALUES(stop_utc), title=VALUES(title),
        subtitle=VALUES(subtitle), descr=VALUES(descr), year=VALUES(year), icon=VALUES(icon),
        episode=VALUES(episode), category=VALUES(category), rating=VALUES(rating), star=VALUES(star),
        film_url=VALUES(film_url)");
    };
  } else {
    $mk_prog = function () use ($pdo, $tq) {
      return $pdo->prepare("INSERT OR REPLACE INTO $tq
        (channel,start_utc,stop_utc,title,subtitle,descr,year,icon,episode,category,rating,star,film_url)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    };
  }

  // Intraday-safe refresh: no full-table wipe (60+ HTTP calls can die
  // mid-import). Instead each day is replaced as one unit - fetch first,
  // then DELETE that day's window + INSERT fresh rows in a single txn.
  // Removed/rescheduled programmes disappear on the next run, while a
  // failed day keeps yesterday's rows instead of an empty window.
  // Channels absent from a day response keep their old rows (safe default).
  $stats = array('channels' => count($chan_rows), 'programmes' => 0, 'skipped' => 0);
  foreach ($days as $date) {
    $date = (string)$date;
    $day_prog = 0;
    $day_skip = 0;
    $rows = array(); // collect first: short txn, never during HTTP
    $day_slugs = array();
    if ($day_json !== null && array_key_exists($date, $day_json)) {
      $payloads = array((string)$day_json[$date]);
    } else {
      $payloads = array();
      foreach (epg_porthu_day_urls($ids, $date, $batch) as $url) {
        if ($delay_us > 0) {
          usleep($delay_us); // politeness: never burst the API
        }
        list($raw, $st) = epg_http_get($url, $timeout, '', '');
        if ($raw === false || $st >= 400) {
          throw new RuntimeException('porthu day fetch failed (' . $st . '): ' . $date);
        }
        $payloads[] = (string)$raw;
      }
    }
    foreach ($payloads as $raw) {
      $doc = json_decode($raw, true);
      if (!is_array($doc) || !isset($doc['channels']) || !is_array($doc['channels'])) {
        throw new RuntimeException('porthu day: bad JSON (' . $date . ')');
      }
      foreach ($doc['channels'] as $ch) {
        if (!is_array($ch) || empty($ch['id']) || !isset($id2slug[(string)$ch['id']])) {
          continue;
        }
        $slug = $id2slug[(string)$ch['id']];
        if (!isset($ch['programs']) || !is_array($ch['programs'])) {
          continue;
        }
        $day_slugs[$slug] = true;
        foreach ($ch['programs'] as $p) {
          if (!is_array($p)) {
            $day_skip++;
            continue;
          }
          $row = epg_porthu_prog_row($p);
          if ($row === null) {
            $day_skip++;
            continue;
          }
          $rows[] = array_merge(array($slug), array_values($row));
        }
      }
    }
    $pdo->beginTransaction();
    try {
      // window replace for the fetched channels only (validated date, so a
      // malformed day key can never wipe the wrong window)
      if (count($day_slugs) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        list($wfrom, $wto) = epg_day_bounds($date);
        $ph = implode(',', array_fill(0, count($day_slugs), '?'));
        $st_del = $pdo->prepare("DELETE FROM $tq"
          . " WHERE channel IN ($ph) AND start_utc >= ? AND start_utc < ?");
        $st_del->execute(array_merge(array_keys($day_slugs), array($wfrom, $wto)));
      }
      $st_prog = $mk_prog();
      foreach ($rows as $r) {
        $st_prog->execute($r);
        $day_prog++;
      }
      $pdo->commit();
    } catch (Exception $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }
    $stats['programmes'] += $day_prog;
    $stats['skipped'] += $day_skip;
    if ($progress !== null) {
      call_user_func($progress, array('date' => $date,
        'programmes' => $stats['programmes'], 'skipped' => $stats['skipped']));
    }
  }
  epg_meta_set($pdo, $provider, 'last_import', (string)time());
  epg_meta_set($pdo, $provider, 'programmes', (string)$stats['programmes']);
  epg_meta_set($pdo, $provider, 'channels', (string)$stats['channels']);
  return $stats;
}
