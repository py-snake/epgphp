<?php
// lib/providers/epglat.php - epg.lat HU mirror of the ripper feed.
// Byte-compatible shape with epgshare's epg_ripper_HU1 (193 ch / ~22741 prog,
// local +0200, dotted tvmustra-native ids, tvmustra icons). Kept right behind
// `ripper` in the fallback chain: same data, different host.

function epg_provider_epglat() {
  return array(
    'id'       => 'epglat',
    'label'    => 'epg.lat HU (ripper mirror)',
    'urls'     => array('https://epg.lat/files/hu.xml.gz'),
    'tz_hint'  => '+0200',
  );
}
