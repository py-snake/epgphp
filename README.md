# epgphp

Magyar, tisztán szerveroldali (PHP + HTML + CSS) TV-műsor böngésző. Nincs JavaScript,
nincsenek sütik — minden állapot az URL-ben utazik. Öt, külön táblákban tárolt EPG-szolgáltatóval (`ripper`, `epglat`,
`hungary1`, `iptvepg`, `freeepg`), automatikus fallback-lánccal.

## Követelmények

- PHP >= 7.0, bővítmények: `pdo_sqlite`, `xmlreader`, `zlib`, `mbstring`, `json`
- Írható `var/` könyvtár (sqlite DB, lock, log, gz-mentések)
- Apache + mod_rewrite (enélkül is megy `?p=/…` fallbackkal, lásd lent)

Ellenőrzés a szerveren: `php tests/run.php` (függőség nélkül, ~500 teszt).

## Telepítés

1. Másold fel a csomagot domain-gyökérbe vagy almappába
   (`public_html/` vagy `public_html/tv/`). Az almappát a kód magától
   felismeri (`base_path: null` = auto-detect a front-controller útvonalából).
2. Hozd létre a saját `config.php`-d az example-ból (a `config.php` git-ignored,
   éles titkokat tartalmaz, soha ne commitold):

       cp config.example.php config.php

   Majd cseréld le mindkét tokent új UUID-re:
   - `token` – cron-kulcs a `cron/*.php`-hez,
   - `site_token` – a webfelület kapuja (`''` = publikus oldal).
   Friss UUID: `php -r 'echo trim(file_get_contents("/proc/sys/kernel/random/uuid")), "\n";'`
   Ha a tokenek korábban bárhol nyilvánosságra kerültek (chat, log, URL-előzmény),
   generálj újakat — a régiekkel bárki cron-importot indíthat.
3. Ellenőrizd: `/cron/status.php?token=CRONTOKEN` → `{"status":"ok",…}`.

## Cronjob: szolgáltatók letöltése (EGY url elég)

Egyetlen hívás végigmegy **mind a hat** bekapcsolt szolgáltatón
(`ripper`, `epglat`, `hungary1`, `iptvepg`, `freeepg`, `porthu` — sorrend:
`config.php` `providers`). A `porthu` JSON API-ról dolgozik (XMLTV helyett),
ezért sok kis HTTP-hívása van: nagy ablakban érdemes külön ütemezni
(`?provider=porthu`).
Ha egy szolgáltató feedje hibázik (timeout, 404, hibás XML), a hiba a
`meta` táblába kerül, a válasz `partial` lesz, és az import **halad tovább
a következő szolgáltatóra** — a jó táblákat sosem rontja el (szolgáltatónként
külön tranzakció + `flock`, átfedő futásból `409 busy`).

    https://DOMAIN/tvsite/cron/import.php?token=CRONTOKEN

Válasz példa:

    {"status":"ok","providers":{"ripper":{"channels":192,"programmes":22741,…},
     "hungary1":{…},"iptvepg":{…}},"pruned":{"ripper":0,…},"backup":"var/backup/epg-….sqlite.gz"}

Állapot-lekérdezés (sorok, utolsó import/hiba, DB-méret szolgáltatónként):

    https://DOMAIN/tvsite/cron/import.php?token=…          # mind (ajánlott, napi 1x)
    https://DOMAIN/tvsite/cron/status.php?token=…          # ellenőrzés

### Példák időzítésre

cPanel → Cron Jobs (naponta hajnalban, egy sor elég):

    5 4 * * * curl -s -o /dev/null "https://DOMAIN/tvsite/cron/import.php?token=CRONTOKEN"

wget-tel (ha nincs curl):

    5 4 * * * wget -q -O /dev/null "https://DOMAIN/tvsite/cron/import.php?token=CRONTOKEN"

serv00 példa (a panel Cron fülén sima parancs):

    curl -s -o /dev/null "https://USER.serv00.net/tvsite/cron/import.php?token=CRONTOKEN"

Ha a tárhely `max_execution_time` miatt megölné az egyben futást, darabold
szolgáltatónként, eltolva (mindegyik önmagában idempotens, ismételhető):

    5  4 * * * curl -s -o /dev/null "https://DOMAIN/tvsite/cron/import.php?token=CRONTOKEN&provider=ripper"
    15 4 * * * curl -s -o /dev/null "https://DOMAIN/tvsite/cron/import.php?token=CRONTOKEN&provider=epglat"
    20 4 * * * curl -s -o /dev/null "https://DOMAIN/tvsite/cron/import.php?token=CRONTOKEN&provider=hungary1"
    30 4 * * * curl -s -o /dev/null "https://DOMAIN/tvsite/cron/import.php?token=CRONTOKEN&provider=iptvepg"
    35 4 * * * curl -s -o /dev/null "https://DOMAIN/tvsite/cron/import.php?token=CRONTOKEN&provider=freeepg"
    # Megjegyzés: az iptv-epg.org sima URL-je lejárt aláírt tükörre 302-zik;
    # a letöltő követi az átirányítást, külön teendő nincs.

SSH-hozzáféréssel ugyanez időlimit nélkül (ugyanaz a kód):

    php bin/import_epg.php --src=https://epgshare01.online/epgshare01/epg_ripper_HU1.xml.gz --provider=ripper
    php bin/import_epg.php --src=https://www.open-epg.com/files/hungary1.xml.gz --provider=hungary1

### Mit csinál egy import-futás (sorrendben, szolgáltatónként)

1. Letöltés (`.gz`, etag alapján `304`-nél átugorja),
2. streaming XML-feldolgozás a saját `programs_<id>` / `channels_<id>` táblába
   (a többi szolgáltató táblájához nem nyúl),
3. hiba esetén `last_error` + megy tovább a következőre,
4. végén: `keep_past` (30 nap) / `keep_future` (8 nap) takarítás táblánként,
   `VACUUM`, `var/backup/epg-*.sqlite.gz` rotált mentés (`backup_keep`: 7).

Kapcsolók a `config.php`-ben: `providers`, `keep_past`, `keep_future`,
`prune_after_import`, `backup_gz`, `backup_keep`, `fetch_timeout`.
Új szolgáltató = egy fájl `lib/providers/<id>.php` + bejegyzés a
`providers` listába (tábla-migráció, kódmódosítás nem kell).

## Frontend (böngészés)

- `/` – egyoldalas EPG rács (`?cat=`, `?ch=`, `?date=`),
- `?view=h|v` – vízszintes idővonal / függőleges lista,
- `?zoom=0..4` – méret (vízszintes: 2400/3600/6000/9000/12000px, függőleges: 110/140/170/210/260px oszlop),
- `?provider=` – szolgáltató-választás (fallback-lánc automatikus),
- `?refresh=N` – böngésző-frissítés percben (0 = ki, alapból ki),
- `/tvmusor/{SLUG}[/{date}]` – egy csatorna napja,
- `/musor/{SLUG}/{start}` – műsor-részletező,
- `/settings` – beállítások + URL-építő (szolgáltatók, csatornák, dátumok
  böngészése, kész linkek másolása),
- `/api/epg.php` – ugyanaz JSON-ben külső felhasználásra.

Minden link viszi a tokent (`?token=…`), ha a `site_token` be van állítva.
Rewrite nélkül: `/?p=/tvmusor/RTL&token=…`.

## Hibaelhárítás

| Tünet | Ok / teendő |
|---|---|
| `403` mindenhol | rossz/hiányzó `?token=` (privát példány) |
| `409 {"status":"busy"}` | már fut egy import, várj 1 percet |
| Üres rács import közben | normális átmenet volt: az olvasók most 30 mp-ig várnak a zárolásra (`busy_timeout`), nem adnak üres oldalt |
| `partial` az importban | egyik feed döglött — `providers.<id>.error` mutatja, a többi OK |
| Üres oldal / 503 | nincs DB: futtasd az import URL-t (lásd fent) |
| Régi kód fut | view-source végén: `<!-- epg-viewer VERZIÓ -->` |
| Szép URL-ek 404 | mod_rewrite kell, vagy `?p=` fallback |
| `var/` írási hiba | könyvtárjog (775/777 a hosttól függően) |
