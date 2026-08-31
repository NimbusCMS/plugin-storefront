<?php

declare(strict_types=1);

namespace NimbusCMS\Storefront;

use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Site\PageView;
use NimbusCMS\Commerce\CartPort;

/**
 * The storefront's public cart + checkout face (ADR 0026). It owns the themed
 * `/cart`·`/checkout`·`/order` **sections** (GET) and the `/ext/shop/*` POST
 * **actions** (ADR 0017), driving Commerce's {@see CartPort} — never its tables.
 *
 * Security posture (ADR 0026): the cart is authorised by an opaque, server-random
 * cookie token (`HttpOnly`+`SameSite=Lax`+`Secure`); price is resolved server-side
 * by the port; the money action (checkout) is CSRF-verified with the per-cart
 * secret, and cart mutations are CSRF-verified whenever a real cart exists (a
 * first "bootstrap" add mints the cart and is covered by `SameSite=Lax`). Viewing
 * a page never mints a cart. Cart/checkout/order pages are marked **private** so a
 * CDN never serves one visitor's cart to another.
 */
final class StorefrontCart
{
    public const COOKIE = 'nb_cart';
    /** A short-lived cookie naming the visitor's just-placed order, so only they see its confirmation. */
    public const ORDER_COOKIE = 'nb_order';
    private const COOKIE_TTL = 14 * 86400;
    private const ORDER_TTL  = 3600;

    /** @param \Closure():?CartPort $cart resolved per request; null when Commerce is absent */
    public function __construct(private \Closure $cart)
    {
    }

    // --- render (GET sections) ------------------------------------------

    /** The `/cart` page — the cart priced live, with update/remove forms. Private. */
    public function cartSection(Request $request): PageView
    {
        $port = ($this->cart)();
        $meta = $this->existing($request, $port);
        $contents = ($port !== null && $meta !== null) ? $port->contents($meta['token']) : ['lines' => [], 'total' => '0.00', 'count' => 0];

        return new PageView('shop-cart', [
            'cart'      => $contents,
            'csrf'      => $meta['csrf'] ?? '',
            'available' => $port !== null,
        ], ['title' => 'Your cart'], 200, true);
    }

    /** The `/checkout` page — the order summary + a customer form. Private. */
    public function checkoutSection(Request $request): PageView
    {
        $port     = ($this->cart)();
        $meta     = $this->existing($request, $port);
        $contents = ($port !== null && $meta !== null) ? $port->contents($meta['token']) : ['lines' => [], 'total' => '0.00', 'count' => 0];

        return new PageView('shop-checkout', [
            'cart'      => $contents,
            'csrf'      => $meta['csrf'] ?? '',
            'available' => $port !== null,
        ], ['title' => 'Checkout'], 200, true);
    }

    /**
     * The `/order/{ref}` confirmation — shown **only** to the visitor who placed it
     * (the `nb_order` cookie must name this ref), so an order ref can't be guessed
     * to read someone else's confirmation. Any mismatch → the themed 404. Private.
     */
    public function orderSection(Request $request): ?PageView
    {
        $ref = $this->refFromPath($request->path);
        if ($ref === null || $request->cookie(self::ORDER_COOKIE) !== $ref) {
            return null;
        }
        return new PageView('shop-order', ['ref' => $ref], ['title' => 'Order received'], 200, true);
    }

    // --- actions (POST /ext) --------------------------------------------

    /**
     * POST checkout: place the cart as an order (server-side prices, atomic stock
     * reservation) and confirm. Always CSRF-verified (a cart always pre-exists at
     * checkout). On success, redirect to the private confirmation and drop the
     * one-time order cookie; on any failure, back to the cart.
     */
    public function checkout(Request $request): Response
    {
        $port = ($this->cart)();
        if ($port === null) {
            return Response::redirect('/shop');
        }
        $token = $request->cookie(self::COOKIE);
        if ($token === null) {
            return Response::redirect('/cart');
        }
        $meta = $port->getOrCreate($token);
        if ($meta['token'] !== $token || !$port->csrfOk($token, $request->input('_cart_csrf'))) {
            return Response::redirect('/cart');
        }

        $customer = [
            'name'  => trim((string) ($request->input('name') ?? '')),
            'email' => trim((string) ($request->input('email') ?? '')),
        ];
        try {
            $ref = $port->checkout($token, $customer);
        } catch (\InvalidArgumentException | \RuntimeException) {
            // Empty cart, or stock that vanished between browsing and checkout —
            // send them back to the cart rather than 500.
            return Response::redirect('/cart');
        }

        return Response::redirect('/order/' . rawurlencode($ref))
            ->withCookie(self::ORDER_COOKIE, $ref, self::ORDER_TTL);
    }

    /** POST add: {sku, qty}. Mints the cart on first add (sets the cookie). */
    public function add(Request $request): Response
    {
        return $this->mutating($request, function (CartPort $port, string $token) use ($request): void {
            $port->add($token, (string) ($request->input('sku') ?? ''), (string) ($request->input('qty') ?? '1'));
        });
    }

    /** POST update: {sku, qty} (qty 0 removes). */
    public function update(Request $request): Response
    {
        return $this->mutating($request, function (CartPort $port, string $token) use ($request): void {
            $port->setQty($token, (string) ($request->input('sku') ?? ''), (string) ($request->input('qty') ?? '0'));
        });
    }

    // --- helpers ---------------------------------------------------------

    /**
     * Run a cart mutation with the cart-token cookie + CSRF discipline, then
     * redirect to /cart. If the request already carried a real cart, its CSRF
     * token is required; a first add (no/stale cookie → a freshly minted cart) is
     * allowed and covered by SameSite=Lax. The (possibly new) token is always
     * re-set as the cookie.
     *
     * @param \Closure(CartPort,string):void $do
     */
    private function mutating(Request $request, \Closure $do): Response
    {
        $port = ($this->cart)();
        if ($port === null) {
            return Response::redirect('/shop');
        }
        $incoming = $request->cookie(self::COOKIE);
        $meta     = $port->getOrCreate($incoming);
        $token    = $meta['token'];

        // A pre-existing cart must present its CSRF token; a bootstrap mint need not.
        $preExisting = $incoming !== null && $incoming === $token;
        if ($preExisting && !$port->csrfOk($token, $request->input('_cart_csrf'))) {
            return Response::redirect('/cart');
        }

        try {
            $do($port, $token);
        } catch (\InvalidArgumentException) {
            // A bad qty / unavailable item: fall through to the cart, which shows
            // the current state — the storefront never 500s on user input.
        }

        return Response::redirect('/cart')->withCookie(self::COOKIE, $token, self::COOKIE_TTL);
    }

    /**
     * The visitor's existing cart meta, or null — WITHOUT minting one (viewing a
     * page must never create a cart row). Returns null when there's no cookie.
     *
     * @return array{token:string,csrf:string}|null
     */
    public function existing(Request $request, ?CartPort $port): ?array
    {
        $token = $request->cookie(self::COOKIE);
        if ($token === null || $token === '' || $port === null) {
            return null;
        }
        // getOrCreate returns the existing cart for a valid token (no INSERT); a
        // stale token mints a fresh empty one, which is harmless and rare.
        $meta = $port->getOrCreate($token);
        return $meta['token'] === $token ? $meta : null;
    }

    /** The order reference from a `/order/{ref}` path, or null for the bare `/order`. */
    private function refFromPath(string $path): ?string
    {
        if (!str_starts_with($path, '/order/')) {
            return null;
        }
        $ref = rawurldecode(explode('/', substr($path, strlen('/order/')), 2)[0]);
        return trim($ref) === '' ? null : $ref;
    }
}
