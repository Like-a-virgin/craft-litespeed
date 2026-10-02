<?php

namespace GlueAgency\LiteSpeed\Tests;

use craft\base\ElementInterface;
use GlueAgency\LiteSpeed\enums\EsiCache;
use GlueAgency\LiteSpeed\helpers\Esi;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

class EsiTest extends TestCase
{
    public function testElementsTravelAsClassAndIdAndEverythingElseAsIs(): void
    {
        $entry = $this->element(42);

        $dehydrated = Esi::dehydrate([
            'entry'   => $entry,
            'mobile'  => true,
            'title'   => 'Mijn Aquaduin',
            'count'   => 3,
            'nothing' => null,
            'ids'     => [1, 2, ['nested' => 'yes']],
        ]);

        $this->assertSame([
            'vars' => [
                'mobile'  => true,
                'title'   => 'Mijn Aquaduin',
                'count'   => 3,
                'nothing' => null,
                'ids'     => [1, 2, ['nested' => 'yes']],
            ],
            'elements' => [
                'entry' => [$entry::class, 42],
            ],
        ], $dehydrated);
    }

    /**
     * An array that happens to look like an element reference stays a plain var.
     */
    public function testAnArrayIsNeverMistakenForAnElement(): void
    {
        $vars = ['ref' => ['craft\\elements\\Entry', 42]];

        $this->assertSame(['vars' => $vars, 'elements' => []], Esi::dehydrate($vars));
    }

    public static function unsupportedVars(): array
    {
        return [
            'object'          => [new stdClass()],
            'object in array' => [[1, new stdClass()]],
            'closure'         => [fn() => 1],
        ];
    }

    #[DataProvider('unsupportedVars')]
    public function testAnythingElseIsRefused(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Esi::dehydrate(['value' => $value]);
    }

    public function testAnUnsavedElementIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Esi::dehydrate(['entry' => $this->element(null)]);
    }

    public function testHydratingLoadsTheElementsAgain(): void
    {
        $entry = $this->element(42);
        $dehydrated = Esi::dehydrate(['entry' => $entry, 'mobile' => false]);

        $vars = Esi::hydrate($dehydrated['vars'], $dehydrated['elements'], function(string $class, int $id) use ($entry) {
            if ($class === $entry::class && $id === 42) {
                return $entry;
            }

            return null;
        });

        $this->assertSame(['mobile' => false, 'entry' => $entry], $vars);
    }

    public function testAnElementThatIsGoneHydratesAsNull(): void
    {
        $vars = Esi::hydrate([], ['entry' => ['craft\\elements\\Entry', 42]], fn() => null);

        $this->assertSame(['entry' => null], $vars);
    }

    public function testTheIncludeTagEncodesItsSource(): void
    {
        $this->assertSame(
            '<esi:include src="/nl/actions/litespeed/esi/render?payload=a%22b&amp;x=&quot;y" cache-control="private" />',
            Esi::includeTag('/nl/actions/litespeed/esi/render?payload=a%22b&x="y', EsiCache::PRIVATE),
        );
    }

    public function testTheIncludeTagLooksTheBlockUpInItsOwnCache(): void
    {
        $this->assertSame('<esi:include src="/b" cache-control="public" />', Esi::includeTag('/b', EsiCache::PUBLIC));
        $this->assertSame('<esi:include src="/b" />', Esi::includeTag('/b', EsiCache::NO_CACHE));
    }

    public static function cacheControls(): array
    {
        return [
            'private'          => [EsiCache::PRIVATE, 300, 'private,max-age=300'],
            'public'           => [EsiCache::PUBLIC, 86400, 'public,max-age=86400'],
            'no-cache'         => [EsiCache::NO_CACHE, 300, 'no-cache'],
            'private, no ttl'  => [EsiCache::PRIVATE, 0, 'no-cache'],
            'public, negative' => [EsiCache::PUBLIC, -1, 'no-cache'],
        ];
    }

    #[DataProvider('cacheControls')]
    public function testTheBlockCacheControl(EsiCache $cache, int $ttl, string $expected): void
    {
        $this->assertSame($expected, $cache->cacheControl($ttl));
    }

    public static function lscacheValues(): array
    {
        return [
            'enterprise' => ['on,crawler,esi,combine', true],
            'upper case' => ['ON,ESI', true],
            'no esi'     => ['on,crawler', false],
            'empty'      => ['', false],
            'not set'    => [null, false],
        ];
    }

    #[DataProvider('lscacheValues')]
    public function testItReadsEsiSupportFromLiteSpeed(?string $value, bool $supported): void
    {
        $this->assertSame($supported, Esi::isSupported($value));
    }

    public static function noCacheDecisions(): array
    {
        return [
            'esi, private'               => [EsiCache::PRIVATE, true, false, false],
            'esi, no-cache'              => [EsiCache::NO_CACHE, true, false, false],
            'inline, private'            => [EsiCache::PRIVATE, false, false, true],
            'inline, no-cache'           => [EsiCache::NO_CACHE, false, false, true],
            'inline, public'             => [EsiCache::PUBLIC, false, false, false],
            'inline, private, fallback'  => [EsiCache::PRIVATE, false, true, false],
            'inline, no-cache, fallback' => [EsiCache::NO_CACHE, false, true, false],
        ];
    }

    #[DataProvider('noCacheDecisions')]
    public function testOnlyAPersonalBlockRenderedInlineWithoutFallbackKeepsThePageOutOfTheCache(EsiCache $cache, bool $available, bool $hasFallback, bool $expected): void
    {
        $this->assertSame($expected, Esi::forcesNoCache($cache, $available, $hasFallback));
    }

    protected function element(?int $id): ElementInterface
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getId')->willReturn($id);

        return $element;
    }
}
