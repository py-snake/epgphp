<?php
// lib/http_legacy.php - last-resort header access for PHP < 8.0.
// Loaded ONLY when http_get_last_response_headers() does not exist.
// Isolated in its own file on purpose: merely *compiling* a reference to the
// local $http_response_header emits a deprecation on PHP >= 8.5, so this
// file must never be loaded there (see epg_http_get() in lib/providers.php).

function epg_http_last_headers_legacy() {
  if (isset($http_response_header) && is_array($http_response_header)) {
    return $http_response_header;
  }
  return array();
}
