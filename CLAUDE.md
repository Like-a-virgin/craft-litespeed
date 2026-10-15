# CLAUDE.md — likeavirgin/craft-litespeed

Maintainer guide for Like a Virgin's fork of the Craft LiteSpeed cache plugin. Read this before changing anything here or implementing it in a project.

## What this repo is
A **fork** of [`glue-agency/craft-litespeed`](https://github.com/glue-agency/craft-litespeed) (MIT). Upstream has **no stable release** — its default branch is `develop` with zero tags, so a plain `composer require` only gets the moving `dev-develop` branch (fragile: breaks if upstream force-pushes or deletes the branch). We forked it so we control a stable, versioned source we own.

- **Upstream:** https://github.com/glue-agency/craft-litespeed (branch `develop`, no releases)
- **This fork:** https://github.com/Like-a-virgin/craft-litespeed
- **Only change vs upstream:** the top-level composer `name` (`glue-agency/craft-litespeed` → `likeavirgin/craft-litespeed`). The PHP namespace (`GlueAgency\LiteSpeed`), plugin handle (`litespeed`), and class are **unchanged** — renaming the package name does not touch the code. **Re-apply this one-line rename on every upstream pull** (it's the one permanent divergence).
- It's a **pinned-but-tracked fork**, not freeze-and-forget. Upstream is **actively maintained** (frequent commits, ticketed development, e.g. ESI added Oct 2026), so periodically pull upstream into our `develop`, re-apply the rename, test, and cut a new tag. We pin for reproducibility; we still adopt upstream's improvements deliberately.

## What the plugin does
Full-page caching via **LiteSpeed LSCache** for Craft 5. LiteSpeed serves a cached page straight from memory **before PHP/Craft boots**, which is the only thing that beats Craft's ~0.5s bootstrap floor → tens-of-ms first paint. On non-LiteSpeed servers the headers are simply ignored (safe no-op on local/nginx dev). At LaV this is for **Combell Boost** sites (Boost = LiteSpeed). See `~/Obsidian/Combell/LiteSpeed LSCache voor Craft.md`.

It sends `X-LiteSpeed-Cache-Control: public,max-age=N` (a LiteSpeed-only header, separate from the browser `Cache-Control`), tags pages with Craft element cache tags (`X-LiteSpeed-Tag`), and purges precisely by tag/URL (`X-LiteSpeed-Purge`) when content changes. ESI (`craft.litespeed.esi()`) renders per-visitor fragments inside an otherwise-cached page.

## Architecture (and the Craft 6 risk surface)
- `src/LiteSpeed.php` — registers everything.
- `src/services/` — `CacheService` (cacheability + headers), `PurgeService` (tag/URL purge + relay), `TrackerService` (records which elements appeared on which page, in DB tables, for precise purge), `CheckService`, `EsiService`.
- `src/migrations/` — DB tables tracking cached pages (`create_page_tables`).
- `src/jobs/PurgeJob.php`, `src/controllers/` (Relay/Purge/Settings/Esi), `src/console/controllers/PurgeController.php`.
- `src/enums/`, `src/helpers/`, `src/models/Settings.php`, `src/web/twig/LiteSpeedVariable.php`, `tests/`.

**It hooks the Yii event system**: `Event::on` for `craft\web\Response::EVENT_AFTER_PREPARE` (sets headers), `yii\base\Application` (BEFORE_ACTION / AFTER_REQUEST), `yii\queue\Queue` (AFTER_EXEC/ERROR relay), `yii\web\User` (login/logout for ESI), plus Craft services `Elements` (EVENT_AFTER_SAVE_ELEMENT / EVENT_INVALIDATE_CACHES), `Gc`, `Utilities`, `ClearCaches`, `UserPermissions`. Those Yii-level hooks + the `craft\web\Response` lifecycle are exactly what changes in Craft 6 (see below).

## Using it in a project
1. `composer.json`: add a VCS repository for this fork and require the tag:
   ```json
   "repositories": [
     { "type": "vcs", "url": "https://github.com/Like-a-virgin/craft-litespeed.git", "canonical": false }
   ],
   "require": { "likeavirgin/craft-litespeed": "^1.0" }
   ```
   The fork is public, so no composer auth is needed.
2. `php craft plugin/install litespeed`.
3. `web/.htaccess` (required — the header alone does nothing without this):
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
4. `config/general.php`: `->asyncCsrfInputs()` (so pages have no inline CSRF token → cacheable). LaV base-craft already does this.
5. `config/litespeed.php` settings (defaults in `src/models/Settings.php`): `enabled`, `prefix` (keep stable across deploys), `ttl` (86400), `statusTtls` ([404=>3600]), `excludeUris`, `varyCookies`, `varyLoggedIn` (false), `loggedInCookie`, `purgeStale`, `purgeOnGc`, `loopbackUrls`/`loopbackOptions` (config-only, for multi-site purge relay — uses Craft `securityKey`, so web + console must share it), `maxHeaderLength` (8000), `esi`.
6. **Purge after each deploy** (new templates don't invalidate old cache entries): `php craft litespeed/purge/all`.
7. **Test on STAGING first** (a real LiteSpeed host): confirm `x-litespeed-cache: hit`, forms still submit, CP/actions excluded, multi-site purge works, a content edit purges the page.

### The #1 gotcha: active session kills caching
`CacheService` only caches a response when the visitor `getUser()->getIsGuest()` **and** `! getSession()->getIsActive()` **and** the response sets no cookies. Craft starts a session (and sets `CraftSessionId`) more eagerly than you'd think. `asyncCsrfInputs` removes the inline-CSRF trigger, but you must verify anonymous public pages are genuinely session/cookie-free, or **nothing caches**. This is the thing to solve per project. Pages Craft marks `Cache-Control: no-store` (CSRF token access, ungenerated image transforms) are also skipped.

## Releases / versioning (we own this — upstream has none)
Because upstream never tags releases, **we cut our own stable releases** from commits we've tested:
- Tag `vX.Y.Z` only at a commit verified on a staging LiteSpeed site.
- `v1.0.0` = first LaV-tested release (the current one, at the rename commit).
- Loose SemVer: **patch** = upstream bugfix pulled + retested; **minor** = upstream feature; **major** = a Craft major version bump or a breaking change.
- Projects pin `^1.x` from this fork's VCS repo; the lockfile keeps deploys reproducible.
- **To cut a release:** merge upstream `develop` into our `develop`, re-apply the `name` rename, run the test suite + a staging smoke test, then `git tag vX.Y.Z` and push the tag.

## Maintaining across Craft 6 (Laravel)
Craft 6 ports the framework from **Yii 2 to Laravel** (beta ~Q3 2026, GA ~Q4 2026, Laravel 13 / PHP 8.5). This plugin leans on Yii's event system and the `craft\web\Response` lifecycle (see Architecture), which is precisely what moves.

- **Short term:** `craftcms/yii2-adapter` is a compatibility layer intended to run Craft 5 plugins on Craft 6 "in most cases without a single change." This fork will most likely work on early Craft 6 **via the adapter**. Verify on staging when Craft 6 lands.
- **Long term:** the adapter is a transition aid, not permanent. A **native Laravel port** will eventually be needed. The work is re-expressing the Yii event hooks (Application/Response/Queue/User events) and the response-header injection in Laravel terms; the LSCache logic (headers, tags, purge, tracker tables) is framework-agnostic and carries over.
- **No rush:** Craft 5 is an **LTS** release with security support to ~2031, so sites on Craft 5 keep this working for years. Do NOT port at Craft 6 GA — let Craft 6, its plugin APIs, and upstream's port mature first.
- **Expected path (upstream is active, so prefer their work):**
  1. Run as-is via `yii2-adapter` when a site first moves to Craft 6; verify on staging.
  2. **Adopt upstream's native Craft 6 port** when they ship it (they're actively maintained, so this is the likely path) → pull + re-tag.
  3. **Self-port only as a last resort** — if upstream abandons it AND we still need LSCache on Craft 6. By then re-evaluate the landscape: Craft-6-on-Laravel may have a first-party or better option worth switching to instead of maintaining our own port.
  The LSCache logic (headers, tags, purge, tracker tables) is framework-agnostic and carries over; a port is re-expressing the Yii event hooks + response-header injection in Craft-6/Laravel terms. Re-test on staging either way.

## Links
- Upstream: https://github.com/glue-agency/craft-litespeed
- LiteSpeed LSCache docs: https://docs.litespeedtech.com/lscache/
- LaV Combell + implementation notes: `~/Obsidian/Combell/` (`Combell services & packages.md`, `LiteSpeed LSCache voor Craft.md`)
