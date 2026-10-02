<?php

namespace GlueAgency\LiteSpeed\services;

use Craft;
use craft\base\Component;
use craft\events\InvalidateElementCachesEvent;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\web\Response;
use GlueAgency\LiteSpeed\enums\Header;
use GlueAgency\LiteSpeed\helpers\Tags;
use GlueAgency\LiteSpeed\helpers\Urls;
use GlueAgency\LiteSpeed\jobs\PurgeJob;
use GlueAgency\LiteSpeed\LiteSpeed;
use GuzzleHttp\Client;
use Throwable;

/**
 * Collects purges during a request and gets them to LiteSpeed.
 *
 * LiteSpeed only purges through a header on a response it serves. Web requests send it on their own response;
 * console commands and queue jobs relay it through a request to the site, and when that fails the next web
 * response sends it instead.
 */
class PurgeService extends Component
{
    protected const STORE_KEY = 'litespeed:pending-purges';

    /**
     * LiteSpeed tags to purge, keyed by tag.
     *
     * @var array<string, true>
     */
    protected array $tags = [];

    /**
     * Whether to empty the entire LiteSpeed cache, other apps on the vhost included.
     */
    protected bool $everything = false;

    /**
     * Whether to empty the private cache of the visitor this web response goes to. It can't be relayed.
     */
    protected bool $private = false;

    /**
     * Set by a garbage collection run, whose closing invalidation of all element caches is not a content change.
     */
    protected bool $ignoreNextFullInvalidation = false;

    /**
     * Whether this web response's headers have been prepared, after which purges can only be relayed.
     */
    protected bool $responsePrepared = false;

    /**
     * @param string[] $tags
     */
    public function purgeTags(array $tags): void
    {
        $prefix = $this->prefix();

        $this->queue(array_filter(array_map(fn(string $tag) => Tags::custom($prefix, $tag), $tags)));
    }

    /**
     * @param string[] $urls
     */
    public function purgeUrls(array $urls, bool $subpages = false): void
    {
        $prefix = $this->prefix();
        $tags = [];

        foreach ($urls as $url) {
            $parts = $this->parseUrl($url);

            if ($parts === null) {
                continue;
            }

            $tags[] = $subpages
                ? Tags::tree($prefix, $parts['host'], $parts['path'])
                : Tags::url($prefix, $parts['host'], $parts['path']);
        }

        $this->queue($tags);
    }

    public function purgeAll(): void
    {
        $this->queue([Tags::install($this->prefix())]);
    }

    public function purgeEverything(): void
    {
        $this->queue([], true);
    }

    public function purgePrivate(): void
    {
        $this->private = true;
    }

    /**
     * @param string[] $tags LiteSpeed tags, already prefixed.
     */
    public function queue(array $tags, bool $everything = false): void
    {
        foreach ($tags as $tag) {
            $this->tags[$tag] = true;
        }

        $this->everything = $this->everything || $everything;
    }

    public function hasPending(): bool
    {
        return $this->hasPendingPublic() || $this->private;
    }

    public function handleInvalidation(InvalidateElementCachesEvent $event): void
    {
        if ($event->element && $this->isDraftOrRevision($event)) {
            return;
        }

        if ($event->tags === ['element'] && $this->ignoreNextFullInvalidation) {
            $this->ignoreNextFullInvalidation = false;

            return;
        }

        $prefix = $this->prefix();
        $tags = Tags::fromCraftTags($prefix, $event->tags);
        $url = $event->element?->getUrl();

        if ($url && ($parts = $this->parseUrl($url))) {
            $tags[] = Tags::url($prefix, $parts['host'], $parts['path']);
        }

        $this->queue($tags);
    }

    public function handleGcRun(): void
    {
        if (! LiteSpeed::getInstance()->getSettings()->purgeOnGc) {
            $this->ignoreNextFullInvalidation = true;
        }
    }

    public function prepareResponse(Response $response): void
    {
        $this->responsePrepared = true;
        $this->pullStored();

        if (! $this->hasPending() || headers_sent()) {
            return;
        }

        $settings = LiteSpeed::getInstance()->getSettings();
        $chunks = $this->everything ? [[]] : $this->chunks();
        $value = Tags::purgeHeader($chunks[0] ?? [], $this->everything, $settings->purgeStale, $this->private);

        if ($value !== null) {
            $response->getHeaders()->set(Header::PURGE->value, $value);
            Craft::info("Purging {$value}", 'litespeed');
        }

        if ($this->hasPendingPublic()) {
            LiteSpeed::getInstance()->tracker->forget($chunks[0], $this->everything);
        }

        if (count($chunks) > 1) {
            Queue::push(new PurgeJob(['tags' => array_merge(...array_slice($chunks, 1))]));
            Craft::info('Purge too long for one response, the other ' . (count($chunks) - 1) . ' parts go out from the queue.', 'litespeed');
        }

        $this->reset();
    }

    /**
     * Sends whatever is still pending once no response of our own can carry it, one request per part that fits in
     * a header. Returns false when a relay failed and that part was left for the next web response.
     */
    public function relay(): bool
    {
        if (! $this->hasPending()) {
            return true;
        }

        if (! Craft::$app->getRequest()->getIsConsoleRequest() && ! $this->responsePrepared) {
            return true;
        }

        $this->private = false;

        if (! $this->hasPendingPublic()) {
            return true;
        }

        $everything = $this->everything;
        $chunks = $everything ? [[]] : $this->chunks();

        $this->reset();

        $settings = LiteSpeed::getInstance()->getSettings();
        $client = Craft::createGuzzleClient(array_merge([
            'timeout'         => 10,
            'allow_redirects' => false,
        ], $settings->loopbackOptions));
        $urls = $settings->getLoopbackUrls();
        $relayed = ! empty($urls);

        foreach ($chunks as $tags) {
            $payload = [
                'tags'       => $tags,
                'everything' => $everything,
            ];

            if (! $this->relayPayload($client, $urls, $payload)) {
                $this->store($payload);
                $relayed = false;
            }
        }

        if (empty($urls)) {
            Craft::error('Couldn’t relay purge: no site has a base URL, and no loopbackUrls are set.', 'litespeed');
        }

        return $relayed;
    }

    /**
     * @return array{tags: string[], everything: bool}|null
     */
    public function readRelayPayload(string $body): ?array
    {
        $data = Craft::$app->getSecurity()->validateData($body);

        if ($data === false) {
            return null;
        }

        $payload = Json::decodeIfJson($data);

        if (! is_array($payload) || ! isset($payload['time']) || abs(time() - (int) $payload['time']) > 300) {
            return null;
        }

        return [
            'tags'       => array_values(array_filter((array) ($payload['tags'] ?? []), 'is_string')),
            'everything' => (bool) ($payload['everything'] ?? false),
        ];
    }

    /**
     * @return string[][]
     */
    protected function chunks(): array
    {
        $settings = LiteSpeed::getInstance()->getSettings();

        return Tags::purgeChunks($this->prefix(), array_keys($this->tags), $settings->maxHeaderLength, $settings->purgeStale, $this->private);
    }

    protected function hasPendingPublic(): bool
    {
        return $this->everything || ! empty($this->tags);
    }

    /**
     * @param string[] $urls
     * @param array{tags: string[], everything: bool} $payload
     */
    protected function relayPayload(Client $client, array $urls, array $payload): bool
    {
        $body = Craft::$app->getSecurity()->hashData(Json::encode($payload + ['time' => time()]));
        $relayed = ! empty($urls);

        foreach ($urls as $url) {
            try {
                $response = $client->post($url, [
                    'form_params' => [
                        'action'  => 'litespeed/relay',
                        'payload' => $body,
                    ],
                ]);
            } catch (Throwable $e) {
                Craft::error("Couldn’t relay purge to {$url}: {$e->getMessage()}", 'litespeed');
                $relayed = false;

                continue;
            }

            if ($response->getStatusCode() !== 204) {
                Craft::error("Couldn’t relay purge to {$url}: it answered {$response->getStatusCode()} instead of 204.", 'litespeed');
                $relayed = false;

                continue;
            }

            Craft::info("Relayed purge to {$url}", 'litespeed');
        }

        return $relayed;
    }

    protected function reset(): void
    {
        $this->tags = [];
        $this->everything = false;
        $this->private = false;
    }

    /**
     * @param array{tags: string[], everything: bool} $payload
     */
    protected function store(array $payload): void
    {
        $mutex = Craft::$app->getMutex();

        if (! $mutex->acquire(self::STORE_KEY, 5)) {
            Craft::error('Couldn’t store the pending purge for the next request.', 'litespeed');

            return;
        }

        try {
            $cache = Craft::$app->getCache();
            $stored = $cache->get(self::STORE_KEY) ?: ['tags' => [], 'everything' => false];

            $cache->set(self::STORE_KEY, [
                'tags'       => array_values(array_unique(array_merge($stored['tags'], $payload['tags']))),
                'everything' => $stored['everything'] || $payload['everything'],
            ], 0);
        } finally {
            $mutex->release(self::STORE_KEY);
        }
    }

    protected function pullStored(): void
    {
        $cache = Craft::$app->getCache();

        if ($cache->get(self::STORE_KEY) === false) {
            return;
        }

        $mutex = Craft::$app->getMutex();

        if (! $mutex->acquire(self::STORE_KEY)) {
            return;
        }

        try {
            $stored = $cache->get(self::STORE_KEY);
            $cache->delete(self::STORE_KEY);
        } finally {
            $mutex->release(self::STORE_KEY);
        }

        if (is_array($stored)) {
            $this->queue($stored['tags'] ?? [], $stored['everything'] ?? false);
        }
    }

    protected function isDraftOrRevision(InvalidateElementCachesEvent $event): bool
    {
        try {
            return ElementHelper::isDraftOrRevision($event->element);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{host: string, path: string}|null
     */
    protected function parseUrl(string $url): ?array
    {
        $url = Urls::absolute($url, Urls::defaultOrigin());

        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        return [
            'host' => $parts['host'],
            'path' => $parts['path'] ?? '/',
        ];
    }

    protected function prefix(): string
    {
        return LiteSpeed::getInstance()->getSettings()->getPrefix();
    }
}
