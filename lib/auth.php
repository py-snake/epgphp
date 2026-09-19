<?php
// lib/auth.php - two-token gate (old-PHP safe, PHP >= 7.0).
//
//  - cron token  : ?token= (GET or POST) on cron/*.php, compared with hash_equals().
//  - site token  : website-wide access for a private instance. Empty site_token
//                  in config.php = public site, gate open. Otherwise the visitor
//                  must present ?token=<site_token> once; success sets a cookie
//                  (site_cookie) so later page views need no token in the URL.
//                  The token value itself is never stored in the cookie, only a
//                  verifier hash (HMAC with the token as key).

function epg_request_token() {
  if (isset($_REQUEST['token'])) {
    return (string)$_REQUEST['token'];
  }
  // "Authorization: Bearer <token>" also accepted (API clients)
  if (function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
      if (strcasecmp($k, 'Authorization') === 0 && preg_match('/^Bearer\s+(.+)$/i', trim($v), $m)) {
        return $m[1];
      }
    }
  } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])
    && preg_match('/^Bearer\s+(.+)$/i', trim($_SERVER['HTTP_AUTHORIZATION']), $m)) {
    return $m[1];
  }
  return '';
}

function epg_cron_auth(array $cfg) {
  $ok = isset($cfg['token']) && $cfg['token'] !== ''
    && hash_equals((string)$cfg['token'], epg_request_token());
  return $ok;
}

function epg_require_cron(array $cfg) {
  if (!epg_cron_auth($cfg)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    epg_json(array('status' => 'forbidden'));
    exit;
  }
}

// Site gate. Returns true when access granted (and refreshes the cookie,
// unless 'cookies'=>false in config: pure-URL mode, token lives only in ?token=
// and is propagated link-to-link by lib/web.php u()).
// When site_token is '' the site is public: always true, no cookie.
function epg_site_auth(array $cfg) {
  if (empty($cfg['site_token'])) {
    return true; // public instance
  }
  $no_cookies = isset($cfg['cookies']) && !$cfg['cookies'];
  $cookie = isset($cfg['site_cookie']) && $cfg['site_cookie'] !== ''
    ? $cfg['site_cookie'] : 'tvm_site';
  $want = hash_hmac('sha256', 'site-ok', (string)$cfg['site_token']);
  if (!$no_cookies && isset($_COOKIE[$cookie])
    && hash_equals($want, (string)$_COOKIE[$cookie])) {
    return true;
  }
  if (hash_equals((string)$cfg['site_token'], epg_request_token())) {
    if (!$no_cookies) {
      $days = isset($cfg['site_cookie_days']) ? (int)$cfg['site_cookie_days'] : 30;
      if (!headers_sent()) {
        // CLI tests / stray output: cookie can't persist, current request still
        // passes via $_COOKIE below.
        setcookie($cookie, $want, time() + $days * 86400, '/', '', false, true);
      }
      $_COOKIE[$cookie] = $want;
    }
    return true;
  }
  return false;
}

function epg_require_site(array $cfg) {
  if (epg_site_auth($cfg)) {
    return;
  }
  http_response_code(403);
  header('Content-Type: text/html; charset=UTF-8');
  echo "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>403</title></head>"
    . "<body><h1>403</h1><p>Private instance. Add ?token=... to the URL.</p></body></html>";
  exit;
}

function epg_require_site_json(array $cfg) {
  if (epg_site_auth($cfg)) {
    return;
  }
  http_response_code(403);
  header('Content-Type: application/json; charset=UTF-8');
  epg_json(array('status' => 'forbidden'));
  exit;
}
