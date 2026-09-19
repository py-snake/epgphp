<?php
// tests/bootstrap.php - project config for tests. Prefers the live
// config.php (git-ignored, real secrets); falls back to the tracked
// config.example.php so a fresh clone can run the suite immediately.

function t_project_config() {
  $root = dirname(__DIR__);
  foreach (array($root . '/config.php', $root . '/config.example.php') as $f) {
    if (is_file($f)) {
      $cfg = require $f;
      if (is_array($cfg)) {
        return $cfg;
      }
    }
  }
  fwrite(STDERR, "ERROR: no config.php nor config.example.php found\n");
  exit(1);
}
