<?php
// pages/settings.php - /settings : all site options in one place.
// Browse providers/channels/dates and construct canonical URLs.
// Pure GET forms (no JS): submit -> the built URL is shown as
// a readonly textbox (manual copy) plus an "Open" link.

$GLOBALS['WCARRY'] = array(
  // self-links (provider rows, detail browser, coverage) keep refresh;
  // explicit builder params ($bst) still win where present
  'refresh' => isset($_GET['refresh']) ? (string)$_GET['refresh'] : null,
);
$viewlinks = array('h' => '/settings', 'v' => '/settings');
$page = array(
  'title' => 'Beállítások | EPG',
  'desc' => 'Beállítások: szolgáltató, csatornák, dátum, nézet és URL-építő.',
  'canonical' => $base_url . '/settings',
  'h1' => 'Beállítások',
);

$providers = $CFG['providers'];
// Every option also reads the plain guide param as fallback, so pasting any
// guide URL (or arriving from one) restores the same selection here.
$b_provider = isset($_GET['b_provider']) && in_array($_GET['b_provider'], $providers, true)
  ? $_GET['b_provider']
  : (isset($_GET['provider']) && in_array($_GET['provider'], $providers, true)
    ? $_GET['provider'] : $WSTATE['provider']);
$b_type = isset($_GET['b_type']) ? (string)$_GET['b_type'] : 'epg';
if (!in_array($b_type, array('epg', 'channel', 'detail'), true)) {
  $b_type = 'epg';
}
$b_cat = isset($_GET['b_cat']) ? (string)$_GET['b_cat']
  : (isset($_GET['cat']) ? (string)$_GET['cat'] : 'mind');
if (web_cat_keywords($b_cat) === false) {
  $b_cat = 'mind';
}
$b_view = isset($_GET['b_view']) ? ((string)$_GET['b_view'] === 'v' ? 'v' : 'h')
  : ((isset($_GET['view']) && (string)$_GET['view'] === 'v') ? 'v' : 'h');
$b_zoom = isset($_GET['b_zoom']) && in_array((string)$_GET['b_zoom'], array('0', '1', '2', '3', '4'), true)
  ? (string)$_GET['b_zoom']
  : ((isset($_GET['zoom']) && in_array((string)$_GET['zoom'], array('0', '1', '2', '3', '4'), true))
    ? (string)$_GET['zoom'] : '2');
$b_font = isset($_GET['b_font']) && in_array((string)$_GET['b_font'], array('1', '2', '3', '4', '5'), true)
  ? (string)$_GET['b_font']
  : ((isset($_GET['font']) && in_array((string)$_GET['font'], array('1', '2', '3', '4', '5'), true))
    ? (string)$_GET['font'] : '3');
// refresh minutes, manually typed (falls back to plain ?refresh=, then default)
$b_ref_raw = isset($_GET['b_refresh']) ? (string)$_GET['b_refresh']
  : (isset($_GET['refresh']) ? (string)$_GET['refresh'] : null);
if ($b_ref_raw !== null && preg_match('/^\d{1,3}$/', $b_ref_raw) && (int)$b_ref_raw <= 120) {
  $b_ref = (string)((int)$b_ref_raw);
} else {
  $b_ref = (string)web_refresh_mins($CFG);
}
// time correction, minutes, signed (falls back to plain ?offset=, then 0)
$b_off_raw = isset($_GET['b_offset']) ? (string)$_GET['b_offset']
  : (isset($_GET['offset']) ? (string)$_GET['offset'] : '0');
$b_off = (preg_match('/^-?\d{1,4}$/', $b_off_raw) && abs((int)$b_off_raw) <= 720)
  ? (string)((int)$b_off_raw) : '0';

$names = web_channel_names($pdo, $b_provider);
$all_slugs = web_channel_slugs($pdo, $b_provider);
// plain ?ch=CSV (guide form) works here too, not just ?b_ch[]=
$b_ch_raw = isset($_GET['b_ch']) ? $_GET['b_ch']
  : (isset($_GET['ch']) ? explode(',', (string)$_GET['ch']) : array());
$b_ch = epg_filter_slugs($b_ch_raw, $all_slugs);

// date options = dates that actually have data for this provider
$coverage = web_date_coverage($pdo, $b_provider);
$b_date = web_valid_date(isset($_GET['b_date']) ? $_GET['b_date']
  : (isset($_GET['date']) ? $_GET['date'] : null));
if (count($coverage) && !isset($coverage[$b_date])) {
  // keep user-typed date (query still works); options just highlight coverage
}

// ---- 1. providers ----
echo '<h2 class="section-title">Szolgáltatók</h2>';
echo '<div class="tablescroll"><table class="epg" cellpadding="0" cellspacing="0">'
  . '<tr><th>Azonosító</th><th>Csatorna</th><th>Műsor</th>'
  . '<th>Időszak</th><th>Utolsó import</th><th>Hiba</th></tr>';
foreach ($providers as $pid) {
  $st = web_provider_stats($pdo, $pid);
  echo '<tr><td><a href="' . h(u('/settings', array('b_provider' => $pid))) . '">'
    . h($pid) . '</a>' . ($pid === $b_provider ? ' *' : '') . '</td>'
    . '<td>' . $st['channels'] . '</td><td>' . $st['programmes'] . '</td><td>';
  echo $st['first'] === null ? '-' : h(date('m-d', $st['first']) . ' – ' . date('m-d', $st['last']));
  echo '</td><td>' . ($st['last_import'] !== '' ? h(date('m-d H:i', (int)$st['last_import'])) : '-') . '</td>'
    . '<td>' . ($st['last_error'] !== '' ? h(mb_substr($st['last_error'], 0, 60, 'UTF-8')) : '-') . '</td></tr>';
}
echo '</table></div>';
echo '<p>* = az építőben kiválasztott szolgáltató. A kettő külön táblákban él,'
  . ' az olvasás esik vissza a másikra, ha az egyik üres.</p>';

// ---- 2. provider switch (own form: changing the select alone cannot
// reload without JS, so this submits to a fresh provider view) ----
echo '<h2 class="section-title">Szolgáltató</h2>';
echo '<form method="get" action="' . h(web_base($GLOBALS['CFG']) . '/settings') . '" class="filterbox">';
if (!empty($CFG['site_token']) && isset($_GET['token']) && $_GET['token'] !== '') {
  echo '<input type="hidden" name="token" value="' . h((string)$_GET['token']) . '">';
}
if ($WSTATE['theme'] === 'dark') {
  echo '<input type="hidden" name="theme" value="dark">';
}
if (isset($_GET['refresh']) && $_GET['refresh'] !== '') {
  echo '<input type="hidden" name="refresh" value="' . h((string)$_GET['refresh']) . '">';
}
echo '<p><label>Szolgáltató: <select name="b_provider">';
foreach ($providers as $pid) {
  echo '<option value="' . h($pid) . '"' . ($pid === $b_provider ? ' selected' : '') . '>'
    . h($pid) . '</option>';
}
echo '</select></label> ';
echo '<input type="submit" value="Váltás"></p></form>';

// ---- 3. builder form ----
// NOTE: GET forms submit FIELDS ONLY — the action URL's own query string is
// discarded by browsers. So the site token must ride as a hidden field here
// (hidden_state_fields), or one submit locks a private instance out (403).
echo '<h2 class="section-title">URL-építő</h2>';
echo '<form method="get" action="' . h(web_base($GLOBALS['CFG']) . '/settings') . '" class="filterbox">';
echo hidden_state_fields(array('provider', 'view', 'cat', 'h', 'theme', 'b_ch'));
echo '<input type="hidden" name="b_provider" value="' . h($b_provider) . '">';
echo '<p>Szolgáltató: <b>' . h($b_provider) . '</b> '
  . '<label>Oldaltípus: <select name="b_type">';
foreach (array('epg' => 'Műsor (egyoldalas EPG)',
  'channel' => 'Csatorna nap', 'detail' => 'Műsor részletező') as $v => $l) {
  echo '<option value="' . h($v) . '"' . ($v === $b_type ? ' selected' : '') . '>' . h($l) . '</option>';
}
echo '</select></label> ';
echo '<label>Kategória: <select name="b_cat">';
foreach (array('mind', 'film', 'gyerekeknek', 'sport', 'termeszet', 'zene') as $c) {
  echo '<option value="' . h($c) . '"' . ($c === $b_cat ? ' selected' : '') . '>'
    . h(web_cat_label($c)) . '</option>';
}
echo '</select></label></p>';
echo '<p><label>Dátum: <select name="b_date">';
$dates = count($coverage) ? array_keys($coverage)
  : array(web_today(), date('Y-m-d', time() + 86400));
foreach ($dates as $d) {
  $n = isset($coverage[$d]) ? ' (' . $coverage[$d] . ' műsor)' : '';
  echo '<option value="' . h($d) . '"' . ($d === $b_date ? ' selected' : '') . '>'
    . h($d) . h($n) . '</option>';
}
echo '</select></label> ';
echo '<label>Nézet: <select name="b_view">'
  . '<option value="h"' . ($b_view === 'h' ? ' selected' : '') . '>Vízszintes</option>'
  . '<option value="v"' . ($b_view === 'v' ? ' selected' : '') . '>Függőleges</option>'
  . '</select></label> ';
echo '<label>Méret: <select name="b_zoom">'
  . '<option value="0"' . ($b_zoom === '0' ? ' selected' : '') . '>Extra kicsi</option>'
  . '<option value="1"' . ($b_zoom === '1' ? ' selected' : '') . '>Kicsi</option>'
  . '<option value="2"' . ($b_zoom === '2' ? ' selected' : '') . '>Normál</option>'
  . '<option value="3"' . ($b_zoom === '3' ? ' selected' : '') . '>Nagy</option>'
  . '<option value="4"' . ($b_zoom === '4' ? ' selected' : '') . '>Extra nagy</option>'
  . '</select></label> ';
echo '<label>Betűméret: <select name="b_font">'
  . '<option value="1"' . ($b_font === '1' ? ' selected' : '') . '>Extra kicsi</option>'
  . '<option value="2"' . ($b_font === '2' ? ' selected' : '') . '>Kicsi</option>'
  . '<option value="3"' . ($b_font === '3' ? ' selected' : '') . '>Normál</option>'
  . '<option value="4"' . ($b_font === '4' ? ' selected' : '') . '>Nagy</option>'
  . '<option value="5"' . ($b_font === '5' ? ' selected' : '') . '>Extra nagy</option>'
  . '</select></label> ';
echo '<label><input type="checkbox" name="theme" value="dark"'
  . ($WSTATE['theme'] === 'dark' ? ' checked' : '') . '> Sötét mód</label> ';
echo '<label>Frissítés (perc, 0 = ki): <input type="text" name="b_refresh" size="4" value="'
  . h($b_ref) . '"></label> ';
echo '<label>Időkorrekció (perc): <input type="text" name="b_offset" size="5" value="'
  . h($b_off) . '"></label></p>';
echo '<div class="checks-grid">';
foreach (web_sorted_channels($pdo, $b_provider) as $slug => $nm) {
  echo '<label><input type="checkbox" name="b_ch[]" value="' . h($slug) . '"'
    . (in_array($slug, $b_ch, true) ? ' checked' : '') . '> '
    . h($nm) . '</label>';
}
echo '</div><div class="clear"></div>';
echo '<p><input type="submit" value="URL mutatása"></p></form>';

// ---- 4. result ----
$bst = array('provider' => $b_provider, 'view' => $b_view,
  'zoom' => ($b_zoom === '2' ? null : $b_zoom),
  'font' => ($b_font === '3' ? null : $b_font),
  'theme' => ($WSTATE['theme'] === 'dark' ? 'dark' : null),
  'refresh' => ($b_ref == (string)$CFG['refresh_mins'] ? null : $b_ref),
  'offset' => ($b_off !== '0' ? $b_off : null));
$built = '';
switch ($b_type) {
  case 'epg':
    $built = u('/', array(
      'cat' => $b_cat === 'mind' ? null : $b_cat,
      'ch' => count($b_ch) ? implode(',', $b_ch) : null,
      'date' => $b_date === web_today() ? null : $b_date), $bst, 'now');
    break;
  case 'channel':
    $first = count($b_ch) ? $b_ch[0] : (count($all_slugs) ? $all_slugs[0] : 'RTL');
    $built = u(channel_path($first, $b_date === web_today() ? null : $b_date), array(), $bst, 'now');
    break;
  case 'detail':
    $built = ''; // programme picker below yields the links
    break;
}
echo '<h2 class="section-title">Eredmény</h2>';
if ($built !== '') {
  // absolute URL with domain: copy-paste ready anywhere
  $absolute = web_abs_built($CFG, $base_url, $built);
  echo '<p><label>Kész URL (másolható):<br>'
    . '<input type="text" class="urlbox" readonly size="80" value="' . h($absolute) . '"></label></p>';
  echo '<p><a class="btn" href="' . h($absolute) . '">Megnyitás &gt;</a></p>';
} else {
  echo '<p>Válasszon csatornát és dátumot: a műsorok listájában minden sor a '
    . 'kész részletező-URL-re mutat.</p>';
}
// channel order: up/down links re-emit the whole builder state with two
// items swapped (no JS). The guide renders exactly this order.
if (count($b_ch) > 1) {
  $base_params = array(
    'b_provider' => $b_provider, 'b_type' => $b_type, 'b_cat' => $b_cat,
    'b_date' => $b_date, 'b_view' => $b_view, 'b_zoom' => $b_zoom,
  );
  echo '<h3 class="section-title">Sorrend (így mutatja a műsor)</h3><ol>';
  $last = count($b_ch) - 1;
  foreach ($b_ch as $i => $slug) {
    echo '<li>' . ($i + 1) . '. ' . h(isset($names[$slug]) ? $names[$slug] : $slug);
    if ($i > 0) {
      $up = $base_params;
      $up['b_ch'] = web_move_ch($b_ch, $i, 'up');
      echo ' <a href="' . h(u('/settings', $up)) . '">↑ Fel</a>';
    }
    if ($i < $last) {
      $dn = $base_params;
      $dn['b_ch'] = web_move_ch($b_ch, $i, 'down');
      echo ' <a href="' . h(u('/settings', $dn)) . '">↓ Le</a>';
    }
    echo '</li>';
  }
  echo '</ol>';
}

// ---- 5. channel browser (+ detail-URL picker) ----
echo '<h2 class="section-title">Csatornák (' . h($b_provider) . ', ' . h($b_date) . ')</h2>';
$counts = web_channel_counts($pdo, $b_provider, $b_date);
if (!count($counts)) {
  echo '<p>Erre a napra nincs adat ennél a szolgáltatónál.</p>';
} else {
  echo '<div class="tablescroll"><table class="epg" cellpadding="0" cellspacing="0">'
    . '<tr><th>Csatorna</th><th>Műsorok</th><th>Hivatkozások</th></tr>';
  uksort($counts, function ($a, $b) use ($names) {
    $na = isset($names[$a]) ? $names[$a] : $a;
    $nb = isset($names[$b]) ? $names[$b] : $b;
    $ka = web_channel_sort_key($na);
    $kb = web_channel_sort_key($nb);
    if ($ka === $kb) {
      return 0;
    }
    return ($ka < $kb) ? -1 : 1;
  });
  foreach ($counts as $slug => $c) {
    $nm = isset($names[$slug]) ? $names[$slug] : $slug;
    echo '<tr><td class="chan">' . h($nm) . '</td><td>' . $c . '</td><td>'
      . '<a class="btn" href="' . h(u(channel_path($slug, $b_date), array(), $bst)) . '">Nap</a> '
      . '<a class="btn" href="' . h(u('/settings', array('b_provider' => $b_provider,
        'b_type' => 'detail', 'b_date' => $b_date,
        'b_ch' => array($slug)))) . '">Műsor-URL-ek</a></td></tr>';
  }
  echo '</table></div>';
}

// detail-URL picker: programmes of the chosen channel+date as ready links
if ($b_type === 'detail' && count($b_ch)) {
  $pick = $b_ch[0];
  echo '<h2 class="section-title">Műsor-URL-ek: '
    . h(isset($names[$pick]) ? $names[$pick] : $pick) . ' (' . h($b_date) . ')</h2>';
  $progs = web_day_programmes($pdo, $b_provider, $pick, $b_date);
  if (!count($progs)) {
    echo '<p>Nincs műsor.</p>';
  } else {
    echo '<ul class="progs">';
    foreach ($progs as $r) {
      $dl = web_abs_built($CFG, $base_url, u(detail_path($pick, $r['start_utc']), array(), $bst));
      echo '<li>' . h(web_hm($r['start_utc'])) . ' '
        . '<a href="' . h($dl) . '">' . h($r['title']) . '</a><br>'
        . '<input type="text" class="urlbox" readonly size="60" value="' . h($dl) . '"></li>';
    }
    echo '</ul>';
  }
}

// ---- 6. date coverage ----
echo '<h2 class="section-title">Dátum-lefedettség (' . h($b_provider) . ')</h2>';
if (!count($coverage)) {
  echo '<p>Nincs adat.</p>';
} else {
  echo '<ul class="chanindex">';
  foreach ($coverage as $d => $c) {
    echo '<li><a href="' . h(u('/', array('date' => $d), $bst)) . '">'
      . h($d) . ' (' . $c . ')</a></li>';
  }
  echo '</ul>';
}
echo '<p class="tiny">Verzió: ' . h(isset($CFG['version']) ? $CFG['version'] : '?') . '</p>';

// ---- 7. header nav carry (templates/header.php renders AFTER this page):
// the EPG/CSATORNÁK links (u('/')) must return to the guide with the
// builder state, so leaving settings without pressing "Megnyitás" keeps
// every param (provider/view/channels/date/cat/zoom/font/refresh/offset).
// Set LAST so body links above keep their own explicit state.
$GLOBALS['WSTATE'] = array_merge($WSTATE,
  array('provider' => $b_provider, 'view' => $b_view));
$GLOBALS['WCARRY'] = array(
  'cat' => $b_cat === 'mind' ? null : $b_cat,
  'ch' => count($b_ch) ? implode(',', $b_ch) : null,
  'date' => $b_date === web_today() ? null : $b_date,
  'zoom' => ($b_zoom === '2' ? null : $b_zoom),
  'font' => ($b_font === '3' ? null : $b_font),
  'refresh' => ($b_ref == (string)$CFG['refresh_mins'] ? null : $b_ref),
  'offset' => ($b_off !== '0' ? $b_off : null),
);
