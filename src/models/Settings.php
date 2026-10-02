<?php

namespace GlueAgency\LiteSpeed\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use GlueAgency\LiteSpeed\enums\VaryCookie;
use GlueAgency\LiteSpeed\helpers\EditableTable;

/**
 * LiteSpeed settings, set in the CP or through `config/litespeed.php`.
 */
class Settings extends Model
{
    /**
     * Whether the plugin sends any LiteSpeed headers at all. Accepts an env var.
     */
    public bool|string $enabled = true;

    /**
     * Prefix for every tag this install sends, so a purge never reaches another app on the same
     * LiteSpeed vhost. Must stay the same across deploys. Defaults to a hash of the system UID.
     */
    public ?string $prefix = null;

    /**
     * Public cache lifetime, in seconds.
     */
    public int $ttl = 86400;

    /**
     * Cache lifetimes for non-200 responses, keyed by status code. Any other status is never cached.
     *
     * @var array<int, int>
     */
    public array $statusTtls = [404 => 3600];

    /**
     * Regular expressions matched against the request path (no leading slash). A match is never cached.
     *
     * @var string[]
     */
    public array $excludeUris = [];

    /**
     * Cookies whose value changes the rendered page, sent as `X-LiteSpeed-Vary: cookie=…`.
     *
     * @var string[]
     */
    public array $varyCookies = [];

    /**
     * Give logged-in users their own vary cookie, so LiteSpeed never serves them a guest page.
     */
    public bool $varyLoggedIn = false;

    /**
     * Name of that cookie. LiteSpeed only varies on it unprompted while it starts with `_lscache_vary`.
     */
    public string $loggedInCookie = VaryCookie::LOGGED_IN->value;

    /**
     * Serve the stale copy while a purged page regenerates.
     */
    public bool $purgeStale = false;

    /**
     * Purge everything when Craft's garbage collection runs, which invalidates all element caches.
     */
    public bool $purgeOnGc = true;

    /**
     * URLs purges are relayed to when they happen outside a web response (console, queue). Config file only.
     * Defaults to the base URL of the first site on each distinct host.
     *
     * @var string[]
     */
    public array $loopbackUrls = [];

    /**
     * Guzzle request options for the relay request, e.g. `auth` for a basic-auth protected staging site.
     * Merged over a 10 second timeout, and redirects are not followed unless set here.
     *
     * @var array<string, mixed>
     */
    public array $loopbackOptions = [];

    /**
     * Longest `X-LiteSpeed-Tag` / `X-LiteSpeed-Purge` value sent, in bytes.
     */
    public int $maxHeaderLength = 8000;

    /**
     * Render `craft.litespeed.esi()` blocks as ESI includes where LiteSpeed supports them. Accepts an env var.
     * Config file only.
     */
    public bool|string $esi = false;

    /**
     * The CP posts table rows and blank strings, which the typed properties can't hold, so they're
     * normalised before assignment rather than in a filter validator.
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        parent::setAttributes(self::normalize((array) $values), $safeOnly);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function normalize(array $values): array
    {
        if (array_key_exists('ttl', $values) && ! is_numeric($values['ttl'])) {
            unset($values['ttl']);
        }

        if (array_key_exists('ttl', $values)) {
            $values['ttl'] = (int) $values['ttl'];
        }

        if (array_key_exists('statusTtls', $values)) {
            $values['statusTtls'] = EditableTable::integerMap($values['statusTtls'], 'status', 'ttl');
        }

        $columns = [
            'excludeUris' => 'pattern',
            'varyCookies' => 'name',
        ];

        foreach ($columns as $key => $column) {
            if (array_key_exists($key, $values)) {
                $values[$key] = EditableTable::column($values[$key], $column);
            }
        }

        if (array_key_exists('enabled', $values) && in_array($values['enabled'], ['1', '0', ''], true)) {
            $values['enabled'] = $values['enabled'] === '1';
        }

        if (array_key_exists('prefix', $values)) {
            $values['prefix'] = trim((string) $values['prefix']) ?: null;
        }

        if (array_key_exists('loggedInCookie', $values)) {
            $values['loggedInCookie'] = trim((string) $values['loggedInCookie']);
        }

        return $values;
    }

    public static function isValidCookieName(string $name): bool
    {
        return (bool) preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name);
    }

    /**
     * The regular expression an `excludeUris` pattern is matched with.
     */
    public static function excludePattern(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~i';
    }

    public static function isValidExcludePattern(string $pattern): bool
    {
        return @preg_match(self::excludePattern($pattern), '') !== false;
    }

    public static function isValidPrefix(string $prefix): bool
    {
        return preg_match('/^[A-Za-z0-9]+$/', $prefix) && ! in_array(strtolower($prefix), ['public', 'private'], true);
    }

    public function isEnabled(): bool
    {
        return (bool) App::parseBooleanEnv($this->enabled);
    }

    public function isEsiEnabled(): bool
    {
        return (bool) App::parseBooleanEnv($this->esi);
    }

    public function getPrefix(): string
    {
        $prefix = App::parseEnv($this->prefix);

        if (empty($prefix)) {
            return $this->defaultPrefix();
        }

        if (! self::isValidPrefix($prefix)) {
            Craft::error("Invalid LiteSpeed tag prefix “{$prefix}”, using the default one instead.", 'litespeed');

            return $this->defaultPrefix();
        }

        return $prefix;
    }

    /**
     * @return string[]
     */
    public function getLoopbackUrls(): array
    {
        if (! empty($this->loopbackUrls)) {
            return array_values(array_filter(array_map(fn(string $url) => App::parseEnv($url), $this->loopbackUrls)));
        }

        $urls = [];
        $trailingSlash = Craft::$app->getConfig()->getGeneral()->addTrailingSlashesToUrls ? '/' : '';

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = $site->getBaseUrl();
            $host = parse_url($baseUrl ?? '', PHP_URL_HOST);

            if (empty($host) || isset($urls[$host])) {
                continue;
            }

            // The site's home URL as Craft serves it, not the bare host: a redirect (`/` → `/nl`, `/nl/` → `/nl`) would lose the POST.
            $urls[$host] = rtrim($baseUrl, '/') . $trailingSlash;
        }

        return array_values($urls);
    }

    protected function defaultPrefix(): string
    {
        return substr(md5(Craft::$app->getSystemUid() ?? Craft::$app->id), 0, 6);
    }

    protected function defineRules(): array
    {
        return [
            [['loggedInCookie'], 'required'],
            [['ttl'], 'integer', 'min' => 0],
            [['maxHeaderLength'], 'integer', 'min' => 1000],
            [['varyLoggedIn', 'purgeStale', 'purgeOnGc'], 'boolean'],
            ['prefix', function(string $attribute) {
                $prefix = App::parseEnv($this->$attribute);

                if (! empty($prefix) && ! self::isValidPrefix($prefix)) {
                    $this->addError($attribute, Craft::t('litespeed', 'The tag prefix may only contain letters and digits, and can’t be “public” or “private”.'));
                }
            }],
            ['statusTtls', function(string $attribute) {
                foreach ($this->$attribute as $status => $ttl) {
                    if ($status < 100 || $status > 599 || $ttl < 0) {
                        $this->addError($attribute, Craft::t('litespeed', 'Status codes run from 100 to 599, and lifetimes can’t be negative.'));

                        return;
                    }
                }
            }],
            ['excludeUris', function(string $attribute) {
                foreach ($this->$attribute as $pattern) {
                    if (! self::isValidExcludePattern($pattern)) {
                        $this->addError($attribute, Craft::t('litespeed', '“{pattern}” isn’t a valid regular expression.', ['pattern' => $pattern]));
                    }
                }
            }],
            ['varyCookies', function(string $attribute) {
                foreach ($this->$attribute as $name) {
                    if (! self::isValidCookieName($name)) {
                        $this->addError($attribute, Craft::t('litespeed', '“{name}” isn’t a valid cookie name.', ['name' => $name]));
                    }
                }
            }],
            ['loggedInCookie', function(string $attribute) {
                if (! self::isValidCookieName($this->$attribute)) {
                    $this->addError($attribute, Craft::t('litespeed', '“{name}” isn’t a valid cookie name.', ['name' => $this->$attribute]));
                }
            }],
        ];
    }
}
