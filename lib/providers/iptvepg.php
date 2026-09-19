<?php
// lib/providers/iptvepg.php - iptv-epg.org HU (checked 2026-09-18).
// 152 ch / 34101 prog, 7-DAY window (biggest of all feeds), UTC +0000,
// lang=hu, rich: category, date(YYYY), credits, episode-num (no system attr),
// programme icons (1512). All 152 channels carry an icon.
// NOTE: the plain URL 302-redirects to a signed mirror on every hit - the
// fetcher must follow redirects (fopen does; curl needs FOLLOWLOCATION).

function epg_provider_iptvepg() {
  return array(
    'id'       => 'iptvepg',
    'label'    => 'iptv-epg.org HU',
    'urls'     => array('https://iptv-epg.org/files/epg-hu.xml.gz'),
    'tz_hint'  => '+0000',
  );
}
