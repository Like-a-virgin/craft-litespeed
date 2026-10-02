<?php

namespace GlueAgency\LiteSpeed\enums;

/**
 * How LiteSpeed caches an ESI block: per visitor, shared, or not at all.
 */
enum EsiCache: string
{
    case PRIVATE = 'private';
    case PUBLIC = 'public';
    case NO_CACHE = 'no-cache';

    /**
     * The block response's `X-LiteSpeed-Cache-Control`.
     */
    public function cacheControl(int $ttl): string
    {
        if ($this === self::NO_CACHE || $ttl <= 0) {
            return self::NO_CACHE->value;
        }

        return "{$this->value},max-age={$ttl}";
    }
}
