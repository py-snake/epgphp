<?php
// index.php - front controller. Clean URLs via .htaccess, ?p= fallback otherwise.
// No JavaScript, no cookies (URL-only state), old-browser safe output.

require __DIR__ . '/lib/config.php';
$CFG = epg_load_config(__DIR__);
require __DIR__ . '/lib/epg.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/providers.php';
require __DIR__ . '/lib/epg_front.php';
require __DIR__ . '/lib/web.php';

web_no_cache();
web_init($CFG);
$GLOBALS['CFG'] = $CFG;
$GLOBALS['WCARRY'] = array();
$WSTATE = web_state($CFG);
$GLOBALS['WSTATE'] = $WSTATE;

// private instance gate (?token= travels on every link, see u())
epg_require_site($CFG);

try {
  $pdo = epg_open_db($CFG['db_path']);
} catch (Exception $e) {
  http_response_code(503);
  header('Content-Type: text/html; charset=UTF-8');
  echo '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"'
    . ' "http://www.w3.org/TR/html4/loose.dtd"><html lang="hu"><head>'
    . '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
    . '<title>Karbantartás</title></head><body>'
    . '<h1>Karbantartás</h1><p>Az adatbázis nem elérhető. Kérem, próbálja később.</p>'
    . '</body></html>';
  exit;
}

// Effective provider: wanted one if imported, else first healthy feed.
// (A default pointing at empty tables must not 404 the whole site.)
$WSTATE['provider'] = web_resolve_provider($pdo, $CFG, $WSTATE['provider']);
$GLOBALS['WSTATE'] = $WSTATE;

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$base_url = isset($CFG['base_url']) && $CFG['base_url'] !== ''
  ? rtrim($CFG['base_url'], '/') : $scheme . '://' . $host . web_base($CFG);

$path = web_request_path($CFG);
if ($path === null) {
  $path = '/__outside__'; // request outside the install base -> 404 below
}

$ROUTE = null;
$page_file = null;
if (preg_match('#^/$#', $path)) {
  $page_file = 'home.php';
} elseif (preg_match('#^/settings/?$#', $path)) {
  $page_file = 'settings.php';
} elseif (preg_match('#^/tvmusor/([A-Za-z0-9_]+)(?:/(\d{4}-\d{2}-\d{2}))?/?$#', $path, $m)) {
  $page_file = 'channel.php';
  $ROUTE = array('slug' => $m[1], 'date' => isset($m[2]) ? $m[2] : null);
} elseif (preg_match('#^/musor/([A-Za-z0-9_]+)/(\d+)/?$#', $path, $m)) {
  $page_file = 'detail.php';
  $ROUTE = array('slug' => $m[1], 'start' => $m[2]);
} elseif (preg_match('#^/(adatvedelem|impresszum)/?$#', $path, $m)) {
  $page_file = 'legal.php';
  $ROUTE = array('doc' => $m[1]);
} elseif (preg_match('#^/(sitemapxml\.php|sitemap\.xml)$#', $path)) {
  require __DIR__ . '/pages/_render.php'; // channel_path() for URL building
  require __DIR__ . '/pages/sitemap.php';
  exit;
}

// ?ch[]= form posts canonicalize to CSV via 302 (single EPG page only)
if ($page_file === 'home.php'
  && (isset($_GET['ch']) && is_array($_GET['ch']) || isset($_GET['show']))) {
  $tmp_redir = false;
  $tmp_sel = web_selected_channels($pdo, $WSTATE['provider'],
    web_valid_date(isset($_GET['date']) ? $_GET['date'] : null), $tmp_redir);
  if ($tmp_redir) {
    $q = $_GET;
    unset($q['ch'], $q['show'], $q['p']);
    if (count($tmp_sel)) {
      $q['ch'] = implode(',', $tmp_sel);
    }
    // keep token/provider/view/date intact; $path is app-relative,
    // the Location header must carry the install base (subfolders!)
    $qs = http_build_query($q);
    header('Location: ' . web_base($CFG) . $path . ($qs !== '' ? '?' . $qs : ''), true, 302);
    exit;
  }
}

if ($page_file === null) {
  http_response_code(404);
  $page = array('title' => '404 - Nincs ilyen oldal | EPG',
    'desc' => '', 'canonical' => $base_url . '/', 'h1' => 'TVműsor');
  $viewlinks = array('h' => '/', 'v' => '/');
  require __DIR__ . '/templates/header.php';
  echo '<h2 class="section-title">404</h2><p>Nincs ilyen oldal.</p>'
    . '<p><a href="' . h(u('/')) . '">Főoldal</a></p>';
  require __DIR__ . '/templates/footer.php';
  exit;
}

// page contract: sets $page, $viewlinks, may extend $GLOBALS['WCARRY'].
// Buffered so <head> stays first in output although $page is known last.
$page = array('title' => 'EPG', 'desc' => '', 'canonical' => $base_url . $path);
$viewlinks = array('h' => $path, 'v' => $path);
require __DIR__ . '/pages/_render.php';
ob_start();
require __DIR__ . '/pages/' . $page_file;
$body = ob_get_clean();
require __DIR__ . '/templates/header.php';
echo $body;
require __DIR__ . '/templates/footer.php';
