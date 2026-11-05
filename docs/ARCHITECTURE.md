# LiteSpeed: architecture (as built)

As-built description of `likeavirgin/craft-litespeed` (LaV's fork of `glue-agency/craft-litespeed`), branch `develop` at `138a8f9` (2026-11-05; tag `v1.0.0`). Entry point for agents: [`../AGENTS.md`](../AGENTS.md). Fork maintenance, releases and the Craft 6 strategy: [`../CLAUDE.md`](../CLAUDE.md). ESI design notes: [`../roadmap/esi.md`](../roadmap/esi.md). User documentation: `README.md`.

`AGENTS.md`, `CLAUDE.md` and `docs/` are LaV additions; upstream has none of them, so an upstream merge never conflicts on them.

Paths are relative to the repo root. Anything not checked against code or a live response is marked **unverified**. Line numbers left in the text are hints from 2026-11-05 and drift: re-read the code before relying on one. When this file and the code disagree, the code wins and this file gets fixed in the same change. "LaV internal" marks things that only exist inside Like a Virgin (the reference site, hosting); context, not requirements.

---

## 1. Summary

LiteSpeed Cache (LSCache) integration for Craft 5. It lets LiteSpeed serve full Craft pages from memory before PHP boots (LaV reference site: about 30 ms instead of about 600 ms, 2026-10-15), tags every cached page with Craft's element cache tags, and purges exactly the affected pages when content changes, from web, queue and console. ESI blocks (`craft.litespeed.esi()`) let per-visitor fragments live inside a cached page. On non-LiteSpeed servers the headers are ignored (safe no-op, but see the tracker gotcha in section 8).

| Item | Value | Source |
|---|---|---|
| Composer name | `likeavirgin/craft-litespeed` (only divergence from upstream) | `composer.json`, `CLAUDE.md` |
| Handle | `litespeed` | `composer.json` `extra.handle` |
| Class | `GlueAgency\LiteSpeed\LiteSpeed` | `src/LiteSpeed.php` |
| Namespace | `GlueAgency\LiteSpeed\` (PSR-4 `src/`), unchanged from upstream | `composer.json` |
| Developer (metadata) | Glue Agency; `support`/`docs`/`changelogUrl` still point at glue-agency | `composer.json` |
| Min Craft / PHP | `craftcms/cms ^5.2.0`, `php ^8.2` | `composer.json` |
| Version | git tag `v1.0.0` = commit `6fe46b6` (the rename commit). No `version` key in composer.json | `git tag`, `git rev-list -n1 v1.0.0` |
| CHANGELOG | says `5.0.0 - Initial release` (mismatch with tag, see section 9) | `CHANGELOG.md` |
| schemaVersion | `1.1.0` | `src/LiteSpeed.php` |
| Repo | https://github.com/Like-a-virgin/craft-litespeed (origin), upstream https://github.com/glue-agency/craft-litespeed (`develop`, no tags) | `git remote -v` |
| Fork state | `develop` was 3 commits ahead of `upstream/develop`, 0 behind (local tracking refs, 2026-11-05) | `git rev-list --count` |
| License | MIT | `LICENSE.md` |
| Tests | 59 PHPUnit tests in `tests/` (Tags 21, Esi 11, Pages 8, Settings 8, EditableTable 6, Translations 3, Urls 1, CacheStatus 1). Not run on 2026-11-05 (no `vendor/` in the clone) | `tests/*.php`, `phpunit.xml` |

**Users (LaV internal, verified 2026-11-05):** one production site (Lasertopo), pinned to `v1.0.0`, live response `x-litespeed-cache: hit`. Hosting precondition: a LiteSpeed web server (at LaV: Combell **Boost**).

---

## 2. Architecture

### 2.1 Components (registered in `LiteSpeed::config()`, `src/LiteSpeed.php`)

| Component | Class | Role |
|---|---|---|
| `cache` | `src/services/CacheService.php` | Decides cacheability per response; sends `X-LiteSpeed-Cache-Control`, `X-LiteSpeed-Tag`, `X-LiteSpeed-Vary`, `esi=on`; holds per-request `noCache`/`ttl`/custom tags/vary cookies; reconciles the logged-in vary cookie |
| `purge` | `src/services/PurgeService.php` | Collects purges during a request, emits `X-LiteSpeed-Purge` on the web response, relays from console/queue via signed loopback POST, stores failed relays in Craft cache for the next web response, chunks oversized purges into a `PurgeJob` |
| `tracker` | `src/services/TrackerService.php` | Records every page handed to LiteSpeed (`litespeed_pages` + `litespeed_pagetags`), forgets on purge, GC deletes expired, stats/search for the utility |
| `check` | `src/services/CheckService.php` | Live HEAD request to a site URL, classifies `x-litespeed-cache` into `CacheStatus` |
| `esi` | `src/services/EsiService.php` | `craft.litespeed.esi()` blocks: include tag vs inline render, signed payload, block rendering, `lsc_private` identity cookie at login/logout |

Pure helpers (framework-light, port-friendly): `src/helpers/Tags.php` (tag mapping + header building), `src/helpers/Pages.php` (variant/key/max-age parsing), `src/helpers/Esi.php` (dehydrate/hydrate vars, include tag), `src/helpers/Urls.php` (absolute URL, default origin), `src/helpers/EditableTable.php` (CP table normalisation). Enums in `src/enums/` (`Header`, `CacheStatus`, `EsiCache`, `Permission`, `PrivateCookie` = `lsc_private`, `ServerVariable` = `X-LSCACHE`, `VaryCookie` = `_lscache_vary`).

### 2.2 Controllers and routes

| Route | Controller / action | Access | Notes |
|---|---|---|---|
| CP `litespeed` | template `src/templates/index.twig` posts to `litespeed/purge/url` | CP, `accessPlugin-litespeed` | Purge URLs, optional sub-pages |
| CP `litespeed/tags` | template `src/templates/tags.twig` posts to `litespeed/purge/tags` | `{% requirePermission 'litespeed:purgeTags' %}` | Purge custom tags |
| CP `litespeed/settings` | `SettingsController::actionEdit` (rule in `src/LiteSpeed.php`) | admin (`requireAdmin(false)`), read-only when `allowAdminChanges` is off | `asCpScreen()` with tabs General/Caching/Vary/Purging |
| `actions/litespeed/settings/save` | `SettingsController::actionSave` | admin, POST | `Plugins::savePluginSettings()` (project config) |
| `actions/litespeed/purge/url`, `/tags` | `src/controllers/PurgeController.php` | `requireCpRequest`, `accessPlugin-litespeed`; tags also `litespeed:purgeTags` | Refuses when plugin disabled |
| `actions/litespeed/relay` | `src/controllers/RelayController.php` | anonymous (live + offline), **CSRF off**, POST | Accepts `Security::hashData` payload younger than 300 s, answers 204 |
| `actions/litespeed/esi/render` | `src/controllers/EsiController.php` | anonymous (live + offline), GET only | Renders a signed block payload; 400 on bad payload, empty 500 + `no-cache` on render error |
| Console `litespeed/purge/all [--everything]`, `urls <url>... [--subpages]`, `tags <tag>...` | `src/console/controllers/PurgeController.php` | CLI | Returns non-zero when the relay fails (purge then waits for next web response) |

Controller namespace switches between web and console in `registerControllers()` (`src/LiteSpeed.php`).

### 2.3 Data model

| Table | Columns | Indexes / FKs | Source |
|---|---|---|---|
| `{{%litespeed_pages}}` | `id`, `siteId`, `url` (TEXT), `variant` (string, default `''`), `key` (CHAR 32 = `md5(url\nvariant)`), `expiryDate`, `dateCreated`, `dateUpdated`, `uid` | unique `key`; index `expiryDate`; FK `siteId` -> `sites.id` CASCADE | `src/migrations/Install.php` |
| `{{%litespeed_pagetags}}` | `pageId`, `tag`, PK (`pageId`,`tag`) | index `tag`; FK `pageId` -> pages CASCADE | `src/migrations/Install.php` |

Migrations: `Install.php` and `m261002_100000_create_page_tables.php` are identical and both guard with `tableExists` (upgrade path for installs older than the tracker). No records/ActiveRecord classes; raw `craft\db\Query` + `Db::*` helpers. Table names in `src/db/Table.php`.

**Project config vs DB:** settings live in project config (`plugins.litespeed.settings`) via `savePluginSettings` (`SettingsController.php`); `config/litespeed.php` overrides (overridden fields render disabled, `SettingsController.php`). The tracker tables are runtime DB data only. Failed relays live in the Craft data cache under `litespeed:pending-purges` (`PurgeService.php`, `store()`/`pullStored()` at 320-367, guarded by `Craft::$app->getMutex()`).

### 2.4 Settings (`src/models/Settings.php`)

| Key | Default | CP? | Notes |
|---|---|---|---|
| `enabled` | `true` | yes, env var allowed | `isEnabled()` via `App::parseBooleanEnv` (176-179). Off = no purge triggers, no response handlers, no Clear Caches option (`LiteSpeed.php`) |
| `prefix` | `substr(md5(systemUid),0,6)` | yes, env var allowed | Letters/digits only, not `public`/`private` (171-174); invalid falls back to default with an error log (186-201). Changing it orphans every cached page until TTL |
| `ttl` | `86400` | yes | seconds |
| `statusTtls` | `[404 => 3600]` | yes | Any other non-200 status is never cached |
| `excludeUris` | `[]` | yes | **Unanchored, case-insensitive regex on the path without leading slash and without query string** (`excludePattern()` 161-164, `CacheService::isExcluded()` 351-360). Invalid regex from the config file is silently ignored (`@preg_match`) |
| `varyCookies` | `[]` | yes | Sends `X-LiteSpeed-Vary: cookie=a,cookie=b`; each needs an `.htaccess` `Cache-Vary` rule |
| `varyLoggedIn` | `false` | yes | Sets/removes `_lscache_vary=loggedin` cookie (`CacheService::reconcileVaryCookie()` 365-397) |
| `loggedInCookie` | `_lscache_vary` | yes | Other names need a server rule |
| `purgeStale` | `false` | yes | Adds `stale` to purge header |
| `purgeOnGc` | `true` | yes | GC = whole-site purge (`php craft gc`, deploys, 1 in 100 000 requests via `gcProbability`) |
| `loopbackUrls` | first site base URL per host | config only | Relay targets; must answer the POST with 204 itself (no redirects) |
| `loopbackOptions` | `[]` | config only | Guzzle options merged over `timeout 10`, `allow_redirects false` (e.g. basic auth on staging) |
| `maxHeaderLength` | `8000` | config only | Bytes for tag and purge headers |
| `esi` | `false` | config only, env var allowed | Turns ESI includes on where `$_SERVER['X-LSCACHE']` reports `esi` |

### 2.5 CP, permissions, utility, cache options

- CP section with subnav Purge (+ Settings for admins only) (`LiteSpeed.php`). `hasCpSettings` + `hasReadOnlyCpSettings`; both settings responses redirect to `litespeed/settings` (`LiteSpeed.php`).
- Permissions: Craft's implicit `accessPlugin-litespeed` plus registered `litespeed:purgeTags` (`LiteSpeed.php`, `src/enums/Permission.php`; renaming the backed value silently revokes it).
- Utility `Utilities -> LiteSpeed` (`src/utilities/CacheUtility.php`, id `litespeed`): tracker stats per site, search (limit 50), live check when the query is a path or full URL.
- Clear Caches option `litespeed` -> `purge->purgeAll()` (`LiteSpeed.php`), so `php craft clear-caches/all` purges LiteSpeed too.
- Own Monolog target `storage/logs/litespeed-*.log`, category `litespeed`, level INFO (`LiteSpeed.php`).

### 2.6 Twig API (`src/web/twig/LiteSpeedVariable.php`, bound in `init()` not `onInit()`, `LiteSpeed.php`)

`craft.litespeed.noCache()`, `.ttl(int)`, `.tag(string|array)`, `.varyCookie(name)`, `.esi(template, vars, {cache: private|public|no-cache, ttl, fallback, pageUrl})`. Without the plugin `craft.litespeed` is undefined (error in devMode, silently empty otherwise; `roadmap/esi.md` "Uninstalling").

### 2.7 Events listened (all in `src/LiteSpeed.php`)

| Class + constant | Line | Condition | Handler |
|---|---|---|---|
| `craft\web\twig\variables\CraftVariable::EVENT_INIT` | 201 | always | register `craft.litespeed` |
| `craft\web\UrlManager::EVENT_REGISTER_CP_URL_RULES` | 184 | onInit | settings route |
| `craft\web\View::EVENT_REGISTER_CP_TEMPLATE_ROOTS` | 191 | onInit | `litespeed` root |
| `craft\services\UserPermissions::EVENT_REGISTER_PERMISSIONS` | 208 | onInit | `litespeed:purgeTags` |
| `craft\services\Utilities::EVENT_REGISTER_UTILITIES` | 220 | onInit | `CacheUtility` |
| `craft\services\Gc::EVENT_RUN` | 230 | onInit, even when disabled | `tracker->deleteExpired()` |
| `craft\utilities\ClearCaches::EVENT_REGISTER_CACHE_OPTIONS` | 235 | enabled | purgeAll option |
| `craft\services\Elements::EVENT_INVALIDATE_CACHES` | 247 | enabled | `purge->handleInvalidation()` (**the purge trigger**) |
| `craft\services\Gc::EVENT_RUN` | 251 | enabled | `purge->handleGcRun()` (arms "ignore next full invalidation" when `purgeOnGc` is off) |
| `yii\queue\Queue::EVENT_AFTER_EXEC` / `EVENT_AFTER_ERROR` | 253-254 | enabled | `purge->relay()` |
| `yii\base\Application::EVENT_AFTER_REQUEST` (instance `Craft::$app->on`) | 257 | enabled, console | `purge->relay()` |
| `yii\base\Application::EVENT_BEFORE_ACTION` (instance) | 285 | enabled, web | `cache->startCollecting()` |
| `craft\web\Response::EVENT_AFTER_PREPARE` | 287 | enabled, web, only the app response | `purge->prepareResponse()` then `cache->prepareResponse()` |
| `craft\web\Response::EVENT_AFTER_SEND` | 296 | enabled, web | `tracker->record()`, `purge->relay()` |
| `yii\web\User::EVENT_AFTER_LOGIN` / `EVENT_AFTER_LOGOUT` | 267-268 | enabled + esi, web | `lsc_private` cookie renew, private purge on logout |
| `craft\services\Elements::EVENT_AFTER_SAVE_ELEMENT` | 270 | enabled + esi, web | own user saved -> `purgePrivate()` |

**Events fired:** none. `grep trigger(` over `src/` finds nothing: other plugins cannot hook into tagging/purging by event; they call the services (section 4).

### 2.8 Purge triggers (what the `EVENT_INVALIDATE_CACHES` funnel covers)

Craft fires `EVENT_INVALIDATE_CACHES` from `Elements::invalidateAllCaches()` (tags `['element']`), `invalidateCachesForElementType()` (`element::<Class>`), and `invalidateCachesForElement()` (`element::<Class>::*`, `element::<id>`) (verified in Craft 5 core `src/services/Elements.php`). Craft calls these on element save/delete/restore/move/propagate and on section/entry type/volume/field/site/global changes (README "Purging" table). `PurgeService::handleInvalidation()` (`PurgeService.php`):

1. Skips drafts/revisions (`ElementHelper::isDraftOrRevision`).
2. Skips the closing `['element']` invalidation of a GC run when `purgeOnGc` is off.
3. Maps Craft tags to LiteSpeed tags (`Tags::fromCraftTags`) and adds the element's own URL tag.

Not covered (no element invalidation happens): scheduled post/expiry dates passing (needs `craft update-statuses` cron, Craft 5.7+), template deploys (needs `litespeed/purge/all` after `deploy:publish`), non-element plugin data (see section 5), raw DB imports.

### 2.9 Tag strategy (`src/helpers/Tags.php`)

| Kind | Token | Example |
|---|---|---|
| Install (whole site) | `<prefix>` | `a1b2c3` |
| Element id | `<prefix>_id.<id>` | from `element::123` |
| Element type | `<prefix>_<alias>` | `E`, `A`, `C`, `U`, `G`, `T`, `Ad`, `CB`; unknown class -> `x<md5 6>` (`ALIASES` 24-33) |
| Type + qualifier | `<prefix>_<alias>.<qualifier>` | `element::craft\elements\Entry::section:5` -> `_E.section.5`; `::*` -> `.all` |
| Non-element Craft tag | `<prefix>_h.<md5 8>` | any `collectCacheTags()` tag not starting with `element::` |
| Custom | `<prefix>_c.<sanitized>` | `craft.litespeed.tag('news')` |
| URL | `<prefix>_url.<md5(host/path) 12>` | page's own URL |
| Tree | `<prefix>_tree.<hash>` for the path and every parent | enables "purge URL + sub-pages" |

Every cacheable page carries install + URL + all tree tags + Craft tags + custom tags. Over `maxHeaderLength`, per-element `element::<id>` tags collapse to type wildcards; if still too long the page is not cached (`cacheHeader()` 124-153). Purges over the limit are chunked (`purgeChunks()` 163-197): first chunk on the response, the rest via `PurgeJob`.

### 2.10 ESI (`src/services/EsiService.php`, `roadmap/esi.md`)

Guest + `esi` on + `X-LSCACHE` contains `esi` -> `<esi:include src="/actions/litespeed/esi/render?payload=..." cache-control="private|public"/>` and `esi=on` added to the page's cache control (`CacheService::sendEsi()` 173-187). Payload = `Security::hashData(JSON)` base64url (signed, **not encrypted**; vars visible in HTML). Elsewhere the template renders inline; a non-public block without `fallback` then calls `noCache()` on the page (`Esi::forcesNoCache()`). Logged-in visitors always get inline render. Status: **unverified on the LaV hosting** (open questions 1-8 in `roadmap/esi.md`); the reference site does not use `craft.litespeed.esi()`.

### 2.11 .htaccess handling

The plugin writes nothing to `.htaccess`. Required block, documented in `README.md` and present on the reference site:

```apache
<IfModule LiteSpeed>
    CacheLookup on
    RewriteEngine On
    RewriteRule .* - [E=Cache-Control:no-autoflush]
    CacheKeyModify -qs:utm*
    CacheKeyModify -qs:gclid
    CacheKeyModify -qs:fbclid
</IfModule>
```

Vary cookies need one combined `RewriteRule .* - [E="Cache-Vary:a,b"]` (a second rule overwrites the first, `README.md`).

### 2.12 Queue jobs, console, caching

- `src/jobs/PurgeJob.php`: carries already-prefixed tags of overflow chunks; `execute()` queues them and relays.
- Console commands: section 2.2.
- Craft data cache: only the pending-purge store. Element cache info: `Elements::startCollectingCacheInfo()` at `EVENT_BEFORE_ACTION`, `stopCollectingCacheInfo()` at `EVENT_AFTER_PREPARE` (`CacheService.php`, 253-268). `{% cache %}` blocks replay their tags, so they still purge correctly (README "What gets cached").

---

## 3. Data flow

### 3.1 Request -> headers -> LSCache

1. LiteSpeed receives the request. With `CacheLookup on` it looks up host + URI + query string (minus `utm*`/`gclid`/`fbclid`) + vary cookies. Hit = served, Craft never runs (no Craft events, no counters, no `Set-Cookie`).
2. Miss -> Craft boots. `Application::EVENT_BEFORE_ACTION` -> `CacheService::startCollecting()` only for candidates: site request, GET/HEAD, not action, not live preview, no preview param, no token (`isCandidate()` 240-248).
3. Template renders; element queries register cache tags; templates may call `craft.litespeed.*`.
4. `Response::EVENT_AFTER_PREPARE`:
   - `PurgeService::prepareResponse()` pulls stored purges, writes `X-LiteSpeed-Purge` (first chunk), tells the tracker to forget, pushes overflow to `PurgeJob` (153-181).
   - `CacheService::prepareResponse()` -> `prepareCacheControl()` (137-171): if the template already set `X-LiteSpeed-Cache-Control`, only tags + vary are added. Otherwise `maxAge()` (270-311) returns null (-> `no-cache`) when: `noCache()` was called; visitor not a guest; **session active**; response sets **any cookie** or streams; browser `Cache-Control` contains `no-store` (Craft's `setNoCacheHeaders()`, e.g. `getCsrfToken()`); path excluded; status not 200 and not in `statusTtls`; vary cookie had to change. Else `public,max-age=min(ttl, element expiry duration)`, plus `X-LiteSpeed-Tag` and `X-LiteSpeed-Vary`. Then `sendEsi()`.
   - `remember()` stages the tracker row for GET site responses with a storable max-age (212-238).
5. `Response::EVENT_AFTER_SEND` -> tracker upsert (transaction, `TrackerService::record()` 53-86) and relay of anything still pending.
6. LiteSpeed stores the response if `X-LiteSpeed-Cache-Control` is public, strips nothing the browser needs (`Cache-Control` for the browser is left alone; the `x-litespeed-cache-control` header is visible to clients, verified on the reference site's `sitemap.xml` 2026-11-05).

### 3.2 Content save -> purge

1. CP save (web) -> Craft invalidates element caches -> `EVENT_INVALIDATE_CACHES` -> `handleInvalidation()` queues LiteSpeed tags + element URL tag.
2. The CP response's `EVENT_AFTER_PREPARE` writes `X-LiteSpeed-Purge: public,tag=...` and forgets tracker rows. LiteSpeed applies it **only on the vhost that served that response** (multi-domain caveat, `README.md`).
3. Queue job / console save -> no response of its own -> `relay()` at `Queue::EVENT_AFTER_EXEC|ERROR` or console `EVENT_AFTER_REQUEST`: Guzzle POST `action=litespeed/relay` with signed payload to each loopback URL (one per host, so multi-domain installs are reached), expects 204 (`PurgeService.php`). `RelayController` queues the tags into that web request, whose own response then carries the purge header.
4. Relay failure -> payload stored in Craft cache under mutex; the next web response anywhere picks it up (`pullStored()`).
5. Logout / own-user save with ESI on -> `private,*` on that visitor's response.

---

## 4. Public API for other plugins

There is no event API; call the services. Always soft-depend (the plugin is only on Boost sites):

```php
use GlueAgency\LiteSpeed\LiteSpeed;

$ls = class_exists(LiteSpeed::class) ? LiteSpeed::getInstance() : null;
$ls?->cache->noCache();
```

| Need | Plugin-agnostic way (preferred) | LiteSpeed-specific way |
|---|---|---|
| Purge everything for this install | `Craft::$app->getElements()->invalidateAllCaches()` (fires `EVENT_INVALIDATE_CACHES ['element']` -> install tag; also clears `{% cache %}` and Blitz-style listeners) | `LiteSpeed::getInstance()->purge->purgeAll()`; `purgeEverything()` = whole vhost |
| Purge pages showing an element / type | `invalidateCachesForElement($el)` / `invalidateCachesForElementType(Entry::class)` | `purge->purgeUrls([$url], subpages: true)` |
| Purge plugin-specific pages | (none: `TagDependency::invalidate()` with a custom tag does **not** fire the event, so LiteSpeed never hears it) | tag at render with `cache->addTags('my-plugin-x')` / `craft.litespeed.tag()`, purge with `purge->purgeTags(['my-plugin-x'])` (unprefixed; mapped to `<prefix>_c.my-plugin-x`) |
| Tag a page so element saves reach it even when output comes from a data cache | `Craft::$app->getElements()->collectCacheTags(['element::' . Entry::class . '::*'])` (Craft 5 API, `Elements.php`) | same; the plugin maps it to `_E.all` |
| Mark response uncacheable | `Craft::$app->getResponse()->setNoCacheHeaders()` (browser `no-store` -> plugin sends `no-cache`; also stops browser/BFCache caching) or set any cookie / start a session | `cache->noCache()` / `{% do craft.litespeed.noCache() %}` (only affects LiteSpeed) |
| Shorter TTL | `Elements::setCacheExpiryDate()` while collecting (caps max-age) | `cache->setTtl(int)` |
| Vary per cookie | n/a | `cache->addVaryCookie('name')` + `.htaccess` `Cache-Vary` rule (server must know it before lookup) |
| Per-visitor fragment in a cached page | client-side fetch from an action endpoint | `craft.litespeed.esi()` |

**What it consumes from the platform:** `Elements::EVENT_INVALIDATE_CACHES`, the element cache-info collector (tags + expiry), `Response` headers/cookies/status, `Request` classification, `User` guest state, `Session::getIsActive()`, Craft `securityKey` (relay + ESI signatures; web and console must share it), Craft cache + mutex, `GeneralConfig` (`cacheDuration`, `rememberedUserSessionDuration`, `addTrailingSlashesToUrls`, `allowAdminChanges`), system UID (default prefix).

---

## 5. Integration with our other plugins

This plugin does not reference any other LaV plugin. The others integrate with it softly: preferably through Craft core signals it already listens to (`Elements::invalidateAllCaches()` / `invalidateCachesFor*()`, `Elements::collectCacheTags()`), and only where unavoidable through `isPluginEnabled('litespeed')` + its `cache` component. State of 2026-11-05 (working trees of the `feature/plugin-integrations` / `feature/plugin-safety` branches; not yet observed on a LiteSpeed host):

| Plugin | Cached page safe? | Purges when its data changes? | How |
|---|---|---|---|
| Consent | Banner and head script are identical for every visitor (choice lives in a client cookie). `craft.consent.has()` is per visitor, so it calls our `cache->noCache()` (soft: `isPluginEnabled('litespeed')` + `has('cache')`, no class reference). | Yes, full site: `Banner::clearPageCaches()` calls `invalidateAllCaches()` | Consent `ConsentVariable::_noPageCache()`, `Banner::clearPageCaches()` |
| LaV Forms | Yes: async CSRF by default (`csrfInput({async: true})`), `fromQuery` hidden values filled in the browser (no cross-visitor leak of `utm*`/`gclid` values folded by `CacheKeyModify`) | Yes, full site: form save, translation save, delete and import call `invalidateAllCaches()` | LaV Forms `FormsController`, `Transfer::import()`, `templates/site/form.twig` |
| LaV SEO | Yes | Entry-driven head: element tags. Settings and Paginatypes saves: `invalidateAllCaches()`. `sitemap.xml` and `robots.txt` call `collectCacheTags(['element::craft\elements\Entry::*'])`, so any entry save purges them even when they come from LaV SEO's data cache | LaV SEO `SettingsController`, `EntryTypesController`, `SitemapController` |
| Vizier | Script config is the same for every guest | n/a | With LiteSpeed enabled `Vizier::usesPageCache()` is true: totals are counted by the (consent-gated) beacon instead of server-side, because cached hits run no PHP. AI crawlers on cached pages still only count on misses. |
| Markdownify | `.md`/llms.txt replay the element cache tags collected while building them (also on its data-cache hit). `Accept: text/markdown` on a cached HTML URL is answered with the cached HTML (LSCache ignores `Vary: Accept`); the `.md` URLs are the supported path, and the negotiated response itself is `X-LiteSpeed-Cache-Control: no-cache` | Yes: element tags; its own and LaV SEO's settings saves call `invalidateAllCaches()` | Markdownify `Pages::_remember()`, `Pages::invalidate()` |
| DB-merge | n/a | Push: `scripts/db-fromlocal.sh` runs `clear-caches/all` + `invalidate-tags/all` on the remote after the import (our Clear Caches option purges LiteSpeed). Apply (local) flushes caches. `litespeed_pages` / `litespeed_pagetags` are `never` in its table policies (the cache index of the server it runs on). | DB-merge `scripts/db-fromlocal.sh`, `TableMergeService::DEFAULT_TABLE_POLICIES` |

Remaining gaps:
1. **Unverified on a LiteSpeed host**: all rows above were checked in code (and in DDEV without LiteSpeed), not with `x-litespeed-cache` headers.
2. **Accept bypass**: an `.htaccess` rule `RewriteCond %{HTTP:Accept} text/markdown` + `RewriteRule .* - [E=Cache-Control:no-cache]` is documented in Markdownify's README, untested on the LaV hosting.
3. **Vizier AI-crawler counts** on cached pages need an uncached path (ESI no-cache block or server logs); not built.
4. A plugin that caches data outside element tags must call `collectCacheTags()` or purge via `invalidateAllCaches()`; a bare `TagDependency::invalidate()` never reaches LiteSpeed (section 8).

---

## 6. Craft 6 / Laravel port risk inventory

Strategy from `CLAUDE.md`: run via `craftcms/yii2-adapter` first, adopt upstream's native port when it ships, self-port only as last resort. Craft 5 LTS keeps this working for years. Effort: L = mechanical rename, M = re-express per API, H = redesign / depends on unknown Craft 6 semantics.

| Group | Craft 5 / Yii API surface | Files (examples) | Effort | How to isolate |
|---|---|---|---|---|
| Plugin bootstrap | `craft\base\Plugin`, static `config()` components, `init()` + `Craft::$app->onInit()`, `$hasCpSection`, `$hasCpSettings`, `$hasReadOnlyCpSettings`, `getCpNavItem()`, `getSettingsResponse()`, `createSettingsModel()`, `$schemaVersion`, `Craft::setAlias('@litespeed')`, `controllerNamespace` switch | `src/LiteSpeed.php` | M | Already one file; keep all wiring there |
| Yii class-level events | `yii\base\Event::on(...)` for every hook in 2.7 | `src/LiteSpeed.php` | H | Replace with Craft 6 event/listener registration in one place; services already take plain arguments |
| App lifecycle | `Application::EVENT_BEFORE_ACTION`, `EVENT_AFTER_REQUEST` via `Craft::$app->on()` | `LiteSpeed.php` | H | Laravel: middleware `handle()` (start collecting) + `terminate()` (relay) |
| Response lifecycle | `craft\web\Response::EVENT_AFTER_PREPARE`, `EVENT_AFTER_SEND`, `getHeaders()->set/get/has`, `getCookies()->add/remove/getCount`, `->stream`, `->content`, `getStatusCode()`, `setStatusCode()`, `headers_sent()` | `CacheService.php`, `PurgeService.php`, `EsiService.php`, controllers | H | The single most important seam. Introduce a tiny header-bag/response interface; header-building is already pure in `Tags`/`Pages` |
| Request classification | `getIsSiteRequest`, `getIsActionRequest`, `getActionSegments`, `getIsLivePreview`, `getPreviewParam`, `getHadToken`, `getIsConsoleRequest`, `getMethod`, `getIsGet`, `getRawCookies`, `getAbsoluteUrl`, `getUrl`, `getHostName`, `getHostInfo`, `getQueryParam` | `CacheService.php`, `EsiService.php`, `Urls.php` | M-H | Wrap in one "request context" object; Craft 6 may rename site/action/preview concepts |
| Element cache-info model | `Elements::startCollectingCacheInfo()/stopCollectingCacheInfo()` (returns `[yii\caching\TagDependency, duration]`), `EVENT_INVALIDATE_CACHES` + `InvalidateElementCachesEvent::$tags/$element`, `getElementById()`, the **string format** `element::<Class>::<qualifier>` | `CacheService.php`, `PurgeService.php`, `EsiService.php`, `Tags.php` | H | Load-bearing: tagging and purging only meet because both go through `Tags::fromCraftTag()`. If Craft 6 changes tag strings, only `Tags.php` must change; keep it that way |
| Element classes | `craft\elements\{Entry,Asset,Category,User,GlobalSet,Tag,Address,ContentBlock}`, `ElementInterface`, `ElementHelper::isDraftOrRevision` | `Tags.php`, `Esi.php`, `PurgeService.php` | L | Unknown classes already fall back to a hash alias |
| Auth / session | `getUser()->getIsGuest()/getIdentity()/getId()/checkPermission()`, `getSession()->getIsActive()`, `yii\web\User::EVENT_AFTER_LOGIN/LOGOUT`, `yii\web\UserEvent::$cookieBased` | `CacheService.php`, `EsiService.php`, `LiteSpeed.php` | M | Laravel `Login`/`Logout` events; "session active" semantics differ (Laravel always starts a session in the `web` group: **the #1 cacheability rule may need rethinking**) |
| Cookies | `yii\web\Cookie`, `Craft::cookieConfig()`, Craft's validated-cookie wrapper (`lsc_private` value LiteSpeed sees is Craft's signed wrapper) | `CacheService.php`, `EsiService.php` | M | Cookie names are constants in enums already |
| Controllers | `craft\web\Controller`, `$allowAnonymous` + `ALLOW_ANONYMOUS_LIVE|OFFLINE`, `$enableCsrfValidation`, `requireCpRequest/requirePermission/requireAdmin/requirePostRequest`, `asCpScreen()` fluent builder, `asSuccess/asFailure/asModelFailure/asRaw`, `setFailFlash`, `redirectToPostedUrl`, `yii\web\Response`, `yii\web\BadRequestHttpException` | `src/controllers/*.php` | H (CP screens), M (relay/ESI) | Relay + ESI actions are thin; keep logic in services |
| Public URLs baked into cached HTML | `actions/litespeed/esi/render?payload=` (ESI `src`), `action=litespeed/relay` | `EsiService.php`, `PurgeService.php` | M | Route changes break already-cached pages: **purge everything at cutover** |
| URL rules / templates | `UrlManager::EVENT_REGISTER_CP_URL_RULES`, `View::EVENT_REGISTER_CP_TEMPLATE_ROOTS`, `UrlHelper::actionUrl/cpUrl`, CP Twig (`_layouts/cp`, `_includes/forms` macros, `editableTableField`), `Cp::readOnlyNoticeHtml()` (5.6+ guard) | `LiteSpeed.php`, `src/templates/*` | H | CP UI is expected to be rebuilt in Craft 6; keep templates dumb |
| Twig | `CraftVariable::EVENT_INIT` + `$event->sender->set()`, `Twig\Markup`, `Template::raw`, `View::renderTemplate`, `View::TEMPLATE_MODE_SITE` | `LiteSpeed.php`, `LiteSpeedVariable.php`, `EsiService.php` | M | Variable is a thin proxy to services |
| Console | `craft\console\Controller`, `options($actionID)`, `yii\console\ExitCode`, `craft\helpers\Console::FG_*`, `stdout/stderr` | `src/console/controllers/PurgeController.php` | M | Artisan commands calling the same services |
| Queue | `craft\queue\BaseJob`, `execute($queue)`, `defaultDescription()`, `craft\helpers\Queue::push()`, `yii\queue\Queue::EVENT_AFTER_EXEC/ERROR` | `src/jobs/PurgeJob.php`, `PurgeService.php`, `LiteSpeed.php` | M | Laravel jobs + `Queue::after()/failing()` |
| DB / migrations | `craft\db\Migration` (`createTable`, `primaryKey`, `char`, `uid()`, `addForeignKey(null,...)`, `createIndex(null,...)`, `tableExists`), `craft\db\Query`, `Db::upsert/delete/batchInsert/truncateTable/prepareDateForDb`, `{{%table}}` + `col` quoting, `getDb()->transaction()`, `DateTimeHelper`, `craft\db\Table::SITES` | `src/migrations/*`, `TrackerService.php` | M | Tracker is self-contained; Laravel Schema + query builder |
| Model / validation | `craft\base\Model`, `defineRules()` (Yii validators `required`, `integer`, `boolean`, inline closures), `setAttributes($values, $safeOnly)` override, `addError()` | `src/models/Settings.php` | M | Static validators (`isValidPrefix` etc.) are pure already |
| Config / env | `App::parseEnv/parseBooleanEnv`, `getConfig()->getConfigFromFile('litespeed')`, multi-env config arrays, `savePluginSettings`, project config storage, `GeneralConfig` props, `getSystemUid()`, `Craft::$app->id` | `Settings.php`, `SettingsController.php`, `CacheService.php` | M | Default `prefix` derives from system UID: keep the derivation identical or pin `prefix`, else every existing cache entry becomes unpurgeable |
| Crypto | `Security::hashData()/validateData()` (HMAC with `securityKey`), `StringHelper::base64UrlEncode/Decode`, `Json::encode/decodeIfJson` | `PurgeService.php`, `EsiService.php` | M | Payload compatibility across the cutover is not needed if you purge everything |
| Cache / mutex | `getCache()->get/set/delete`, `getMutex()->acquire/release` | `PurgeService.php` | L | Laravel `Cache::lock()` |
| HTTP client | `Craft::createGuzzleClient()`, Guzzle `Client`, PSR-7 `ResponseInterface` | `PurgeService.php`, `CheckService.php`, `CacheStatus.php` | L | Guzzle stays |
| Logging | `craft\log\MonologTarget` appended to `Craft::getLogger()->dispatcher->targets`, `Craft::info/warning/error(..., 'litespeed')` | `LiteSpeed.php` | M | Laravel log channel `litespeed` |
| CP extension points | `Utilities::EVENT_REGISTER_UTILITIES` + `craft\base\Utility`, `ClearCaches::EVENT_REGISTER_CACHE_OPTIONS`, `Gc::EVENT_RUN`, `UserPermissions::EVENT_REGISTER_PERMISSIONS` + `accessPlugin-<handle>` convention | `LiteSpeed.php`, `CacheUtility.php` | H | Unknown in Craft 6; keep optional |
| Translations | `Craft::t('litespeed', ...)` (36 calls), `src/translations/{nl,fr,de}` | everywhere | L | Mechanical |
| Framework-agnostic | `Tags`, `Pages`, `Esi` (except `Html::encode`, `ElementInterface`), enums, LSCache header protocol, tracker schema | `src/helpers/*`, `src/enums/*` | L | Carry over as-is; covered by 59 unit tests |

---

## 7. Verification

Run on a real LiteSpeed host (staging first). HEAD never stores a page (`roadmap/esi.md`, observed on a LiteSpeed staging host 2026-10-02), so warm with GET.

```bash
U=https://<site>/
curl -s -o /dev/null "$U"                                   # warm (GET)
curl -sI "$U" | grep -iE '^HTTP|x-litespeed|set-cookie'     # expect: x-litespeed-cache: hit, no set-cookie
curl -sI "$U" | grep -i x-litespeed-cache-control           # on a miss: public,max-age=86400 (or no-cache = not cacheable)
curl -sI "${U}actions/users/session-info" | grep -i x-litespeed-cache-control   # expect no-cache
curl -sI -b 'customer_group=b' "$U" | grep -i x-litespeed-cache                 # vary cookie check (needs Cache-Vary rule)
curl -sI -H 'Accept: text/markdown' "$U" | grep -iE 'content-type|x-litespeed'  # Markdownify sites: must NOT be a cached text/html hit
```

After a change:

| Change | Check |
|---|---|
| Content save in CP | `curl -sI` the page: `x-litespeed-cache` gone or `miss`; `storage/logs/litespeed-*.log` shows `[INFO] Purging public,tag=...` |
| Queue / console purge | `php craft litespeed/purge/all` prints `Purge sent to LiteSpeed.` and exit 0; log shows `Relayed purge to <url>` |
| Multi-domain | Repeat the curl on every host of the install; a CP save only purges the CP's vhost directly |
| Deploy | `litespeed/purge/all` ran after `deploy:publish` (README) |
| New template / plugin | Guest page sends no `Set-Cookie` and no `CraftSessionId`; `Cache-Control` has no `no-store` unless intended |
| Utility | Utilities -> LiteSpeed -> check a path: "In the cache" (green) |
| Plugin code | `composer install && composer test` (59 tests), `composer check-cs` |
| Forms | Submit each form on a cached page with test data (LaV rule: your own LaV address as email, every other field `TEST`) |

---

## 8. Gotchas and conventions

- **Session or cookie kills caching.** Any `Set-Cookie`, active session or `no-store` on a guest page = `no-cache` (`CacheService.php`). On the reference site the blocker was Formie's JS captcha storing a token in the session. Check every new plugin/template for this.
- **Async CSRF is mandatory** for cacheable form pages (`->asyncCsrfInputs()` in `config/general.php`).
- **Purge after every deploy** (templates do not invalidate anything).
- **Tracker writes on non-LiteSpeed hosts.** With `enabled` true on ddev/nginx/plain Apache, every cacheable guest response is upserted into `litespeed_pages` (+ delete + batch insert of tags) because nothing in front caches it (`CacheService::remember()` 212-238, `TrackerService::record()` 53-86). Set `enabled` from an env var and keep it off outside Boost hosts.
- **`excludeUris` is regex, unanchored, path only.** Glob-style entries are wrong: the reference site's `product-vergelijker?*` is an invalid regex and is silently ignored (tested with PHP 2026-11-05); it only works because the plain `product-vergelijker` entry matches. Query strings are never seen.
- **`prefix` must stay stable**; a staging DB copied from prod shares prod's default prefix (fine on separate vhosts, set `prefix` when they share one).
- **Relay needs the same `securityKey`** on web and console, and loopback URLs that answer 204 without redirects; basic-auth staging needs `loopbackOptions['auth']`.
- **Data-cache-backed outputs lose tags.** Anything built inside `getCache()->getOrSet()` (sitemaps, llms.txt) is only purged by install-tag purges unless the action calls `collectCacheTags()`. `{% cache %}` is fine.
- **Custom `TagDependency::invalidate()` does not purge LiteSpeed.** Only `Elements::invalidate*` fires the event.
- **`invalidateAllCaches()` = full-site purge** (install tag). Fine for rare admin saves, never call it per request.
- **Logged-in users get the guest copy** of cached URLs unless `varyLoggedIn` is on (default off).
- **Scheduled entries** need `craft update-statuses` from cron.
- **Element API** endpoints: `'cache' => false`.
- **404s are cached for an hour** (`statusTtls`).
- `server: nginx` in front (as on Combell) does not mean "no LiteSpeed"; trust `x-litespeed-cache`.
- Fork convention: on every upstream pull re-apply the composer `name` rename, test on staging, tag `vX.Y.Z` (`CLAUDE.md`).

---

## 9. Open issues / TODOs found

1. **No purge on uninstall/disable.** `roadmap/esi.md` ("Uninstalling") says `beforeUninstall()` should purge everything and the README should tell you to remove the `.htaccess` block; neither is built. `CacheLookup on` keeps serving stale pages until TTL.
2. **Version metadata mismatch:** `CHANGELOG.md` says `5.0.0`, the release tag is `v1.0.0`; `composer.json` `support`/`documentationUrl`/`changelogUrl` and README install line (`composer require glue-agency/craft-litespeed`, `README.md`) still point at upstream.
3. **ESI unverified on the LaV hosting** (edition, `X-LSCACHE` content, private-cache keying behind nginx, `*;private,*`): `roadmap/esi.md` open questions 1-8.
4. **Multi-domain CP purges** reach only the CP's vhost; other domains wait for TTL. Possible improvement: also relay web-request purges to the other hosts' loopback URLs.
5. **Relay endpoint** is anonymous with CSRF off; payload is HMAC-signed with a 300 s window and can be replayed within it (impact: extra purges only).
6. **ESI payload** is signed, not encrypted; vars are readable in page HTML (documented in README, keep it to IDs).
7. **Tracker drift:** it records what was sent, not what LiteSpeed holds; DB copies (db-push, staging clones) carry foreign rows.
8. **Invalid `excludeUris` from config files** are swallowed (`@preg_match`) without a log line; worth a warning in `isExcluded()`.
9. **Tests not run in this clone** (no `vendor/`); run `composer install && composer test` before the next tag.
10. The integration gaps found on 2026-11-05 are closed in the other plugins' working trees (section 5); this plugin itself was not changed for them.
