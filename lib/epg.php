<?php
// lib/epg.php - streaming XMLTV importer + query layer (backend).
//
// Covers all 4 feeds compared 2026-09-18 (AI.md sections 11-12 + free-epg check):
//   - epgshare ripper HU1 : 193ch/22741prog, tz +0200, rich fields
//       title/sub-title/desc lang=hu, date, icon (source-site stills + port.hu),
//       episode-num onscreen, category, star-rating Imdb, rating HU
//   - open-epg Hungary1   : 179ch/13716prog, tz +0000, minimal fields
//       title/desc (no lang), episode-num xmltv_ns
//   - free-epg std        : 326ch/49227prog, tz +0000, stale window
//       title/desc/category (no lang)
//   - free-epg rytec      : same as std but category -> sub-title, lang=de
//
// Design notes (AI.md sections 2.3, 7, 11):
//   - Streaming only: XMLReader, one <channel>/<programme> node at a time.
//     Never simplexml_load_file / file_get_contents on the whole 17-19MB file.
//   - .gz handled via compress.zlib:// wrapper (cron: copy(.gz) then import).
//   - Time stored as UTC unix ints; local window 00:00 -> +1day 04:00
//     Europe/Budapest computed per ?date= at query time (port.hu semantics).
//   - past|live|future classified at render time via time() (no JS).
//   - Old-PHP safe: PHP >= 7.0, no typed props / arrow fns / match / str_contains.
//
// Schema (SQLite + MySQL compatible):
//   channels(slug TEXT PK, name TEXT, logo TEXT, xmltv_id TEXT)
//   programs(channel TEXT, start_utc INT, stop_utc INT, title TEXT,
//            subtitle TEXT, descr TEXT, year TEXT, icon TEXT, episode TEXT,
//            category TEXT, rating TEXT, star TEXT,
//            UNIQUE(channel, start_utc))

// ---------------------------------------------------------------------------
// DB
// ---------------------------------------------------------------------------

// Single JSON responder for every HTTP endpoint: pretty-printed, UTF-8
// readable (accents intact), slashes bare. CLI/log lines stay compact.
function epg_json($data) {
  echo json_encode($data,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Server-time correction: ?offset=N shifts "now" by N minutes (signed,
// max +-12h) for every live computation (status, now-line, labels) - in case
// the PHP clock is off. Garbage falls back to uncorrected time.
function epg_now() {
  $off = 0;
  if (isset($_GET['offset']) && preg_match('/^-?\d{1,4}$/', (string)$_GET['offset'])) {
    $off = max(-720, min(720, (int)$_GET['offset']));
  }
  return time() + $off * 60;
}

function epg_open_db($dsn_or_path) {
  if (strpos($dsn_or_path, ':') === false) {
    // plain path -> SQLite file
    $dsn = 'sqlite:' . $dsn_or_path;
  } else {
    $dsn = $dsn_or_path;
  }
  $pdo = new PDO($dsn);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  // native prepares off for old MySQL compat; harmless on SQLite
  if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
    $pdo->exec("SET NAMES utf8mb4");
  } else {
    // Wait (instead of instantly failing) when a cron import holds the
    // write lock: without this, page views DURING the nightly import get
    // SQLITE_BUSY, which the frontend mistakes for "no data" (empty guide).
    $pdo->exec("PRAGMA busy_timeout = 30000");
  }
  return $pdo;
}

function epg_init_schema(PDO $pdo) {
  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  if ($driver === 'mysql') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS channels (
      slug VARCHAR(64) PRIMARY KEY, name VARCHAR(128), logo VARCHAR(128),
      xmltv_id VARCHAR(128)) CHARACTER SET utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS programs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      channel VARCHAR(64), start_utc INT, stop_utc INT,
      title TEXT, subtitle TEXT, descr TEXT, year VARCHAR(8),
      icon TEXT, episode VARCHAR(64), category TEXT,
      rating VARCHAR(16), star VARCHAR(16),
      UNIQUE KEY uq_prog (channel, start_utc),
      KEY ix_prog_start (start_utc)) CHARACTER SET utf8mb4");
  } else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS channels (
      slug TEXT PRIMARY KEY, name TEXT, logo TEXT, xmltv_id TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS programs (
      channel TEXT, start_utc INTEGER, stop_utc INTEGER,
      title TEXT, subtitle TEXT, descr TEXT, year TEXT,
      icon TEXT, episode TEXT, category TEXT,
      rating TEXT, star TEXT,
      UNIQUE(channel, start_utc))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_prog_start ON programs(start_utc)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_prog_chan ON programs(channel, start_utc)");
  }
}

// ---------------------------------------------------------------------------
// Multi-provider storage: programs_<id>, channels_<id>, meta.
// Each provider keeps its own tables; reads choose (never merge).
// $provider = '' means the legacy unprefixed tables (pre-split imports).
// ---------------------------------------------------------------------------

function epg_provider_tables($provider) {
  $provider = (string)$provider;
  if ($provider !== '' && !preg_match('/^[a-z0-9_]+$/', $provider)) {
    throw new InvalidArgumentException('bad provider id: ' . $provider);
  }
  $sfx = $provider === '' ? '' : '_' . $provider;
  return array('channels' . $sfx, 'programs' . $sfx);
}

function epg_init_provider_schema(PDO $pdo, $provider) {
  list($t_chan, $t_prog) = epg_provider_tables($provider);
  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  if ($driver === 'mysql') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `$t_chan` (
      slug VARCHAR(64) PRIMARY KEY, name VARCHAR(128), logo VARCHAR(128),
      xmltv_id VARCHAR(128)) CHARACTER SET utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `$t_prog` (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      channel VARCHAR(64), start_utc INT, stop_utc INT,
      title TEXT, subtitle TEXT, descr TEXT, year VARCHAR(8),
      icon TEXT, episode VARCHAR(64), category TEXT,
      rating VARCHAR(16), star VARCHAR(16),
      UNIQUE KEY uq_prog (channel, start_utc),
      KEY ix_prog_start (start_utc)) CHARACTER SET utf8mb4");
  } else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS \"$t_chan\" (
      slug TEXT PRIMARY KEY, name TEXT, logo TEXT, xmltv_id TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS \"$t_prog\" (
      channel TEXT, start_utc INTEGER, stop_utc INTEGER,
      title TEXT, subtitle TEXT, descr TEXT, year TEXT,
      icon TEXT, episode TEXT, category TEXT,
      rating TEXT, star TEXT,
      UNIQUE(channel, start_utc))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS \"ix_{$t_prog}_start\" ON \"$t_prog\"(start_utc)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS \"ix_{$t_prog}_chan\" ON \"$t_prog\"(channel, start_utc)");
  }
  $pdo->exec("CREATE TABLE IF NOT EXISTS meta (
    provider VARCHAR(64), k VARCHAR(64), v TEXT,
    PRIMARY KEY (provider, k))");
}

// Standalone meta init: the error-recording path (catch -> last_error) must
// work even on a fresh db where no provider import has created tables yet.
function epg_init_meta(PDO $pdo) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS meta (
    provider VARCHAR(64), k VARCHAR(64), v TEXT,
    PRIMARY KEY (provider, k))");
}

function epg_meta_set(PDO $pdo, $provider, $k, $v) {  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  if ($driver === 'mysql') {
    $st = $pdo->prepare("INSERT INTO meta (provider,k,v) VALUES (?,?,?)
      ON DUPLICATE KEY UPDATE v=VALUES(v)");
  } else {
    $st = $pdo->prepare("INSERT OR REPLACE INTO meta (provider,k,v) VALUES (?,?,?)");
  }
  $st->execute(array((string)$provider, (string)$k, (string)$v));
}

function epg_meta_get(PDO $pdo, $provider, $k, $default = null) {
  try {
    $st = $pdo->prepare("SELECT v FROM meta WHERE provider=? AND k=?");
    $st->execute(array((string)$provider, (string)$k));
    $v = $st->fetchColumn();
  } catch (Exception $e) {
    return $default; // meta table may not exist yet on first run
  }
  return $v === false ? $default : $v;
}

// File-level compressed snapshot: var/backup/epg-YYYYMMDD-HHMM.sqlite.gz
// Returns snapshot path or ''. SQLite-only; MySQL dumps are out of scope.
// Streams in 1MB chunks (gzopen) so a 120MB db never spikes memory_limit,
// which on shared hosting is often 128M.
function epg_backup_gz(PDO $pdo, $db_path, $backup_dir, $keep = 7) {
  if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || !is_file($db_path)) {
    return '';
  }
  if (!function_exists('gzopen')) {
    return ''; // no zlib ext: skip backup instead of fatal
  }
  @mkdir($backup_dir, 0777, true);
  $snap = rtrim($backup_dir, '/') . '/epg-' . date('Ymd-Hi') . '.sqlite.gz';
  $in = @fopen($db_path, 'rb');
  $out = @gzopen($snap, 'wb6');
  if ($in === false || $out === false) {
    if ($in !== false) {
      fclose($in);
    }
    if ($out !== false) {
      gzclose($out);
    }
    @unlink($snap);
    return '';
  }
  while (!feof($in)) {
    $chunk = fread($in, 1048576);
    if ($chunk === false) {
      break;
    }
    gzwrite($out, $chunk);
  }
  fclose($in);
  gzclose($out);
  // rotation: keep newest $keep
  $files = glob(rtrim($backup_dir, '/') . '/epg-*.sqlite.gz');
  if (is_array($files) && count($files) > $keep) {
    sort($files);
    foreach (array_slice($files, 0, count($files) - $keep) as $old) {
      @unlink($old);
    }
  }
  return $snap;
}

// ---------------------------------------------------------------------------
// Channel mapping
// ---------------------------------------------------------------------------

// Build xmltv_id -> SLUG lookup from an explicit override map (rarely
// needed: the normalizer below handles feed spellings; empty = all dynamic).
function epg_build_xmltv_map(array $cfg) {
  $map = array();
  foreach ($cfg as $slug => $row) {
    if (isset($row['xmltv']) && is_array($row['xmltv'])) {
      foreach ($row['xmltv'] as $xid) {
        $map[$xid] = $slug;
      }
    }
  }
  return $map;
}

// Fallback normalizer for ids not in the explicit map.
// "RTL.KETTŐ.hu" -> "RTL_KETTO", "Cool (HD).hu" -> "COOL", "Duna TV (HD).hu" -> "DUNA_TV"
function epg_canonical_slug($xmltv_id) {
  $s = $xmltv_id;
  // strip trailing .hu (the country suffix all 4 feeds use)
  $s = preg_replace('/\.hu$/i', '', $s);
  // transliterate Hungarian + German accents (old-PHP safe, no intl dep)
  $from = array('Á','á','É','é','Í','í','Ó','ó','Ö','ö','Ő','ő','Ú','ú','Ü','ü','Ű','ű','Ä','ä','Ö','ö','Ü','ü','ß');
  $to   = array('A','a','E','e','I','i','O','o','O','o','O','o','U','u','U','u','U','u','A','a','O','o','U','u','ss');
  $s = str_replace($from, $to, $s);
  $s = str_replace('+', 'PLUS', $s);
  $s = strtoupper($s);
  // drop quality tags that differ between feeds but mean the same channel:
  // "(HD)", ".HD"/" HD"/"-HD"/"_HD" chunks and glued "HBOHD" suffixes
  $s = preg_replace('/\s*\(HD\)\s*/', '', $s);
  $s = preg_replace('/[\s._-]+HD(?![A-Z0-9])/', '', $s);
  $s = preg_replace('/HD$/', '', $s);
  // dots/spaces/dashes/slashes -> underscore
  $s = preg_replace('/[^A-Z0-9]+/', '_', $s);
  $s = trim($s, '_');
  // "COOL_TV" (ripper) and "COOL" (free-epg) are the same station
  $s = preg_replace('/_TV$/', '', $s);
  return $s === '' ? 'UNKNOWN' : $s;
}

function epg_map_channel($xmltv_id, array $xmltv_map) {
  if (isset($xmltv_map[$xmltv_id])) {
    return $xmltv_map[$xmltv_id];
  }
  return epg_canonical_slug($xmltv_id);
}

// ---------------------------------------------------------------------------
// Dates: "20260918022500 +0200" (ripper) vs "20260918002500 +0000" (others)
// ---------------------------------------------------------------------------

function epg_parse_xmltv_date($s) {
  $s = trim((string)$s);
  if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})?\s*([+-]\d{4})?/', $s, $m)) {
    return false;
  }
  $sec = isset($m[6]) && $m[6] !== '' ? $m[6] : '00';
  $off = isset($m[7]) && $m[7] !== '' ? $m[7] : '+0200'; // assume Budapest summer if missing
  $iso = sprintf('%s-%s-%s %s:%s:%s %s',
    $m[1], $m[2], $m[3], $m[4], $m[5], $sec,
    substr($off, 0, 3) . ':' . substr($off, 3, 2));
  try {
    $dt = new DateTime($iso);
  } catch (Exception $e) {
    return false;
  }
  return $dt->getTimestamp(); // UTC unix
}

// xmltv_ns is 0-based "3.8.0" (= S4 E9); onscreen already "S1 E70" -> keep.
function epg_normalize_episode($raw, $system) {
  $raw = trim((string)$raw);
  if ($raw === '') {
    return '';
  }
  if ($system === 'xmltv_ns' && preg_match('/^(\d+)\.(\d+)\.(\d+)?/', $raw, $m)) {
    $s = ((int)$m[1]) + 1;
    $e = ((int)$m[2]) + 1;
    return 'S' . $s . ' E' . $e;
  }
  return $raw; // onscreen ("S2 E139", "E178") or unknown system: pass through
}

// Pick text with lang preference hu > '' > en > hu-any > first.
// $nodes = array of array(text, lang)
function epg_pick_lang(array $nodes) {
  if (count($nodes) === 0) {
    return '';
  }
  if (count($nodes) === 1) {
    return $nodes[0][0];
  }
  foreach (array('hu', '') as $want) {
    foreach ($nodes as $n) {
      if ($n[1] === $want) {
        return $n[0];
      }
    }
  }
  foreach ($nodes as $n) {
    if (strpos($n[1], 'hu') === 0) {
      return $n[0];
    }
  }
  foreach (array('en', '') as $want) {
    foreach ($nodes as $n) {
      if ($n[1] === $want || strpos($n[1], 'en') === 0) {
        return $n[0];
      }
    }
  }
  return $nodes[0][0];
}

// ---------------------------------------------------------------------------
// Streaming import (XMLReader, .xml + .gz)
// ---------------------------------------------------------------------------

function epg_import_file($xml_path, PDO $pdo, array $opts = array()) {
  $xmltv_map = isset($opts['map']) ? $opts['map'] : array();
  $chan_meta = isset($opts['channels']) ? $opts['channels'] : array(); // slug => row
  $wipe = isset($opts['wipe']) ? (bool)$opts['wipe'] : true;
  $fallback_only = isset($opts['fallback_only']) ? (bool)$opts['fallback_only'] : false;
  $progress = isset($opts['progress']) && is_callable($opts['progress']) ? $opts['progress'] : null;
  $provider = isset($opts['provider']) ? (string)$opts['provider'] : '';
  list($t_chan, $t_prog) = epg_provider_tables($provider);

  if ($provider === '') {
    epg_init_schema($pdo);
  } else {
    epg_init_provider_schema($pdo, $provider);
  }

  // open (transparent .gz): XMLReader can read compress.zlib:// directly
  $open_path = $xml_path;
  if (preg_match('/\.gz$/i', $xml_path)) {
    $open_path = 'compress.zlib://' . $xml_path;
  }

  $reader = new XMLReader();
  // LIBXML_NONET: never fetch external DTD (ripper declares xmltv.dtd)
  $ok = $reader->open($open_path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
  if (!$ok) {
    throw new RuntimeException('cannot open XML: ' . $xml_path);
  }

  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $pdo->beginTransaction();
  if ($wipe && !$fallback_only) {
    // table names come from epg_provider_tables() ([a-z0-9_]+), safe unquoted
    $pdo->exec("DELETE FROM $t_prog");
    $pdo->exec("DELETE FROM $t_chan");
  }

  if ($driver === 'mysql') {
    $st_chan = $pdo->prepare("INSERT INTO `$t_chan` (slug,name,logo,xmltv_id) VALUES (?,?,?,?) " .
      "ON DUPLICATE KEY UPDATE name=VALUES(name), logo=VALUES(logo), xmltv_id=VALUES(xmltv_id)");
    if ($fallback_only) {
      $st_prog = $pdo->prepare("INSERT IGNORE INTO `$t_prog`
        (channel,start_utc,stop_utc,title,subtitle,descr,year,icon,episode,category,rating,star)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    } else {
      $st_prog = $pdo->prepare("INSERT INTO `$t_prog`
        (channel,start_utc,stop_utc,title,subtitle,descr,year,icon,episode,category,rating,star)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE stop_utc=VALUES(stop_utc), title=VALUES(title),
        subtitle=VALUES(subtitle), descr=VALUES(descr), year=VALUES(year), icon=VALUES(icon),
        episode=VALUES(episode), category=VALUES(category), rating=VALUES(rating), star=VALUES(star)");
    }
  } else {
    $st_chan = $pdo->prepare("INSERT OR REPLACE INTO \"$t_chan\" (slug,name,logo,xmltv_id) VALUES (?,?,?,?)");
    if ($fallback_only) {
      $st_prog = $pdo->prepare("INSERT OR IGNORE INTO \"$t_prog\"
        (channel,start_utc,stop_utc,title,subtitle,descr,year,icon,episode,category,rating,star)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    } else {
      $st_prog = $pdo->prepare("INSERT OR REPLACE INTO \"$t_prog\"
        (channel,start_utc,stop_utc,title,subtitle,descr,year,icon,episode,category,rating,star)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    }
  }

  $stats = array('channels' => 0, 'programmes' => 0, 'skipped' => 0);
  $seen_chan = array();

  while ($reader->read()) {
    if ($reader->nodeType !== XMLReader::ELEMENT) {
      continue;
    }
    $tag = $reader->localName;

    if ($tag === 'channel') {
      $xid = $reader->getAttribute('id');
      if ($xid === null || $xid === '') {
        continue;
      }
      // small node -> per-node simplexml is fine (never whole file)
      $outer = $reader->readOuterXml();
      $slug = epg_map_channel($xid, $xmltv_map);
      $name = $slug;
      $logo = '';
      $icon_src = '';
      if ($outer !== false && $outer !== '') {
        $sx = @simplexml_load_string($outer);
        if ($sx !== false) {
          $names = array();
          foreach ($sx->{'display-name'} as $dn) {
            $names[] = array(trim((string)$dn), (string)$dn['lang']);
          }
          if (count($names)) {
            $name = epg_pick_lang($names);
          }
          $ic = $sx->icon;
          if (isset($ic[0]['src'])) {
            $icon_src = trim((string)$ic[0]['src']);
          }
        }
      }
      // prefer explicit config meta (logo filenames) when present
      if (isset($chan_meta[$slug])) {
        if (!empty($chan_meta[$slug]['name'])) {
          $name = $chan_meta[$slug]['name'];
        }
        if (!empty($chan_meta[$slug]['logo_mini'])) {
          $logo = $chan_meta[$slug]['logo_mini'];
        }
      } elseif ($icon_src !== '' && strpos($icon_src, 'tvmustra.hu/logok/csatikon/') !== false) {
        $logo = basename($icon_src); // ripper carries usable logo filename
      }
      if (!isset($seen_chan[$slug])) {
        $st_chan->execute(array($slug, $name, $logo, $xid));
        $seen_chan[$slug] = true;
        $stats['channels']++;
      }
    } elseif ($tag === 'programme') {
      $start_raw = $reader->getAttribute('start');
      $stop_raw = $reader->getAttribute('stop');
      $xid = $reader->getAttribute('channel');
      $start = epg_parse_xmltv_date($start_raw);
      $stop = epg_parse_xmltv_date($stop_raw);
      if ($start === false || $xid === null || $xid === '') {
        $stats['skipped']++;
        continue;
      }
      if ($stop === false) {
        $stop = $start + 30 * 60;
      }
      $outer = $reader->readOuterXml();
      $titles = array();
      $descs = array();
      $subs = array();
      $cats = array();
      $year = '';
      $icon = '';
      $ep = '';
      $rating = '';
      $star = '';
      if ($outer !== false && $outer !== '') {
        $sx = @simplexml_load_string($outer);
        if ($sx !== false) {
          foreach ($sx->title as $e) {
            $titles[] = array(trim((string)$e), (string)$e['lang']);
          }
          foreach ($sx->desc as $e) {
            $descs[] = array(trim((string)$e), (string)$e['lang']);
          }
          foreach ($sx->{'sub-title'} as $e) {
            $subs[] = array(trim((string)$e), (string)$e['lang']);
          }
          foreach ($sx->category as $e) {
            $t = trim((string)$e);
            if ($t !== '') {
              $cats[] = $t;
            }
          }
          if (isset($sx->date)) {
            $year = trim((string)$sx->date);
          }
          // programme icon: first wins (ripper: source-site stills or port.hu)
          if (isset($sx->icon[0]['src'])) {
            $icon = trim((string)$sx->icon[0]['src']);
          }
          if (isset($sx->{'episode-num'})) {
            // multiple systems possible; prefer onscreen, else first
            $first = '';
            $first_sys = '';
            foreach ($sx->{'episode-num'} as $e) {
              $sys = (string)$e['system'];
              $t = trim((string)$e);
              if ($first === '') {
                $first = $t;
                $first_sys = $sys;
              }
              if ($sys === 'onscreen') {
                $ep = $t;
                break;
              }
            }
            if ($ep === '') {
              $ep = epg_normalize_episode($first, $first_sys);
            }
          }
          if (isset($sx->rating[0]->value)) {
            $rating = trim((string)$sx->rating[0]->value);
          }
          if (isset($sx->{'star-rating'}[0]->value)) {
            $star = trim((string)$sx->{'star-rating'}[0]->value);
          }
        }
      }
      $title = epg_pick_lang($titles);
      $desc = epg_pick_lang($descs);
      $sub = epg_pick_lang($subs);
      if ($title === '' && $sub === '' && $desc === '') {
        $stats['skipped']++;
        continue;
      }
      // rytec puts category text into sub-title: recover category when
      // <category> missing but sub-title looks like a genre tag
      $category = implode(', ', array_unique($cats));
      $slug = epg_map_channel($xid, $xmltv_map);
      $st_prog->execute(array(
        $slug, $start, $stop, $title, $sub, $desc, $year,
        $icon, $ep, $category, $rating, $star,
      ));
      $stats['programmes']++;
      if ($progress !== null && ($stats['programmes'] % 5000) === 0) {
        call_user_func($progress, $stats);
      }
    }
  }
  $reader->close();
  $pdo->commit();
  if ($provider !== '') {
    epg_meta_set($pdo, $provider, 'last_import', (string)time());
    epg_meta_set($pdo, $provider, 'programmes', (string)$stats['programmes']);
    epg_meta_set($pdo, $provider, 'channels', (string)$stats['channels']);
  }
  return $stats;
}

// ---------------------------------------------------------------------------
// Query: day window 00:00 -> +1day 04:00 Europe/Budapest (port.hu semantics,
// AI.md 2.3). $date_ymd validated, fallback today.
// ---------------------------------------------------------------------------

function epg_day_bounds($date_ymd, $tz_name = 'Europe/Budapest') {
  if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$date_ymd, $m)
    || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
    $date_ymd = date('Y-m-d');
  }
  $tz = new DateTimeZone($tz_name);
  $from = new DateTime($date_ymd . ' 00:00:00', $tz);
  $to = new DateTime($date_ymd . ' 00:00:00', $tz);
  $to->modify('+1 day +4 hours'); // overnight window to 04:00 next day
  return array($from->getTimestamp(), $to->getTimestamp(), $date_ymd);
}

// Returns array(slug => rows). $allowed_slugs null/empty = all.
// $provider = '' reads legacy tables; otherwise programs_<provider>.
function epg_query_day(PDO $pdo, $date_ymd, $allowed_slugs = null, $tz_name = 'Europe/Budapest', $provider = '') {
  list($from, $to, $date_ymd) = epg_day_bounds($date_ymd, $tz_name);
  list(, $t_prog) = epg_provider_tables($provider);
  // overlap: programme intersects [from, to)
  $sql = "SELECT channel,start_utc,stop_utc,title,subtitle,descr,year,icon,episode,category,rating,star
          FROM $t_prog WHERE stop_utc > ? AND start_utc < ? ";
  $params = array($from, $to);
  if (is_array($allowed_slugs) && count($allowed_slugs)) {
    $ph = implode(',', array_fill(0, count($allowed_slugs), '?'));
    $sql .= "AND channel IN ($ph) ";
    foreach ($allowed_slugs as $s) {
      $params[] = $s;
    }
  }
  $sql .= "ORDER BY channel, start_utc";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $out = array();
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $out[$r['channel']][] = $r;
  }
  return array($out, $from, $to, $date_ymd);
}

function epg_classify($start_utc, $stop_utc, $now = null) {
  if ($now === null) {
    $now = time();
  }
  if ($stop_utc <= $now) {
    return 'past';
  }
  if ($start_utc <= $now) {
    return 'live';
  }
  return 'future';
}

// Validate ?ch=CSV / ch[] against allow-list, preserve order, drop unknown.
function epg_filter_slugs($input, array $allowed) {
  $allow = array_flip($allowed);
  $out = array();
  if (is_string($input)) {
    $input = explode(',', $input);
  }
  if (!is_array($input)) {
    return $out;
  }
  foreach ($input as $s) {
    $s = strtoupper(trim((string)$s));
    if ($s !== '' && isset($allow[$s]) && !in_array($s, $out, true)) {
      $out[] = $s;
    }
  }
  return $out;
}

// ---------------------------------------------------------------------------
// Retention: keeps the DB bounded no matter which import flags are used.
// Feeds only ever cover ~2-4 days, so anything older than $keep_past_days
// or further out than $keep_future_days is unreachable via epg_query_day()
// and safe to delete. Runs inside its own transaction; VACUUM (SQLite only)
// reclaims the file size afterwards.
// ---------------------------------------------------------------------------

function epg_prune(PDO $pdo, $keep_past_days = 2, $keep_future_days = 8, $now = null, $provider = '') {
  if ($now === null) {
    $now = time();
  }
  list(, $t_prog) = epg_provider_tables($provider);
  $cut_past = $now - ((int)$keep_past_days) * 86400;
  $cut_future = $now + ((int)$keep_future_days) * 86400;
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("DELETE FROM $t_prog WHERE stop_utc < ? OR start_utc > ?");
    $st->execute(array($cut_past, $cut_future));
    $deleted = $st->rowCount();
    $pdo->commit();
  } catch (Exception $e) {
    // never leave a dangling transaction: later VACUUM etc. would fail on it
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
  if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    // VACUUM cannot run inside a transaction; commit first (done above).
    $pdo->exec("VACUUM");
  }
  return $deleted;
}
