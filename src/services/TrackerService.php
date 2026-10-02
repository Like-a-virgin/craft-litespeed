<?php

namespace GlueAgency\LiteSpeed\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use GlueAgency\LiteSpeed\db\Table;
use GlueAgency\LiteSpeed\helpers\Pages;
use GlueAgency\LiteSpeed\helpers\Tags;
use GlueAgency\LiteSpeed\LiteSpeed;
use Throwable;

/**
 * Keeps the plugin's own record of the pages it handed to LiteSpeed to cache, and forgets them when they're
 * purged or expire.
 *
 * LiteSpeed can't list what it holds, and can drop a page without telling Craft, so this is what the plugin sent,
 * not what LiteSpeed has.
 */
class TrackerService extends Component
{
    protected const CHUNK_SIZE = 500;

    /**
     * The page this response hands to LiteSpeed, recorded once the response has been sent.
     *
     * @var array{siteId: int, url: string, variant: string, expiryDate: DateTime, tags: string[]}|null
     */
    protected ?array $pending = null;

    /**
     * @param string[] $tags
     */
    public function remember(int $siteId, string $url, string $variant, int $maxAge, array $tags): void
    {
        if (LiteSpeed::getInstance()->esi->isBlockRequest()) {
            return;
        }

        $this->pending = [
            'siteId'     => $siteId,
            'url'        => $url,
            'variant'    => $variant,
            'expiryDate' => new DateTime("+{$maxAge} seconds"),
            'tags'       => $tags,
        ];
    }

    public function record(): void
    {
        if ($this->pending === null) {
            return;
        }

        $page = $this->pending;
        $this->pending = null;

        try {
            Craft::$app->getDb()->transaction(function() use ($page) {
                $key = Pages::key($page['url'], $page['variant']);

                Db::upsert(Table::PAGES, [
                    'siteId'     => $page['siteId'],
                    'url'        => $page['url'],
                    'variant'    => $page['variant'],
                    'key'        => $key,
                    'expiryDate' => Db::prepareDateForDb($page['expiryDate']),
                ]);

                $pageId = (int) (new Query())
                    ->select(['id'])
                    ->from(Table::PAGES)
                    ->where(['key' => $key])
                    ->scalar();

                Db::delete(Table::PAGETAGS, ['pageId' => $pageId]);
                Db::batchInsert(Table::PAGETAGS, ['pageId', 'tag'], array_map(fn(string $tag) => [$pageId, $tag], $page['tags']));
            });
        } catch (Throwable $e) {
            Craft::warning("Couldn’t record {$page['url']} as sent to LiteSpeed: {$e->getMessage()}", 'litespeed');
        }
    }

    /**
     * Forgets the pages a public purge reaches. Private purges never reach a recorded page.
     *
     * @param string[] $tags LiteSpeed tags, already prefixed.
     */
    public function forget(array $tags, bool $everything = false): void
    {
        try {
            if ($everything || in_array(Tags::install(LiteSpeed::getInstance()->getSettings()->getPrefix()), $tags, true)) {
                $this->forgetAll();

                return;
            }

            foreach (array_chunk(array_values(array_unique($tags)), self::CHUNK_SIZE) as $chunk) {
                Db::delete(Table::PAGES, [
                    'id' => (new Query())
                        ->select(['pageId'])
                        ->from(Table::PAGETAGS)
                        ->where(['tag' => $chunk]),
                ]);
            }
        } catch (Throwable $e) {
            Craft::warning("Couldn’t forget the purged pages: {$e->getMessage()}", 'litespeed');
        }
    }

    public function deleteExpired(): void
    {
        try {
            Db::delete(Table::PAGES, ['<=', 'expiryDate', Db::prepareDateForDb(new DateTime())]);
        } catch (Throwable $e) {
            Craft::warning("Couldn’t delete the expired pages: {$e->getMessage()}", 'litespeed');
        }
    }

    /**
     * Counts the pages that haven't expired, per site and in total.
     *
     * @return array{total: array{pages: int, urls: int, variants: int}, sites: array<int, array{pages: int, urls: int, variants: int}>}
     */
    public function stats(): array
    {
        $rows = (new Query())
            ->select([
                'siteId',
                'pages'    => 'COUNT(*)',
                'urls'     => 'COUNT(DISTINCT [[url]])',
                'variants' => "SUM(CASE WHEN [[variant]] = '' THEN 0 ELSE 1 END)",
            ])
            ->from(Table::PAGES)
            ->where($this->unexpired())
            ->groupBy(['siteId'])
            ->all();

        $stats = [
            'total' => ['pages' => 0, 'urls' => 0, 'variants' => 0],
            'sites' => [],
        ];

        foreach ($rows as $row) {
            $counts = [
                'pages'    => (int) $row['pages'],
                'urls'     => (int) $row['urls'],
                'variants' => (int) $row['variants'],
            ];

            $stats['sites'][(int) $row['siteId']] = $counts;

            foreach ($counts as $name => $count) {
                $stats['total'][$name] += $count;
            }
        }

        return $stats;
    }

    /**
     * Finds the pages that haven't expired and whose URL contains `$query`, most recently cached first.
     *
     * @return array<array{siteId: int, url: string, variant: string, dateUpdated: DateTime, expiryDate: DateTime}>
     */
    public function search(string $query, int $limit): array
    {
        $rows = (new Query())
            ->select(['siteId', 'url', 'variant', 'dateUpdated', 'expiryDate'])
            ->from(Table::PAGES)
            ->where(['like', 'url', $query])
            ->andWhere($this->unexpired())
            ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        $pages = [];

        foreach ($rows as $row) {
            $pages[] = [
                'siteId'      => (int) $row['siteId'],
                'url'         => $row['url'],
                'variant'     => $row['variant'],
                'dateUpdated' => DateTimeHelper::toDateTime($row['dateUpdated']),
                'expiryDate'  => DateTimeHelper::toDateTime($row['expiryDate']),
            ];
        }

        return $pages;
    }

    /**
     * MySQL won't truncate a table another table's foreign key points to, so only the tags are truncated.
     */
    protected function forgetAll(): void
    {
        Db::truncateTable(Table::PAGETAGS);
        Db::delete(Table::PAGES);
    }

    /**
     * @return array<mixed>
     */
    protected function unexpired(): array
    {
        return ['>', 'expiryDate', Db::prepareDateForDb(new DateTime())];
    }
}
