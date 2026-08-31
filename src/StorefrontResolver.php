<?php

declare(strict_types=1);

namespace NimbusCMS\Storefront;

use Nimbus\Http\Request;
use Nimbus\Site\PageView;
use NimbusCMS\Inventory\CatalogReadPort;

/**
 * Turns a request under `/shop` into a {@see PageView} the theme renders (ADR
 * 0023), reading the catalog through Inventory's {@see CatalogReadPort} (ADR 0019)
 * — never its tables. `/shop` is the listing (category / search / sort /
 * pagination over active items, coarse availability); `/shop/{sku}` is a product
 * page. An unknown or inactive SKU, or a missing inventory plugin on a product
 * URL, resolves to null → the themed 404, so a hidden product is indistinguishable
 * from a missing one.
 *
 * The port does all validation (allow-listed sort, bound search, active-only); this
 * only shapes the request into filters and the result into a view-model. Every
 * value stays raw here and is escaped by the template on render.
 */
final class StorefrontResolver
{
    /** The handle this section is mounted at, and its URL prefix. */
    private const HANDLE = 'shop';

    /** @param \Closure():?CatalogReadPort $port resolved per request; null when Inventory is absent */
    public function __construct(private \Closure $port)
    {
    }

    public function __invoke(Request $request): ?PageView
    {
        $sku = $this->skuFromPath($request->path);
        return $sku === null ? $this->listing($request) : $this->product($sku);
    }

    /** The listing at `/shop` — filters from the query, always a page (never 404). */
    private function listing(Request $request): PageView
    {
        $port    = ($this->port)();
        $filters = [
            'category' => $request->query('category'),
            'q'        => $request->query('q'),
            'sort'     => $request->query('sort'),
            'page'     => max(1, (int) ($request->query('page') ?? 1)),
        ];

        $result = $port?->list($filters) ?? ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 0, 'pages' => 0];
        $categories = $port?->categories() ?? [];

        return new PageView('shop-index', [
            'items'      => $result['items'],
            'total'      => $result['total'],
            'page'       => $result['page'],
            'pages'      => $result['pages'],
            'categories' => $categories,
            // The current filter values, so the form and pagination can reflect them.
            'current'    => [
                'category' => is_string($filters['category']) ? $filters['category'] : '',
                'q'        => is_string($filters['q']) ? $filters['q'] : '',
                'sort'     => is_string($filters['sort']) ? $filters['sort'] : '',
            ],
            'available'  => $port !== null,
        ], ['title' => 'Shop', 'description' => 'Browse our range.']);
    }

    /** A product page at `/shop/{sku}`, or null (→ themed 404) when not found/active. */
    private function product(string $sku): ?PageView
    {
        $item = (($this->port)())?->get($sku);
        if ($item === null) {
            return null;
        }
        return new PageView('shop-product', [
            'item' => $item,
        ], ['title' => $item['name'], 'description' => $item['description'] ?? '', 'og_type' => 'product']);
    }

    /**
     * The SKU from a `/shop/{sku}` path, or null for the bare `/shop` listing. The
     * first sub-segment only; percent-decoded so a readable code round-trips.
     */
    private function skuFromPath(string $path): ?string
    {
        $prefix = '/' . self::HANDLE;
        if ($path === $prefix || $path === $prefix . '/') {
            return null;
        }
        if (!str_starts_with($path, $prefix . '/')) {
            return null; // not ours (shouldn't happen — the route is scoped)
        }
        $rest    = substr($path, strlen($prefix) + 1);
        $segment = explode('/', $rest, 2)[0];
        $sku     = rawurldecode($segment);
        return trim($sku) === '' ? null : $sku;
    }
}
