<?php

declare(strict_types=1);

namespace NimbusCMS\Storefront;

use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Plugin\Plugin;
use Nimbus\Plugin\PluginContext;
use NimbusCMS\Commerce\CartPort;
use NimbusCMS\Inventory\CatalogReadPort;

/**
 * The official Storefront plugin — the public, themed face of the Inventory item
 * master (ADR 0022). It registers a **page section** (ADR 0023) at `/shop` and
 * renders the catalog through the active theme: a listing with category / search /
 * sort / pagination and coarse availability, plus a product page.
 *
 * It is pure presentation. It owns **no tables** and reads the catalog only
 * through Inventory's {@see CatalogReadPort} (ADR 0019), so a warehouse install
 * can run Inventory with no public face, and the storefront degrades to an empty
 * catalog — never an error — when Inventory is absent. It ships default templates
 * the active theme (Aurora) overrides.
 */
final class StorefrontPlugin implements Plugin
{
    /** Matches extra.nimbus.id in composer.json. */
    public const ID = 'nimbuscms.storefront';

    public function register(PluginContext $context): void
    {
        // The catalog read port, resolved per request — null when no inventory is
        // installed, so the resolver degrades gracefully.
        $port = static fn (): ?CatalogReadPort => $context->services()->get(CatalogReadPort::class);

        // The cart, driven through Commerce's CartPort (ADR 0026) — null when
        // Commerce is absent, so a browse-only storefront still works.
        $cartPort = static fn (): ?CartPort => $context->services()->get(CartPort::class);
        $cart     = new StorefrontCart($cartPort);
        $templates = dirname(__DIR__) . '/templates';

        // The current cart's CSRF token, for add-to-cart forms on the shop pages.
        $cartCsrf = static fn (Request $r): string => $cart->existing($r, $cartPort())['csrf'] ?? '';

        // The themed public sections (ADR 0023): the catalog at /shop, and the cart.
        $context->pages()->register('shop', new StorefrontResolver($port, $cartCsrf), $templates);
        $context->pages()->register('cart', $cart->cartSection(...), $templates);
        $context->pages()->register('checkout', $cart->checkoutSection(...), $templates);
        $context->pages()->register('order', $cart->orderSection(...), $templates);

        // The cart + checkout mutations — public POST actions (ADR 0017),
        // CSRF-guarded, that redirect (POST-redirect-GET) to a private page.
        $context->routes()->post('shop', '/cart/add', static fn (Request $r, array $p): Response => $cart->add($r));
        $context->routes()->post('shop', '/cart/update', static fn (Request $r, array $p): Response => $cart->update($r));
        $context->routes()->post('shop', '/checkout', static fn (Request $r, array $p): Response => $cart->checkout($r));

        // Teach an agent what the storefront is (ADR 0013).
        $context->skills()->register('Storefront', Guide::text());
    }
}
