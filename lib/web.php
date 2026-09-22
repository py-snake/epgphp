<?php
// lib/web.php - frontend helpers. URL-only state (no cookies, no JS):
// every link/form carries provider, view, ch, date and (if private) token.

function web_init(array $cfg) {
  date_default_timezone_set(isset($cfg['tz']) ? $cfg['tz'] : 'Europe/Budapest');
  if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
  }
}

function h($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// No-cache for every dynamic response: the EPG changes on every cron run
// and on every minute (?refresh / meta refresh must always hit the server,
// never serve a cached copy). HTTP/1.1 + HTTP/1.0 + old-IE covers.
function web_no_cache() {
  if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
  }
}

// Current shared state from query string.
function web_state(array $cfg) {
  $providers = isset($cfg['providers']) ? $cfg['providers'] : array('ripper');
  $provider = isset($_GET['provider']) ? (string)$_GET['provider'] : '';
  if (!in_array($provider, $providers, true)) {
    $provider = isset($cfg['default_provider']) ? $cfg['default_provider'] : $providers[0];
  }
  $view = isset($_GET['view']) ? (string)$_GET['view'] : 'h';
  if ($view !== 'v' && $view !== 'h') {
    $view = 'h';
  }
  $theme = (isset($_GET['theme']) && (string)$_GET['theme'] === 'dark') ? 'dark' : 'light';
  return array('provider' => $provider, 'view' => $view, 'theme' => $theme);
}

// Internal URL. Carries provider/view/site-token automatically; $params adds
// or overrides (null value drops the key). $path is absolute (/...).
// $frag appends #fragment: grid destinations use 'now' so page loads land on
// the live show (no-op on dates without live data - the id isn't emitted).
function u($path, array $params = array(), $state = null, $frag = null) {
  $cfg = $GLOBALS['CFG'];
  $st = $state !== null ? $state : $GLOBALS['WSTATE'];
  $q = array();
  if (!empty($cfg['site_token']) && (empty($cfg['cookies']) || $cfg['cookies'] === false)) {
    // pure-URL private instance: token must travel on every link
    $q['token'] = isset($_GET['token']) ? (string)$_GET['token'] : '';
  }
  $q['provider'] = $st['provider'];
  $q['view'] = $st['view'];
  if (isset($st['theme']) && $st['theme'] === 'dark') {
    $q['theme'] = 'dark';
  }
  if (isset($st['refresh']) && $st['refresh'] !== null && $st['refresh'] !== '') {
    $q['refresh'] = (string)$st['refresh'];
  }
  if (isset($st['offset']) && $st['offset'] !== null && $st['offset'] !== '' && $st['offset'] !== '0') {
    $q['offset'] = (string)$st['offset'];
  }
  if (isset($st['zoom']) && $st['zoom'] !== null) {
    $q['zoom'] = $st['zoom']; // explicit $params / WCARRY may still override
  }
  if (isset($st['font']) && in_array($st['font'], array('1', '2', '4', '5'), true)) {
    $q['font'] = $st['font']; // 3 = default, dropped
  }
  // page-level carry: filter state (ch/date/cat/h/...) the router wants kept
  // on every link. Explicit $params win; null drops the key.
  foreach (isset($GLOBALS['WCARRY']) ? $GLOBALS['WCARRY'] : array() as $k => $v) {
    if (!array_key_exists($k, $params) && $v !== '' && $v !== null) {
      $q[$k] = $v;
    }
  }
  foreach ($params as $k => $v) {
    if ($v === null) {
      unset($q[$k]);
    } else {
      $q[$k] = $v;
    }
  }
  // drop defaults for clean canonical URLs (provider/view always explicit
  // would bust caches; keep them only when non-default)
  $def_provider = isset($cfg['default_provider']) ? $cfg['default_provider'] : '';
  if (isset($q['provider']) && $q['provider'] === $def_provider && !array_key_exists('provider', $params)) {
    unset($q['provider']);
  }
  if (isset($q['view']) && $q['view'] === 'h' && !array_key_exists('view', $params)) {
    unset($q['view']);
  }
  if (isset($q['token']) && $q['token'] === '') {
    unset($q['token']);
  }
  $qs = http_build_query($q);
  $url = web_base($cfg) . $path;
  if ($qs !== '') {
    $url .= '?' . $qs;
  }
  if ($frag !== null && $frag !== '') {
    $url .= '#' . $frag;
  }
  return $url;
}

// Install base path: '' at domain root, '/tv' in a subfolder.
// Explicit config wins; null (default) auto-detects from the front
// controller path, so uploads work with zero config. Never trust
// REQUEST_URI for this (it is user input); SCRIPT_NAME is server-set.
function web_base(array $cfg) {
  if (array_key_exists('base_path', $cfg) && $cfg['base_path'] !== null) {
    $b = '/' . trim((string)$cfg['base_path'], '/');
    return $b === '/' ? '' : $b;
  }
  if (isset($_SERVER['SCRIPT_NAME'])
    && substr($_SERVER['SCRIPT_NAME'], -10) === '/index.php') {
    $d = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $d === '' ? '' : $d;
  }
  return '';
}

// Request path, app-relative. ?p= fallback is already app-relative;
// REQUEST_URI has the base prefix stripped. Returns null outside the install.
function web_request_path(array $cfg) {
  if (isset($_GET['p']) && $_GET['p'] !== '') {
    return '/' . ltrim((string)$_GET['p'], '/');
  }
  $path = '/';
  if (isset($_SERVER['REQUEST_URI'])) {
    $p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_string($p) && $p !== '') {
      $path = $p;
    }
  }
  $base = web_base($cfg);
  if ($base !== '') {
    if ($path === $base) {
      return '/';
    }
    if (strpos($path, $base . '/') === 0) {
      return substr($path, strlen($base));
    }
    return null;
  }
  return $path;
}

// Static asset under the install (css, img).
function web_asset($p) {
  return web_base($GLOBALS['CFG']) . $p;
}

// Effective provider: the wanted one if it actually has channels imported,
// else the first enabled provider with data (never 404 on a healthy DB just
// because the default/selected feed is empty for it).
function web_provider_has_channels(PDO $pdo, $provider) {
  try {
    list($t_chan) = epg_provider_tables($provider);
    return (int)$pdo->query("SELECT COUNT(*) FROM $t_chan")->fetchColumn() > 0;
  } catch (Exception $e) {
    return false;
  }
}

function web_resolve_provider(PDO $pdo, array $cfg, $wanted) {
  if (web_provider_has_channels($pdo, $wanted)) {
    return $wanted;
  }
  foreach (isset($cfg['providers']) ? $cfg['providers'] : array() as $pid) {
    if (web_provider_has_channels($pdo, $pid)) {
      return $pid;
    }
  }
  return $wanted;
}

// Absolute URL with domain, exactly once: $built comes from u() (already
// base-prefixed), $base_url already contains the base - strip one copy.
function web_abs_built(array $cfg, $base_url, $built) {
  $base = web_base($cfg);
  if ($base !== '' && strpos($built, $base . '/') === 0) {
    $built = substr($built, strlen($base));
  }
  return $base_url . $built;
}

function web_today() {
  return date('Y-m-d');
}

function web_valid_date($s) {
  if (is_string($s) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)
    && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
    return $s;
  }
  return web_today();
}

// Font size: legacy levels (?font=1..5, frozen for old bookmarks) or a
// percent offset (?font=-50..+200, 0 = normal). null = default, carried raw.
// Legacy 1..5 win over the percent range, so old links keep working.
function web_font_raw() {
  if (!isset($_GET['font'])) {
    return null;
  }
  return web_font_valid((string)$_GET['font'], null);
}

// Validated font token or $default (builder use). Legacy 1..5 pass through
// untouched; other ints in -50..+200 come back canonicalized.
function web_font_valid($s, $default = '0') {
  $s = trim((string)$s);
  if (in_array($s, array('1', '2', '3', '4', '5'), true)) {
    return $s;
  }
  if (preg_match('/^-?\d{1,4}$/', $s)) {
    $n = (int)$s;
    if ($n >= -50 && $n <= 200) {
      return (string)$n;
    }
  }
  return $default;
}

// Scale factor for new percent values, or null for default/legacy
// (those render via the base stylesheet / fs1..fs5 classes).
function web_font_scale($f = null) {
  if ($f === null) {
    $f = web_font_raw();
  } else {
    $f = (string)$f;
  }
  if ($f === null || $f === '' || $f === '0'
    || in_array($f, array('1', '2', '3', '4', '5'), true)) {
    return null;
  }
  if (!preg_match('/^-?\d+$/', $f)) {
    return null;
  }
  return (100 + (int)$f) / 100;
}

// Dynamic font stylesheet for percent values (old-browser safe: plain CSS
// text, no variables). Each component scales from its base size, rounded.
function web_font_css($f = null) {
  $s = web_font_scale($f);
  if ($s === null) {
    return '';
  }
  $px = function ($base) use ($s) {
    return max(6, (int)round($base * $s));
  };
  return 'body{font-size:' . $px(14) . 'px;}'
    . '.prog-item{font-size:' . $px(14) . 'px;height:' . $px(64) . 'px;}'
    . '.tlrel{height:' . $px(72) . 'px;}'
    . '.prog-item .prog-time{font-size:' . $px(12) . 'px;}'
    . 'td.chan{font-size:' . $px(14) . 'px;}'
    . 'ul.progs li{font-size:' . $px(14) . 'px;}'
    . '.navtab a{font-size:' . $px(12) . 'px;}';
}

// Body-px preview for the settings help line (legacy table + computed).
function web_font_preview_px($f) {
  static $legacy = array('1' => 11, '2' => 12, '3' => 14, '4' => 17, '5' => 20);
  $f = (string)$f;
  if (isset($legacy[$f])) {
    return $legacy[$f];
  }
  if (preg_match('/^-?\d+$/', $f)) {
    return max(6, (int)round(14 * (100 + (int)$f) / 100));
  }
  return 14;
}
// Wide enough that a bottom scrollbar appears and shows stay readable.
// Legacy levels (?zoom=0..4, frozen) or percent offset (?zoom=-75..+600).
function web_zoom_raw() {
  if (!isset($_GET['zoom'])) {
    return null;
  }
  return web_zoom_valid((string)$_GET['zoom'], null);
}

// Validated zoom token or $default (builder use). Legacy 0..4 pass through
// untouched (old bookmarks keep pixel-exact sizes); other ints in
// -75..+600 come back canonicalized.
function web_zoom_valid($s, $default = '0') {
  $s = trim((string)$s);
  if (in_array($s, array('0', '1', '2', '3', '4'), true)) {
    return $s;
  }
  if (preg_match('/^-?\d{1,4}$/', $s)) {
    $n = (int)$s;
    if ($n >= -75 && $n <= 600) {
      return (string)$n;
    }
  }
  return $default;
}

// Timeline zoom: pixel width of the whole 24h strip.
// Horizontal layout width. Vertical layout uses web_zoom_col_px().
// $z = null reads the request; legacy levels use the frozen table.
function web_zoom_px($z = null) {
  if ($z === null) {
    $z = web_zoom_raw();
  } else {
    $z = (string)$z;
  }
  if ($z === '0') {
    return 2400; // extra kicsi
  }
  if ($z === '1') {
    return 3600;
  }
  if ($z === '3') {
    return 9000;
  }
  if ($z === '4') {
    return 12000; // extra nagy
  }
  if ($z !== null && $z !== '' && $z !== '2' && preg_match('/^-?\d+$/', $z)) {
    $px = (int)round(6000 * (100 + (int)$z) / 100);
    if ($px < 1200) {
      $px = 1200;
    }
    if ($px > 42000) {
      $px = 42000;
    }
    return $px;
  }
  return 6000; // default
}

// Vertical column width per channel. Legacy levels use the frozen table,
// percent values scale proportionally from the 170px base.
// Mirrors the horizontal timeline so "Méret" visibly scales both layouts.
function web_zoom_col_px($z = null) {
  if ($z === null) {
    $z = web_zoom_raw();
  } else {
    $z = (string)$z;
  }
  if ($z === '0') {
    return 110; // extra kicsi
  }
  if ($z === '1') {
    return 140;
  }
  if ($z === '3') {
    return 210;
  }
  if ($z === '4') {
    return 260; // extra nagy
  }
  if ($z !== null && $z !== '' && $z !== '2' && preg_match('/^-?\d+$/', $z)) {
    $px = (int)round(170 * (100 + (int)$z) / 100);
    if ($px < 80) {
      $px = 80;
    }
    if ($px > 600) {
      $px = 600;
    }
    return $px;
  }
  return 170; // default (zoom=2 / unset)
}

// Vertical detail level: legacy levels keep their meaning, percent values
// switch at -25/+35 (reproduces the legacy points: -60/-40 -> 1, 0 -> 2,
// +50/+100 -> 3).
function web_zoom_level($z = null) {
  if ($z === null) {
    $z = function_exists('web_zoom_raw') ? web_zoom_raw() : null;
  } else {
    $z = (string)$z;
  }
  if ($z === '0' || $z === '1') {
    return 1;
  }
  if ($z === '3' || $z === '4') {
    return 3;
  }
  if ($z !== null && $z !== '' && $z !== '2' && preg_match('/^-?\d+$/', $z)) {
    $n = (int)$z;
    if ($n <= -25) {
      return 1;
    }
    if ($n >= 35) {
      return 3;
    }
  }
  return 2;
}

// Effective browser-refresh, minutes. ?refresh=N wins (0..120, 0 = off),
// otherwise the config default. Every other value is ignored (safe default).
function web_refresh_mins(array $cfg) {
  if (isset($_GET['refresh']) && preg_match('/^\d{1,3}$/', (string)$_GET['refresh'])) {
    $n = (int)$_GET['refresh'];
    if ($n <= 120) {
      return $n;
    }
  }
  return isset($cfg['refresh_mins']) ? (int)$cfg['refresh_mins'] : 0;
}

function web_valid_hour($s) {
  if ($s === '' || $s === null) {
    return null; // empty select option: no hour filter, clean URLs
  }
  if (is_string($s) || is_int($s)) {
    $h = (int)$s;
    if ($h >= 0 && $h <= 23) {
      return $h;
    }
  }
  return null;
}

function web_hm($ts) {
  return date('H:i', (int)$ts);
}

function web_now_text() {
  return 'Most: ' . date('H:i', epg_now());
}

// Channel slugs from ?ch=CSV and ?ch[]= (form). Returns array of valid SLUGs.
// When the checkbox form was used (ch[] present), bool $needs_redirect is set
// so the router can 302 to the canonical CSV form (AI.md 2.3).
function web_selected_channels(PDO $pdo, $provider, $date, &$needs_redirect) {
  $needs_redirect = false;
  $allowed = web_channel_slugs($pdo, $provider);
  if (isset($_GET['ch']) && is_array($_GET['ch'])) {
    // ?ch[]= checkbox form -> canonicalize to CSV + redirect (router does it)
    $needs_redirect = true;
    $sel = epg_filter_slugs($_GET['ch'], $allowed);
  } elseif (isset($_GET['ch']) && is_string($_GET['ch']) && $_GET['ch'] !== '') {
    if ($_GET['ch'] === '*') {
      return $allowed; // ?show=all expands here too (see below)
    }
    $sel = epg_filter_slugs($_GET['ch'], $allowed);
  } else {
    $sel = array();
  }
  if (isset($_GET['show']) && $_GET['show'] === 'all') {
    $needs_redirect = true;
    return $allowed;
  }
  return $sel;
}

// All known channel slugs for a provider, straight from its channel
// table (alphabetical). No curated lists anywhere.
function web_channel_slugs(PDO $pdo, $provider) {
  static $cache = array();
  if (isset($cache[$provider])) {
    return $cache[$provider];
  }
  list($t_chan) = epg_provider_tables($provider);
  $out = array();
  try {
    foreach ($pdo->query("SELECT slug FROM $t_chan ORDER BY slug") as $r) {
      $out[] = $r['slug'];
    }
  } catch (Exception $e) {
    // provider never imported: empty
  }
  $cache[$provider] = $out;
  return $out;
}

// Sort key for DISPLAY names: digits first, then alphabetical
// (case-insensitive). Byte order would do this for ASCII anyway; explicit so
// it holds regardless of locale. Fixes the "sorted by id, shown by name"
// jumble: sort by what the user actually sees.
function web_channel_sort_key($name) {
  $s = function_exists('mb_strtolower')
    ? mb_strtolower(trim((string)$name), 'UTF-8') : strtolower(trim((string)$name));
  $digit = (isset($s[0]) && $s[0] >= '0' && $s[0] <= '9') ? '0' : '1';
  return $digit . $s;
}

// slug => name, sorted by display name (numbers first, then ABC).
function web_sorted_channels(PDO $pdo, $provider) {
  $names = web_channel_names($pdo, $provider);
  $slugs = web_channel_slugs($pdo, $provider);
  $out = array();
  foreach ($slugs as $slug) {
    $out[$slug] = isset($names[$slug]) && $names[$slug] !== '' ? $names[$slug] : $slug;
  }
  uasort($out, function ($a, $b) {
    $ka = web_channel_sort_key($a);
    $kb = web_channel_sort_key($b);
    if ($ka === $kb) {
      return 0;
    }
    return ($ka < $kb) ? -1 : 1;
  });
  return $out;
}

// Reorder helper for the settings page: move item $i one step up/down.
// Returns a new array (bounds-safe, no-op at the edges). No JS needed:
// callers re-emit the whole list as links/forms with the swapped order.
function web_move_ch(array $list, $i, $dir) {
  $i = (int)$i;
  $j = $dir === 'up' ? $i - 1 : $i + 1;
  if (!isset($list[$i]) || !isset($list[$j])) {
    return array_values($list);
  }
  $out = array_values($list);
  $tmp = $out[$i];
  $out[$i] = $out[$j];
  $out[$j] = $tmp;
  return $out;
}

// slug => display name, straight from the feed's <display-name>.
function web_channel_names(PDO $pdo, $provider) {
  $names = array();
  list($t_chan) = epg_provider_tables($provider);
  try {
    foreach ($pdo->query("SELECT slug, name FROM $t_chan") as $r) {
      $names[$r['slug']] = $r['name'];
    }
  } catch (Exception $e) {
  }
  return $names;
}

// Default selection: channels with a healthy day (20+ shows), longest shows
// first - film/sport majors float up, 2-minute clip-channels sink.
// Fully data-driven, no curated list. Scans only the last 3 days (indexed):
// defaults must reflect current data, not months-old history.
function web_default_channels(PDO $pdo, $provider, $n = 12) {
  list(, $t_prog) = epg_provider_tables($provider);
  try {
    $st = $pdo->query("SELECT channel, AVG(stop_utc - start_utc) a"
      . " FROM $t_prog GROUP BY channel HAVING COUNT(*) >= 20"
      . " ORDER BY a DESC LIMIT " . (int)$n);
    $out = array();
    foreach ($st as $r) {
      $out[] = $r['channel'];
    }
    if (count($out)) {
      return $out;
    }
  } catch (Exception $e) {
  }
  return array_slice(web_channel_slugs($pdo, $provider), 0, $n);
}

// Merge two day-groups (today + tomorrow): dedupe by (channel, start_utc),
// because the day windows overlap (00:00 -> +1day 04:00), then sort by start.
function web_merge_days($g1, $g2) {
  foreach ($g2 as $slug => $rows) {
    if (!isset($g1[$slug])) {
      $g1[$slug] = array();
    }
    $seen = array();
    foreach ($g1[$slug] as $r) {
      $seen[(int)$r['start_utc']] = true;
    }
    foreach ($rows as $r) {
      if (!isset($seen[(int)$r['start_utc']])) {
        $g1[$slug][] = $r;
        $seen[(int)$r['start_utc']] = true;
      }
    }
  }
  foreach ($g1 as $slug => $rows) {
    usort($g1[$slug], function ($a, $b) {
      if ($a['start_utc'] === $b['start_utc']) {
        return 0;
      }
      return ($a['start_utc'] < $b['start_utc']) ? -1 : 1;
    });
  }
  return $g1;
}

// Category keyword map for /grid/{cat} (matches category+subtitle, accents kept).
function web_cat_keywords($cat) {
  static $map = array(
    'mind' => null,
    // Feeds carry genre pairs ("Akció Sorozat", "Romantikus Sorozat") rather
    // than a film flag, so the film page matches movie-ish genres. Series
    // with those genres leak in; an inhabited page beats an empty one.
    'film' => array('film', 'mozifilm', 'akció', 'akcio', 'dráma', 'drama',
      'krimi', 'romantikus', 'vígjáték', 'vigjatek', 'thriller', 'horror',
      'kaland', 'fantasy', 'sci-fi', 'western', 'katasztrófa', 'katasztrofa',
      'animációs', 'animacios'),
    'gyerekeknek' => array('gyerek', 'mese', 'rajzfilm', 'családi', 'csaladi', 'ifjúsági', 'ifjusagi', 'kölyök', 'kolyok'),
    'sport' => array('sport'),
    'termeszet' => array('természet', 'termeszet', 'doku', 'ismeretterjesztő', 'ismeretterjeszto', 'dokumentum'),
    'zene' => array('zene', 'koncert', 'klip'),
  );
  $cat = strtolower((string)$cat);
  return array_key_exists($cat, $map) ? $map[$cat] : false; // false = unknown cat
}

function web_cat_match($row, $keywords) {
  if ($keywords === null) {
    return true;
  }
  $hay = mb_strtolower($row['category'] . ' ' . $row['subtitle'], 'UTF-8');
  foreach ($keywords as $kw) {
    if (strpos($hay, $kw) !== false) {
      return true;
    }
  }
  return false;
}

function web_cat_label($cat) {
  static $labels = array(
    'mind' => 'Minden kategória', 'film' => 'Filmek',
    'gyerekeknek' => 'Gyerekeknek', 'sport' => 'Sport',
    'termeszet' => 'Természet', 'zene' => 'Zene',
  );
  return isset($labels[$cat]) ? $labels[$cat] : $cat;
}

// Watchlist (?watch=): one title per line (newline-separated, so titles
// may contain commas, dashes and other punctuation), each optionally
// prefixed with e: (exact) or p: (partial/substring), case-insensitive:
//   ?watch=Országos híradó%0Ap:Mese%0Ae:RTL Híradó
// No prefix = match from the START of the title (the end may differ):
// "Országos híradó" hits "Országos híradó magyar nyelven" but not "Híradó".
// "*" is a wildcard (any text, even empty). A literal leading "e:"/"p:"
// escapes with a backslash ("\e:X" = title "e:X", default start-match).
// Matching is case- and accent-insensitive, title only (never subtitle).
// Caps: 50 items, ~2000 chars total (stays far under the ~8k request-line
// limit even with the rest of the state params).
function web_watch_norm($s) {
  $s = function_exists('mb_strtolower')
    ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s);
  $from = array('á', 'é', 'í', 'ó', 'ö', 'ő', 'ú', 'ü', 'ű', 'ä', 'ö', 'ü', 'ß',
    'à', 'â', 'è', 'ê', 'ë', 'î', 'ï', 'ô', 'ù', 'û', 'ü', 'ÿ', 'ç', 'ñ');
  $to   = array('a', 'e', 'i', 'o', 'o', 'o', 'u', 'u', 'u', 'a', 'o', 'u', 'ss',
    'a', 'a', 'e', 'e', 'e', 'i', 'i', 'o', 'u', 'u', 'u', 'y', 'c', 'n');
  $s = str_replace($from, $to, $s);
  $s = preg_replace('/\s+/', ' ', trim($s));
  return $s;
}

function web_watch_parse($raw) {
  $items = is_array($raw) ? $raw : preg_split('/[\r\n]+/', (string)$raw);
  $out = array();
  $len = 0;
  foreach ($items as $it) {
    $it = trim((string)$it);
    if ($it === '') {
      continue;
    }
    $mode = 'prefix'; // default: match from the start of the title
    if (preg_match('/^\\\\([eEpP]:)/', $it)) {
      $it = substr($it, 1); // escaped literal: "\e:X" -> title "e:X"
    } elseif (preg_match('/^([eEpP]):(.*)$/s', $it, $m)) {
      $mode = (strtolower($m[1]) === 'e') ? 'exact' : 'partial';
      $it = trim($m[2]);
    }
    $t = web_watch_norm($it);
    if ($t === '') {
      continue;
    }
    $len += strlen($it);
    if ($len > 2000) {
      break;
    }
    $out[] = array('mode' => $mode, 'text' => $t);
    if (count($out) >= 50) {
      break;
    }
  }
  return $out;
}

function web_watch_match($row, array $watch) {
  if (!count($watch)) {
    return false;
  }
  $title = web_watch_norm(isset($row['title']) ? $row['title'] : '');
  if ($title === '') {
    return false;
  }
  foreach ($watch as $w) {
    if (web_watch_hit($title, $w)) {
      return true;
    }
  }
  return false;
}

// Single pattern against an already-normalized title. Unescaped "*" spans
// any text ("\*" stays literal). Exact anchors both ends, prefix (default)
// anchors the start, partial searches anywhere.
function web_watch_hit($title, array $w) {
  if (!isset($w['text']) || $w['text'] === '' || !isset($w['mode'])) {
    return false;
  }
  $parts = preg_split('/(?<!\\\\)\\*/', $w['text']);
  if ($parts === false) {
    return false;
  }
  foreach ($parts as &$pt) {
    $pt = str_replace('\\*', '*', $pt);
  }
  unset($pt);
  $re = implode('.*', array_map(function ($pt) {
    return preg_quote($pt, '/');
  }, $parts));
  if ($w['mode'] === 'exact') {
    $re = '^' . $re . '$';
  } elseif ($w['mode'] === 'prefix') {
    $re = '^' . $re;
  }
  return preg_match('/' . $re . '/', $title) === 1;
}

// Evening page: programmes starting at/after 19:50 local (port.hu marker).
function web_evening_start($date) {
  $dt = new DateTime($date . ' 19:50:00', new DateTimeZone('Europe/Budapest'));
  return $dt->getTimestamp();
}

// Programme detail lookup by (channel, start_utc) across fallback chain.
function web_find_programme(PDO $pdo, array $cfg, $slug, $start) {
  $chain = array();
  if (isset($_GET['provider']) && $_GET['provider'] !== '') {
    $chain[] = (string)$_GET['provider'];
  }
  foreach ($cfg['providers'] as $id) {
    if (!in_array($id, $chain, true)) {
      $chain[] = $id;
    }
  }
  foreach ($chain as $pid) {
    list(, $t_prog) = epg_provider_tables($pid);
    try {
      $st = $pdo->prepare("SELECT * FROM $t_prog WHERE channel=? AND start_utc=?");
      $st->execute(array($slug, (int)$start));
      $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
      continue;
    }
    if ($row) {
      return array($row, $pid);
    }
  }
  return array(null, null);
}

// Neighbours on the same channel for detail prev/next links.
function web_neighbours(PDO $pdo, $provider, $slug, $start) {
  list(, $t_prog) = epg_provider_tables($provider);
  try {
    $st = $pdo->prepare("SELECT start_utc, title FROM $t_prog"
      . " WHERE channel=? AND start_utc < ? ORDER BY start_utc DESC LIMIT 1");
    $st->execute(array($slug, (int)$start));
    $prev = $st->fetch(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT start_utc, title FROM $t_prog"
      . " WHERE channel=? AND start_utc > ? ORDER BY start_utc ASC LIMIT 1");
    $st->execute(array($slug, (int)$start));
    $next = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    return array(null, null);
  }
  return array($prev, $next);
}

// ---------------------------------------------------------------------------
// Helper-site data browsing (pages/helper.php): provider stats, date
// coverage and per-channel counts. Read-only, index-friendly queries.
// ---------------------------------------------------------------------------

function web_provider_stats(PDO $pdo, $provider) {
  list($t_chan, $t_prog) = epg_provider_tables($provider);
  $out = array('channels' => 0, 'programmes' => 0,
    'first' => null, 'last' => null);
  try {
    $out['channels'] = (int)$pdo->query("SELECT COUNT(*) FROM $t_chan")->fetchColumn();
    $out['programmes'] = (int)$pdo->query("SELECT COUNT(*) FROM $t_prog")->fetchColumn();
    $r = $pdo->query("SELECT MIN(start_utc), MAX(start_utc) FROM $t_prog")
      ->fetch(PDO::FETCH_NUM);
    if ($r && $r[0] !== null) {
      $out['first'] = (int)$r[0];
      $out['last'] = (int)$r[1];
    }
  } catch (Exception $e) {
    // provider never imported: zeros
  }
  $out['last_import'] = epg_meta_get($pdo, $provider, 'last_import', '');
  $out['last_error'] = epg_meta_get($pdo, $provider, 'last_error', '');
  return $out;
}

// array(Y-m-d => programmes). Local-day grouping via fixed +02:00 shift
// (exact for CEST; off-by-one only for 00:00-01:00 shows in CET months).
// Window-bounded (indexed): coverage never needs months-old history.
function web_date_coverage(PDO $pdo, $provider) {
  list(, $t_prog) = epg_provider_tables($provider);
  $out = array();
  try {
    $now = time();
    $st = $pdo->prepare("SELECT date(start_utc,'unixepoch','+2 hours') d, COUNT(*) c"
      . " FROM $t_prog WHERE start_utc BETWEEN ? AND ? GROUP BY d ORDER BY d");
    $st->execute(array($now - 90 * 86400, $now + 30 * 86400));
    foreach ($st as $r) {
      $out[$r['d']] = (int)$r['c'];
    }
  } catch (Exception $e) {
  }
  return $out;
}

// array(SLUG => programmes) intersecting the Budapest day window.
function web_channel_counts(PDO $pdo, $provider, $date) {
  list($from, $to) = epg_day_bounds($date);
  list(, $t_prog) = epg_provider_tables($provider);
  $out = array();
  try {
    $st = $pdo->prepare("SELECT channel, COUNT(*) c FROM $t_prog"
      . " WHERE stop_utc > ? AND start_utc < ? GROUP BY channel ORDER BY channel");
    $st->execute(array($from, $to));
    foreach ($st as $r) {
      $out[$r['channel']] = (int)$r['c'];
    }
  } catch (Exception $e) {
  }
  return $out;
}

// Programme list for the detail-URL browser (capped, ordered).
function web_day_programmes(PDO $pdo, $provider, $slug, $date, $limit = 300) {
  list($from, $to) = epg_day_bounds($date);
  list(, $t_prog) = epg_provider_tables($provider);
  try {
    $st = $pdo->prepare("SELECT start_utc, stop_utc, title FROM $t_prog"
      . " WHERE channel=? AND stop_utc > ? AND start_utc < ?"
      . " ORDER BY start_utc LIMIT " . (int)$limit);
    $st->execute(array($slug, $from, $to));
    return $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    return array();
  }
}
