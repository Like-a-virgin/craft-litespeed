# LiteSpeed (`litespeed`): notes for agents

Full-page caching through LiteSpeed LSCache for Craft CMS 5 (`craftcms/cms ^5.2`). LiteSpeed serves cached pages before PHP boots; this plugin sends `X-LiteSpeed-Cache-Control`, tags pages with Craft's element cache tags (`X-LiteSpeed-Tag`), purges exactly the affected pages (`X-LiteSpeed-Purge`) from web, queue and console, varies per cookie, and offers ESI blocks for per-visitor fragments. On non-LiteSpeed servers the headers are ignored.

Package `likeavirgin/craft-litespeed` = **LaV's fork** of `glue-agency/craft-litespeed`. Namespace `GlueAgency\LiteSpeed`, class `GlueAgency\LiteSpeed\LiteSpeed`, handle `litespeed` are unchanged from upstream.

- Fork maintenance, releases, project setup, Craft 6 strategy: [`CLAUDE.md`](CLAUDE.md).
- Architecture, tags, purge flow, Craft 6 risks, open issues: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). ESI design: [`roadmap/esi.md`](roadmap/esi.md). User docs: `README.md`.

## Install and config

- Composer VCS repo `https://github.com/Like-a-virgin/craft-litespeed.git`, require `likeavirgin/craft-litespeed: ^1.0`, then `php craft plugin/install litespeed`.
- Required in the project: the `<IfModule LiteSpeed>` block in `web/.htaccess` (`CacheLookup on`, `CacheKeyModify -qs:utm*` / `gclid` / `fbclid`) and `->asyncCsrfInputs()` in `config/general.php`. Exact block in `CLAUDE.md`.
- Settings (CP, or `config/litespeed.php`; defaults in `src/models/Settings.php`): `enabled` (set from an env var, off outside LiteSpeed hosts), `prefix` (keep stable), `ttl` (86400), `statusTtls` ([404 => 3600]), `excludeUris` (unanchored regex on the path), `varyCookies`, `varyLoggedIn`, `loggedInCookie`, `purgeStale`, `purgeOnGc`; config only: `loopbackUrls`, `loopbackOptions`, `maxHeaderLength`, `esi`.
- Console: `php craft litespeed/purge/all [--everything]`, `litespeed/purge/urls <url>... [--subpages]`, `litespeed/purge/tags <tag>...`. Run `purge/all` after every deploy. `clear-caches/all` also purges (Clear Caches option `litespeed`).
- Permissions: `accessPlugin-litespeed` (purge URLs), `litespeed:purgeTags`; settings need admin.
- Twig: `craft.litespeed.noCache()`, `.ttl(int)`, `.tag(...)`, `.varyCookie(name)`, `.esi(template, vars, options)`.

## Write from a migration

LiteSpeed stores nothing an editor changes: `litespeed_pages` / `litespeed_pagetags` are the cache index of the server it runs on (never written by hand or by a migration). What a migration may need:

- **Settings** are project config (`plugins.litespeed.settings`; `enabled` usually from an env var), overridable in `config/litespeed.php`. Change them locally (CP: LiteSpeed > Settings, admin) and commit the YAML; `craft up` applies it before content migrations run. If you script it locally, pass every key: Craft's `savePluginSettings()` writes only the keys you pass and drops the rest from project config.
- **Purge after data that bypasses element events.** A migration that saves elements through `saveElement()` purges by itself. One that writes raw SQL, or plugin records without their service, does not: end it with a purge. From a console request (`craft up`) the purge reaches LiteSpeed through the loopback relay (`loopbackUrls`); a failed relay waits in the data cache for the next web response. Deploys should still run `php craft litespeed/purge/all` (templates changed).

```php
public function safeUp(): bool
{
    // Plugin-agnostic and enough in almost every case: every cached page of this install.
    Craft::$app->getElements()->invalidateAllCaches();

    // Or targeted, only where LiteSpeed is on:
    if (Craft::$app->getPlugins()->isPluginEnabled('litespeed')) {
        \GlueAgency\LiteSpeed\LiteSpeed::getInstance()->purge->purgeUrls([\craft\helpers\UrlHelper::siteUrl('whitepaper')], true);
    }
    return true;
}
```

The LaV plugins' own write APIs (LaV Forms `transfer->import()`, Consent `Banner::save()` and `publishDocument()`, LaV SEO settings) already call `invalidateAllCaches()`.

## Rules

- **Fork rule:** the only code divergence from upstream is the composer `name`. Re-apply it on every upstream pull; keep LaV changes to docs (`AGENTS.md`, `CLAUDE.md`, `docs/`) so upstream merges stay clean. Tag `vX.Y.Z` only after a staging test on a real LiteSpeed host.
- **An active session, any `Set-Cookie` or a `no-store` response makes a page uncacheable** (`CacheService::maxAge()`). Check every new template or plugin for this.
- Keep tag mapping in `helpers/Tags.php` only: tagging and purging meet through `Tags::fromCraftTag()`.
- `enums\Permission` values (`GlueAgency\LiteSpeed\enums\Permission`) and the `prefix` derivation are effectively stored data; renaming them silently revokes permissions or orphans cached pages.
- `composer test` (PHPUnit) and `composer check-cs` before a commit. Keep `AGENTS.md` and `docs/ARCHITECTURE.md` true in the same change.

## Public API for other plugins

No events are fired. Prefer Craft core signals, which this plugin listens to:

- Purge: `Craft::$app->getElements()->invalidateAllCaches()` (whole install), `invalidateCachesForElement($el)`, `invalidateCachesForElementType(Entry::class)` (all reach `Elements::EVENT_INVALIDATE_CACHES` -> `PurgeService::handleInvalidation()`).
- Tag output built from a data cache: `Craft::$app->getElements()->collectCacheTags(['element::' . Entry::class . '::*'])`. A bare `TagDependency::invalidate()` does **not** purge LiteSpeed.
- Uncacheable response: `Craft::$app->getResponse()->setNoCacheHeaders()`, or LiteSpeed-only `LiteSpeed::getInstance()->cache->noCache()`.
- Direct services (soft: `isPluginEnabled('litespeed')` first): `cache->noCache()`, `->setTtl(int)`, `->addTags(string|array)`, `->addVaryCookie(name)`; `purge->purgeTags(array)`, `->purgeUrls(array, bool $subpages)`, `->purgeAll()`, `->purgeEverything()`.

## Our other plugins (they integrate with us; we reference none)

| Plugin | Integration |
|---|---|
| Consent | `craft.consent.has()` calls our `cache->noCache()`; banner/document saves call `invalidateAllCaches()`. |
| LaV Forms | Async CSRF keeps form pages cacheable; form changes call `invalidateAllCaches()`. |
| LaV SEO | Settings saves call `invalidateAllCaches()`; `sitemap.xml` / `robots.txt` collect `element::craft\elements\Entry::*`. |
| Vizier | Detects us (`Vizier::usesPageCache()`) and counts totals from its beacon, since cached hits run no PHP. |
| Markdownify | `.md` / llms.txt replay element tags; behind LiteSpeed use the `.md` URLs, not `Accept: text/markdown`. |
| DB-merge | Runs `clear-caches/all` on the remote after a push; never merges our tables. |

## Tables and DB-merge policy

| Table | Holds | DB-merge |
|---|---|---|
| `litespeed_pages` | every page handed to LiteSpeed (site, url, variant, key, expiry) | `never` (the cache index of the server it runs on) |
| `litespeed_pagetags` | tags per tracked page | `never` |

Settings are project config (`plugins.litespeed.settings`). Failed relays wait in the Craft data cache (`litespeed:pending-purges`).

## Verify a change

1. `composer install && composer test && composer check-cs` in the repo.
2. In a DDEV project via a composer path repo: `ddev craft up`, CP LiteSpeed pages and the utility load with no errors in `storage/logs`. DDEV has no LiteSpeed, so it proves nothing about caching.
3. On a LiteSpeed staging host: warm with GET, then `curl -sI <url>` shows `x-litespeed-cache: hit` and no `set-cookie`; a CP content save purges the page; `php craft litespeed/purge/all` exits 0. Full checklist: `docs/ARCHITECTURE.md` section 7.
