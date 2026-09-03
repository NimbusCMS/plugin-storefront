<?php

declare(strict_types=1);

namespace NimbusCMS\Storefront;

use Nimbus\Site\PageContext;
use Nimbus\Site\ViewDataContributor;
use NimbusCMS\Inventory\CatalogReadPort;

/**
 * Feeds a handful of **featured products** into the themed home page (ADR 0027),
 * so a storefront landing can show live catalog items instead of static tiles. The
 * body-data counterpart to the storefront's page sections.
 *
 * Visitor-independent and cache-safe by construction: it reads only the public
 * {@see CatalogReadPort} (active items, coarse availability — never per-visitor
 * data), and the data is escaped on render by the theme. It contributes only on
 * the **home** page and caps the list, so it can't bloat the page cache.
 */
final class StorefrontViewData implements ViewDataContributor
{
    /** At most this many featured items — "a handful, not a feed" (ADR 0027). */
    private const LIMIT = 6;

    /** @param \Closure():?CatalogReadPort $port resolved per request; null when Inventory is absent */
    public function __construct(private \Closure $port)
    {
    }

    /**
     * @return array{featured:list<array<string,mixed>>}|array{}
     */
    public function data(PageContext $page): array
    {
        // Only the landing page wants a featured row; skip the query elsewhere.
        if ($page->kind !== 'home') {
            return [];
        }
        $port = ($this->port)();
        if ($port === null) {
            return [];
        }

        // Featured-first, then keep only genuinely featured items (the sort floats
        // them up but does not filter), capped to a handful.
        $featured = [];
        foreach ($port->list(['sort' => 'featured', 'page' => 1])['items'] as $item) {
            if ($item['featured'] === true) {
                $featured[] = $item;
                if (count($featured) >= self::LIMIT) {
                    break;
                }
            }
        }

        return $featured === [] ? [] : ['featured' => $featured];
    }
}
