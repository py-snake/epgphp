<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html lang="hu">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<?php if (!empty($page['refresh'])): ?>
<meta http-equiv="refresh" content="<?php echo (int)$page['refresh']; ?>">
<?php endif; ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($page['title']); ?></title>
<meta name="description" content="<?php echo h($page['desc']); ?>">
<link rel="canonical" href="<?php echo h($page['canonical']); ?>">
<meta name="robots" content="index,follow">
<meta property="og:type" content="<?php echo h(isset($page['og_type']) ? $page['og_type'] : 'website'); ?>">
<meta property="og:title" content="<?php echo h($page['title']); ?>">
<meta property="og:description" content="<?php echo h($page['desc']); ?>">
<meta property="og:url" content="<?php echo h($page['canonical']); ?>">
<?php if (!empty($page['og_image'])): ?>
<meta property="og:image" content="<?php echo h($page['og_image']); ?>">
<?php endif; ?>
<script type="application/ld+json">{"@context":"http://schema.org","@type":"WebSite","name":"EPG"}</script>
<link rel="stylesheet" type="text/css" href="<?php echo h(web_asset('/style.css') . '?v=' . urlencode(isset($CFG['version']) ? $CFG['version'] : '1')); ?>">
</head>
<body<?php
$__cls = array();
if (!empty($page['app'])) { $__cls[] = 'app'; }
if (isset($WSTATE['theme']) && $WSTATE['theme'] === 'dark') { $__cls[] = 'dark'; }
$__font = web_font_raw();
if (in_array($__font, array('1', '2', '4', '5'), true)) { $__cls[] = 'fs' . $__font; }
if (count($__cls)) { echo ' class="' . h(implode(' ', $__cls)) . '"'; }
?>>
<div class="topnav">
<table class="wrap navtab" cellpadding="0" cellspacing="0"><tr>
<td class="brand"><a href="<?php echo h(u('/', array(), null, 'now')); ?>">EPG</a></td>
<td class="navlinks">
<a href="<?php echo h(u('/', array(), null, 'now')); ?>">CSATORNÁK</a> |
<a href="<?php echo h(u('/settings')); ?>">Beállítások</a>
</td>
</tr></table>
</div>
<?php if (!empty($page['h1'])): ?>
<div class="header">
<div class="wrap">
<h1 class="main-title"><?php echo h($page['h1']); ?></h1>
</div>
</div>
<?php endif; ?>
<div class="wrap content">
