<?php

declare(strict_types=1);

namespace NimbusCMS\Storefront\Tests;

use Nimbus\Http\Request;
use Nimbus\Site\PageView;
use NimbusCMS\Commerce\CartPort;
use NimbusCMS\Storefront\StorefrontCart;
use PHPUnit\Framework\TestCase;

/**
 * The storefront's cart cookie + CSRF discipline (ADR 0026), over a fake CartPort:
 * viewing never mints a cart; the /cart page is private; a mutation sets the cart
 * cookie; a pre-existing cart's mutation requires its CSRF token; a first
 * "bootstrap" add is allowed; and a missing Commerce degrades gracefully.
 */
final class StorefrontCartTest extends TestCase
{
    private FakeCartPort $port;
    private StorefrontCart $cart;

    protected function setUp(): void
    {
        $this->port = new FakeCartPort();
        $port       = $this->port;
        $this->cart = new StorefrontCart(static fn (): CartPort => $port);
    }

    /** @param array<string,mixed> $post */
    private function request(string $method, array $post = [], ?string $cookie = null): Request
    {
        $cookies = $cookie === null ? [] : [StorefrontCart::COOKIE => $cookie];
        return new Request($method, '/', [], $post, [], [], null, '', $cookies);
    }

    public function test_the_cart_page_is_private_and_empty_without_a_cookie(): void
    {
        $view = $this->cart->cartSection($this->request('GET'));
        self::assertInstanceOf(PageView::class, $view);
        self::assertSame('shop-cart', $view->template);
        self::assertTrue($view->private, 'the cart is no-store');
        self::assertSame(0, $view->data['cart']['count']);
        self::assertSame([], $this->port->minted, 'viewing without a cookie mints no cart');
    }

    public function test_a_first_add_bootstraps_a_cart_sets_the_cookie_and_adds(): void
    {
        $res = $this->cart->add($this->request('POST', ['sku' => 'apple', 'qty' => '2']));

        self::assertSame(302, $res->status);
        self::assertSame('/cart?added=apple', $res->headers['Location'] ?? null, 'no return field → /cart, with the added flash');
        self::assertStringContainsString(StorefrontCart::COOKIE . '=', $res->headers['Set-Cookie'] ?? '');
        self::assertStringContainsString('HttpOnly', $res->headers['Set-Cookie'] ?? '');
        self::assertStringContainsString('SameSite=Lax', $res->headers['Set-Cookie'] ?? '');
        self::assertSame([['apple', '2']], $this->port->added, 'the item was added to the fresh cart');
    }

    public function test_a_pre_existing_cart_mutation_requires_the_csrf_token(): void
    {
        $token = $this->port->seed('the-csrf-secret');

        // Wrong/absent token → no add.
        $this->cart->add($this->request('POST', ['sku' => 'apple', 'qty' => '1', '_cart_csrf' => 'wrong'], $token));
        self::assertSame([], $this->port->added, 'a pre-existing cart rejects a bad CSRF token');

        // Correct token → add.
        $this->cart->add($this->request('POST', ['sku' => 'apple', 'qty' => '1', '_cart_csrf' => 'the-csrf-secret'], $token));
        self::assertSame([['apple', '1']], $this->port->added);
    }

    public function test_update_removes_via_qty_zero(): void
    {
        $token = $this->port->seed('sec');
        $this->cart->update($this->request('POST', ['sku' => 'apple', 'qty' => '0', '_cart_csrf' => 'sec'], $token));
        self::assertSame([['apple', '0']], $this->port->setQ);
    }

    public function test_without_commerce_a_mutation_just_redirects(): void
    {
        $cart = new StorefrontCart(static fn (): ?CartPort => null);
        $res  = $cart->add($this->request('POST', ['sku' => 'apple', 'qty' => '1']));
        self::assertSame(302, $res->status);
        self::assertSame('/shop', $res->headers['Location'] ?? null);
    }

    public function test_the_checkout_page_is_private(): void
    {
        $view = $this->cart->checkoutSection($this->request('GET'));
        self::assertSame('shop-checkout', $view->template);
        self::assertTrue($view->private);
    }

    public function test_checkout_requires_csrf_and_confirms_to_a_private_gated_order(): void
    {
        $token = $this->port->seed('sec');

        // Wrong CSRF → back to cart, no order.
        $bad = $this->cart->checkout($this->request('POST', ['name' => 'Sam', 'email' => 'sam@x.test', '_cart_csrf' => 'nope'], $token));
        self::assertSame('/cart?notice=expired', $bad->headers['Location'] ?? null);
        self::assertSame([], $this->port->checkedOut);

        // Correct CSRF → order placed, redirect to the confirmation, order cookie set.
        $ok = $this->cart->checkout($this->request('POST', ['name' => 'Sam', 'email' => 'sam@x.test', '_cart_csrf' => 'sec'], $token));
        self::assertSame('/order/ORD-TEST', $ok->headers['Location'] ?? null);
        self::assertStringContainsString(StorefrontCart::ORDER_COOKIE . '=ORD-TEST', $ok->headers['Set-Cookie'] ?? '');
        self::assertCount(1, $this->port->checkedOut);
        self::assertSame('sam@x.test', $this->port->checkedOut[0]['email']);
    }

    public function test_checkout_without_a_cart_cookie_redirects_to_cart(): void
    {
        $res = $this->cart->checkout($this->request('POST', ['name' => 'Sam', 'email' => 'a@b.test']));
        self::assertSame('/cart?notice=expired', $res->headers['Location'] ?? null);
        self::assertSame([], $this->port->checkedOut);
    }

    /**
     * The add-to-cart return URL is composed SERVER-SIDE from the allow-listed form
     * fields only — never an echoed path/URL — so it can only ever be an on-site
     * `/shop…`, `/shop/{sku}`, or `/cart`. A hostile `category`/`sku`/etc. is
     * URL-encoded into a query value (or the fixed path prefix) and can't escape the
     * site or inject a header. (Open-redirect regression, ADR 0026.)
     */
    public function test_add_returns_to_an_allow_listed_on_site_origin(): void
    {
        // return=shop → the filter query, then the added flash — all under /shop.
        $shop = $this->cart->add($this->request('POST', [
            'sku' => 'apple', 'return' => 'shop',
            'category' => 'fruit', 'q' => 'milk', 'sort' => 'name', 'page' => '2',
        ]));
        self::assertSame('/shop?category=fruit&q=milk&sort=name&page=2&added=apple', $shop->headers['Location'] ?? null);

        // return=product → the product page (sku in a rawurlencoded path segment).
        $prod = $this->cart->add($this->request('POST', ['sku' => 'apple', 'return' => 'product']));
        self::assertSame('/shop/apple?added=apple', $prod->headers['Location'] ?? null);

        // An unknown return value falls back to /cart (never an echoed target).
        $other = $this->cart->add($this->request('POST', ['sku' => 'apple', 'return' => 'http://evil.example']));
        self::assertSame('/cart?added=apple', $other->headers['Location'] ?? null);
    }

    /**
     * A hostile origin field can't produce an off-site or protocol-relative redirect,
     * nor a CRLF header injection: `http_build_query` URL-encodes every value and the
     * path uses fixed prefixes + rawurlencode.
     */
    public function test_add_return_url_cannot_escape_the_site(): void
    {
        $evil = $this->cart->add($this->request('POST', [
            'sku' => '//evil.example/x', 'return' => 'shop',
            'category' => "//evil.example\r\nSet-Cookie: pwn=1", 'q' => 'https://evil.example',
        ]));
        $loc = $evil->headers['Location'] ?? '';
        self::assertStringStartsWith('/shop?', $loc, 'stays on-site under /shop');
        self::assertStringNotContainsString('//evil', $loc, 'no protocol-relative host survives');
        self::assertStringNotContainsString("\r", $loc, 'no CR');
        self::assertStringNotContainsString("\n", $loc, 'no LF');

        // return=product with a hostile sku → a single rawurlencoded path segment.
        $prod = $this->cart->add($this->request('POST', ['sku' => '//evil.example', 'return' => 'product']));
        self::assertStringStartsWith('/shop/%2F%2Fevil.example', $prod->headers['Location'] ?? '');
    }

    public function test_checkout_failure_notices_say_why(): void
    {
        // An empty cart → notice=empty.
        $this->port->throwOnCheckout = new \InvalidArgumentException('empty');
        $empty = $this->cart->checkout($this->request('POST', ['_cart_csrf' => 'sec'], $this->port->seed('sec')));
        self::assertSame('/cart?notice=empty', $empty->headers['Location'] ?? null);

        // Stock vanished (a failed reservation) → notice=stock.
        $this->port->throwOnCheckout = new \RuntimeException('reservation failed');
        $stock = $this->cart->checkout($this->request('POST', ['_cart_csrf' => 'sec'], 'existing-token'));
        self::assertSame('/cart?notice=stock', $stock->headers['Location'] ?? null);
    }

    public function test_summary_counts_total_qty_and_never_mints(): void
    {
        // No cookie → null, and nothing minted (a header pill must never create a cart).
        self::assertNull($this->cart->summary($this->request('GET')));
        self::assertSame([], $this->port->minted);

        // A seeded cart with two lines → count is the SUM of line quantities.
        $token = $this->port->seed('sec');
        $this->port->stubContents = ['lines' => [
            ['sku_code' => 'apple', 'qty' => 2, 'name' => 'Apple', 'unit' => null, 'unit_price' => '1.00', 'line_total' => '2.00', 'availability' => 'in_stock'],
            ['sku_code' => 'milk', 'qty' => 3, 'name' => 'Milk', 'unit' => null, 'unit_price' => '1.00', 'line_total' => '3.00', 'availability' => 'in_stock'],
        ], 'total' => '5.00', 'count' => 2];
        $summary = $this->cart->summary($this->request('GET', [], $token));
        self::assertSame(['count' => 5, 'total' => '5.00'], $summary, 'count = Σ line qty, not distinct-line count');
    }

    public function test_the_order_confirmation_is_gated_to_the_order_cookie(): void
    {
        // With the matching order cookie → the confirmation renders (private).
        $seen = $this->cart->orderSection(new Request('GET', '/order/ORD-TEST', [], [], [], [], null, '', [StorefrontCart::ORDER_COOKIE => 'ORD-TEST']));
        self::assertInstanceOf(PageView::class, $seen);
        self::assertTrue($seen->private);
        self::assertSame('ORD-TEST', $seen->data['ref']);

        // Without the cookie (a guesser), or a mismatched ref → the themed 404.
        self::assertNull($this->cart->orderSection(new Request('GET', '/order/ORD-TEST', [], [], [], [], null, '', [])));
        self::assertNull($this->cart->orderSection(new Request('GET', '/order/OTHER', [], [], [], [], null, '', [StorefrontCart::ORDER_COOKIE => 'ORD-TEST'])));
    }
}

/** A minimal CartPort double that records what the storefront asked it to do. */
final class FakeCartPort implements CartPort
{
    /** @var array<string,string> token => csrf */
    private array $carts = [];
    /** @var list<string> tokens minted this test */
    public array $minted = [];
    /** @var list<array{0:string,1:string}> */
    public array $added = [];
    /** @var list<array{0:string,1:string}> */
    public array $setQ = [];
    /** @var list<array{name?:string,email?:string}> */
    public array $checkedOut = [];
    /** @var array{lines:list<array<string,mixed>>,total:string,count:int}|null */
    public ?array $stubContents = null;
    public ?\Throwable $throwOnCheckout = null;

    /** Seed a pre-existing cart, returning its token. */
    public function seed(string $csrf): string
    {
        $token               = 'existing-token';
        $this->carts[$token] = $csrf;
        return $token;
    }

    public function getOrCreate(?string $token): array
    {
        if ($token !== null && isset($this->carts[$token])) {
            return ['token' => $token, 'csrf' => $this->carts[$token]];
        }
        $new                 = 'minted-' . bin2hex(random_bytes(4));
        $this->carts[$new]   = 'csrf-' . $new;
        $this->minted[]      = $new;
        return ['token' => $new, 'csrf' => $this->carts[$new]];
    }

    public function csrfOk(string $token, ?string $submitted): bool
    {
        return is_string($submitted) && isset($this->carts[$token]) && hash_equals($this->carts[$token], $submitted);
    }

    public function add(string $token, string $sku, string $qty): void
    {
        $this->added[] = [$sku, $qty];
    }

    public function setQty(string $token, string $sku, string $qty): void
    {
        $this->setQ[] = [$sku, $qty];
    }

    public function remove(string $token, string $sku): void
    {
    }

    public function contents(string $token): array
    {
        return $this->stubContents ?? ['lines' => [], 'total' => '0.00', 'count' => 0];
    }

    public function checkout(string $token, array $customer): string
    {
        if ($this->throwOnCheckout !== null) {
            throw $this->throwOnCheckout;
        }
        $this->checkedOut[] = $customer;
        return 'ORD-TEST';
    }
}
