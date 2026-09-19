<?php
// config.example.php - COPY to config.php on install, then set real tokens:
//   cp config.example.php config.php
// config.php is git-ignored on purpose (it holds the live secrets below).
// Frontend pages include the copy; cron + api endpoints enforce the tokens.

return array(
  // --- access tokens ---
  // Generate fresh ones, e.g.: php -r 'echo trim(file_get_contents("/proc/sys/kernel/random/uuid")), "\n";'
  'token'         => 'CHANGE-ME-cron-token', // cron HTTP key, hash_equals() compare
  // site-wide gate for a private instance. Empty string = public site, no gate.
  // When non-empty, pages/*.php (via lib/auth.php) and api/epg.php require it.
  'site_token'    => 'CHANGE-ME-site-token',
  'site_cookie'   => 'tvsite',   // cookie name that remembers site_token auth
  'site_cookie_days' => 30,
  // URL-only frontend: no cookies at all, every state in path+query (incl.
  // the site token, propagated link-to-link). true = classic cookie convenience.
  'cookies'       => false,

  // --- storage ---
  'db_path'       => __DIR__ . '/var/epg.sqlite',

  // --- install location ---
  // null = auto-detect from the front controller path (zero-config uploads:
  // domain root and subfolders like example.com/tv/ just work).
  // Set explicitly ('', '/tv') only to override detection.
  'base_path'     => null,

  // --- providers: enabled ids + priority order ( = default + read fallback chain).
  // Each id must have lib/providers/<id>.php. Tables are programs_<id>, channels_<id>.
  'providers'     => array('ripper', 'epglat', 'hungary1', 'iptvepg', 'freeepg'),

  // --- retention (days) ---
  'keep_past'     => 30,   // history depth, per provider table
  'keep_future'   => 8,    // headroom above the 2-4d feed window
  'prune_after_import' => true,
  'vacuum_after_prune' => true,

  // --- file-level compressed snapshots ---
  'backup_gz'     => true,
  'backup_dir'    => __DIR__ . '/var/backup',
  'backup_keep'   => 7,    // rotated generations kept

  // --- cron robustness ---
  'lock_file'     => __DIR__ . '/var/import.lock',
  'log_file'      => __DIR__ . '/var/import.log',
  'fetch_timeout' => 60,

  // --- frontend defaults ---
  'default_provider' => 'ripper',
  'tz'            => 'Europe/Budapest',
  // browser auto-refresh of guide pages, minutes (meta refresh, no JS).
  // 0 = off; ?refresh=N overrides per URL (0..120). Pick in /settings.
  'refresh_mins'  => 0,

  // --- release stamp (view-source check: <!-- epg-viewer ... -->) ---
  // Bump on every upload so a mixed/partial deploy is visible immediately.
  'version'       => '6.6-20260918',
);
