<?php
// lib/providers/ripper.php - epgshare epg_ripper_HU1 (preferred primary).
// 193 ch / 22741 prog, local +0200, dotted ids, rich fields
// (AI.md section 12). Upstream icons hotlinked from the source site.

function epg_provider_ripper() {
  return array(
    'id'       => 'ripper',
    'label'    => 'epgshare HU1',
    'urls'     => array('https://epgshare01.online/epgshare01/epg_ripper_HU1.xml.gz'),
    'tz_hint'  => '+0200',
  );
}
