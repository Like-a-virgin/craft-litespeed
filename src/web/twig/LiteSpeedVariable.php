<?php

namespace GlueAgency\LiteSpeed\web\twig;

use GlueAgency\LiteSpeed\LiteSpeed;
use InvalidArgumentException;
use Twig\Markup;

/**
 * `craft.litespeed` in templates.
 */
class LiteSpeedVariable
{
    public function noCache(): void
    {
        LiteSpeed::getInstance()->cache->noCache();
    }

    public function ttl(int $seconds): void
    {
        LiteSpeed::getInstance()->cache->setTtl($seconds);
    }

    /**
     * @param string|string[] $tags
     */
    public function tag(string|array $tags): void
    {
        LiteSpeed::getInstance()->cache->addTags($tags);
    }

    public function varyCookie(string $name): void
    {
        LiteSpeed::getInstance()->cache->addVaryCookie($name);
    }

    /**
     * Includes a template as an ESI block where LiteSpeed supports it, and inline otherwise.
     *
     * @param array<string, mixed> $vars
     * @param array{cache?: string, ttl?: int, fallback?: string} $options
     * @throws InvalidArgumentException
     */
    public function esi(string $template, array $vars = [], array $options = []): Markup
    {
        return LiteSpeed::getInstance()->esi->block($template, $vars, $options);
    }
}
