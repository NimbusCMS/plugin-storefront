<?php
/**
 * Default cart page (ADR 0026). Private (no-store). Every value escaped; the
 * update/remove forms carry the per-cart CSRF token.
 *
 * @var callable(?string):string $e
 * @var array{lines:list<array<string,mixed>>,total:string,count:int} $cart
 * @var string $csrf
 * @var bool $available
 * @var ?string $notice
 */
$notices = [
    'unavailable' => 'That item is unavailable right now.',
    'expired'     => 'Your session expired — please try again.',
    'empty'       => 'Your cart is empty.',
    'stock'       => 'Sorry, an item just went out of stock — please review your cart.',
];
?>
<div class="sf-wrap">
    <h1>Your cart</h1>

    <?php if (($notice ?? null) !== null && isset($notices[$notice])): ?>
        <p class="sf-flash sf-flash-warn" role="status"><?= $e($notices[$notice]) ?></p>
    <?php endif; ?>

    <?php if (!$available): ?>
        <p class="sf-empty">The cart is unavailable right now.</p>
    <?php elseif ($cart['count'] === 0): ?>
        <p class="sf-empty">Your cart is empty. <a href="/shop">Browse the shop</a>.</p>
    <?php else: ?>
        <div class="sf-cart">
            <?php foreach ($cart['lines'] as $line): ?>
                <div class="sf-cart-row">
                    <div class="sf-cart-name"><?= $e($line['name']) ?><?php if ($line['unit'] !== null): ?> <span class="sf-muted">/ <?= $e($line['unit']) ?></span><?php endif; ?></div>
                    <form class="sf-cart-qty" method="post" action="/ext/shop/cart/update">
                        <input type="hidden" name="_cart_csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="sku" value="<?= $e($line['sku_code']) ?>">
                        <label class="sf-sr">Quantity of <?= $e($line['name']) ?></label>
                        <input type="number" name="qty" min="0" max="999" value="<?= $e((string) $line['qty']) ?>" inputmode="numeric">
                        <button type="submit" class="sf-btn sf-btn-sm">Update</button>
                    </form>
                    <div class="sf-cart-price"><?= $e($line['line_total']) ?></div>
                    <form class="sf-cart-remove" method="post" action="/ext/shop/cart/update">
                        <input type="hidden" name="_cart_csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="sku" value="<?= $e($line['sku_code']) ?>">
                        <input type="hidden" name="qty" value="0">
                        <button type="submit" class="sf-link-danger">Remove</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="sf-cart-foot">
            <p class="sf-cart-total">Total <strong><?= $e($cart['total']) ?></strong></p>
            <a class="sf-btn sf-btn-primary" href="/checkout">Checkout</a>
        </div>
    <?php endif; ?>
</div>
