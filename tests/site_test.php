<?php
// tests/site_test.php - full-site crawl: every route, both views, gates,
// no-JS/no-cookie audits, token propagation, sitemap validity, responsive CSS.
// Isolated docroot + seeded db; nothing touches the real repo var/.

require_once dirname(__DIR__) . '/lib/epg.php';

function t_site_docroot() {
  static $site = null;
  if ($site !== null) {
    return $site;
  }
  if (!function_exists('curl_init')) {
    return false;
  }
  $root = dirname(__DIR__);
  $d = t_tmpdir() . '/siteroot';
  mkdir($d, 0777, true);
  foreach (array('lib', 'pages', 'templates', 'config') as $n) {
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

  // seed: today + tomorrow, live + evening film + sport
  $now = time();
  $fmt = function ($ts) {
    return gmdate('YmdHis', $ts) . ' +0000';
  };
  $eve = strtotime('today 21:00 +0000') ;
  if ($eve < $now) {
    $eve += 86400;
  }
  $xml = '<?xml version="1.0" encoding="UTF-8"?><tv>'
    . '<channel id="RTL.hu"><display-name lang="hu">RTL</display-name></channel>'
    . '<channel id="TV2.hu"><display-name lang="hu">TV2</display-name></channel>'
    . '<channel id="Sport1.hu"><display-name lang="hu">Sport1</display-name></channel>'
    . '<programme start="' . $fmt($now - 1800) . '" stop="' . $fmt($now + 1800)
    . '" channel="RTL.hu"><title lang="hu">Live Show</title><desc lang="hu">Eloben.</desc>'
    . '<category lang="hu">Szórakoztató</category></programme>'
    . '<programme start="' . $fmt($eve) . '" stop="' . $fmt($eve + 5400)
    . '" channel="RTL.hu"><title lang="hu">Esti Akciófilm</title><desc lang="hu">Film este.</desc>'
    . '<category lang="hu">Akció</category><rating system="HU"><value>12</value></rating></programme>'
    . '<programme start="' . $fmt($now - 900) . '" stop="' . $fmt($now + 2700)
    . '" channel="Sport1.hu"><title lang="hu">Meccs</title><desc lang="hu">Foci.</desc>'
    . '<category lang="hu">Sport</category></programme>'
    . '<programme start="' . $fmt($now + 86400) . '" stop="' . $fmt($now + 90000)
    . '" channel="TV2.hu"><title lang="hu">Holnapi Híradó</title><desc lang="hu">Hirek.</desc></programme>'
    . '</tv>';
  file_put_contents($d . '/seed.xml', $xml);
  $pdo = new PDO('sqlite:' . $cfg['db_path']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $st = epg_import_file($d . '/seed.xml', $pdo, array('wipe' => true, 'provider' => 'ripper'));
  $GLOBALS['__t_site_start'] = $pdo->query(
    'SELECT start_utc FROM programs_ripper WHERE channel=\'RTL\' ORDER BY start_utc LIMIT 1')
    ->fetchColumn();
  @unlink($d . '/seed.xml');

  $port = 8924;
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

function t_site_curl($url, &$code, $ua = null, $follow = false) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);
  curl_setopt($ch, CURLOPT_HEADER, true);
  if ($follow) {
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  }
  if ($ua !== null) {
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
  }
  $resp = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  unset($ch);
  return array(substr($resp, 0, $hsize), substr($resp, $hsize));
}

function t_site_audit_page($body, $headers, $label) {
  t_false(stripos($headers, 'Set-Cookie') !== false, "$label: no cookies");
  $stripped = preg_replace('#<script type="application/ld\+json">.*?</script>#s', '', $body);
  foreach (array('<script', 'onclick', 'onchange', 'onload', 'fetch(') as $bad) {
    t_false(stripos($stripped, $bad) !== false, "$label: no JS ($bad)");
  }
  t_ok(preg_match('#<title>[^<]{5,}</title>#', $body) === 1, "$label: title");
  t_ok(strpos($body, 'rel="canonical"') !== false, "$label: canonical");
  t_ok(strpos($body, 'name="viewport"') !== false, "$label: viewport meta");
  // structural validity on every crawled page (catches markup bugs that
  // content assertions miss, e.g. duplicated attributes leaking as text)
  $probs = t_html_problems($body);
  t_ok(count($probs) === 0, "$label: valid HTML"
    . (count($probs) ? ' (' . $probs[0] . ')' : ''));
  t_ok(strpos($body, '<!-- epg-viewer') !== false, "$label: version stamp");
  // every internal link carries the site token (pure-URL state)
  preg_match_all('#href="(/[^"]*)"#', $body, $m);
  $bare = array();
  foreach ($m[1] as $href) {
    if (strpos($href, '//') === 0) {
      continue;
    }
    if (preg_match('#^/(style\.css|robots\.txt|img/|logok/|.*\.(png|gif|jpg))#', $href)) {
      continue;
    }
    if (strpos($href, 'token=SITETEST') === false) {
      $bare[] = $href;
    }
  }
  t_eq(array_slice($bare, 0, 3), array(), "$label: token on all links");
}

function test_site_route_matrix() {
  $s = t_site_docroot();
  if ($s === false) {
    echo "  SKIP (no curl or server)\n";
    return;
  }
  $T = 'token=SITETEST';
  $today = date('Y-m-d');
  $tomorrow = date('Y-m-d', time() + 86400);
  $start = $GLOBALS['__t_site_start'];
  $ok = array(
    '/', '/?view=v', '/?cat=film', '/?cat=gyerekeknek', '/?cat=sport',
    '/?cat=termeszet', '/?cat=zene', '/?cat=film&view=v',
    '/?cat=film&date=' . $today . '&h=20',
    '/tvmusor/RTL', '/tvmusor/RTL/' . $today, '/tvmusor/RTL/' . $tomorrow,
    '/tvmusor/SPORT1',
    "/musor/RTL/$start",
    '/adatvedelem', '/impresszum', '/settings',
  );
  foreach ($ok as $p) {
    list(, $body) = t_site_curl($s['base'] . $p . (strpos($p, '?') === false ? '?' : '&') . $T, $code);
    t_eq($code, 200, "GET $p -> 200");
    if ($code === 200 && strpos($p, '/musor/') === 0) {
      t_ok(strpos($body, 'Live Show') !== false, 'detail renders programme');
    }
  }
  $nf = array('/nope', '/tvmusor/NOPE', '/musor/RTL/123', '/blog', '/blog/nope',
    '/grid/film/', '/grid/bogus/', '/tvmusorujsag');
  foreach ($nf as $p) {
    t_site_curl($s['base'] . $p . "?$T", $code);
    t_eq($code, 404, "GET $p -> 404");
  }
  // sitemap: valid XML with channel URLs
  list(, $body) = t_site_curl($s['base'] . "/sitemapxml.php?$T", $code);
  t_eq($code, 200, 'sitemap 200');
  $xml = @simplexml_load_string($body);
  t_ok($xml !== false, 'sitemap is valid XML');
  t_ok(strpos($body, '/tvmusor/RTL') !== false, 'sitemap lists channels');
  // statics
  t_site_curl($s['base'] . '/robots.txt', $code);
  t_eq($code, 200, 'robots 200 (public, no token needed)');
  t_site_curl($s['base'] . '/style.css', $code);
  t_eq($code, 200, 'css 200 (public)');
  // ?p= fallback (no rewrite hosts)
  list(, $body) = t_site_curl($s['base'] . "/?p=/tvmusor/RTL&$T", $code);
  t_eq($code, 200, '?p= fallback 200');
  // gates
  t_site_curl($s['base'] . '/', $code);
  t_eq($code, 403, 'anonymous 403 on private instance');
}

function test_site_redirects() {
  $s = t_site_docroot();
  if ($s === false) {
    echo "  SKIP (no curl or server)\n";
    return;
  }
  $T = 'token=SITETEST';
  list($headers) = t_site_curl($s['base'] . "/?token=SITETEST&ch[]=RTL&ch[]=TV2", $code);
  t_eq($code, 302, 'ch[] form canonicalizes with 302');
  t_ok(strpos($headers, 'ch=RTL') !== false, '302 target has CSV channels');
  // Location must be usable: same install, same path (subfolder-safe)
  if (preg_match('/Location:\s*(\S+)/i', $headers, $m)) {
    t_ok(strpos($m[1], 'ch=RTL') !== false,
      '302 target points back at the app');
  } else {
    t_ok(false, '302 carries Location header');
  }
  list($headers) = t_site_curl($s['base'] . "/?$T&show=all", $code);
  t_eq($code, 302, 'show=all canonicalizes with 302');
}

function test_site_page_audits() {
  $s = t_site_docroot();
  if ($s === false) {
    echo "  SKIP (no curl or server)\n";
    return;
  }
  $T = 'token=SITETEST';
  $today = date('Y-m-d');
  $start = $GLOBALS['__t_site_start'];
  $pages = array(
    'home-h' => "/?$T", 'home-v' => "/?view=v&$T",
    'cat' => "/?cat=film&$T", 'cat-v' => "/?cat=sport&view=v&$T",
    'zoom' => "/?zoom=3&$T",
    'channel' => "/tvmusor/RTL?$T",
    'detail' => "/musor/RTL/$start?$T",
    'settings' => "/settings?$T",
  );
  foreach ($pages as $label => $p) {
    list($headers, $body) = t_site_curl($s['base'] . $p, $code);
    if ($code !== 200) {
      t_eq($code, 200, "$label reachable");
      continue;
    }
    t_site_audit_page($body, $headers, $label);
  }
  // responsive markup: EPG tables scroll, viewport present, CSS has @media
  list(, $body) = t_site_curl($s['base'] . "/?$T", $code);
  t_ok(strpos($body, 'epg-wrap') !== false, 'EPG timeline wrapped (page scrolls)');
  t_ok(strpos($body, 'id="now"') !== false, 'jump anchor present');
  t_ok(strpos($body, 'autofocus="autofocus"') !== false, 'anchor refires on every load');
  list(, $css) = t_site_curl($s['base'] . '/style.css', $code);
  t_ok(strpos($css, '@media') !== false, 'CSS has mobile @media block');
  t_ok(strpos($css, '.tablescroll') !== false, 'CSS has tablescroll rule');
  t_ok(strpos($css, '.checks-grid') !== false, 'CSS has responsive checkbox grid');
  t_ok(strpos($css, 'box-sizing: border-box') !== false, 'CSS strips keep exact % width');
  t_ok(strpos($css, '.urlbox') !== false, 'CSS has responsive URL boxes');
  t_ok(strpos($css, 'scroll-margin-left') !== false, 'CSS offsets #now jumps');
  t_ok(strpos($css, '100dvh') !== false, 'CSS sizes app to the visible viewport');
  t_ok(strpos($css, 'tr:first-child td') !== false, 'CSS sticks the hour row');
  t_ok(strpos($css, 'position: sticky') !== false, 'CSS keeps headers visible');
  t_ok(preg_match('/tr:first-child td[^}]*top:\s*0/', $css) === 1,
    'CSS hour row flush at box top (no covering gap)');
}

function test_site_settings_builds_urls() {
  $s = t_site_docroot();
  if ($s === false) {
    echo "  SKIP (no curl or server)\n";
    return;
  }
  $T = 'token=SITETEST';
  // single-EPG URL construction
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T
    . '&b_provider=ripper&b_type=epg&b_cat=sport&b_view=v&b_ch[]=RTL', $code);
  t_eq($code, 200, 'settings epg build 200');
  t_ok(strpos($body, '?cat=sport') !== false || strpos($body, 'cat=sport') !== false,
    'settings emits cat URL');
  t_ok(strpos($body, 'ch=RTL') !== false, 'settings URL carries channels');
  // channel URL construction
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T
    . '&b_type=channel&b_ch[]=TV2', $code);
  t_ok(strpos($body, '/tvmusor/TV2') !== false, 'settings emits channel URL');
  // detail picker lists ready /musor/ links
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T
    . '&b_type=detail&b_ch[]=RTL', $code);
  t_ok(strpos($body, '/musor/RTL/') !== false, 'settings emits detail URLs');
  // reorder section: two channels -> up/down links, Megnyitás is a button
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T
    . '&b_ch[]=TV2&b_ch[]=RTL', $code);
  t_ok(strpos($body, 'Sorrend') !== false, 'reorder section present');
  t_ok(strpos($body, 'Fel</a>') !== false, 'move-up link present');
  t_ok(preg_match('~<a class="btn" href="[^"]*ch=TV2%2CRTL[^"]*"~', $body) === 1,
    'built URL keeps channel order');
  t_ok(preg_match('~value="http://[^"]*ch=TV2%2CRTL[^"]*"~', $body) === 1,
    'built URL contains the domain');
  // regression: settings GET forms must carry the site token as hidden fields
  // (browsers drop the action URL's query string on GET submit -> 403)
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T, $code);
  t_eq(substr_count($body, 'name="token" value="SITETEST"'), 2,
    'both settings forms keep token in hidden field');
  // provider switch reloads with the other provider's data
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T
    . '&b_provider=hungary1', $code);
  t_ok(strpos($body, 'Váltás') !== false, 'provider switch form present');
  // plain guide params also restore the selection (round-trip with the guide)
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T
    . '&ch=RTL&cat=film&view=v', $code);
  t_eq($code, 200, 'settings accepts guide params');
  t_ok(strpos($body, 'value="RTL" checked') !== false, 'ch= restores checkbox');
  t_ok(strpos($body, 'value="film" selected') !== false, 'cat= restores category');
  // browser action links render as buttons
  t_ok(substr_count($body, 'class="btn"') >= 2, 'browser actions are buttons');
  // channel checkboxes render in a responsive grid div, in sorted order
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T, $code);
  t_ok(strpos($body, 'checks-grid') !== false, 'checkbox grid present');
  $n_box = substr_count($body, 'name="b_ch[]"');
  t_ok($n_box === 3, "all 3 channels selectable (got $n_box)");
  // auto-refresh meta only when asked (?refresh=N); default is off
  list(, $body) = t_site_curl($s['base'] . "/?$T", $code);
  t_ok(strpos($body, 'http-equiv="refresh"') === false, 'no refresh by default');
  list(, $body) = t_site_curl($s['base'] . "/?refresh=5&$T", $code);
  t_ok(strpos($body, 'http-equiv="refresh" content="300"') !== false,
    '?refresh=5 refreshes every 5 min');
  t_ok(preg_match('~<span class="tiny"[^>]*>\d\d:\d\d:\d\d</span>~', $body) === 0,
    'no load-time stamp in header');
  list(, $body) = t_site_curl($s['base'] . "/tvmusor/RTL?refresh=5&$T", $code);
  t_ok(strpos($body, 'http-equiv="refresh"') !== false, 'channel page refreshes');
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T, $code);
  t_ok(strpos($body, 'http-equiv="refresh"') === false, 'settings never refreshes');
  t_ok(strpos($body, 'name="theme"') !== false, 'dark-mode checkbox present');
  // dark mode: body class + sticky checkbox
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T . '&theme=dark', $code);
  t_ok(strpos($body, '"dark"') !== false, 'dark body class');
  t_ok(preg_match('~name="theme"[^>]*checked~', $body) === 1,
    'checkbox reflects dark mode');
  // font size: body class + builder select + result URL
  list(, $body) = t_site_curl($s['base'] . "/?$T&font=5", $code);
  t_ok(strpos($body, 'fs5') !== false, 'extra large font body class');
  list(, $body) = t_site_curl($s['base'] . "/?$T&font=3", $code);
  t_ok(strpos($body, 'fs1') === false && strpos($body, 'fs5') === false,
    'default level adds no font class');
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T . '&b_font=1&b_ch[]=RTL', $code);
  t_ok(strpos($body, 'font=1') !== false, 'built URL carries font size');
  // refresh select honors config default (off) and explicit choice
  t_ok(preg_match('~name="b_refresh"[^>]*value="0"~', $body) === 1, 'refresh default off');
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T . '&b_refresh=15', $code);
  t_ok(preg_match('~name="b_refresh"[^>]*value="15"~', $body) === 1, 'refresh choice sticks');
  // offset input present, result URL carries it when non-zero
  t_ok(strpos($body, 'name="b_offset"') !== false, 'offset input present');
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T
    . '&b_type=epg&b_ch[]=RTL&b_offset=-30', $code);
  t_ok(strpos($body, 'offset=-30') !== false, 'result URL carries offset');
  // settings self-links keep raw refresh (provider rows, detail browser)
  list(, $body) = t_site_curl($s['base'] . '/settings?' . $T . '&refresh=5', $code);
  preg_match_all('~href="([^"]*(?:b_provider|b_type=detail)[^"]*)"~', $body, $mm);
  $lost = array();
  foreach ($mm[1] as $href) {
    if (strpos($href, 'refresh=5') === false) {
      $lost[] = $href;
    }
  }
  t_eq(array_slice($lost, 0, 2), array(), 'settings self-links keep refresh');
  // display state survives leaving the detail page (refresh kept!)
  $dstart = $GLOBALS['__t_site_start'];
  list(, $body) = t_site_curl($s['base'] . "/musor/RTL/$dstart?$T&refresh=5", $code);
  t_eq($code, 200, 'detail with refresh 200');
  preg_match_all('~href="([^"]+)"~', $body, $mm);
  $leaks = array();
  foreach ($mm[1] as $href) {
    if (strpos($href, '/tvmusor/') !== false && strpos($href, 'refresh=5') === false) {
      $leaks[] = $href;
    }
  }
  t_eq(array_slice($leaks, 0, 2), array(), 'detail back-links keep refresh');
}

function test_site_subfolder_install() {
  // example.com/tv/ : copy serves from a subdir, base_path=/tv. Real-world
  // request paths (/tv/grid/...) must route, links/assets must stay inside.
  if (!function_exists('curl_init')) {
    echo "  SKIP (no curl)\n";
    return;
  }
  $root = dirname(__DIR__);
  $parent = t_tmpdir() . '/parent';
  $d = $parent . '/tv';
  mkdir($d, 0777, true);
  foreach (array('lib', 'pages', 'templates', 'config') as $n) {
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
  $cfg['site_token'] = ''; // public: assert no token needed, links clean
  $cfg['base_path'] = '/tv';
  $cfg['db_path'] = $d . '/epg.sqlite';
  $cfg['providers'] = array('ripper');
  mkdir($d . '/var', 0777, true);
  file_put_contents($d . '/config.php', '<?php return ' . var_export($cfg, true) . ';');
  $now = time();
  $fmt = function ($ts) {
    return gmdate('YmdHis', $ts) . ' +0000';
  };
  file_put_contents($d . '/seed.xml',
    '<?xml version="1.0"?><tv>'
    . '<channel id="RTL.hu"><display-name>RTL</display-name></channel>'
    . '<programme start="' . $fmt($now - 600) . '" stop="' . $fmt($now + 600)
    . '" channel="RTL.hu"><title>Live</title><desc>D.</desc></programme></tv>');
  require_once $root . '/lib/epg.php';
  $pdo = new PDO('sqlite:' . $cfg['db_path']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  epg_import_file($d . '/seed.xml', $pdo, array('wipe' => true, 'provider' => 'ripper'));
  @unlink($d . '/seed.xml');

  $port = 8927;
  exec(sprintf('php -S 127.0.0.1:%d -t %s >/dev/null 2>&1 & echo $!',
    $port, escapeshellarg($parent)) . "\n", $out);
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
    echo "  SKIP (no server)\n";
    return;
  }
  $base = "http://127.0.0.1:$port/tv";
  register_shutdown_function(function () use ($pid) {
    @exec('kill ' . (int)$pid . ' 2>/dev/null');
  });
  list(, $body) = t_site_curl($base . '/', $code);
  t_eq($code, 200, 'subfolder home 200');
  t_ok(strpos($body, 'Live') !== false, 'subfolder home renders data');
  t_ok(strpos($body, 'href="/tv/') !== false, 'links stay under /tv');
  t_ok(strpos($body, '/tv/style.css') !== false, 'stylesheet under /tv');
  t_ok(strpos($body, 'token=') === false, 'public install: no token in links');
  list(, $body) = t_site_curl($base . '/?cat=film', $code);
  t_eq($code, 200, 'subfolder cat page 200');
  t_ok(strpos($body, 'Live') === false, 'subfolder cat filter applies (seed uncategorized)');
  list(, $body) = t_site_curl($base . '/tvmusor/RTL', $code);
  t_eq($code, 200, 'subfolder channel 200');
  // regression: ch[] canonical redirect keeps the install base
  list($headers) = t_site_curl(
    $base . '/?ch[]=RTL&ch[]=TV2', $code);
  t_eq($code, 302, 'subfolder ch[] -> 302');
  if (preg_match('/Location:\s*(\S+)/i', $headers, $m)) {
    t_ok(strpos($m[1], '/tv/?') === 0 || strpos($m[1], '/tv?') !== false, '302 stays under /tv');
    t_ok(strpos($m[1], 'ch=RTL') !== false, '302 target has CSV channels');
  } else {
    t_ok(false, '302 carries Location header');
  }
}

function test_site_app_layout() {
  $s = t_site_docroot();
  if ($s === false) {
    echo "  SKIP (no curl or server)\n";
    return;
  }
  $T = 'token=SITETEST';
  list(, $body) = t_site_curl($s['base'] . "/?$T", $code);
  t_eq($code, 200, 'home-h 200');
  t_ok(strpos($body, '<body class="app">') !== false, 'grid page locks app layout');
  t_ok(strpos($body, 'epg-fill') !== false, 'grid scrolls inside epg-fill');
  t_ok(preg_match('~<a href="[^"]*#now"~', $body) === 1, 'grid links auto-jump to live');
  list(, $body) = t_site_curl($s['base'] . "/?view=v&$T", $code);
  t_ok(strpos($body, '<body class="app">') !== false, 'vertical list locks app layout too');
  list(, $body) = t_site_curl($s['base'] . "/tvmusor/RTL?$T", $code);
  t_ok(strpos($body, '<body class="app">') !== false, 'channel grid locks app layout');
}

function test_site_mobile_ua() {  $s = t_site_docroot();
  if ($s === false) {
    echo "  SKIP (no curl or server)\n";
    return;
  }
  // server renders the same responsive markup for phones (no UA sniffing)
  $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15';
  $T = 'token=SITETEST';
  foreach (array('/', '/?cat=film&view=v', '/tvmusor/RTL') as $p) {
    $sep = strpos($p, '?') === false ? '?' : '&';
    list($headers, $body) = t_site_curl($s['base'] . $p . $sep . $T, $code, $ua);
    t_eq($code, 200, "mobile $p -> 200");
    t_site_audit_page($body, $headers, "mobile $p");
  }
}
