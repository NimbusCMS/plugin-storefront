<?php

declare(strict_types=1);

namespace NimbusCMS\Storefront\Tests;

use Nimbus\Http\Request;
use Nimbus\Site\PageView;
use NimbusCMS\Inventory\CatalogReadPort;
use NimbusCMS\Storefront\StorefrontResolver;
use PHPUnit\Framework\TestCase;

/**
 * The storefront resolver (ADR 0023) — how a request under /shop becomes a
 * view-model. It reads the catalog only through {@see CatalogReadPort}, forwards
 * the query as filters, and degrades to an empty catalog (never an error) when no
 * inventory is installed. An unknown/inactive product resolves to null → the
 * themed 404.
 */
final class StorefrontResolverTest extends TestCase
{
    /** @param array<string,mixed> $query */
    private function request(string $path, array $query = []): Request
    {
        return new Request('GET', $path, $query, [], [], []);
    }

    /** A resolver over a given port (or null for "no inventory installed"). */
    private function resolver(?CatalogReadPort $port): StorefrontResolver
    {
        return new StorefrontResolver(static fn (): ?CatalogReadPort => $port);
    }

    private function fakePort(): FakeCatalogPort
    {
        return new FakeCatalogPort();
    }

    public function test_the_listing_renders_the_index_with_items_and_categories(): void
    {
        $view = ($this->resolver($this->fakePort()))($this->request('/shop'));

        self::assertInstanceOf(PageView::class, $view);
        self::assertSame('shop-index', $view->template);
        self::assertCount(1, $view->data['items']);
        self::assertSame('Dairy', $view->data['categories'][0]['name']);
        self::assertTrue($view->data['available']);
    }

    public function test_query_params_become_filters_on_the_port(): void
    {
        $port     = $this->fakePort();
        $resolver = $this->resolver($port);

        $resolver($this->request('/shop', ['category' => 'dairy', 'q' => 'milk', 'sort' => 'price_asc', 'page' => '2']));

        self::assertSame('dairy', $port->lastFilters['category']);
        self::assertSame('milk', $port->lastFilters['q']);
        self::assertSame('price_asc', $port->lastFilters['sort']);
        self::assertSame(2, $port->lastFilters['page'], 'page is a bounded int');
    }

    public function test_a_product_page_resolves_by_sku(): void
    {
        $view = ($this->resolver($this->fakePort()))($this->request('/shop/milk'));

        self::assertInstanceOf(PageView::class, $view);
        self::assertSame('shop-product', $view->template);
        self::assertSame('Milk', $view->data['item']['name']);
        self::assertSame('Milk', $view->meta['title']);
    }

    public function test_an_unknown_product_is_null_for_a_themed_404(): void
    {
        self::assertNull(($this->resolver($this->fakePort()))($this->request('/shop/nope')));
    }

    public function test_a_percent_encoded_sku_is_decoded(): void
    {
        // /shop/milk resolves; prove decoding by encoding a normal code.
        $view = ($this->resolver($this->fakePort()))($this->request('/shop/mi%6ck')); // %6c = l
        self::assertInstanceOf(PageView::class, $view);
        self::assertSame('milk', $view->data['item']['sku_code']);
    }

    public function test_without_inventory_the_listing_is_empty_not_an_error(): void
    {
        $view = ($this->resolver(null))($this->request('/shop'));

        self::assertInstanceOf(PageView::class, $view);
        self::assertSame('shop-index', $view->template);
        self::assertSame([], $view->data['items']);
        self::assertFalse($view->data['available']);
    }

    public function test_without_inventory_a_product_is_a_404(): void
    {
        self::assertNull(($this->resolver(null))($this->request('/shop/milk')));
    }

    /**
     * The `?added=` flash resolves the item's NAME through the port (active-only) —
     * it never reflects the raw query value. A real SKU yields the name; a bogus one
     * yields null (no banner), so `?added=<script>` can't be reflected.
     */
    public function test_added_flash_is_looked_up_never_reflected(): void
    {
        $view = ($this->resolver($this->fakePort()))($this->request('/shop', ['added' => 'milk']));
        self::assertSame(['sku' => 'milk', 'name' => 'Milk'], $view->data['added'], 'canonical sku + name from the catalog');

        $bogus = ($this->resolver($this->fakePort()))($this->request('/shop', ['added' => '<script>alert(1)</script>']));
        self::assertNull($bogus->data['added'], 'a non-item SKU yields no banner — the raw value is never reflected');
    }

    /** The `?notice=` code is a validated enum — an unknown/hostile value becomes null. */
    public function test_notice_is_a_validated_enum(): void
    {
        $ok = ($this->resolver($this->fakePort()))($this->request('/shop', ['notice' => 'unavailable']));
        self::assertSame('unavailable', $ok->data['notice']);

        $bad = ($this->resolver($this->fakePort()))($this->request('/shop', ['notice' => '<script>alert(1)</script>']));
        self::assertNull($bad->data['notice']);
    }

    /** The cart summary closure feeds the header pill on section pages only. */
    public function test_cart_summary_closure_is_surfaced_when_present(): void
    {
        $resolver = new StorefrontResolver(
            static fn (): CatalogReadPort => new FakeCatalogPort(),
            null,
            static fn (Request $r): array => ['count' => 4, 'total' => '9.99'],
        );
        $view = $resolver($this->request('/shop'));
        self::assertSame(['count' => 4, 'total' => '9.99'], $view->data['cart_summary']);

        // Absent closure → null (a content page would get no count).
        $view2 = ($this->resolver($this->fakePort()))($this->request('/shop'));
        self::assertNull($view2->data['cart_summary']);
    }
}

/**
 * A catalog-read test double that records the last filters it was queried with,
 * and returns one product ("milk") — enough to exercise the resolver.
 */
final class FakeCatalogPort implements CatalogReadPort
{
    /** @var array{category?:?string,q?:?string,sort?:?string,page?:int}|null */
    public ?array $lastFilters = null;

    public function list(array $filters): array
    {
        $this->lastFilters = $filters;
        return [
            'items' => [[
                'sku_code' => 'milk', 'name' => 'Milk', 'price' => '1.20', 'unit' => 'litre',
                'description' => null, 'image_media_id' => null, 'category_id' => null,
                'category' => null, 'featured' => false, 'availability' => 'in_stock',
            ]],
            'total' => 1, 'page' => 1, 'per_page' => 24, 'pages' => 1,
        ];
    }

    public function get(string $sku): ?array
    {
        if ($sku !== 'milk') {
            return null;
        }
        return [
            'sku_code' => 'milk', 'name' => 'Milk', 'price' => '1.20', 'unit' => 'litre',
            'description' => 'Fresh milk.', 'image_media_id' => null, 'category_id' => null,
            'category' => null, 'featured' => false, 'availability' => 'in_stock',
        ];
    }

    public function categories(): array
    {
        return [['id' => 1, 'name' => 'Dairy', 'slug' => 'dairy', 'parent_id' => null]];
    }
}
