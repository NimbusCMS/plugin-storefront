# NimbusCMS Storefront

The public, themed face of the [Inventory](https://github.com/NimbusCMS/plugin-inventory)
item master — an official [NimbusCMS](https://github.com/NimbusCMS/nimbus) plugin.

It renders your catalog at **`/shop`**: a listing with **categories, search,
sort, pagination, and availability**, plus a **product page** at `/shop/{sku}`.
It is pure presentation — it owns no tables and reads the catalog through
Inventory's service port, so the storefront looks like the rest of your site and
degrades to an empty catalog (never an error) if Inventory is not installed.

## How it works

- **Page section (ADR 0023).** The plugin registers a themed public page at the
  `shop` handle. NimbusCMS renders it through the *active theme's* layout, using
  the plugin's default templates (`shop-index`, `shop-product`) which a theme
  **overrides** by shipping templates of the same name.
- **Catalog read port (ADR 0019).** Products, prices, categories and stock all
  live in Inventory. The storefront reads them through `CatalogReadPort`, which
  returns only **active** items with **coarse** availability (in stock / low /
  out) — never exact counts or cost.

## Managing what appears

Use the **Inventory** plugin (its admin *Catalog* page or its MCP tools —
`inventory_item_set`, `inventory_category_set`, `inventory_items`). An item shows
on the storefront when it is **active**; an inactive or unknown SKU is a 404.

## Security

The storefront renders admin/agent-authored values (name, description, category)
to the public. Every value is **escaped on render** (`View::e`); descriptions are
plain text. Search is a bound, escaped `LIKE`; sort is an allow-list; pages past
the end 404. Templates are CSP-clean (no inline `style=`; the section receives the
CSP nonce for a nonce'd `<style>` block).

## License

MIT.
