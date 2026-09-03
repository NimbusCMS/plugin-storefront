<?php

declare(strict_types=1);

namespace NimbusCMS\Storefront\Tests;

use Nimbus\Site\PageContext;
use NimbusCMS\Inventory\CatalogReadPort;
use NimbusCMS\Storefront\StorefrontViewData;
use PHPUnit\Framework\TestCase;

/**
 * The storefront's view-data contributor (ADR 0027): it feeds a capped list of
 * genuinely-featured products into the HOME page only, reads only the public
 * CatalogReadPort, and degrades to nothing when Inventory is absent.
 */
final class StorefrontViewDataTest extends TestCase
{
    private function page(string $kind): PageContext
    {
        return new PageContext($kind, 'https://shop.test/', 'Home', 'Shop', 'AAAAAAAAAAAAAAAAAAAAAA==');
    }

    private function contributor(?CatalogReadPort $port): StorefrontViewData
    {
        return new StorefrontViewData(static fn (): ?CatalogReadPort => $port);
    }

    public function test_it_contributes_only_featured_items_on_the_home_page(): void
    {
        $data = ($this->contributor(new FakeFeaturedPort()))->data($this->page('home'));

        self::assertArrayHasKey('featured', $data);
        $skus = array_map(static fn (array $i): string => $i['sku_code'], $data['featured']);
        self::assertSame(['a', 'c'], $skus, 'only featured=true items, in featured-first order');
    }

    public function test_it_caps_the_list_to_a_handful(): void
    {
        $data = ($this->contributor(new FakeFeaturedPort(featuredCount: 20)))->data($this->page('home'));
        self::assertCount(6, $data['featured'], 'capped at the LIMIT');
    }

    public function test_it_is_silent_off_the_home_page(): void
    {
        self::assertSame([], ($this->contributor(new FakeFeaturedPort()))->data($this->page('entry')));
        self::assertSame([], ($this->contributor(new FakeFeaturedPort()))->data($this->page('collection')));
    }

    public function test_without_inventory_it_contributes_nothing(): void
    {
        self::assertSame([], ($this->contributor(null))->data($this->page('home')));
    }

    public function test_no_featured_items_contributes_nothing(): void
    {
        self::assertSame([], ($this->contributor(new FakeFeaturedPort(featuredCount: 0)))->data($this->page('home')));
    }
}

/** A CatalogReadPort double: emits N featured items interleaved with non-featured. */
final class FakeFeaturedPort implements CatalogReadPort
{
    public function __construct(private int $featuredCount = 2)
    {
    }

    public function list(array $filters): array
    {
        // Default shape: featured 'a', non-featured 'b', featured 'c' — proves
        // filtering keeps only featured and preserves order. For other counts,
        // emit `featuredCount` featured items (+ one non-featured decoy).
        if ($this->featuredCount === 2) {
            $items = [
                $this->item('a', true),
                $this->item('b', false),
                $this->item('c', true),
            ];
        } else {
            $items = [$this->item('decoy', false)];
            for ($i = 0; $i < $this->featuredCount; $i++) {
                $items[] = $this->item('f' . $i, true);
            }
        }
        return ['items' => $items, 'total' => count($items), 'page' => 1, 'per_page' => 24, 'pages' => 1];
    }

    public function get(string $sku): ?array
    {
        return null;
    }

    public function categories(): array
    {
        return [];
    }

    /** @return array<string,mixed> */
    private function item(string $sku, bool $featured): array
    {
        return [
            'sku_code' => $sku, 'name' => ucfirst($sku), 'price' => '1.00', 'unit' => null,
            'description' => null, 'image_media_id' => null, 'category_id' => null,
            'category' => null, 'featured' => $featured, 'availability' => 'in_stock',
        ];
    }
}
