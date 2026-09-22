<?php
// lib/config.php - config loader with a helpful failure mode.
// Every entry point loads config through epg_load_config() instead of a bare
// require, so a missing config.php (fresh clone: only config.example.php is
// tracked) explains itself instead of fataling on an unclear error.

function epg_config_path($here_dir) {
  return $here_dir . '/config.php';
}

function epg_load_config($here_dir) {
  if (php_sapi_name() !== 'cli') {
    // Production: never print fatals/stack traces to visitors (paths, SQL).
    // Logging is untouched (log_errors), only on-screen display is muted.
    @ini_set('display_errors', '0');
  }
  $path = epg_config_path($here_dir);
  if (is_file($path)) {
    $cfg = require $path;
    if (is_array($cfg)) {
      return $cfg;
    }
  }
  $msg = 'Missing or invalid config.php - copy config.example.php to config.php'
    . ' and set token + site_token.';
  if (php_sapi_name() === 'cli') {
    fwrite(STDERR, "ERROR: $msg\n");
    exit(1);
  }
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "Configuration error: $msg\n";
  exit;
}
