# TVMustra.hu – Site Structure (AI Reference)

> Purpose: Black-box documentation of https://www.tvmustra.hu/ to allow a later
> rebuild as a **pure PHP + HTML + CSS site, no JavaScript, no browser-side dynamic content**,
> compatible with very old browsers.
> Generated: 2026-09-18 by black-box HTTP/HTML analysis. No server source was public.
> Local workspace: `/home/multibox/source/repos/tvmustra` (was empty at analysis time).

## 1. High-level overview

- Type: Hungarian online TV guide / EPG portal. ~179 channels.
- Owner: 4Web Kft., network n4.hu. Contact `info@tvmustra.hu`.
- Current live stack (to be replaced):
  - `Apache/2.4.57 (Debian)`, `Content-Type: text/html; charset=UTF-8`
  - Custom PHP (no framework detected): `index.php` front-controller,
    `/api/*.php`, `/sitemapxml.php`.
  - SSR HTML + vanilla JS (`/js/app.js` ~29KB/681 lines), `/sw.js` WebPush.
  - CSS: Bootstrap 5.3.0 (jsdelivr), Font-Awesome 6.4.0 (cdnjs),
    Flatpickr + dark theme + hu locale, `/css/style.css` ~26KB.
  - Analytics/ads (DO NOT port to old-browser version unless explicitly requested):
    Matomo `//matomo.n4.hu/matomo.php siteId=1`, gtag `G-3NECKGDFPY`,
    AdSense `ca-pub-6453106419712047`, `cfusion-internet.com tvmustra_hu_sticky`,
    Facebook SDK v18 `appId=171192279676399`, PayPal donate pixel.
- Infra: `www.tvmustra.hu -> 46.249.154.84 + 2a01:5d0:2:1100::154:84`.
- No `X-Powered-By`, no `Set-Cookie` on first hit. Cookies set only by JS:
  `tvm_cookie_consent=accepted (365d)`, `tvm_favs=id,id,... (365d)`,
  `sessionStorage push_banner_closed=1`.
- Config injected server-side: `window.TVM_CONFIG={serverTimeTs, vapidPublicKey:'BJej...'}`.
  Only `serverTimeTs` is needed in PHP rebuild (render server time directly).

## 2. URL map / routing (current)

Apache clean URLs (presumably rewritten to `index.php`):

```
GET /                                          # homepage: hero + 179-ch EPG grid
GET /tvmusorujsag                              # editorial evening list + same hero
GET /tvmusor/{SLUG}                            # channel day, e.g. /tvmusor/RTL
GET /tvmusor/{SLUG}/{YYYY-MM-DD}               # next-day variant (from sitemap.xml)
GET /grid/{cat}/                               # cat = mind|film|gyerekeknek|sport|termeszet|zene
GET /grid/{cat}/{YYYY-MM-DD-HH}                # hour-step nav, built by JS stepTime()
GET /blog                                      # list + /blog/archive/{YYYY}/{MM} sidebar
GET /blog/{slug}                               # article, og:type=article
GET /adatvedelem, /impresszum                  # legal
GET /sitemap.xml                               # static wrapper -> links to /sitemapxml.php ?
GET /sitemapxml.php                            # dynamic, ISO-8859-2, lists /,/tvmusor/* (priority 1.0/0.8/0.7)
GET /robots.txt                                # Allow: /css/ /js/ /img/ /logok/ /800_kepek/ /th_kepek/ /blog_pic/
                                               # Sitemap: https://www.tvmustra.hu/sitemapxml.php
GET /index.php?ajax_action=get_details&table={T}&id={ID} -> JSON {status,data}
POST /api/save_vote.php      FormData{id,table,vote} -> JSON {status}
POST /api/save_push_subscription.php  JSON PushSubscription
GET /sw.js, /js/app.js, /css/style.css
```

Channel slug examples (from `<select data-slug>`):
`M1HD(2), RTL(5), TV2(6), VIASAT3(9), RTL_KETTO(19), COOL(25), HBO(31), AXN(56),
NATIONALGEOGRAPHIC(76), M4(198), EPICDRAMA(235), M4_SPORT_PLUSZ(272), ...` total ~179.

### 2.1 Channel mapping (how id <-> slug <-> logo works)

Source of truth in live HTML (two identical `<select>`s: `.mobile-channel-select`, `.channel-select`):

```html
<option value="{id}" data-slug="{SLUG}">{Name}</option>
<!-- e.g. <option value="5" data-slug="RTL">RTL</option> -->
<!-- onchange: window.location.href='/tvmusor/'+slug -->
```

- `id`: numeric PK used only in DOM/JS: `ch-wrapper-{id}`, `mob-ch-wrapper-{id}`,
  `detail-row-{id}`, `toggleFav(id)`, `moveChannel(id)`, cookie `tvm_favs=id,id,...`.
- `SLUG`: uppercase canonical key used in URLs: `/tvmusor/{SLUG}`, `/tvmusor/{SLUG}/{YYYY-MM-DD}`.
  Also passed as `channelId` to `loadDetails(element, channelId, tableName, programId)`.
- `Name`: human label (may contain spaces/accents: `RTL KETTŐ`, `Magyar Mozi TV`).
- Logo filename is NOT derivable from SLUG – needs explicit map:
  `RTL->rtl.gif`, `RTL_KETTO->rtl_ketto.gif`, `NATIONALGEOGRAPHIC->ng.gif`,
  `M1HD->m1hd.gif`, etc. under `/logok/mini/` (+ OG variant `/logok/csatikon/`).
  New `config.php` must store: `id, slug, name, logo_mini, logo_og` per channel.

Live selection behavior (JS-only, to be replaced):

1. Single-channel jump: `select onchange -> /tvmusor/{SLUG}` (full page load, SEO page).
2. In-page jump: `jumpToChannel(id)` = `scrollIntoView` – JS-only, drop in rebuild.
3. Favorites: `toggleFav/moveChannel` reorders already-rendered 179 rows to top via DOM,
   persists order in `tvm_favs` cookie. All channels are always rendered; favs only reorder.

### 2.2 Channel selection in new pure-PHP frontend (no JS)

Old-browser-safe, server-rendered only (see §7.4 for implementation):

- Channel index: plain `<ul>` with `<a href=/tvmusor/{SLUG}>` per channel (replaces `<select onchange>`).
- Multi-channel filter on `/` and `/grid/{cat}` via `<form method=get>` with checkboxes
  `name=ch[] value={SLUG}` + `<input type=submit value="Mutat">` – no JS.
  PHP reads `$_GET['ch']`, validates against `config.php` allow-list, filters EPG query,
  re-renders only selected channels. Unknown slugs ignored.
- Persistent selection via links `?fav_add={id}&fav_del={id}&fav_move=up|down&redir=...`
  that do `setcookie('tvm_favs', ...)` + `Location:` redirect. Fav channel block rendered
  first, then the rest. Works without JS; cookie is read by PHP, not JS.
- Fallbacks: no params/cookie = show default set (e.g. first 12 or all, decided at build;
  recommend default 12 + `?show=all` link for old browsers/memory).

### 2.3 Bookmarkable URL state + live jump (no JS)

Goal: reload/bookmark restores exactly what the user saw, and lands on the
currently-live programmes while past+future remain visible. Fragments (`#now`)
are never sent to the server, so all filter state must live in path+query.

Canonical state (all optional, defaults in brackets):

```
/grid/{cat}/{date}-{h}?ch={CSV}          # multi-channel EPG, SEO-compatible
/tvmusor/{SLUG}/{date}?ch={CSV}          # single-channel day view
/?date={date}&cat={cat}&h={h}&ch[]={SLUG} # filter-form target, PHP 302-canonicalizes to /grid/... form
```

- `cat = mind|film|gyerekeknek|sport|termeszet|zene [mind]` – in path when possible.
- `date = YYYY-MM-DD [today]` – validated in PHP (`checkdate`), fallback today.
- `ch = CSV of SLUGs, e.g. ?ch=RTL,TV2,M1HD` – validated against allow-list,
  unknown slugs dropped, order preserved for rendering. Empty = default set + `?show=all` link.
  Form uses `ch[]` checkboxes; PHP canonicalizes to CSV + 302 redirect so the bookmark URL is clean.
- `h = 00-23 [now-1 if date==today else 08]` – window-start hour. Keeps evening bookmarks
  like `/grid/film/2026-09-18-20?ch=RTL,TV2` working. Server renders full day 00-24 regardless;
  `h` only decides which hour header gets `id="h20"` and the `Előző/Következő óra` prev/next links.
- `detail = {table}:{id}, e.g. ?detail=musor:12345#prog-12345 [none]` – server-expands one
  programme inline (replaces AJAX `get_details`). Bookmarkable programme link is
  `/musor/{table}/{id}` (canonical) + optional inline `?detail=` on EPG pages.
- What NOT in URL: fav order (cookie `tvm_favs`, fallback only), votes, push, session.
  `show=all|fav` is sugar for `ch=*` vs `ch=<favs>` – implement as links that expand to CSV.

Live-jump without JS (server does it):

1. PHP computes `$now=time()`, `$isToday=(date==today)`.
2. Per programme: `past if end<=$now`, `live if start<=$now<end && $isToday`, else `future`.
   Render `class="past|live|future"` + text `Most:` header from server time (replaces `updateTimeMarker`).
3. Render full day (past+future always present). First `live` programme per page gets
   `<a id="now"></a>` (single id per page; on multi-channel EPG put it on time-header row
   for current hour + per-channel `class=live` highlight). Top of EPG has
   `<a href="#now">Ugrás a mostani műsorhoz</a>` – pure anchor jump, works in Lynx/IE6.
4. Bookmark with fragment: `...?ch=RTL,TV2#now`. On reload the browser jumps to `#now`
   automatically (no JS). Without fragment the page loads at top – user clicks the link.
   Do NOT rely on `scrollIntoView`/`jumpToChannel()` – deleted.
5. If `date!=today`, no `live` class; `id="h{h}"` on that hour header instead, and
   `...-{h}#h{h}` bookmark jumps to that hour.

### Sitemap priorities

- `/` 1.0 hourly, `/tvmusorujsag` 0.9 daily,
  `/tvmusor/{SLUG}` 0.8 daily, `/tvmusor/{SLUG}/{tomorrow}` 0.7 daily.

## 3. Page anatomy (homepage `/` – same skeleton on all pages)

```
.top-nav-bar > nav.navbar
  a.navbar-brand[href=/] img[/img/tv_mustra_s.png 120x25]
  div#topNavMenu > div.navbar-nav
    a[href=/blog] BLOG
    a[href=/] CSATORNÁK
    a[href=/tvmusorujsag] MŰSORÚJSÁG
    a[href=mailto:info@tvmustra.hu] HIRDETÉS & MARKETING

header.header-section > div.container
  div.row
    div.col-lg-6
      h1.main-title "Mai TV műsor és esti filmek"
      p "Részletes online műsorújság: ..."
      a.btn[href=/tvmusorujsag] + a.btn[href=/blog]
      select.mobile-channel-select[onchange -> /tvmusor/+slug] (mobile only)
        option[value=id data-slug=SLUG] * 179
    div.col-lg-6
      div#blogSlider.blog-slider-container
        div.blog-slide[data-index 0..5] * 6
          div.blog-content > a[href=/blog/{slug}] > div.blog-title + div.blog-short
          div.blog-nav-box > button[onclick=prevBlogSlide/nextBlogSlide]
          div.blog-image-container > a > img[/blog_pic/*_o.jpg 250x140]

  div.row (desktop featured, d-none d-md-flex)
    div.col > a[href=/tvmusor/SLUG] > div.featured-card * 4 (RTL,TV2,VIASAT3,AXN)
      div.fc-header > img.fc-logo[/logok/mini/*.gif 60x26] + span.fc-time (20:00...)
      div.fc-img-wrap > img.fc-prog-img[https://www.tvmustra.hu/800_kepek/{hash}/{file}.jpg 200x95]
      div.fc-title

div.container.d-md-none (mobile rec-card-mobile * 4, same data, 65x45 thumbs)
h1.section-title "TVműsor"

div.filter-bar.container
  div.filter-left > a.cat-btn[href=/grid/{cat}/] * 6 (active=mind)
  div.filter-right > select.channel-select (same 179 options, onchange -> /tvmusor/)

main EPG:
  div.epg-wrapper > div.epg-scroll-area > div.epg-grid#epg-grid-main
    div.time-header#epg-time-header (sticky) : 08:00 09:00 10:00 11:00 ...
    per-channel:
      div.ch-row-wrapper#ch-wrapper-{id}[data-id] (desktop)
      div.mobile-ch-row#mob-ch-wrapper-{id} (mobile)
        logo, fav-star (.far/.fas), fav-nav up/down arrows
        program strips (time range + title + age badge 6/12/15/16/18)
        div.detail-row#detail-row-{id} > div.detail-content-placeholder (filled by AJAX)
        // vertical/mobile variants use nextElementSibling detailBlock

footer (dark #1c1830):
  logo, [BLOG|CSATORNÁK|HIRDETÉS...], © 2008-2026 4Web Kft.
  partners: n4.hu, vegyesapro.hu, eoktat.hu, tinyurl.hu, geoip.hu
  contact info@tvmustra.hu, /adatvedelem, /impresszum
  PayPal pixel, skydrive/twitter share (dead), disclaimer
  div#cookie-consent-banner, div#push-banner (JS-only)

head extras:
  meta verify-v1, canonical, geo.region=HU / placename Budapest / position 47.4979;19.0402,
  itemprop, og:*, robots index,follow,max-image-preview:large,
  ld+json graph [WebSite, Organization{n4, logo, email}],
  preconnect jsdelivr/cdnjs
```

`/tvmusorujsag` adds editorial cards: `img 800_kepek + img logok/mini + time + h2 title + age-circle + (year.) desc`.

`/tvmusor/{SLUG}` adds per-program `table` param for AJAX, channel OG image `/logok/csatikon/*.gif`.

`/blog/{slug}`: `title | TV Mustra blog`, `canonical /blog/{slug}`, `og:type article`, `og:image /blog_pic/*`.

## 4. Assets inventory

```
/css/style.css?{ts}   :root --brand-purple:#592DBA etc. EPG grid, cards, footer
/js/app.js            33 funcs: see §5
/img/tv_mustra_s.png
/logok/mini/*.gif     channel logos small (60x26 / 50x35)
/logok/csatikon/*.gif channel OG logos
/800_kepek/{hash}/{file}.jpg  program stills (200x95 desktop, 65x45 mobile)
/blog_pic/*_o.jpg      250x140
CDN: bootstrap 5.3 css+js, font-awesome 6.4, flatpickr css+js + hu.js
```

## 5. Current JS behavior (MUST become server-side in rebuild)

`app.js` functions:

- `initCookieConsent/acceptCookies/closeCookieBanner/getCookie/setCookie`
- `initFacebookSdk, initPushNotifications/showPushBanner/triggerNativePush/urlBase64ToUint8Array` (+ `/sw.js` push/click)
- `jumpToChannel(id), stepTime(offset)` -> `location=/grid/{cat}/{date-hour}`
- `toggleFav/moveChannel/refreshFavArrows/updateFavCookie` – fav reorder via DOM + cookie
- `submitVote(val,progId,tableName,container)` -> `fetch /api/save_vote.php`
- `initStarRating`
- `loadDetails/loadDetailsMobile/loadDetailsVertical/loadDetailsFeatured(el,table,programId,timeRange)` -> `fetch /index.php?ajax_action=get_details&table=&id=` render `{kor,tartalom,long,short,szavazat_atlag}`
- `changeSlide/showBlogSlide/nextBlogSlide/prevBlogSlide, showBlogArchive/hideBlogArchive`
- `openLightbox/closeLightbox/openLightboxFromMobile/scrollDesktopGallery/stepMobileGallery`
- `updateTimeMarker` via `serverClientDiff = TVM_CONFIG.serverTimeTs*1000-Date.now()`
- `expandAllMobile, closeDetails`

All of the above must be either dropped or re-implemented as plain links/forms/PHP in the no-JS version – see §7.

## 6. Inferred data model (no DB dump available)

```
channels: id INT, slug VARCHAR (e.g. RTL), name (RTL), logo_mini, logo_og
programs: id INT, channel_id FK, table VARCHAR (day-sharded? passed as ?table=),
          start DATETIME, end DATETIME, title, desc_short/long/tartalom TEXT,
          image_800, kor TINYINT NULL (6/12/15/16/18), szavazat_atlag FLOAT, szavazat_count INT
blog_posts: slug PK, title, short, body HTML, cover blog_pic, published_at, archive YYYY/MM
votes: program_id + table + ip/date + value
push_subscriptions: endpoint JSON blob
```

AJAX detail response fields observed: `kor, tartalom/long/short, szavazat_atlag`.

## 7. REBUILD CONSTRAINTS – pure PHP/HTML/CSS for old browsers (normative for next task)

Why relevant: it already implements the bookmarkable `channel[]+date` model
proposed in §2.3, but as a JS SPA. Our rebuild copies its URL/API semantics
with server-side rendering (no JS).

- Page: `GET https://port.hu/tv` – thin SSR shell (`nginx`, Yii2-style
  `csrf-param/_csrf`, GTM, AdOcean, InMobi CMP, Hotjar). Real EPG is NOT in HTML:
  `<section id="tvLister"></section>` + `main-20231222.js` fetches JSON.
- API: `GET /tvapi?channel_id[]=tvchannel-5&...&date=YYYY-MM-DD -> application/json`
  Example: `?channel_id[]=tvchannel-5&...[6 ids]...&date=2026-09-18` = 223KB for 6 channels.
  Response: `{date, date_from: 00:00+02:00, date_to: next-day 04:00+02:00,
  eveningStartTime:{hour:19,min:50}, channels:[...]}`.
- Channel: `{id: tvchannel-5, name, domain, url, logo, stream_url, article, banners, programs[]}`.
  ID is opaque `tvchannel-N` string, not numeric – same role as our SLUG.
- Program: `{id: event-tv-...+, start_datetime, start_time, start_ts, end_datetime, end_time,
  title, episode_title, short_description (I / 5. rész), description, film_id: movie-N,
  film_url: /adatlap/.../event-.../episode-..., restriction:{age_limit, ageLimitImage, ageLimitName, category},
  type: past|afternoon|evening, flags: is_repeat/is_live_mp/is_overlapping/is_child_event/highlight/has_video/...}`.
- `type` is server-classified relative to now (past vs afternoon vs evening from 19:50),
  exactly the `past|live|future` split our §2.3 re-implements in PHP with `time()`.
  Overnight window to 04:00 next day covers past+future in one call – adopt same
  (`date_from 00:00 -> date_to +1day 04:00`) instead of strict midnight cut.
- Takeaway for rebuild: keep port.hu's query contract (`ch[]/channel_id[] + date`,
  CSV canonical form for bookmarks) and its day-window + evening-marker semantics,
  but render rows as `<table>` in PHP with `#now` anchor instead of `tvLister.js`.

1. **No JavaScript at all**: no `<script>`, no `on*=` handlers, no `fetch`, no service worker,
   no Matomo/gtag/AdSense/CFusion/Facebook SDK. Remove `/js/app.js`, `/sw.js`, CDN JS.
2. **No browser-side dynamic content**: everything rendered by PHP on the server.
   If data is needed, full page reload via `<a href>` or `<form method=get|post>`.
3. **Old-browser safe HTML/CSS only**:
   - Use HTML 4.01 Transitional or simple HTML5 without new input types; avoid
     `<select onchange>`, `<button>` JS nav, sticky/flex/grid if targeting IE6-8.
     Prefer `<table>` for EPG grid, floats/clearfix, CSS 2.1 properties.
   - No `media=print onload`, no `fetchpriority`, `decoding`, flex `gap`.
     Provide width/height on all `<img>`, `alt` text.
   - No Bootstrap 5 / Font-Awesome webfonts / Flatpickr. Replace with single
     hand-written `style.css` (~CSS2.1). Icons via text (`< >`, `*`) or GIFs.
   - Charset `UTF-8`, no external preconnect.
4. **Navigation replacements**:
   - Channel `<select onchange>` -> channel index `<ul>` with `/tvmusor/{SLUG}` links
     (preferred) + optional `<form method=get>` with checkboxes for multi-select – see §2.2.
     Never use `onchange`.
   - **4a. Channel filter implementation (PHP-only)**:
     `config/channels.php`: `return [5=>['slug'=>'RTL','name'=>'RTL','logo'=>'rtl.gif'], ...];`
     Build reverse map `slug->id` once. On `home.php/grid.php`:
     `$sel = array_intersect((array)($_GET['ch']??explode(',',$_COOKIE['tvm_favs']??'')), $allowedSlugs);`
     Validate, filter SQL with `WHERE channel_slug IN (...)`, render
     `<input type=checkbox name=ch[] value={SLUG} checked>` so selection survives reload.
     Fav links: `<a href="/?fav_add=5&redir=/">+ RTL</a>` /
     `<a href="/?fav_del=5&redir=/">x</a>` -> PHP `setcookie()` + redirect. No JS.
   - `stepTime(±1h)` -> `<a href=/grid/{cat}/{YYYY-MM-DD-HH}>Előző óra / Következő óra</a>`
     computed in PHP with `strtotime`.
   - Date picker (Flatpickr) -> `<form>` with 3x `<select>` or `<input type=text YYYY-MM-DD>`
     + server-side validation, or day links `/tvmusor/{SLUG}/{date}`.
   - Category filter `.cat-btn` stays as plain anchors (already is).
   - Blog slider (6 slides + prev/next JS) -> static list: first post full +
     `<ol>` of 6 titles with thumbnails, each linking to `/blog/{slug}`.
     No `display:none` slides.
   - Program details (AJAX `get_details`) -> each program title is
     `<a href=/musor/{table}/{id}>` (new detail page) OR server-expanded
     `?detail={id}#ch-{id}` anchor. No `fetch`.
   - Favorites (cookie + DOM reorder) -> drop OR server-side via
     `?fav_add={id}&fav_del={id}&fav_move=up|down` links + `Set-Cookie: tvm_favs=...`
     rendered by PHP. No star JS toggle.
   - Voting stars -> `<form method=post action=/api/save_vote.php>` with radio 1-5 + submit.
     Return to referrer via `Location:` header.
   - Cookie consent banner -> static footer text + link to `/adatvedelem`. No banner JS.
   - Push notifications -> drop entirely.
   - Time marker line (`updateTimeMarker`) -> render `Most: HH:MM` text server-side from `time()`.
5. **Proposed new file layout** (to be created in next task):
   ```
   /index.php            # router (parse REQUEST_URI, no rewrite dependency if possible)
   /config.php           # DB DSN, base URL, channel/category lists
   /lib/epg.php          # data access + date/hour helpers + escaping
   /templates/header.php # doctype + top nav + hero (no JS)
   /templates/footer.php # footer + partners (no JS)
   /pages/home.php       # hero + featured 4 + category links + channel index + EPG tables
   /pages/musorujsag.php
   /pages/csatorna.php   # /tvmusor/{SLUG}[/{date}]
   /pages/grid.php       # /grid/{cat}[/{date-hour}]
   /pages/blog.php + blog_reszlet.php + archive.php
   /pages/musor_reszlet.php  # replaces AJAX detail
   /pages/legal.php      # adatvedelem/impresszum
   /sitemapxml.php       # keep, ensure valid UTF-8 XML
   /style.css            # single CSS2.1 file, no CDN
   /.htaccess            # optional clean-URL rewrite to index.php (keep query fallback ?p=)
   ```
6. **SEO parity**: keep `<title>`, `meta description`, `link canonical`,
   `og:*`, `geo.*`, `ld+json WebSite/Organization`. Keep sitemap priorities.
7. **Accessibility/perf**: single CSS, no external requests, server time rendering,
   paginated channel list if 179 logos too heavy (e.g. `?page=` or anchor index A-Z).

## 8. Verification checklist for rebuild

- [ ] `curl` pages contain no `script`, `onclick`, `onchange`, `onload`, `fetch(`.
- [ ] Works with JS disabled / Lynx / IE8-class CSS (tables, no flex).
- [ ] All current routes return 200 with equivalent content (spot-check `/`, `/tvmusor/RTL`, `/grid/film/`, `/blog/{slug}`).
- [ ] Details reachable without AJAX (`/musor/{table}/{id}` + `?detail=`), voting via POST form, favs via links or removed.
- [ ] Bookmarkable state: `/grid/{cat}/{date}-{h}?ch=CSV` reload restores channels/date/hour/category;
  `#now`/`#h{h}` anchor jumps to live/hour with past+future still rendered.
- [ ] Single local `style.css`, no CDN CSS/JS/fonts.
- [ ] Canonical + sitemap unchanged.

## 9. Open questions for next task

- DB source for EPG? ✅ Candidate: open-epg Hungary1 XMLTV (§11). Decide: import to MySQL/SQLite vs flat cache.
- Keep 179 channels on one page or paginate for old browsers?
- Keep voting/favorites at all, or drop for simplicity?
- Target floor: IE6, IE8, or e.g. 2010 mobile? Decides table vs. minimal CSS.

## 10. Reference: port.hu/tv + /tvapi (checked 2026-09-18)

Why relevant: it already implements the bookmarkable `channel[]+date` model
proposed in §2.3, but as a JS SPA. Our rebuild copies its URL/API semantics
with server-side rendering (no JS).

- Page: `GET https://port.hu/tv` – thin SSR shell (`nginx`, Yii2-style
  `csrf-param/_csrf`, GTM, AdOcean, InMobi CMP, Hotjar). Real EPG is NOT in HTML:
  `<section id="tvLister"></section>` + `main-20231222.js` fetches JSON.
- API: `GET /tvapi?channel_id[]=tvchannel-5&...&date=YYYY-MM-DD -> application/json`
  Example: `?channel_id[]=tvchannel-5&...[6 ids]...&date=2026-09-18` = 223KB for 6 channels.
  Response: `{date, date_from: 00:00+02:00, date_to: next-day 04:00+02:00,
  eveningStartTime:{hour:19,min:50}, channels:[...]}`.
- Channel: `{id: tvchannel-5, name, domain, url, logo, stream_url, article, banners, programs[]}`.
  ID is opaque `tvchannel-N` string, not numeric – same role as our SLUG.
- Program: `{id: event-tv-...+, start_datetime, start_time, start_ts, end_datetime, end_time,
  title, episode_title, short_description (I / 5. rész), description, film_id: movie-N,
  film_url: /adatlap/.../event-.../episode-..., restriction:{age_limit, ageLimitImage, ageLimitName, category},
  type: past|afternoon|evening, flags: is_repeat/is_live_mp/is_overlapping/is_child_event/highlight/has_video/...}`.
- `type` is server-classified relative to now (past vs afternoon vs evening from 19:50),
  exactly the `past|live|future` split our §2.3 re-implements in PHP with `time()`.
  Overnight window to 04:00 next day covers past+future in one call – adopt same
  (`date_from 00:00 -> date_to +1day 04:00`) instead of strict midnight cut.
- Takeaway for rebuild: keep port.hu's query contract (`ch[]/channel_id[] + date`,
  CSV canonical form for bookmarks) and its day-window + evening-marker semantics,
  but render rows as `<table>` in PHP with `#now` anchor instead of `tvLister.js`.

## 11. Data source: open-epg Hungary1 XMLTV (checked 2026-09-18)

- URLs (same content): `https://www.open-epg.com/files/hungary1.xml` (4.15MB),
  `https://www.open-epg.com/files/hungary1.xml.gz` (636KB gzip).
- Counts: 179 `<channel>` (matches tvmustra's ~179), 13716 `<programme>`.
  Date spread (UTC `start=`): 20260916:441 (tail), 20260917:5763 (full),
  20260918:5743 (full), 20260919:1769 (head). Core coverage = 09-17 + 09-18.
- Format: XMLTV `<tv generator-info-name="Processed by Open-EPG Visual Processor v1.0">`.
  Times UTC (`+0000`) – PHP must `+2h` for HU/CEST to match tvmustra/port.hu (`+02:00`).
- Channel IDs differ from tvmustra SLUGs, explicit map required in `config/channels.php`:
  `RTL (HD).hu->RTL`, `TV2 (HD).hu->TV2`, `Cool (HD).hu->COOL`, `Viasat 3 (HD).hu->VIASAT3`,
  `RTL KETTO (HD).hu->RTL_KETTO`, `Duna TV (HD).hu->DUNA`, `M1 (HD).hu->M1HD`, etc.
  XMLTV has no logos – reuse `/logok/mini/*.gif` via that map.
- Programme: `<programme start stop channel><title><desc><episode-num system="xmltv_ns">`
  e.g. RTL: `Castle / Halalos tanc... / 3.17.0`. Only title+desc+episode; NO icon,
  NO category, NO rating/kor, NO film_url/image (vs port.hu `restriction/film_url`
  and tvmustra `kor/800_kepek/szavazat`). Rebuild either accepts minimal rows or
  enriches later; `past|live|future` + `#now` computed via `start/stop vs time()` as in 2.3.
- Import (old-PHP safe): cron `copy(.gz)` -> `gzopen/XMLReader` streaming parse
  (not simplexml_load_file – 4MB+), upsert to `channels(slug,xmltv_id,name,logo)` +
  `programs(channel, start_utc, stop_utc, title, descr, episode)`; index `(channel,start)`.
  Adopt port.hu window: serve `00:00 -> +1day 04:00` local per `?date=`.

## 12. Data source (preferred): epgshare epg_ripper_HU1 (checked 2026-09-18)

- Index: `https://epgshare01.online/epgshare01/` – files dated 17-Sep-2026 23:36/23:39:
  `epg_ripper_HU1.pdf` (50647 B, docs), `epg_ripper_HU1.txt` (2645 B, channel list + build stamp
  `202609172336`), `epg_ripper_HU1.xml.gz` (2090492 B gz / 17582228 B xml).
- Counts: 193 `<channel>`, 22741 `<programme>` – bigger than open-epg Hungary1
  (179 ch / 13716 prog / 4.1MB). Date spread (local `+0200` already):
  0917:4944, 0918:6096, 0919:5927, 0920:5763, 0921:11. Core = 4 full days 09-17..09-20.
- Provenance: ripped FROM tvmustra.hu – `<channel><icon src="https://www.tvmustra.hu/logok/csatikon/*.gif">`,
  `<url>http://www.tvmustra.hu</url>`, `<icon src="https://www.tvmustra.hu/800_kepek/{hash}/...jpg">`
  per programme. Channel IDs are dotted tvmustra slugs: `RTL.hu->RTL`, `RTL.KETTŐ.hu->RTL_KETTO`,
  `TV2.hu->TV2`, `Cool.TV.hu->COOL`, `Viasat.3.hu->VIASAT3`, `Duna.TV.hu->DUNA`, `m1.HD.hu->M1HD`.
  Map = strip `.hu`, dots->lookup (dots ambiguous: `RTL.KETTŐ` vs `RTL_KETTO` – keep explicit map table).
  TXT channel list (193 lines, `Name.With.Dots.hu`) is the2014 allow-list source.
- Richness vs Hungary1: `title lang=hu + sub-title + desc lang=hu + date(YYYY) + icon(tvmustra) +
  episode-num system=onscreen (S1 E70 / S2026 E178 / E178)` + 1881 `<category lang=hu>`.
  No rating/kor – same gap as Hungary1, compute `past|live|future` via `start/stop vs time()`.
- Rebuild decision: prefer this file as primary importer (4-day window, local time, images,
  tvmustra-native IDs). Hungary1 stays as fallback. Importer: nightly cron `copy(.gz)` ->
  `XMLReader` streaming (17MB – never `simplexml`), tables
  `channels(slug, ripper_id, name, logo_csatikon)` + `programs(channel,start_local,stop_local,title,subtitle,descr,year,icon,episode,category)`.
  Serve `00:00->+1day 04:00` per `?date=` per §2.3; `#now` anchor from local times (no TZ conversion).
