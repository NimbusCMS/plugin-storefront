<?php

declare(strict_types=1);

namespace NimbusCMS\Storefront;

use Nimbus\Plugin\Plugin;
use Nimbus\Plugin\PluginContext;
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

        // A themed public section at /shop, with this plugin's default templates as
        // the theme-overridable fallback.
        $context->pages()->register('shop', new StorefrontResolver($port), dirname(__DIR__) . '/templates');

        // Teach an agent what the storefront is (ADR 0013).
        $context->skills()->register('Storefront', Guide::text());
    }
}
