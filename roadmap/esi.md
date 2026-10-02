# ESI and caching for logged-in users

**Status:** first version built, open questions still to verify on staging. Written up and built during GT-113
(October 2026). The [Design](#design) section describes what was built; answer the [open questions](#open-questions)
on staging before relying on it.

## Why

Logged-in users never get a cached page today. With `varyLoggedIn` on, their `_lscache_vary` cookie keeps them off the
guests' copies and Craft renders every page they open, the homepage included. With it off, they get the guests' copy of
every cached URL, so anything that depends on being logged in shows the logged-out version.

ESI (Edge Side Includes) fixes both: a page is cached once for everyone, and the parts that differ per visitor are left
as holes that LiteSpeed fills in separately on every request.

## What we know

### Seen on Combell staging (2 October 2026)

- A `HEAD` request reads the cache (`x-litespeed-cache: hit`) but never stores a page: an uncached URL comes back
  without `x-litespeed-cache` and is still uncached afterwards. It does carry the plugin's `x-litespeed-cache-control`.
- Cached pages still go through the `.htaccess` basic auth: without credentials a cached page answers `401`.
- `utm*`, `gclid` and `fbclid` share one cache entry with the bare URL (`CacheKeyModify`); any other query string is an
  entry of its own.
- Saving an entry with a real change purges its page. A save without changes leaves it cached.

### From LiteSpeed's documentation

- A **private** cache entry is keyed on host, URI, query string, **client IP**, **session cookies** and the vary string.
- The session cookies come from a fixed list: `frontend`, `PHPSESSID`, `xf_session`, `wp_woocommerce_session_*` and
  `lsc_private`. When none of those is present, LiteSpeed uses all the cookies it receives whose value is at least 16
  bytes long. Craft's `CraftSessionId` isn't on the list.
- `X-LiteSpeed-Purge: private, *` purges the private cache of **the current user only**: the visitor whose request gets
  that response. No header purges every user's private cache.
- Any cookie whose name starts with `_lscache_vary` is a vary cookie by default, and always part of the cache key,
  unless the response sends `X-LiteSpeed-Cache-Control: no-vary`. With `no-vary`, varies aren't used to find or store
  that entry.
- ESI is switched on per response with `esi=on` in `X-LiteSpeed-Cache-Control` (`public, max-age=120, esi=on`), or for
  many pages at once with `RewriteRule .? - [E=esi_on:1]`. Either way `CacheLookup on` has to be in `.htaccess`.
- `<esi:include>` takes `src`, `cache-tag`, `cache-control`, `as-var` (private content under 8 KB, kept in shared
  memory), `test` and `combined`.
- **OpenLiteSpeed doesn't support ESI.** It needs LiteSpeed Web Server Enterprise, LiteSpeed Web ADC or QUIC.cloud.

## Open questions

Answer these on staging, which has `esi` on. Whenever ESI is on but not detected, the plugin logs what `X-LSCACHE`
contains:

1. **Edition.** Does Combell run LiteSpeed Enterprise? Without it there's no ESI at all.
2. **Detection.** What does `$_SERVER['X-LSCACHE']` contain on Combell? The WordPress plugin reads ESI support from it.
3. **`no-cache` pages.** Does LiteSpeed still process an include on a `no-cache, esi=on` response, or only on cached
   ones?
4. **Client IP.** With nginx in front, does LiteSpeed key private entries on the visitor's IP or on nginx's?
5. **Private identity.** With an `lsc_private` cookie set:
   - does the block answer `x-litespeed-cache: hit,private` on the second request?
   - does a second user behind the same IP get a block of their own?
   - after a logout and a new `lsc_private` value, is the old block out of reach?
6. **`no-vary` on a page.** Does a page sent with `no-vary` reach logged-in users (who carry `_lscache_vary`) from the
   guests' cache entry, while its blocks still vary?
7. **The include's `cache-control`.** The docs say it's only used to look the block up (`public` or `private`, plus
   `no-vary`). Is the block's own `X-LiteSpeed-Cache-Control` then what decides how long it's kept?
8. **Purging everything and the private cache at once.** Does LiteSpeed accept `*;private,*`?

## Design

### Templates

```twig
{{ craft.litespeed.esi('_partials/user-menu', { entry: entry }, { cache: 'private', ttl: 600, fallback: '_partials/user-menu-client' }) }}
```

- `template` is any site template, `vars` what it gets, and `options.cache` one of `private`, `public` or `no-cache`,
  with `options.ttl` in seconds: 300 by default for private blocks, the `ttl` setting for public ones.
  `options.fallback` is a template to render inline instead where ESI isn't available.
- It behaves like `{% include template with vars only %}`, whether it ends up as an ESI block or is rendered inline. The
  inline fallback uses `only` as well, so a template that works locally works as a block.
- The block is rendered in a request of its own, so the template has to work on its own:

  | The template expects | In the block request |
  |---|---|
  | `entry` and other route variables | Only what's passed. Elements go in as an ID and are loaded again |
  | Variables set in the layout | Missing: pass them, or set them in the template |
  | The page URL (`craft.app.request`, the active menu item) | The URL of the ESI action. The plugin passes the page URL as `litespeed.pageUrl` |
  | `currentUser`, `currentSite`, global sets | Available as usual |
  | `{% js %}`, `{% css %}`, `craft.vite.script()` | Lost: a block has no `<head>` or end of `<body>`. Register them in the page |
  | `{% cache %}` | Works as usual |

- The vars travel in the page's HTML, as part of the block URL: pass IDs and short values, nothing confidential.
  Elements (at the top level of `vars`) travel as class and ID in a map of their own, so an array can't pass for one;
  scalars and arrays of scalars go as they are; anything else throws an `InvalidArgumentException`.
- `craft.litespeed.noCache()`, `ttl()` and `tag()` in a block template apply to the block's response. A block that
  calls `esi()` itself renders that one inline.
- Only what really differs per person goes in a private block: the name, the profile picture, the account link instead
  of "Log in". The menu stays in the page, cached and purged as it is now.

### Rendering

- `esi()` writes an `<esi:include>` when the `esi` setting is on, the request is a site request, and
  `$_SERVER['X-LSCACHE']` contains `esi` (what the WordPress plugin checks). It marks the response, and `CacheService`
  adds `esi=on` to `X-LiteSpeed-Cache-Control`, `no-cache` included. A response whose HTML contains `<esi:include`
  gets it too, so a `{% cache %}` block that replays an include still works.
- The include's `cache-control` is only the lookup scope, `private` or `public`, and is left out for `no-cache`: the
  docs say it's used for cache lookups, while the block's own response says how long it's kept.
- Otherwise, so locally, on OpenLiteSpeed or with the setting off, it renders the template inline. A block that isn't
  `public` then also calls `noCache()`, so personal content never ends up in a shared page. With `fallback`, the
  fallback template is rendered inline instead and the page stays cacheable.
- `src` is a path to the `litespeed/esi/render` action (`/actions/litespeed/esi/render?payload=…`), built with
  `UrlHelper::actionUrl()` without `index.php`, so there's no `&` that LiteSpeed would have to decode. The template,
  vars, site, cache scope, TTL and page URL go in one `Security::hashData()` payload, base64url-encoded in the
  `payload` query parameter. The page URL makes a block's URL unique per page, so a private block only hits when the
  same page is opened again within its TTL.
- The action takes GET only, anonymously, also while the system is offline. It sets the site from the payload, loads
  the elements again for that site, renders the template in site mode while collecting the element cache info, and
  sends its own `X-LiteSpeed-Cache-Control` (`private,max-age=…`, `public,max-age=…` or `no-cache`) and
  `X-LiteSpeed-Tag`. An invalid payload gets an empty 400; a template that fails gets an empty 500 with `no-cache`,
  and is logged.
- The plugin's normal response handling leaves block requests alone, recognised by their route: no URL or tree tags
  for the action URL, no tracker record, no `_lscache_vary` reconcile.

### Visitor identity

The plugin sets an `lsc_private` cookie with 16 random bytes (32 hex characters) at login, and a new value at logout.
It's httpOnly, on path `/`, secure where Craft's cookies are, and lasts as long as the `_lscache_vary` cookie. Craft
validates cookies, so the value LiteSpeed sees is Craft's signed wrapper around it, still unique per login. A login
restored from Craft's identity cookie keeps the `lsc_private` the browser already has, and block requests never
touch it.
`CraftSessionId` isn't on LiteSpeed's list, so without it LiteSpeed would build the key from every long cookie it
receives, analytics cookies included. GA4's `_ga_<id>` changes during a visit, which would make private blocks hardly
ever hit.

### Logged-in users

Once the personal parts are blocks, the rest of a page is the same for everyone. Two ways to serve it to logged-in users
from the cache, depending on open question 6:

- **`no-vary` on the page:** logged-in users get the guests' entry, and only the blocks vary.
- **The WordPress plugin's model:** the logged-in version of a page is cached once per role or user group, with a hash
  of the groups as the `_lscache_vary` value instead of the fixed `loggedin`.

Pages that are personal from top to bottom (dashboards, account pages) stay `no-cache`.

### Purging

- A block's element tags go on the block's own response, so a change only purges the blocks that showed it, not the
  pages they sit in.
- `private,*` goes out on the logout response, and on the response to a logged-in user saving their own user element.
  It follows the public part of the same header (`public,tag=a,tag=b;private,*`), and a purge too long for one
  response keeps room for it in the first part. Console and queue relays drop it, since it only means something on
  the visitor's own response, and the tracker ignores it.
- When an editor changes another user in the CP, the purge reaches the editor, not that user. Private blocks therefore
  get a short TTL (five to ten minutes), or are sent `no-cache` when they have to be current.

### Uninstalling

Templates that call `craft.litespeed.esi()` lose that variable when the plugin is uninstalled or disabled:

- **devMode:** Craft turns on Twig's `strict_variables` only in devMode, so `craft.litespeed` throws and the page errors.
- **Otherwise:** `craft.litespeed.esi(…)` quietly renders nothing, so the block disappears from the page without an
  error. That's worse than the other `craft.litespeed` calls, which only change headers.

To keep sites working without the plugin, a site can wrap the call in a partial of its own, so uninstalling falls
back to a plain include in one place. Not decided yet: Aquaduin calls `craft.litespeed.esi()` directly for now.

```twig
{# templates/_partials/esi.twig #}
{% if craft.litespeed ?? null %}
    {{ craft.litespeed.esi(template, vars ?? {}, options ?? {}) }}
{% else %}
    {% include template with vars ?? {} only %}
{% endif %}
```

LiteSpeed keeps serving the pages it already cached, with `<esi:include>` tags that point at an action that no longer
exists. So the plugin should purge everything in `beforeUninstall()`, and the README's uninstall steps should say to
remove the `.htaccess` block too. Neither is built yet. Without that, nothing purges LiteSpeed any more while `CacheLookup on` keeps serving cached pages
until their TTL runs out. That part applies today, without ESI.

### Settings

- `esi` (`bool|string`, default `false`): turn ESI on. It accepts an environment variable, like `enabled`, and is
  config-file only.

### Later

- A `{% esi %}…{% endesi %}` tag for inline blocks, compiled into a block of the template.
- `as-var` for very small private values, such as a CSRF token.
- `test` and `combined` on the include.

## Sources

- [LSCache Developer's Guide: Overview](https://docs.litespeedtech.com/lscache/devguide/): the private cache key, the
  session cookie list and the 16-byte rule.
- [LSCache Developer's Guide: Basic Controls](https://docs.litespeedtech.com/lscache/devguide/controls/):
  `private, *`, `esi=on` and `X-LiteSpeed-Vary`.
- [LSCache Developer's Guide: Advanced Concepts](https://docs.litespeedtech.com/lscache/devguide/advanced/):
  `_lscache_vary`, `no-vary`, enabling ESI and the `<esi:include>` attributes.
- [LiteSpeed Cache for WordPress: Cache](https://docs.litespeedtech.com/lscache/lscwp/cache/): OpenLiteSpeed doesn't
  support ESI.
