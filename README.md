# LiteSpeed for Craft CMS

Lets LiteSpeed Web Server's page cache (LSCache) cache Craft pages, and purges them the moment their
content changes. A Craft port of LiteSpeed's own Laravel, Drupal and WordPress cache plugins, using the
same response headers.

- Every cacheable page is sent with `X-LiteSpeed-Cache-Control` and tagged with Craft's own element cache
  tags through `X-LiteSpeed-Tag`.
- Saving, deleting or moving an element purges exactly the pages that showed it, through
  `X-LiteSpeed-Purge`. That also works from the queue and from console commands.
- Logged-in users and cookie-dependent pages get their own cache variant through `X-LiteSpeed-Vary`.
- The parts of a page that differ per visitor can be ESI blocks, which LiteSpeed fills in on every request while the
  page around them is cached once.
- A CP section purges URLs or tags by hand, and so do the console and the Clear Caches utility.
- A utility shows how many pages were handed to LiteSpeed, and asks LiteSpeed whether a given URL is cached.
- Every setting can be managed in the CP, or per environment through `config/litespeed.php`.

## Requirements

Craft CMS 5.2 or later, PHP 8.2 or later, and a LiteSpeed Web Server (Enterprise or OpenLiteSpeed) with the
cache module enabled. The plugin does nothing harmful without LiteSpeed; the headers are simply ignored.

## Installation

```bash
composer require glue-agency/craft-litespeed
php craft plugin/install litespeed
```

Turn on cache lookups in `web/.htaccess`:

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

Then purge after every deploy, since new templates don't invalidate anything. Do it once the new release is
live: Deployer's Craft recipe runs `clear-caches/all` (which includes the LiteSpeed cache) *before*
`deploy:publish`, so the old release can re-cache its pages in between. With Deployer:

```php
desc('Purges the LiteSpeed cache');
task('craft:litespeed_purge', craft('litespeed/purge/all', ['showOutput']));
after('deploy:publish', 'craft:litespeed_purge');
```

## What gets cached

A response is cached when all of these hold, and is sent `X-LiteSpeed-Cache-Control: no-cache` otherwise:

- It's a GET or HEAD site request that a controller action rendered: no action request, CP request, preview or
  token.
- The visitor is a guest without an active session, and the response sets no cookies.
- The status is 200, or listed in `statusTtls`.
- The path matches none of the `excludeUris`.
- Its `Cache-Control` doesn't contain `no-store`. Craft's `setNoCacheHeaders()` sends that, and Craft calls it
  itself for every page that outputs a CSRF token, and for pages that link to an image transform that hasn't
  been generated yet.

The `Cache-Control` header itself is left alone. It's meant for browsers and for caches downstream of
LiteSpeed, while LiteSpeed follows the plugin's `X-LiteSpeed-Cache-Control`, so `private` or `no-cache` in it
don't keep a page out of LiteSpeed. To keep one out, call `setNoCacheHeaders()` (which sends `no-store`) or
`{% do craft.litespeed.noCache() %}`.

A page whose HTML depends on a cookie needs that cookie in `varyCookies`, plus the `.htaccess` rule (see
[Vary](#vary)), *before* the plugin is enabled. Otherwise the first visitor's variant is served to everyone.

Craft's `{% cache %}` tags keep working: their element tags are passed on to LiteSpeed even when the block comes
from Craft's cache.

### Forms and CSRF

A `{{ csrfInput() }}` makes a page uncacheable. Enable Craft's asynchronous CSRF inputs so forms stay
cacheable:

```php
// config/general.php
'asyncCsrfInputs' => true,
```

or per form: `{{ csrfInput({ async: true }) }}`.

## Purging

| Trigger | What's purged |
|---|---|
| Element saved, deleted, restored, moved or propagated | The pages that showed it, and the element's own URL |
| Section, entry type, volume, field, site or global set changed | Every page that queried that kind of element; for fields, sites and globals, the whole site |
| Drafts and revisions | Nothing |
| Craft's garbage collection | The whole site, unless `purgeOnGc` is off |
| `php craft litespeed/purge/all` or *Utilities → Clear Caches → LiteSpeed cache* | The whole site |
| `php craft litespeed/purge/all --everything` | The entire LiteSpeed cache, including other apps on the same vhost |
| `php craft litespeed/purge/urls <url>... [--subpages]` | Those URLs, and with `--subpages` everything below them |
| `php craft litespeed/purge/tags <tag>...` | The pages tagged with `craft.litespeed.tag()` |

LiteSpeed only accepts a purge on a response it serves. In a web request the purge rides along on the
response itself. Console commands and queue jobs relay it with a request to each site's base URL; when that
request fails, the purge is kept and the next web request sends it.

The relay request goes to the first site URL of each host, so it passes through LiteSpeed the way a visitor
does. Both relay settings are config-file only:

- `loopbackUrls` replaces those URLs. Only set it when LiteSpeed has to be reached on another URL than the site's
  base URL, for example because the base URL redirects: the relay doesn't follow redirects, and each URL must
  answer the POST itself with a 204.
- `loopbackOptions` are Guzzle options for the request, such as basic auth on a staging site.

```php
// config/litespeed.php
return [
    '*' => [],
    'staging' => [
        'loopbackOptions' => ['auth' => ['dev', 'dev']],
    ],
    'production' => [
        'loopbackUrls' => ['https://www.example.com/nl/'],
    ],
];
```

The relay is authenticated with Craft's `securityKey`, so the web and console processes need the same one.

## The control panel

The **LiteSpeed** section purges one or more URLs, optionally with all their sub-pages. To purge a whole
site, enter its base URL and tick "Purge all sub-pages". The **Purge tags** permission adds the Tags tab.

## Utility

*Utilities → LiteSpeed* has two parts, behind Craft's own permission for the utility.

**Pages sent to LiteSpeed for caching** counts the plugin's own record, per site: every GET response it sent with
`X-LiteSpeed-Cache-Control: public,max-age=N` is stored with its URL (query string included), cookie variant, tags
and expiry. A purge removes the pages it reaches, and Craft's garbage collection removes expired ones. LiteSpeed
can't list what it holds, so this is what the plugin handed over, not what LiteSpeed kept: LiteSpeed can evict a
page early, refuse to store one, or be purged on the server (from the WebAdmin console, or by another app on the
vhost) without Craft knowing. URLs that `.htaccess` folds into one cache entry, such as `?utm_source=…` visits,
are separate rows. HEAD requests are never recorded: LiteSpeed doesn't store their responses.

**Check a page** takes a full URL or a path, checks it, and lists the recorded pages for it; part of a URL only
searches the record. **The check is the real status**: it sends LiteSpeed a HEAD request, which it answers from its
cache without storing anything, with the `loopbackOptions` and without cookies, so it checks what a guest gets. The answer is
*in the cache* (`x-litespeed-cache: hit`), *not in the cache right now* (a public `x-litespeed-cache-control`
without a hit), *never cached* (`no-cache`), a redirect, or an error. Only URLs on the hosts of this install's
sites can be checked.

On a multi-domain install, a purge from a web request only reaches the vhost that served it (see
[Caveats](#caveats)), but it removes the matching rows for every host. The other domains' pages can then still be
cached while the utility no longer lists them; the check shows the truth.

## Vary

### Logged-in users

LiteSpeed answers a cached page before Craft runs, so the `no-cache` a site sends to logged-in users only
helps on pages that aren't cached yet: on a cached URL a logged-in user gets the guests' copy. That's fine while
pages look the same for everyone. When they don't, turn on `varyLoggedIn` (off by default), or vary on a cookie
of your own that the site sets at login (see below).

With `varyLoggedIn` on, logged-in users get a `_lscache_vary` cookie when they log in, which is removed again
when they log out. LiteSpeed varies on that cookie name without being told to, so a logged-in user never gets a
guest's cached page. The cookie's name is the `loggedInCookie` setting; a name that doesn't start with
`_lscache_vary` needs a [server rule](#server-rules).

### Cookies of your own

When a page renders differently depending on a cookie of your own, cache a variant per cookie value. Either for
the whole site:

```php
// config/litespeed.php
return [
    'varyCookies' => ['customer_group'],
];
```

or only on the pages that read it:

```twig
{% do craft.litespeed.varyCookie('customer_group') %}
```

That sends `X-LiteSpeed-Vary: cookie=customer_group`. Each of these cookies also needs a
[server rule](#server-rules).

### Server rules

LiteSpeed looks up a cached page before Craft runs, so it has to know the vary cookies up front.

**LiteSpeed Web Server** reads them from `web/.htaccess`, in Apache syntax. List every cookie in one rule,
separated by commas: `E=` sets the variable, so a second `Cache-Vary` rule overwrites the first instead of adding
to it. A `loggedInCookie` that doesn't start with `_lscache_vary` goes in the same list.

```apache
<IfModule LiteSpeed>
    RewriteRule .* - [E="Cache-Vary:customer_group,my_vary_cookie"]
</IfModule>
```

**OpenLiteSpeed** reads the same rule, but only when the virtual host has *Auto Load from .htaccess* on, and it
picks up changes to `.htaccess` after a graceful restart.

**nginx** needs nothing. The cache runs inside LiteSpeed: an nginx proxy in front of it, as on Combell, passes
the cookies through, and a server that only runs nginx has no LiteSpeed cache at all.

## Templates

```twig
{# Keep this page out of the cache #}
{% do craft.litespeed.noCache() %}

{# Cache this page for an hour instead of the configured TTL #}
{% do craft.litespeed.ttl(3600) %}

{# Tag this page, to purge it by hand later on #}
{% do craft.litespeed.tag(['news', 'footer']) %}

{# Cache a variant per value of this cookie #}
{% do craft.litespeed.varyCookie('customer_group') %}
```

A template that sets `X-LiteSpeed-Cache-Control` itself, with `{% header %}`, keeps it: the plugin only adds
its tags.

## ESI

ESI (Edge Side Includes) leaves the parts of a page that differ per visitor, such as the account button in the
header, as holes that LiteSpeed fills in separately on every request. The page around them is cached once for
everyone.

```twig
{{ craft.litespeed.esi('_includes/account', { is_home: is_home }, {
    cache: 'private',
    ttl: 300,
    fallback: '_includes/account-client',
}) }}
```

- It behaves like `{% include template with vars only %}`, both as a block and inline. With `pageUrl: true` the
  template also gets `litespeed.pageUrl`, the URL of the page it sits in; that makes the block's URL, and so its
  cache entry, one per page.
- `cache` is `private` (the default: a copy per visitor), `public` (one copy for everyone) or `no-cache`. `ttl` is in
  seconds, 300 by default for private blocks and the `ttl` setting for public ones.
- The vars travel in the block's URL: elements (loaded again by ID, for the same site), scalars and arrays of
  scalars. Anything else throws. Pass IDs and short values, nothing confidential.
- A block is rendered in a request of its own: variables set in the layout aren't there and the request URL is the
  plugin's action, while `currentUser`, `currentSite` and global sets work as usual. `{% js %}`, `{% css %}` and
  `craft.vite.script()` are lost, so register them in the page. `craft.litespeed.noCache()`, `ttl()` and `tag()`
  apply to the block's own response.

**Where LiteSpeed supports ESI**, so the `esi` setting is on, it's a site request and LiteSpeed reports `esi` in
`$_SERVER['X-LSCACHE']`, the call outputs `<esi:include src="…" cache-control="private" />` and the page gets
`esi=on` in its `X-LiteSpeed-Cache-Control`. LiteSpeed fetches the block from the plugin's `litespeed/esi/render`
action, which answers with its own `X-LiteSpeed-Cache-Control` (`private,max-age=N`, `public,max-age=N` or
`no-cache`) and `X-LiteSpeed-Tag`. A block that fails to render comes back empty and is logged.

**Everywhere else** (locally, on OpenLiteSpeed, with the setting off), the template is rendered inline. A block that
isn't `public` then keeps the page out of the cache, so personal content never ends up in a shared page. With
`fallback`, that template is rendered inline instead and the page stays cacheable: the fallback has to be safe to
cache, for example markup that fetches the personal data client-side.

**Private blocks:**

- LiteSpeed keys private blocks on the visitor's IP and a session cookie it knows, and Craft's isn't one of them. So
  the plugin sets an `lsc_private` cookie at login, and gives it a new value at logout.
- Logging out, and saving your own account, sends `X-LiteSpeed-Purge: private,*`, which empties that visitor's
  private blocks.
- Tags on a private block are private: a purge from a save in the CP never reaches another visitor's private
  blocks. Keep private TTLs short, or use `no-cache` for blocks that always have to be current.
- Without `pageUrl`, a block's URL is the same on every page, so a private block keeps hitting while the visitor
  moves around the site.
- A logged-in visitor's page is never cached, so it gets the block rendered in place instead of an include.

Without the plugin, `craft.litespeed` is undefined: an error in devMode, and silently nothing otherwise. What we
found out about LiteSpeed's ESI support, and what's still to be verified on a real server, is in
[roadmap/esi.md](roadmap/esi.md).

## Settings

Everything below can be set under *LiteSpeed → Settings*, which admins see in the section's nav (*Settings →
Plugins → LiteSpeed* leads there too). The CP saves to project config, so it's only editable where `allowAdminChanges` is on; elsewhere admins see it read-only. To set a value per
environment, enter an environment variable such as `$LITESPEED_ENABLED` or `$LITESPEED_PREFIX` (`enabled`
and `prefix` accept one), or set it in `config/litespeed.php`, which overrides the CP: an overridden field is
shown read-only. The relay settings (`loopbackUrls`, `loopbackOptions`), `maxHeaderLength` and `esi` are config-file
only.

| Setting | Default | |
|---|---|---|
| `enabled` | `true` | Send any LiteSpeed headers at all |
| `prefix` | hash of the system UID | Prefix for every tag, letters and digits only. It keeps purges from reaching other apps on the same vhost, so it must never change between deploys |
| `ttl` | `86400` | Public cache lifetime, in seconds |
| `statusTtls` | `[404 => 3600]` | Lifetimes for non-200 responses, by status code. Any other status isn't cached |
| `excludeUris` | `[]` | Regular expressions matched against the path (without leading slash) |
| `varyCookies` | `[]` | Cookies every page varies on |
| `varyLoggedIn` | `false` | Give logged-in users their own cache variant |
| `loggedInCookie` | `_lscache_vary` | Name of the logged-in users' vary cookie |
| `purgeStale` | `false` | Serve the stale copy while a purged page regenerates |
| `purgeOnGc` | `true` | Purge the whole site when Craft's garbage collection runs: `php craft gc`, a deploy, and about 1 in 100,000 requests that reach Craft (`gcProbability`) |
| `loopbackUrls` | each host's first site URL | Where console and queue purges are relayed to, when LiteSpeed has to be reached on another URL than the site's base URL. Config file only |
| `loopbackOptions` | `[]` | Guzzle options for that relay request, merged over a 10 second timeout and no redirects. Config file only |
| `maxHeaderLength` | `8000` | Longest tag or purge header sent, in bytes. Config file only |
| `esi` | `false` | Render `craft.litespeed.esi()` blocks as ESI includes where LiteSpeed supports ESI. Accepts an environment variable. Config file only |

`maxHeaderLength` exists because servers cap header sizes and LiteSpeed doesn't document its limit. The only
data point is an unconfirmed OpenLiteSpeed report: a header line over 16 KB is dropped and the request hangs, more
than 64 KB of headers gives a 503. At about 15 bytes a tag, 8000 bytes is roughly 500 tags. A page with more tags
than fit swaps its per-element tags for per-type ones, so it's still purged, just by any change to that element
type; if even that doesn't fit, the page isn't cached. A purge that doesn't fit is split into parts that do: from
the console or the queue each part is its own relay request, and a web response carries the first part and leaves
the rest to a queue job. Both are logged. Only change it once the server's real limit is known.

## Logging

Everything the plugin does goes to `storage/logs/litespeed-<date>.log`:

- `[INFO] Purging public,tag=…`: the purge header sent with a response, from a CP save, a relay or the next
  request after a failed relay.
- `[INFO] Relayed purge to <url>`: a console or queue purge reached the site and came back with a 204.
- `[ERROR] Couldn’t relay purge to <url>: …`: the relay request failed (timeout, basic auth, a redirect). The
  purge is kept and sent with the next web request.
- `[INFO] Purge too long for one response, the other N parts go out from the queue.`: a big purge from a web
  request, split over a queue job.
- `[WARNING] Too many cache tags for <path>, not caching it.`: the page-side `maxHeaderLength` fallback. A page
  that falls back to per-type tags is logged at `[INFO]`.
- `[WARNING] Couldn’t record <url> as sent to LiteSpeed: …`: the utility's record of a page couldn't be written.
  The page itself is unaffected.
- `[INFO] ESI is on, but LiteSpeed doesn’t report ESI support (X-LSCACHE: …)`: once per request that calls
  `craft.litespeed.esi()` with `esi` on, showing what the server reported (or `not set`). The blocks were rendered
  inline.
- `[ERROR] Couldn’t render the ESI block <template>: …`: a block's template failed. LiteSpeed got an empty block.

LiteSpeed itself doesn't acknowledge a purge, so the log shows what was sent, not what LiteSpeed did with it. To
confirm, request the page again: `x-litespeed-cache: miss` means it was purged.

## Caveats

- **Separate domains.** A purge from a web request only reaches the LiteSpeed vhost that served that request.
  When the sites of a multi-site install are served by different vhosts, a save in the CP only purges the CP's
  vhost; the others catch up when their TTL runs out.
- **Element API.** An endpoint cached by Element API's own `cache` option loses its element tags on Craft's
  cache hits, so LiteSpeed is never told to purge it. Set `'cache' => false` on endpoints LiteSpeed caches.
- **Scheduled entries.** An entry whose post or expiry date passes isn't saved, so nothing purges it. Run
  `craft update-statuses` from cron (Craft 5.7+), which resaves them, or rely on the TTL.
- **Copied databases.** The default prefix comes from the system UID, so a staging site built from a
  production database shares production's prefix. That's harmless while each runs on its own vhost; set
  `prefix` when two of them share one.
- **ESI is unverified on a real server.** The first version is built, but whether Combell's LiteSpeed supports ESI,
  and how it treats private blocks, still has to be checked on staging: see [roadmap/esi.md](roadmap/esi.md).
  Pages for logged-in users are still never cached; only their ESI blocks are.
