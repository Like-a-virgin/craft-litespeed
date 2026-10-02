<?php

namespace GlueAgency\LiteSpeed\Tests;

use craft\elements\Asset;
use craft\elements\Entry;
use GlueAgency\LiteSpeed\helpers\Tags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TagsTest extends TestCase
{
    protected const PREFIX = 'ab12cd';

    public static function craftTags(): array
    {
        return [
            'all elements'      => ['element', 'ab12cd'],
            'element id'        => ['element::42', 'ab12cd_id.42'],
            'element type'      => ['element::' . Entry::class, 'ab12cd_E'],
            'wildcard'          => ['element::' . Entry::class . '::*', 'ab12cd_E.all'],
            'section'           => ['element::' . Entry::class . '::section:5', 'ab12cd_E.section.5'],
            'entry type'        => ['element::' . Entry::class . '::entryType:3', 'ab12cd_E.entryType.3'],
            'drafts'            => ['element::' . Entry::class . '::drafts', 'ab12cd_E.drafts'],
            'asset volume'      => ['element::' . Asset::class . '::volume:2', 'ab12cd_A.volume.2'],
            'leading backslash' => ['element::\\' . Entry::class, 'ab12cd_E'],
            'unsafe characters' => ['element::' . Entry::class . '::field:1,"x y"', 'ab12cd_E.field.1..x.y.'],
            'unknown element'   => ['element::modules\\Product::*', 'ab12cd_x' . substr(md5('modules\\Product'), 0, 6) . '.all'],
            'non-element tag'   => ['graphql', 'ab12cd_h.' . substr(md5('graphql'), 0, 8)],
        ];
    }

    #[DataProvider('craftTags')]
    public function testItMapsCraftTags(string $craftTag, string $expected): void
    {
        $this->assertSame($expected, Tags::fromCraftTag(self::PREFIX, $craftTag));
    }

    /**
     * LiteSpeed reads `*` in a purge as "everything", and `:` after `public` as a scope.
     */
    public function testMappedTagsOnlyUseSafeCharacters(): void
    {
        foreach (self::craftTags() as [$craftTag]) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+$/', Tags::fromCraftTag(self::PREFIX, $craftTag));
        }
    }

    public function testItDeduplicatesMappedTags(): void
    {
        $tags = Tags::fromCraftTags(self::PREFIX, ['element::1', 'element::1', 'element::\\' . Entry::class, 'element::' . Entry::class]);

        $this->assertSame(['ab12cd_id.1', 'ab12cd_E'], $tags);
    }

    public function testCustomTagsAreSanitised(): void
    {
        $this->assertSame('ab12cd_c.news', Tags::custom(self::PREFIX, ' news '));
        $this->assertSame('ab12cd_c.footer.nl', Tags::custom(self::PREFIX, 'footer:nl'));
        $this->assertNull(Tags::custom(self::PREFIX, '  '));
        $this->assertNull(Tags::custom(self::PREFIX, '*'));
    }

    public function testUrlTagsIgnoreSlashesCaseOfHostAndEncoding(): void
    {
        $tag = Tags::url(self::PREFIX, 'example.com', '/nl/nieuws');

        $this->assertSame($tag, Tags::url(self::PREFIX, 'EXAMPLE.com', 'nl/nieuws/'));
        $this->assertSame(Tags::url(self::PREFIX, 'example.com', '/café'), Tags::url(self::PREFIX, 'example.com', '/caf%C3%A9'));
        $this->assertNotSame($tag, Tags::url(self::PREFIX, 'example.org', '/nl/nieuws'));
        $this->assertMatchesRegularExpression('/^ab12cd_url\.[0-9a-f]{12}$/', $tag);
    }

    public function testAPageCarriesATreeTagForItselfAndEachParent(): void
    {
        $tags = Tags::forPage(self::PREFIX, 'example.com', '/nl/nieuws/artikel');

        $this->assertSame([
            Tags::url(self::PREFIX, 'example.com', '/nl/nieuws/artikel'),
            Tags::tree(self::PREFIX, 'example.com', '/'),
            Tags::tree(self::PREFIX, 'example.com', '/nl'),
            Tags::tree(self::PREFIX, 'example.com', '/nl/nieuws'),
            Tags::tree(self::PREFIX, 'example.com', '/nl/nieuws/artikel'),
        ], $tags);
    }

    public function testPurgingAUrlTreeReachesTheSubpagesOnly(): void
    {
        $subpage = Tags::forPage(self::PREFIX, 'example.com', '/nl/nieuws/artikel');
        $sibling = Tags::forPage(self::PREFIX, 'example.com', '/nl/agenda');

        $tree = Tags::tree(self::PREFIX, 'example.com', '/nl/nieuws');

        $this->assertContains($tree, $subpage);
        $this->assertNotContains($tree, $sibling);
    }

    public function testTheHomepageHasAUrlTagAndTheRootTreeTag(): void
    {
        $this->assertSame([
            Tags::url(self::PREFIX, 'example.com', '/'),
            Tags::tree(self::PREFIX, 'example.com', ''),
        ], Tags::forPage(self::PREFIX, 'example.com', '/'));
    }

    public function testTheCacheHeaderStartsWithTheInstallTag(): void
    {
        $header = Tags::cacheHeader(self::PREFIX, ['element', 'element::7', 'element::' . Entry::class], ['ab12cd_url.x'], 8000);

        $this->assertSame('ab12cd,ab12cd_url.x,ab12cd_id.7,ab12cd_E', $header);
    }

    /**
     * Every element save purges its type's wildcard tag, so a page that swaps its id tags for that wildcard is
     * still purged by each change it was purged by before.
     */
    public function testAnOverflowingCacheHeaderTradesIdTagsForTypeWildcards(): void
    {
        $craftTags = ['element', 'element::' . Entry::class, 'element::' . Asset::class, 'element::' . Entry::class . '::section:1'];

        for ($id = 1; $id <= 500; $id++) {
            $craftTags[] = "element::{$id}";
        }

        $header = Tags::cacheHeader(self::PREFIX, $craftTags, [], 200);

        $this->assertSame('ab12cd,ab12cd_E,ab12cd_E.all,ab12cd_A,ab12cd_A.all,ab12cd_E.section.1', $header);
    }

    public function testACacheHeaderThatCannotFitIsRefused(): void
    {
        $this->assertNull(Tags::cacheHeader(self::PREFIX, ['element::' . Entry::class], [], 10));
    }

    public function testThePurgeHeaderListsEachTag(): void
    {
        $this->assertSame(
            'public,tag=ab12cd_id.1,tag=ab12cd_E.all',
            Tags::purgeHeader(['ab12cd_id.1', 'ab12cd_E.all', 'ab12cd_id.1']),
        );
    }

    public function testAStalePurgeServesTheOldCopyWhileRegenerating(): void
    {
        $this->assertSame('public,stale,tag=ab12cd_id.1', Tags::purgeHeader(['ab12cd_id.1'], stale: true));
    }

    public function testEmptyingEverythingIsTheBareWildcard(): void
    {
        $this->assertSame('*', Tags::purgeHeader(['ab12cd_id.1'], true));
    }

    public function testAPrivatePurgeFollowsThePublicPart(): void
    {
        $this->assertSame('public,tag=ab12cd_id.1,tag=ab12cd_E.all;private,*', Tags::purgeHeader(['ab12cd_id.1', 'ab12cd_E.all'], private: true));
        $this->assertSame('public,stale,tag=ab12cd_id.1;private,*', Tags::purgeHeader(['ab12cd_id.1'], stale: true, private: true));
        $this->assertSame('*;private,*', Tags::purgeHeader([], true, private: true));
    }

    public function testAPrivatePurgeCanGoOutOnItsOwn(): void
    {
        $this->assertSame('private,*', Tags::purgeHeader([], private: true));
    }

    /**
     * The private part goes out with the first chunk only, so only that one has to leave room for it.
     */
    public function testTheFirstChunkKeepsRoomForThePrivatePurge(): void
    {
        $tags = array_map(fn(int $id) => "ab12cd_id.{$id}", range(1, 2000));
        $chunks = Tags::purgeChunks(self::PREFIX, $tags, 8000, private: true);

        $this->assertSame($tags, array_merge(...$chunks));
        $this->assertLessThanOrEqual(8000, strlen(Tags::purgeHeader($chunks[0], private: true)));
        $this->assertGreaterThan(8000 - 30, strlen(Tags::purgeHeader($chunks[1])));

        foreach (array_slice($chunks, 1) as $chunk) {
            $this->assertLessThanOrEqual(8000, strlen(Tags::purgeHeader($chunk)));
        }
    }

    public function testNothingToPurgeSendsNoHeader(): void
    {
        $this->assertNull(Tags::purgeHeader([]));
        $this->assertSame([], Tags::purgeChunks(self::PREFIX, [], 8000));
    }

    public function testPurgingTheInstallTagSupersedesOtherTags(): void
    {
        $this->assertSame([['ab12cd']], Tags::purgeChunks(self::PREFIX, ['ab12cd_id.1', 'ab12cd'], 8000));
    }

    public function testAPurgeThatFitsIsOneChunk(): void
    {
        $this->assertSame([['ab12cd_id.1', 'ab12cd_E.all']], Tags::purgeChunks(self::PREFIX, ['ab12cd_id.1', 'ab12cd_E.all', 'ab12cd_id.1'], 8000));
    }

    public function testALongPurgeIsSplitIntoChunksThatEachFit(): void
    {
        $tags = array_map(fn(int $id) => "ab12cd_id.{$id}", range(1, 2000));

        foreach ([false, true] as $stale) {
            $chunks = Tags::purgeChunks(self::PREFIX, $tags, 8000, $stale);

            $this->assertGreaterThan(1, count($chunks));
            $this->assertSame($tags, array_merge(...$chunks));

            foreach ($chunks as $chunk) {
                $this->assertLessThanOrEqual(8000, strlen(Tags::purgeHeader($chunk, stale: $stale)));
            }
        }
    }
}
