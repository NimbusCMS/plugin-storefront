<?php

declare(strict_types=1);

namespace NimbusCMS\Storefront;

/**
 * The agent-facing guide (ADR 0013), served as an MCP resource — reference, not
 * instructions.
 */
final class Guide
{
    public static function text(): string
    {
        return <<<'MD'
            # Storefront

            The Storefront is the public, themed face of the **Inventory item
            master**. It renders the catalog at `/shop` — a listing with category,
            search, sort and pagination, and a product page at `/shop/{sku}`.

            ## What it is (and isn't)

            - It **owns no data**. Products, prices, categories and stock all live in
              the Inventory plugin; the Storefront reads them through Inventory's
              catalog port and renders them through the active theme.
            - To add or change what appears, use the Inventory tools
              (`inventory_item_set`, `inventory_category_set`, `inventory_items`) —
              not the Storefront. An item shows on the storefront when it is
              **active**; availability is shown coarsely (in stock / low / out),
              never as an exact count.
            - Only active items are public. An inactive or unknown SKU at
              `/shop/{sku}` is a 404.

            ## Theming

            The Storefront ships default templates (`shop-index`, `shop-product`).
            A theme overrides them by providing templates of the same name — the
            theme's win, and the page always renders in the theme's layout.
            MD;
    }
}
