<?php
// pages/legal.php - /adatvedelem, /impresszum. Generic texts for a private
// EPG-viewer instance (no company, no tracking to disclose beyond the basics).

$doc = isset($ROUTE['doc']) ? $ROUTE['doc'] : 'adatvedelem';
$GLOBALS['WCARRY'] = array(
  'zoom' => web_zoom_raw(),
  'refresh' => isset($_GET['refresh']) ? (string)$_GET['refresh'] : null,
);
$viewlinks = array('h' => '/' . $doc, 'v' => '/' . $doc);
if ($doc === 'impresszum') {
  $page = array(
    'title' => 'Impresszum | EPG',
    'desc' => 'Impresszum: üzemeltető, elérhetőség.',
    'canonical' => $base_url . '/impresszum',
    'h1' => 'Impresszum',
  );
  echo '<h2 class="section-title">Impresszum</h2>';
  echo '<p>Ez egy privát EPG-néző példány. Üzemeltető: a telepítést végző.</p>';
} else {
  $page = array(
    'title' => 'Adatvédelem | EPG',
    'desc' => 'Adatvédelmi tájékoztató.',
    'canonical' => $base_url . '/adatvedelem',
    'h1' => 'Adatvédelem',
  );
  echo '<h2 class="section-title">Adatvédelem</h2>';
  echo '<p>Ez az oldal sütiket nem használ, JavaScriptet nem futtat, '
    . 'látogatói statisztikát nem gyűjt. A szerver naplók a szokásos '
    . 'üzemeltetési adatokat tartalmazhatják.</p>';
}
