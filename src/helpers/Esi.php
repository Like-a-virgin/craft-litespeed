<?php

namespace GlueAgency\LiteSpeed\helpers;

use craft\base\ElementInterface;
use craft\helpers\Html;
use GlueAgency\LiteSpeed\enums\EsiCache;
use InvalidArgumentException;

/**
 * Builds ESI blocks: the vars that travel in the block's URL, and the include tag that points at it.
 */
class Esi
{
    /**
     * Splits template vars into values that travel as they are, and elements, which travel as class and ID and are
     * loaded again in the block request.
     *
     * @param array<string, mixed> $vars
     * @return array{vars: array<string, mixed>, elements: array<string, array{string, int}>}
     * @throws InvalidArgumentException when a var is neither an element, a scalar nor an array of scalars.
     */
    public static function dehydrate(array $vars): array
    {
        $values = [];
        $elements = [];

        foreach ($vars as $name => $value) {
            if ($value instanceof ElementInterface) {
                $id = $value->getId();

                if ($id === null) {
                    throw new InvalidArgumentException("The ESI var “{$name}” is an element without an ID.");
                }

                $elements[$name] = [$value::class, $id];

                continue;
            }

            if (! self::isPlain($value)) {
                throw new InvalidArgumentException("The ESI var “{$name}” must be an element, a scalar or an array of scalars.");
            }

            $values[$name] = $value;
        }

        return [
            'vars'     => $values,
            'elements' => $elements,
        ];
    }

    /**
     * Puts the elements back among the vars. An element `$find` can't load any more becomes null.
     *
     * @param array<string, mixed> $vars
     * @param array<string, array{string, int}> $elements
     * @param callable(string, int): ?ElementInterface $find
     * @return array<string, mixed>
     */
    public static function hydrate(array $vars, array $elements, callable $find): array
    {
        foreach ($elements as $name => [$class, $id]) {
            $vars[$name] = $find($class, $id);
        }

        return $vars;
    }

    /**
     * The include's `cache-control` only tells LiteSpeed where to look the block up. How long the block is kept is
     * up to the block's own response.
     */
    public static function includeTag(string $src, EsiCache $cache): string
    {
        $lookup = $cache === EsiCache::NO_CACHE ? '' : ' cache-control="' . $cache->value . '"';

        return '<esi:include src="' . Html::encode($src) . '"' . $lookup . ' />';
    }

    /**
     * Whether LiteSpeed's `X-LSCACHE` server variable reports ESI support.
     */
    public static function isSupported(?string $lscache): bool
    {
        return $lscache !== null && str_contains(strtolower($lscache), 'esi');
    }

    /**
     * Whether rendering a block inline has to keep the page out of the cache. A fallback template is declared
     * cache-safe by whoever passes it.
     */
    public static function forcesNoCache(EsiCache $cache, bool $usesFallback): bool
    {
        return ! $usesFallback && $cache !== EsiCache::PUBLIC;
    }

    protected static function isPlain(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! self::isPlain($item)) {
                return false;
            }
        }

        return true;
    }
}
