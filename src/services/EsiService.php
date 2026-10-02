<?php

namespace GlueAgency\LiteSpeed\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\ConfigHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use craft\web\Response;
use craft\web\View;
use GlueAgency\LiteSpeed\enums\EsiCache;
use GlueAgency\LiteSpeed\enums\PrivateCookie;
use GlueAgency\LiteSpeed\enums\ServerVariable;
use GlueAgency\LiteSpeed\helpers\Esi;
use GlueAgency\LiteSpeed\LiteSpeed;
use InvalidArgumentException;
use Twig\Markup;
use yii\web\Cookie;
use yii\web\UserEvent;

/**
 * Renders the parts of a page that differ per visitor as ESI blocks, which LiteSpeed fills in on every request, or
 * inline where it can't.
 */
class EsiService extends Component
{
    public const ROUTE = 'litespeed/esi/render';

    protected const DEFAULT_PRIVATE_TTL = 300;

    /**
     * Whether ESI is available for this request, once worked out.
     */
    protected ?bool $available = null;

    /**
     * Whether this response contains an ESI include.
     */
    protected bool $included = false;

    /**
     * The page URL of the block this request renders.
     */
    protected ?string $pageUrl = null;

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $settings = LiteSpeed::getInstance()->getSettings();
        $request = Craft::$app->getRequest();

        if (! $settings->isEnabled() || ! $settings->isEsiEnabled()) {
            $this->available = false;

            return false;
        }

        if ($request->getIsConsoleRequest() || ! $request->getIsSiteRequest() || $this->isBlockRequest()) {
            $this->available = false;

            return false;
        }

        $lscache = $_SERVER[ServerVariable::LSCACHE->value] ?? null;
        $this->available = Esi::isSupported(is_string($lscache) ? $lscache : null);

        if (! $this->available) {
            $reported = is_string($lscache) ? "“{$lscache}”" : 'not set';
            Craft::info("ESI is on, but LiteSpeed doesn’t report ESI support (X-LSCACHE: {$reported}), so blocks are rendered inline.", 'litespeed');
        }

        return $this->available;
    }

    public function isBlockRequest(): bool
    {
        $request = Craft::$app->getRequest();

        return ! $request->getIsConsoleRequest()
            && $request->getIsActionRequest()
            && implode('/', $request->getActionSegments()) === self::ROUTE;
    }

    /**
     * Whether this response needs `esi=on`.
     */
    public function hasIncludes(Response $response): bool
    {
        return $this->included || str_contains((string) $response->content, '<esi:include');
    }

    /**
     * `craft.litespeed.esi()`: behaves like `{% include template with vars only %}`.
     *
     * @param array<string, mixed> $vars
     * @param array{cache?: string, ttl?: int, fallback?: string} $options
     * @throws InvalidArgumentException
     */
    public function block(string $template, array $vars = [], array $options = []): Markup
    {
        $cache = EsiCache::tryFrom((string) ($options['cache'] ?? EsiCache::PRIVATE->value));

        if ($cache === null) {
            throw new InvalidArgumentException('The ESI cache option must be “private”, “public” or “no-cache”.');
        }

        $ttl = (int) ($options['ttl'] ?? $this->defaultTtl($cache));
        $fallback = $options['fallback'] ?? null;
        $pageUrl = $this->pageUrl ?? Craft::$app->getRequest()->getAbsoluteUrl();
        $dehydrated = Esi::dehydrate($vars);
        $available = $this->isAvailable();

        if ($available) {
            $this->included = true;

            return Template::raw(Esi::includeTag($this->src([
                'template' => $template,
                'vars'     => $dehydrated['vars'],
                'elements' => $dehydrated['elements'],
                'siteId'   => Craft::$app->getSites()->getCurrentSite()->id,
                'cache'    => $cache->value,
                'ttl'      => $ttl,
                'pageUrl'  => $pageUrl,
            ]), $cache));
        }

        if (Esi::forcesNoCache($cache, $available, $fallback !== null)) {
            LiteSpeed::getInstance()->cache->noCache();
        }

        return Template::raw(Craft::$app->getView()->renderTemplate($fallback ?? $template, $this->templateVars($vars, $pageUrl)));
    }

    /**
     * @return array{template: string, vars: array<string, mixed>, elements: array<string, array{string, int}>, siteId: int, cache: EsiCache, ttl: int, pageUrl: string}|null
     */
    public function readPayload(string $payload): ?array
    {
        $decoded = StringHelper::base64UrlDecode($payload);
        $data = is_string($decoded) ? Craft::$app->getSecurity()->validateData($decoded) : false;

        if ($data === false) {
            return null;
        }

        $block = Json::decodeIfJson($data);

        if (! is_array($block) || ! is_string($block['template'] ?? null) || ! is_string($block['pageUrl'] ?? null)) {
            return null;
        }

        if (! is_array($block['vars'] ?? null) || ! is_array($block['elements'] ?? null)) {
            return null;
        }

        if (! is_int($block['siteId'] ?? null) || ! is_int($block['ttl'] ?? null)) {
            return null;
        }

        $cache = EsiCache::tryFrom((string) ($block['cache'] ?? ''));

        if ($cache === null) {
            return null;
        }

        foreach ($block['elements'] as $element) {
            if (! is_array($element) || ! is_string($element[0] ?? null) || ! is_int($element[1] ?? null) || ! is_subclass_of($element[0], ElementInterface::class)) {
                return null;
            }
        }

        return [
            'template' => $block['template'],
            'vars'     => $block['vars'],
            'elements' => $block['elements'],
            'siteId'   => $block['siteId'],
            'cache'    => $cache,
            'ttl'      => $block['ttl'],
            'pageUrl'  => $block['pageUrl'],
        ];
    }

    /**
     * Renders a block in the current site, collecting the element cache info its template queries.
     *
     * @param array{template: string, vars: array<string, mixed>, elements: array<string, array{string, int}>, siteId: int, cache: EsiCache, ttl: int, pageUrl: string} $block
     * @return array{html: string, tags: string[], duration: int|null}
     */
    public function render(array $block): array
    {
        $this->pageUrl = $block['pageUrl'];
        $elements = Craft::$app->getElements();
        $siteId = $block['siteId'];

        $vars = Esi::hydrate($block['vars'], $block['elements'], fn(string $class, int $id) => $elements->getElementById($id, $class, $siteId));

        $elements->startCollectingCacheInfo();

        try {
            $html = Craft::$app->getView()->renderTemplate($block['template'], $this->templateVars($vars, $block['pageUrl']), View::TEMPLATE_MODE_SITE);
        } finally {
            [$dependency, $duration] = $elements->stopCollectingCacheInfo();
        }

        return [
            'html'     => $html,
            'tags'     => $dependency->tags ?? [],
            'duration' => $duration,
        ];
    }

    /**
     * Gives the visitor a private cache identity of their own at login. A login restored from the identity cookie
     * keeps the one the browser already has.
     */
    public function handleLogin(UserEvent $event): void
    {
        if ($this->isBlockRequest()) {
            return;
        }

        if ($event->cookieBased && Craft::$app->getRequest()->getRawCookies()->has(PrivateCookie::IDENTITY->value)) {
            return;
        }

        $this->renewPrivateCookie();
    }

    /**
     * Leaves the private blocks of the session that ended out of reach, and empties them.
     */
    public function handleLogout(): void
    {
        if ($this->isBlockRequest()) {
            return;
        }

        $this->renewPrivateCookie();
        LiteSpeed::getInstance()->purge->purgePrivate();
    }

    protected function renewPrivateCookie(): void
    {
        $duration = ConfigHelper::durationInSeconds(Craft::$app->getConfig()->getGeneral()->rememberedUserSessionDuration);

        Craft::$app->getResponse()->getCookies()->add(new Cookie(Craft::cookieConfig([
            'name'     => PrivateCookie::IDENTITY->value,
            'value'    => bin2hex(random_bytes(16)),
            'path'     => '/',
            'httpOnly' => true,
            'expire'   => $duration > 0 ? time() + $duration : 0,
        ])));
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    protected function templateVars(array $vars, string $pageUrl): array
    {
        return array_merge($vars, [
            'litespeed' => ['pageUrl' => $pageUrl],
        ]);
    }

    /**
     * The block's URL as a path, with the payload as its only query parameter: LiteSpeed fetches it from the host
     * that served the page.
     *
     * @param array<string, mixed> $block
     */
    protected function src(array $block): string
    {
        $url = UrlHelper::actionUrl(self::ROUTE, [
            'payload' => rtrim(StringHelper::base64UrlEncode(Craft::$app->getSecurity()->hashData(Json::encode($block))), '='),
        ], showScriptName: false);
        $query = parse_url($url, PHP_URL_QUERY);

        return (parse_url($url, PHP_URL_PATH) ?: '/') . ($query ? '?' . $query : '');
    }

    protected function defaultTtl(EsiCache $cache): int
    {
        return match ($cache) {
            EsiCache::PRIVATE  => self::DEFAULT_PRIVATE_TTL,
            EsiCache::PUBLIC   => LiteSpeed::getInstance()->getSettings()->ttl,
            EsiCache::NO_CACHE => 0,
        };
    }
}
