<?php
// lib/epg_front.php - RECOMMENDED read path for the server-rendered site.
// Same-process PHP calls (require, no HTTP): pages/*.php get grouped rows
// with past|live|future already classified. api/epg.php wraps this same
// function in JSON, so HTML and JSON can never diverge.

function epg_front_day(PDO $pdo, array $cfg, $date_ymd, $ch_input, $provider = null) {
  $tz = isset($cfg['tz']) ? $cfg['tz'] : 'Europe/Budapest';
  $enabled = isset($cfg['providers']) ? $cfg['providers'] : array('ripper');
  $defs = function_exists('epg_provider_defs') ? epg_provider_defs() : array();

  // candidate providers: explicit ?provider= first, then config priority order
  $chain = array();
  if ($provider !== null && $provider !== '') {
    $chain[] = (string)$provider;
  }
  foreach ($enabled as $id) {
    if (!in_array($id, $chain, true)) {
      $chain[] = $id;
    }
  }

  // channel allow-list for ?ch= validation: slugs straight from the
  // provider channel tables (fully dynamic, no curated lists).
  $allowed = array();
  foreach ($chain as $pid) {
    list($t_chan) = epg_provider_tables($pid);
    try {
      foreach ($pdo->query("SELECT slug FROM $t_chan") as $r) {
        if (!in_array($r['slug'], $allowed, true)) {
          $allowed[] = $r['slug'];
        }
      }
    } catch (Exception $e) {
    }
  }
  $sel = epg_filter_slugs($ch_input, $allowed);
  // unknown-SLUG fallback: if config list is empty, pass raw input through
  if (!count($allowed) && is_string($ch_input) && $ch_input !== '') {
    $sel = array_map('strtoupper', array_map('trim', explode(',', $ch_input)));
  }

  $now = epg_now();
  foreach ($chain as $pid) {
    if (!isset($defs[$pid]) && $pid !== '') {
      continue; // no such provider module
    }
    try {
      list($groups, $from, $to, $date_ok) = epg_query_day($pdo, $date_ymd, $sel, $tz, $pid);
    } catch (Exception $e) {
      continue; // missing table etc. -> next provider
    }
    $n = 0;
    foreach ($groups as $rows) {
      $n += count($rows);
    }
    if ($n === 0) {
      continue; // healthy but no data for this window -> try fallback
    }
    // requested channel order wins over SQL's alphabetical order (?ch= is
    // an ordered CSV - the settings reorder links rely on this)
    if (count($sel) > 1) {
      $rank = array_flip($sel);
      uksort($groups, function ($a, $b) use ($rank) {
        $ra = isset($rank[$a]) ? $rank[$a] : 9999;
        $rb = isset($rank[$b]) ? $rank[$b] : 9999;
        if ($ra === $rb) {
          return strcasecmp($a, $b);
        }
        return ($ra < $rb) ? -1 : 1;
      });
    }
    // attach classification + anchor flags (server does the live-jump, AI.md 2.3)
    $first_live_marked = false;
    foreach ($groups as $slug => &$rows) {
      foreach ($rows as &$r) {
        $r['cls'] = epg_classify($r['start_utc'], $r['stop_utc'], $now);
        $r['anchor'] = false;
      }
      unset($r);
    }
    unset($rows);
    foreach ($groups as $slug => &$rows) {
      foreach ($rows as &$r) {
        if ($r['cls'] === 'live' && !$first_live_marked) {
          $r['anchor'] = true; // single id="now" per page
          $first_live_marked = true;
        }
      }
      unset($r);
    }
    unset($rows);
    return array($groups, $from, $to, $date_ok, $pid, false);
  }
  return array(array(), 0, 0, $date_ymd, $provider, true); // no_data
}
