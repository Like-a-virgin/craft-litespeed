<?php

namespace GlueAgency\LiteSpeed\helpers;

use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\ContentBlock;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;

/**
 * Maps Craft's element cache tags onto LiteSpeed tags, and builds the tag and purge headers.
 *
 * Craft tags contain `\` and `:`, and LiteSpeed reserves `public:`, so every tag is rewritten into a short
 * `<prefix>_<token>` form. Tagging and purging go through the same mapping, which is what makes them meet.
 */
class Tags
{
    protected const PRIVATE_PURGE = 'private,*';

    protected const ALIASES = [
        Entry::class        => 'E',
        Asset::class        => 'A',
        Category::class     => 'C',
        User::class         => 'U',
        GlobalSet::class    => 'G',
        Tag::class          => 'T',
        Address::class      => 'Ad',
        ContentBlock::class => 'CB',
    ];

    public static function install(string $prefix): string
    {
        return $prefix;
    }

    public static function fromCraftTag(string $prefix, string $tag): string
    {
        if ($tag === 'element') {
            return self::install($prefix);
        }

        if (! str_starts_with($tag, 'element::')) {
            return self::token($prefix, 'h.' . substr(md5($tag), 0, 8));
        }

        $rest = substr($tag, strlen('element::'));

        if (ctype_digit($rest)) {
            return self::token($prefix, 'id.' . $rest);
        }

        $parts = explode('::', $rest, 2);
        $alias = self::alias($parts[0]);

        if (! isset($parts[1])) {
            return self::token($prefix, $alias);
        }

        return self::token($prefix, $alias . '.' . self::qualifier($parts[1]));
    }

    /**
     * @param string[] $tags
     * @return string[]
     */
    public static function fromCraftTags(string $prefix, array $tags): array
    {
        return array_values(array_unique(array_map(fn(string $tag) => self::fromCraftTag($prefix, $tag), $tags)));
    }

    public static function custom(string $prefix, string $tag): ?string
    {
        $name = trim(self::sanitize(trim($tag)), '.');

        if ($name === '') {
            return null;
        }

        return self::token($prefix, 'c.' . $name);
    }

    public static function url(string $prefix, string $host, string $path): string
    {
        return self::token($prefix, 'url.' . self::hash($host, self::segments($path)));
    }

    /**
     * The tag a page carries for its own path and for each of its parent paths, used to purge a URL and
     * everything below it.
     */
    public static function tree(string $prefix, string $host, string $path): string
    {
        return self::token($prefix, 'tree.' . self::hash($host, self::segments($path)));
    }

    /**
     * @return string[]
     */
    public static function forPage(string $prefix, string $host, string $path): array
    {
        $segments = self::segments($path);
        $tags = [self::url($prefix, $host, $path)];

        for ($depth = 0; $depth <= count($segments); $depth++) {
            $tags[] = self::token($prefix, 'tree.' . self::hash($host, array_slice($segments, 0, $depth)));
        }

        return $tags;
    }

    /**
     * Returns the `X-LiteSpeed-Tag` value for a page, or null when the page carries more tags than fit.
     *
     * An overflowing page drops its per-element tags for the element-type wildcard tags: every element save
     * also purges its type's wildcard, so the page still gets purged, only more often.
     *
     * @param string[] $craftTags
     * @param string[] $pageTags
     */
    public static function cacheHeader(string $prefix, array $craftTags, array $pageTags, int $maxLength): ?string
    {
        $value = self::join($prefix, $craftTags, $pageTags);

        if (strlen($value) <= $maxLength) {
            return $value;
        }

        $collapsed = [];

        foreach ($craftTags as $tag) {
            if (preg_match('/^element::\d+$/', $tag)) {
                continue;
            }

            $collapsed[] = $tag;

            if (preg_match('/^element::[^:]+$/', $tag)) {
                $collapsed[] = $tag . '::*';
            }
        }

        $value = self::join($prefix, $collapsed, $pageTags);

        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return null;
    }

    /**
     * Splits a purge into groups of tags whose `X-LiteSpeed-Purge` value stays within `$maxLength`. Each group has
     * to go out on a response of its own. Purging the install tag supersedes any other tag. A private purge rides
     * along on the first group, so that one keeps room for it.
     *
     * @param string[] $tags
     * @return string[][]
     */
    public static function purgeChunks(string $prefix, array $tags, int $maxLength, bool $stale = false, bool $private = false): array
    {
        $tags = array_values(array_unique($tags));

        if (empty($tags)) {
            return [];
        }

        $install = self::install($prefix);

        if (in_array($install, $tags, true)) {
            return [[$install]];
        }

        $chunks = [];
        $chunk = [];
        $length = strlen(self::purgeValue([], $stale)) + ($private ? strlen(';' . self::PRIVATE_PURGE) : 0);

        foreach ($tags as $tag) {
            $tagLength = strlen(',tag=' . $tag);

            if (! empty($chunk) && $length + $tagLength > $maxLength) {
                $chunks[] = $chunk;
                $chunk = [];
                $length = strlen(self::purgeValue([], $stale));
            }

            $chunk[] = $tag;
            $length += $tagLength;
        }

        $chunks[] = $chunk;

        return $chunks;
    }

    /**
     * Returns the `X-LiteSpeed-Purge` value, or null when there's nothing to purge. A private purge empties the
     * private cache of the visitor whose request gets the response, and of nobody else.
     *
     * @param string[] $tags
     */
    public static function purgeHeader(array $tags, bool $everything = false, bool $stale = false, bool $private = false): ?string
    {
        $parts = [];

        if ($everything) {
            $parts[] = '*';
        } elseif (! empty($tags)) {
            $parts[] = self::purgeValue(array_values(array_unique($tags)), $stale);
        }

        if ($private) {
            $parts[] = self::PRIVATE_PURGE;
        }

        if (empty($parts)) {
            return null;
        }

        return implode(';', $parts);
    }

    /**
     * @param string[] $craftTags
     * @param string[] $pageTags
     */
    protected static function join(string $prefix, array $craftTags, array $pageTags): string
    {
        $tags = array_merge([self::install($prefix)], $pageTags, self::fromCraftTags($prefix, $craftTags));

        return implode(',', array_values(array_unique($tags)));
    }

    /**
     * @param string[] $tags
     */
    protected static function purgeValue(array $tags, bool $stale): string
    {
        $parts = ['public'];

        if ($stale) {
            $parts[] = 'stale';
        }

        foreach ($tags as $tag) {
            $parts[] = 'tag=' . $tag;
        }

        return implode(',', $parts);
    }

    protected static function alias(string $class): string
    {
        $class = ltrim($class, '\\');

        return self::ALIASES[$class] ?? 'x' . substr(md5($class), 0, 6);
    }

    protected static function qualifier(string $qualifier): string
    {
        if ($qualifier === '*') {
            return 'all';
        }

        return self::sanitize(str_replace(':', '.', $qualifier));
    }

    protected static function sanitize(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '.', $value);
    }

    /**
     * @return string[]
     */
    protected static function segments(string $path): array
    {
        $path = trim(rawurldecode($path), '/');

        if ($path === '') {
            return [];
        }

        return explode('/', $path);
    }

    /**
     * @param string[] $segments
     */
    protected static function hash(string $host, array $segments): string
    {
        return substr(md5(strtolower($host) . '/' . implode('/', $segments)), 0, 12);
    }

    protected static function token(string $prefix, string $name): string
    {
        return $prefix . '_' . $name;
    }
}
