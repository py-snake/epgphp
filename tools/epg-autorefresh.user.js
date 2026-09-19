// ==UserScript==
// @name         EPG auto-frissites + ugras a most futora
// @namespace    tvsite-epg
// @version      1.0
// @description  Cache-t megkerulo ujratoltes (Ctrl+F5 hatas) + ugras a #now savhoz az EPG oldalon.
// @match        https://bossking.serv00.net/tvsite/*
// @match        http://localhost*/tvsite/*
// @match        http://127.0.0.1*/tvsite/*
// @grant        none
// @run-at       document-idle
// ==/UserScript==

(function () {
  'use strict';

  var INTERVAL_SEC = 60; // automata ujratoltes masodpercben; 0 = csak kezi (Alt+R)
  var MIN_AGE_SEC = 45;  // hatterbol visszatereskor csak ennel regebbi oldalt tolt ujra

  var loadedAt = Date.now();

  function freshReload() {
    var u = new URL(location.href);
    // cache-buster: mindig uj URL -> a bongeszo keptelen a cache-bol szolgalni,
    // a szervertol keri le (a Ctrl+F5 funkcionalis megfeleloje; a location.reload(true)
    // parametert a modern bongeszok figyelmen kivul hagyjak). A PHP az ismeretlen
    // "_" parametert figyelmen kivul hagyja, a generalt linkekbe nem szivarog be.
    u.searchParams.set('_', Date.now().toString(36));
    // a meta-refresh helyett a script vezerel (kulonben ket idozito versenyzene,
    // es a meta ugyanarra a "_" URL-re frissitene ra, ami mar cache-bol jonhetne)
    u.searchParams.delete('refresh');
    // toltes utan ugras az epp futo musorra (vizszintes timeline nezeben el)
    u.hash = 'now';
    location.href = u.toString();
  }

  function pageAgeSec() {
    return (Date.now() - loadedAt) / 1000;
  }

  // 1) idozitett frissites. Eloterben megbizhato; hatter-tabon a bongeszo
  // az idozitoket fojtja, eldobott tabon pedig semmi nem fut - lasd megjegyzes lent.
  if (INTERVAL_SEC > 0) {
    setTimeout(freshReload, INTERVAL_SEC * 1000);
  }

  // 2) hatterbol visszatereskor azonnali bepotlas (ha regota nem frissult).
  // Ez a legfontosabb ag: felnyitod a tabot, es maris friss + a most futon all.
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && pageAgeSec() > MIN_AGE_SEC) {
      freshReload();
    }
  });
  window.addEventListener('focus', function () {
    if (pageAgeSec() > MIN_AGE_SEC) {
      freshReload();
    }
  });

})();

// MEGJEGYZES A HATTER-FUTASROL:
// Egyik megoldas sem fut garantaltan hatter-tabon: a bongeszok a rejtett tabok
// idozitoit nehany perc utan kb. percenkent 1 futasra fojtjak, a memoriatakarekos
// (Brave: Memory Saver) pedig el is dobhatja a tabot - olyankor a script es a
// <meta refresh> is all, amig vissza nem tersz a tabra (ekkor a 2. ag azonnal
// frissit). Megbizhatobb hatter-mukodeshez: Brave beallitasokban a memoria-
// kimelo kivetelei koze tedd az oldalt (brave://settings/system), tuzd ki a
// tabot (pin), es ne aludjon a gep. 100%-os garancia csak tabon kivul letezne
// (kiterjesztes alarms API-val) - erre itt nincs szukseg, mert a no-store
// valaszfejlecek ota minden toltes ugyis a szervertol jon.
