<?php

declare(strict_types=1);

namespace NimbusCMS\Storefront;

use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Site\PageView;
use NimbusCMS\Commerce\CartPort;
use NimbusCMS\Commerce\OrderReadPort;
use NimbusCMS\Inventory\CatalogReadPort;

/**
 * The storefront's public cart + checkout face (ADR 0026). It owns the themed
 * `/cart`·`/checkout`·`/order` **sections** (GET) and the `/ext/shop/*` POST
 * **actions** (ADR 0017), driving Commerce's {@see CartPort} — never its tables.
 *
 * Security posture (ADR 0026): the cart is authorised by an opaque, server-random
 * cookie token (`HttpOnly`+`SameSite=Lax`+`Secure`); price is resolved server-side
 * by the port; checkout is CSRF-verified with the per-cart secret, and cart
 * mutations are CSRF-verified whenever a real cart exists (a first "bootstrap" add
 * mints the cart and is covered by `SameSite=Lax`). Viewing never mints a cart.
 * Cart/checkout/order pages are marked **private** so a CDN never serves one
 * visitor's cart to another.
 *
 * Add-to-cart redirects **back to where you were** (a server-composed, allow-listed
 * origin — never a submitted path, honouring the ADR-0026 server-fixed-redirect
 * rule) with an `?added=` flash, so browsing stays natural.
 */
final class StorefrontCart
{
    public const COOKIE = 'nb_cart';
    /** A short-lived cookie naming the visitor's just-placed order, so only they see its confirmation. */
    public const ORDER_COOKIE = 'nb_order';
    private const COOKIE_TTL = 14 * 86400;
    private const ORDER_TTL  = 3600;

    /** The only notice codes a template will render — anything else is ignored (no reflection). */
    private const NOTICES = ['unavailable', 'expired', 'empty', 'stock'];

    /**
     * @param \Closure():?CartPort       $cart      resolved per request; null when Commerce is absent
     * @param ?\Closure():?OrderReadPort $orderRead public-safe order read for the confirmation (ADR 0026); null → ref-only view
     * @param ?\Closure():?CatalogReadPort $catalog  resolves a line's SKU to a display name; null → the SKU itself
     */
    public function __construct(
        private \Closure $cart,
        private ?\Closure $orderRead = null,
        private ?\Closure $catalog = null,
    ) {
    }

    // --- render (GET sections) ------------------------------------------

    /** The `/cart` page — the cart priced live, with update/remove forms. Private. */
    public function cartSection(Request $request): PageView
    {
        $port     = ($this->cart)();
        $meta     = $this->existing($request, $port);
        $contents = ($port !== null && $meta !== null) ? $port->contents($meta['token']) : ['lines' => [], 'total' => '0.00', 'count' => 0];

        return new PageView('shop-cart', [
            'cart'         => $contents,
            'csrf'         => $meta['csrf'] ?? '',
            'available'    => $port !== null,
            'notice'       => $this->notice($request),
            'cart_summary' => $this->summaryOf($contents),
        ], ['title' => 'Your cart'], 200, true);
    }

    /** The `/checkout` page — the order summary + a customer form. Private. */
    public function checkoutSection(Request $request): PageView
    {
        $port     = ($this->cart)();
        $meta     = $this->existing($request, $port);
        $contents = ($port !== null && $meta !== null) ? $port->contents($meta['token']) : ['lines' => [], 'total' => '0.00', 'count' => 0];

        return new PageView('shop-checkout', [
            'cart'         => $contents,
            'csrf'         => $meta['csrf'] ?? '',
            'available'    => $port !== null,
            'cart_summary' => $this->summaryOf($contents),
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
        // The itemised receipt, or null → the theme falls back to the ref-only view.
        return new PageView('shop-order', [
            'ref'   => $ref,
            'order' => $this->orderSummary($ref),
        ], ['title' => 'Order received'], 200, true);
    }

    /**
     * The visitor's just-placed order, projected public-safe (no PII) by Commerce's
     * {@see OrderReadPort} and enriched with each line's display name via the catalog.
     * Null when Commerce is absent or the order can't be read — the confirmation then
     * shows the reference alone. Only reached after the ORDER_COOKIE gate above.
     *
     * @return array{status:string,total:string,lines:list<array{name:string,sku_code:string,qty:int,unit_price:string,line_total:string}>}|null
     */
    private function orderSummary(string $ref): ?array
    {
        $port = $this->orderRead !== null ? ($this->orderRead)() : null;
        if ($port === null) {
            return null;
        }
        $order = $port->get($ref);
        if ($order === null) {
            return null;
        }
        $catalog = $this->catalog !== null ? ($this->catalog)() : null;

        $lines = [];
        foreach ($order['lines'] as $line) {
            // Active item → its name; an inactive/deleted SKU → the SKU itself (never blank/500).
            $item = $catalog?->get($line['sku_code']);
            $name = is_array($item) ? $item['name'] : $line['sku_code'];
            $lines[] = [
                'name'       => $name,
                'sku_code'   => $line['sku_code'],
                'qty'        => $line['qty'],
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
            ];
        }
        return ['status' => $order['status'], 'total' => $order['total'], 'lines' => $lines];
    }

    // --- actions (POST /ext) --------------------------------------------

    /** POST add: {sku, qty}. Mints the cart on first add; returns to the origin page with a flash. */
    public function add(Request $request): Response
    {
        $sku    = trim((string) ($request->input('sku') ?? ''));
        $origin = $this->originUrl($request);
        return $this->mutating(
            $request,
            static function (CartPort $port, string $token) use ($request, $sku): void {
                $port->add($token, $sku, (string) ($request->input('qty') ?? '1'));
            },
            $origin,
            $sku,
        );
    }

    /** POST update: {sku, qty} (qty 0 removes). Stays on /cart. */
    public function update(Request $request): Response
    {
        return $this->mutating(
            $request,
            static function (CartPort $port, string $token) use ($request): void {
                $port->setQty($token, (string) ($request->input('sku') ?? ''), (string) ($request->input('qty') ?? '0'));
            },
            '/cart',
            null,
        );
    }

    /**
     * POST checkout: place the cart as an order (server-side prices, atomic stock
     * reservation) and confirm. Always CSRF-verified. On success, redirect to the
     * private confirmation with the one-time order cookie; on failure, back to the
     * cart with a notice saying why.
     */
    public function checkout(Request $request): Response
    {
        $port = ($this->cart)();
        if ($port === null) {
            return Response::redirect('/shop');
        }
        $token = $request->cookie(self::COOKIE);
        if ($token === null) {
            return Response::redirect('/cart?notice=expired');
        }
        $meta = $port->getOrCreate($token);
        if ($meta['token'] !== $token || !$port->csrfOk($token, $request->input('_cart_csrf'))) {
            return Response::redirect('/cart?notice=expired');
        }

        $customer = [
            'name'  => trim((string) ($request->input('name') ?? '')),
            'email' => trim((string) ($request->input('email') ?? '')),
        ];
        try {
            $ref = $port->checkout($token, $customer);
        } catch (\InvalidArgumentException) {
            return Response::redirect('/cart?notice=empty');
        } catch (\RuntimeException) {
            // Stock vanished between browsing and checkout (a failed reservation).
            return Response::redirect('/cart?notice=stock');
        }

        return Response::redirect('/order/' . rawurlencode($ref))
            ->withCookie(self::ORDER_COOKIE, $ref, self::ORDER_TTL);
    }

    // --- read-only summary (for the header pill) ------------------------

    /**
     * The visitor's cart summary — item **count** (Σ line qty) and total — or null
     * when there's no cart or it's empty. Read-only: it never mints a cart. Used
     * only on section pages (never the cached content pages), so it can't leak
     * across visitors.
     *
     * @return array{count:int,total:string}|null
     */
    public function summary(Request $request): ?array
    {
        $port = ($this->cart)();
        $meta = $this->existing($request, $port);
        if ($port === null || $meta === null) {
            return null;
        }
        return $this->summaryOf($port->contents($meta['token']));
    }

    // --- helpers ---------------------------------------------------------

    /**
     * Run a cart mutation with the cart-token cookie + CSRF discipline, then
     * redirect to `$origin` (with an `?added=` flash on a successful add, or a
     * `notice=` on failure). A pre-existing cart must present its CSRF token; a
     * first add (no/stale cookie → a freshly minted cart) is allowed and covered
     * by SameSite=Lax. The (possibly new) token is re-set as the cookie.
     *
     * @param \Closure(CartPort,string):mixed $do
     */
    private function mutating(Request $request, \Closure $do, string $origin, ?string $addedSku): Response
    {
        $port = ($this->cart)();
        if ($port === null) {
            return Response::redirect('/shop');
        }
        $incoming = $request->cookie(self::COOKIE);
        $meta     = $port->getOrCreate($incoming);
        $token    = $meta['token'];

        $preExisting = $incoming !== null && $incoming === $token;
        if ($preExisting && !$port->csrfOk($token, $request->input('_cart_csrf'))) {
            return Response::redirect($this->withQuery($origin, 'notice', 'expired'));
        }

        try {
            $do($port, $token);
        } catch (\InvalidArgumentException) {
            // A bad qty / unavailable item — never a 500; tell them, keep them put.
            return Response::redirect($this->withQuery($origin, 'notice', 'unavailable'))
                ->withCookie(self::COOKIE, $token, self::COOKIE_TTL);
        }

        $dest = ($addedSku !== null && $addedSku !== '') ? $this->withQuery($origin, 'added', $addedSku) : $origin;
        return Response::redirect($dest)->withCookie(self::COOKIE, $token, self::COOKIE_TTL);
    }

    /**
     * The page to return to after an add — composed **server-side** from the form's
     * own allow-listed fields (`return` enum + the storefront's filter fields),
     * never from a submitted URL/path, so it can only ever be an on-site
     * `/shop…` or `/shop/{sku}` or `/cart`. `http_build_query` URL-encodes every
     * value, so a `//evil` or CRLF in a filter becomes an inert encoded query value.
     */
    private function originUrl(Request $request): string
    {
        $return = (string) ($request->input('return') ?? '');
        if ($return === 'product') {
            $sku = trim((string) ($request->input('sku') ?? ''));
            return $sku === '' ? '/cart' : '/shop/' . rawurlencode($sku);
        }
        if ($return === 'shop') {
            $page  = (int) ($request->input('page') ?? 1);
            $query = array_filter([
                'category' => trim((string) ($request->input('category') ?? '')),
                'q'        => trim((string) ($request->input('q') ?? '')),
                'sort'     => trim((string) ($request->input('sort') ?? '')),
                'page'     => $page > 1 ? (string) $page : '',
            ], static fn (string $v): bool => $v !== '');
            return '/shop' . ($query === [] ? '' : '?' . http_build_query($query));
        }
        return '/cart';
    }

    /** Append a single URL-encoded query param, choosing `?` or `&`. */
    private function withQuery(string $url, string $key, string $value): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . rawurlencode($value);
    }

    /** A validated notice code from the query, or null — never reflects raw input. */
    private function notice(Request $request): ?string
    {
        $n = (string) ($request->query('notice') ?? '');
        return in_array($n, self::NOTICES, true) ? $n : null;
    }

    /**
     * @param array{lines:list<array<string,mixed>>,total:string,count:int} $contents
     * @return array{count:int,total:string}|null
     */
    private function summaryOf(array $contents): ?array
    {
        $count = 0;
        foreach ($contents['lines'] as $line) {
            $count += (int) $line['qty'];
        }
        return $count > 0 ? ['count' => $count, 'total' => $contents['total']] : null;
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
