<?php

namespace GlueAgency\LiteSpeed\controllers;

use Craft;
use craft\web\Controller;
use GlueAgency\LiteSpeed\enums\EsiCache;
use GlueAgency\LiteSpeed\enums\Header;
use GlueAgency\LiteSpeed\LiteSpeed;
use Throwable;
use yii\web\Response;

/**
 * Renders the ESI blocks LiteSpeed fetches to fill in a cached page.
 */
class EsiController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;

    public function actionRender(): Response
    {
        if (! $this->request->getIsGet()) {
            return $this->emptyResponse(405);
        }

        $plugin = LiteSpeed::getInstance();
        $payload = $this->request->getQueryParam('payload');
        $block = is_string($payload) ? $plugin->esi->readPayload($payload) : null;
        $site = $block ? Craft::$app->getSites()->getSiteById($block['siteId']) : null;

        if ($site === null) {
            return $this->emptyResponse(400);
        }

        Craft::$app->getSites()->setCurrentSite($site);

        try {
            $rendered = $plugin->esi->render($block);
        } catch (Throwable $e) {
            Craft::error("Couldn’t render the ESI block {$block['template']}: {$e->getMessage()}", 'litespeed');

            return $this->emptyResponse(500);
        }

        $response = $this->asRaw($rendered['html']);
        $response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');
        $plugin->cache->prepareBlockResponse($response, $block['cache'], $block['ttl'], $rendered['tags'], $rendered['duration']);

        return $response;
    }

    protected function emptyResponse(int $status): Response
    {
        $response = $this->asRaw('');
        $response->setStatusCode($status);
        $response->getHeaders()->set(Header::CACHE_CONTROL->value, EsiCache::NO_CACHE->value);

        return $response;
    }
}
