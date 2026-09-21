<?php
// lib/providers.php - modular provider registry.
// A provider = lib/providers/<id>.php defining epg_provider_<id>().
// Adding a provider: drop in one file + list its id in config.php 'providers'.
// No core code changes, no migrations of other providers' tables.
// XMLTV providers only need id/label/urls/tz_hint (cron fetches the URLs and
// streams them through epg_import_file()). JSON/API providers (e.g. porthu)
// instead set 'custom_import' => '<fn>' and cron/import.php calls
// <fn>(PDO, def, opts) which must return epg_import_file()-style stats.

function epg_provider_defs() {
  static $defs = null;
  if ($defs !== null) {
    return $defs;
  }
  $defs = array();
  $dir = __DIR__ . '/providers';
  if (is_dir($dir)) {
    foreach (glob($dir . '/*.php') as $f) {
      $id = basename($f, '.php');
      if (!preg_match('/^[a-z0-9_]+$/', $id)) {
        continue;
      }
      require_once $f;
      $fn = 'epg_provider_' . $id;
      if (is_callable($fn)) {
        $def = call_user_func($fn);
        if (is_array($def)) {
          $def['id'] = $id;
          $defs[$id] = $def;
        }
      }
    }
  }
  return $defs;
}

// Fetch one provider's feed(s) to temp files. Returns local paths.
// Cheap freshness: skips download when the server answers 304 (etag/mtime
// remembered in meta). Returns array('files'=>..., 'fresh'=>bool).
function epg_provider_fetch(PDO $pdo, array $def, $timeout = 60) {
  $tmpdir = sys_get_temp_dir();
  $files = array();
  $id = $def['id'];
  $etag = epg_meta_get($pdo, $id, 'etag', '');
  $mtime = epg_meta_get($pdo, $id, 'feed_mtime', '');
  foreach (isset($def['urls']) ? $def['urls'] : array() as $i => $url) {
    $local = $tmpdir . '/epg_' . $id . '_' . $i . '_' . basename(parse_url($url, PHP_URL_PATH));
    list($data, $status) = epg_http_get($url, $timeout, $etag, $mtime);
    global $epg_http_last_headers;
    if (isset($epg_http_last_headers) && is_array($epg_http_last_headers)) {
      foreach ($epg_http_last_headers as $h) {
        if (preg_match('/^ETag:\s*(.+)$/i', trim($h), $m)) {
          epg_meta_set($pdo, $id, 'etag', trim($m[1]));
        }
        if (preg_match('/^Last-Modified:\s*(.+)$/i', trim($h), $m)) {
          epg_meta_set($pdo, $id, 'feed_mtime', trim($m[1]));
        }
      }
    }
    if ($status === 304) {
      continue; // unchanged upstream
    }
    if ($data === false || $status >= 400) {
      throw new RuntimeException("fetch failed ($status): $url");
    }
    file_put_contents($local, $data);
    $files[] = $local;
  }
  return array('files' => $files, 'fresh' => count($files) > 0);
}

// Normal browser headers for every outbound fetch: some feed/API hosts
// throttle or refuse non-browser clients (bot UAs, missing Accept).
function epg_http_user_agent() {
  return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    . ' (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';
}

function epg_http_browser_headers() {
  return array(
    'Accept: */*',
    'Accept-Language: hu-HU,hu;q=0.9,en;q=0.8',
  );
}

// GET with conditional headers. file_get_contents() when allow_url_fopen is
// on (typical), curl fallback otherwise (some shared hosts disable fopen URLs).
// Returns array(body|false, http_status). Also harvests ETag/Last-Modified.
function epg_http_get($url, $timeout, $etag, $mtime) {
  $headers = array();
  if (function_exists('curl_init') && !ini_get('allow_url_fopen')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)$timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // signed mirrors 302
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, epg_http_user_agent());
    curl_setopt($ch, CURLOPT_HEADER, true);
    $cond = epg_http_browser_headers();
    if ($etag !== '') {
      $cond[] = "If-None-Match: $etag";
    }
    if ($mtime !== '') {
      $cond[] = "If-Modified-Since: $mtime";
    }
    if (count($cond)) {
      curl_setopt($ch, CURLOPT_HTTPHEADER, $cond);
    }
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false) {
      curl_close($ch);
      return array(false, $status);
    }
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $htext = substr($resp, 0, $hsize);
    $body = substr($resp, $hsize);
    curl_close($ch);
    $headers = explode("\r\n", $htext);
  } else {
    $hdr = array_merge(array('User-Agent: ' . epg_http_user_agent()),
      epg_http_browser_headers());
    if ($etag !== '') {
      $hdr[] = "If-None-Match: $etag";
    }
    if ($mtime !== '') {
      $hdr[] = "If-Modified-Since: $mtime";
    }
    $ctx = stream_context_create(array('http' => array(
      'timeout' => (int)$timeout,
      'header' => implode("\r\n", $hdr) . "\r\n",
      'ignore_errors' => true,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
  // PHP 8.5 deprecated the local $http_response_header; prefer the new API.
  // Legacy access lives in http_legacy.php, loaded only where needed, so the
  // deprecated variable is never even compiled on PHP >= 8.5.
  if (function_exists('http_get_last_response_headers')) {
    $h = http_get_last_response_headers();
    $headers = is_array($h) ? $h : array();
  } else {
    require_once __DIR__ . '/http_legacy.php';
    $headers = epg_http_last_headers_legacy();
  }
  }
  global $epg_http_last_headers;
  $epg_http_last_headers = $headers;
  $status_out = 0;
  foreach ($headers as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d+)#i', $h, $m)) {
      $status_out = (int)$m[1];
    }
  }
  if ($status_out === 0) {
    $status_out = (int)$status; // curl error path (0 = connection failed)
  }
  return array($body, $status_out);
}
