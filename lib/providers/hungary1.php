<?php
// lib/providers/hungary1.php - open-epg Hungary1 (fallback).
// 179 ch / 13716 prog, UTC +0000, minimal fields (title/desc/episode xmltv_ns),
// spaced ids e.g. "RTL (HD).hu" (AI.md section 11).

function epg_provider_hungary1() {
  return array(
    'id'       => 'hungary1',
    'label'    => 'open-epg Hungary1',
    'urls'     => array('https://www.open-epg.com/files/hungary1.xml.gz'),
    'tz_hint'  => '+0000',
  );
}
