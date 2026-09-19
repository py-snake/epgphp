<?php
// lib/providers/freeepg.php - free-epg.de HU (stale backfill only).
// 326 ch / 49227 prog, UTC +0000, title/desc/category, no episodes/icons.
// Last-Modified Jul-2026 at last check: enable only for backfill.

function epg_provider_freeepg() {
  return array(
    'id'       => 'freeepg',
    'label'    => 'free-epg.de HU',
    'urls'     => array('https://www.free-epg.de/api/epg/hu.xml.gz'),
    'tz_hint'  => '+0000',
  );
}
