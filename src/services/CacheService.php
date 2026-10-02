<?php

namespace GlueAgency\LiteSpeed\services;

use Craft;
use craft\base\Component;
use craft\helpers\ConfigHelper;
use craft\web\Request;
use craft\web\Response;
use GlueAgency\LiteSpeed\enums\EsiCache;
use GlueAgency\LiteSpeed\enums\Header;
use GlueAgency\LiteSpeed\helpers\Pages;
use GlueAgency\LiteSpeed\helpers\Tags;
use GlueAgency\LiteSpeed\LiteSpeed;
use GlueAgency\LiteSpeed\models\Settings;
use yii\base\InvalidCallException;
use yii\web\Cookie;

/**
 * Decides whether the current response may be cached by LiteSpeed, and with which tags and varies.
 */
class CacheService extends Component
{
    /**
     * Whether this request started collecting Craft's element cache tags.
     */
    protected bool $collecting = false;

    /**
     * Set from a template or module to keep this response out of the cache.
     */
    protected bool $noCache = false;

    /**
     * Overrides the configured TTL for this response, in seconds.
     */
    protected ?int $ttl = null;

    /**
     * Custom tags added to this response.
     *
     * @var string[]
     */
    protected array $tags = [];

    /**
     * Cookies this response varies on, on top of the configured ones.
     *
     * @var string[]
     */
    protected array $varyCookies = [];

    public function noCache(): void
    {
        $this->noCache = true;
    }

    public function setTtl(int $seconds): void
    {
        $this->ttl = max(0, $seconds);
    }

    /**
     * @param string|string[] $tags
     */
    public function addTags(string|array $tags): void
    {
        array_push($this->tags, ...(array) $tags);
    }

    public function addVaryCookie(string $name): void
    {
        $this->varyCookies[] = $name;
    }

    public function startCollecting(): void
    {
        if ($this->collecting || ! $this->isCandidate(Craft::$app->getRequest())) {
            return;
        }

        Craft::$app->getElements()->startCollectingCacheInfo();
        $this->collecting = true;
    }

    public function prepareResponse(Response $response): void
    {
        if (LiteSpeed::getInstance()->esi->isBlockRequest()) {
            return;
        }

        $this->prepareCacheControl($response);
        $this->sendEsi($response);
    }

    /**
     * Sends an ESI block's own cache headers. Tags on a private block are private tags, which public purges don't
     * reach: such a block lives as long as its TTL, or until the visitor's own private purge.
     *
     * @param string[] $craftTags
     */
    public function prepareBlockResponse(Response $response, EsiCache $cache, int $ttl, array $craftTags, ?int $duration): void
    {
        $headers = $response->getHeaders();
        $ttl = $this->ttl ?? $ttl;

        if ($duration) {
            $ttl = min($ttl, $duration);
        }

        if ($this->noCache) {
            $cache = EsiCache::NO_CACHE;
        }

        $cacheControl = $cache->cacheControl($ttl);
        $headers->set(Header::CACHE_CONTROL->value, $cacheControl);

        if ($cacheControl === EsiCache::NO_CACHE->value) {
            return;
        }

        $settings = LiteSpeed::getInstance()->getSettings();
        $prefix = $settings->getPrefix();
        $customTags = array_filter(array_map(fn(string $tag) => Tags::custom($prefix, $tag), $this->tags));
        $value = Tags::cacheHeader($prefix, $craftTags, $customTags, $settings->maxHeaderLength);

        if ($value === null) {
            Craft::warning('Too many cache tags for an ESI block, not caching it.', 'litespeed');
            $headers->set(Header::CACHE_CONTROL->value, EsiCache::NO_CACHE->value);

            return;
        }

        $headers->set(Header::TAG->value, $value);
    }

    protected function prepareCacheControl(Response $response): void
    {
        $rendered = $this->collecting;
        [$craftTags, $duration] = $this->stopCollecting();

        if (headers_sent()) {
            return;
        }

        $request = Craft::$app->getRequest();
        $headers = $response->getHeaders();
        $varyChanged = $this->reconcileVaryCookie($request, $response);

        if ($headers->has(Header::CACHE_CONTROL->value)) {
            if (! preg_match('/no-cache|no-store/i', $headers->get(Header::CACHE_CONTROL->value)) && $this->sendTags($request, $response, $craftTags)) {
                $this->sendVary($response);
            }

            $this->remember($request, $response);

            return;
        }

        $maxAge = $rendered && ! $varyChanged ? $this->maxAge($response, $duration) : null;

        if ($maxAge === null || ! $this->sendTags($request, $response, $craftTags)) {
            $headers->set(Header::CACHE_CONTROL->value, 'no-cache');

            return;
        }

        $headers->set(Header::CACHE_CONTROL->value, "public,max-age={$maxAge}");
        $this->sendVary($response);
        $this->remember($request, $response);
    }

    protected function sendEsi(Response $response): void
    {
        $headers = $response->getHeaders();
        $cacheControl = (string) $headers->get(Header::CACHE_CONTROL->value);

        if (headers_sent() || $cacheControl === '' || ! LiteSpeed::getInstance()->esi->hasIncludes($response)) {
            return;
        }

        if (preg_match('/(^|,)\s*esi=on\s*(,|$)/i', $cacheControl)) {
            return;
        }

        $headers->set(Header::CACHE_CONTROL->value, $cacheControl . ',esi=on');
    }

    protected function sendVary(Response $response): void
    {
        $varyCookies = $this->varyCookies();

        if (empty($varyCookies)) {
            return;
        }

        $response->getHeaders()->set(Header::VARY->value, implode(',', array_map(fn(string $name) => "cookie={$name}", $varyCookies)));
    }

    /**
     * @return string[]
     */
    protected function varyCookies(): array
    {
        return array_values(array_unique(array_merge(LiteSpeed::getInstance()->getSettings()->varyCookies, $this->varyCookies)));
    }

    /**
     * Hands the page to the tracker when the headers as sent let LiteSpeed store it. LiteSpeed doesn't store HEAD
     * responses.
     */
    protected function remember(Request $request, Response $response): void
    {
        if (! $request->getIsGet() || ! $request->getIsSiteRequest()) {
            return;
        }

        $headers = $response->getHeaders();
        $maxAge = Pages::maxAge((string) $headers->get(Header::CACHE_CONTROL->value));

        if ($maxAge === null) {
            return;
        }

        $cookies = [];

        foreach ($request->getRawCookies() as $cookie) {
            $cookies[$cookie->name] = (string) $cookie->value;
        }

        LiteSpeed::getInstance()->tracker->remember(
            siteId: Craft::$app->getSites()->getCurrentSite()->id,
            url: $request->getAbsoluteUrl(),
            variant: Pages::variant($this->varyCookies(), $cookies),
            maxAge: $maxAge,
            tags: Pages::tags((string) $headers->get(Header::TAG->value)),
        );
    }

    protected function isCandidate(Request $request): bool
    {
        return $request->getIsSiteRequest()
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && ! $request->getIsActionRequest()
            && ! $request->getIsLivePreview()
            && $request->getPreviewParam() === null
            && ! $request->getHadToken();
    }

    /**
     * @return array{string[], int|null}
     */
    protected function stopCollecting(): array
    {
        if (! $this->collecting) {
            return [[], null];
        }

        $this->collecting = false;

        try {
            [$dependency, $duration] = Craft::$app->getElements()->stopCollectingCacheInfo();
        } catch (InvalidCallException) {
            return [[], null];
        }

        return [$dependency->tags ?? [], $duration];
    }

    protected function maxAge(Response $response, ?int $duration): ?int
    {
        if ($this->noCache) {
            return null;
        }

        if (! Craft::$app->getUser()->getIsGuest() || Craft::$app->getSession()->getIsActive()) {
            return null;
        }

        if ($response->getCookies()->getCount() > 0 || $response->stream !== null) {
            return null;
        }

        if (preg_match('/no-store/i', (string) $response->getHeaders()->get('Cache-Control'))) {
            return null;
        }

        if ($this->isExcluded(trim($this->path(Craft::$app->getRequest()), '/'))) {
            return null;
        }

        $settings = LiteSpeed::getInstance()->getSettings();
        $status = $response->getStatusCode();

        if ($status !== 200 && ! isset($settings->statusTtls[$status])) {
            return null;
        }

        $maxAge = $this->ttl ?? ($status === 200 ? $settings->ttl : (int) $settings->statusTtls[$status]);
        $cacheDuration = Craft::$app->getConfig()->getGeneral()->cacheDuration;

        if ($duration && (! $cacheDuration || $duration < $cacheDuration)) {
            $maxAge = min($maxAge, $duration);
        }

        if ($maxAge <= 0) {
            return null;
        }

        return $maxAge;
    }

    /**
     * @param string[] $craftTags
     */
    protected function sendTags(Request $request, Response $response, array $craftTags): bool
    {
        $settings = LiteSpeed::getInstance()->getSettings();
        $prefix = $settings->getPrefix();

        $pageTags = Tags::forPage($prefix, $request->getHostName() ?? '', $this->path($request));

        foreach ($this->tags as $tag) {
            $pageTags[] = Tags::custom($prefix, $tag);
        }

        $value = Tags::cacheHeader($prefix, $craftTags, array_filter($pageTags), $settings->maxHeaderLength);

        if ($value === null) {
            Craft::warning("Too many cache tags for {$this->path($request)}, not caching it.", 'litespeed');

            return false;
        }

        if (strlen($value) < strlen(Tags::cacheHeader($prefix, $craftTags, array_filter($pageTags), PHP_INT_MAX))) {
            Craft::info("Too many element tags for {$this->path($request)}, tagged it per element type instead.", 'litespeed');
        }

        $headers = $response->getHeaders();
        $existing = $headers->get(Header::TAG->value);
        $headers->set(Header::TAG->value, $existing ? $existing . ',' . $value : $value);

        return true;
    }

    protected function path(Request $request): string
    {
        return (string) parse_url($request->getUrl(), PHP_URL_PATH);
    }

    protected function isExcluded(string $path): bool
    {
        foreach (LiteSpeed::getInstance()->getSettings()->excludeUris as $pattern) {
            if (@preg_match(Settings::excludePattern($pattern), $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns whether the vary cookie had to change, in which case the response must not be cached.
     */
    protected function reconcileVaryCookie(Request $request, Response $response): bool
    {
        $settings = LiteSpeed::getInstance()->getSettings();

        if (! $settings->varyLoggedIn || $request->getIsConsoleRequest()) {
            return false;
        }

        $name = $settings->loggedInCookie;
        $hasCookie = $request->getRawCookies()->has($name);
        $isGuest = Craft::$app->getUser()->getIsGuest();

        if ($isGuest === ! $hasCookie) {
            return false;
        }

        if ($isGuest) {
            $response->getCookies()->remove(new Cookie(Craft::cookieConfig(['name' => $name, 'path' => '/'])));

            return true;
        }

        $duration = ConfigHelper::durationInSeconds(Craft::$app->getConfig()->getGeneral()->rememberedUserSessionDuration);

        $response->getCookies()->add(new Cookie(Craft::cookieConfig([
            'name'   => $name,
            'value'  => 'loggedin',
            'path'   => '/',
            'expire' => $duration > 0 ? time() + $duration : 0,
        ])));

        return true;
    }
}
