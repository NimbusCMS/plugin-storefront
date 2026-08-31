<?php
/**
 * Default checkout page (ADR 0026). Private (no-store). The order summary + a
 * customer form; the form carries the per-cart CSRF token. Every value escaped.
 *
 * @var callable(?string):string $e
 * @var array{lines:list<array<string,mixed>>,total:string,count:int} $cart
 * @var string $csrf
 * @var bool $available
 */
?>
<div class="sf-wrap">
    <h1>Checkout</h1>

    <?php if (!$available || $cart['count'] === 0): ?>
        <p class="sf-empty">Your cart is empty. <a href="/shop">Browse the shop</a>.</p>
    <?php else: ?>
        <div class="sf-summary">
            <?php foreach ($cart['lines'] as $line): ?>
                <div class="sf-summary-row">
                    <span><?= $e((string) $line['qty']) ?>× <?= $e($line['name']) ?></span>
                    <span><?= $e($line['line_total']) ?></span>
                </div>
            <?php endforeach; ?>
            <div class="sf-summary-row sf-summary-total"><strong>Total</strong> <strong><?= $e($cart['total']) ?></strong></div>
        </div>

        <form class="sf-checkout" method="post" action="/ext/shop/checkout">
            <input type="hidden" name="_cart_csrf" value="<?= $e($csrf) ?>">
            <div class="sf-field">
                <label for="co-name">Name</label>
                <input id="co-name" type="text" name="name" required maxlength="120" autocomplete="name">
            </div>
            <div class="sf-field">
                <label for="co-email">Email</label>
                <input id="co-email" type="email" name="email" required maxlength="191" autocomplete="email">
            </div>
            <button type="submit" class="sf-btn sf-btn-primary">Place order</button>
            <p class="sf-muted">Payment is arranged after you place your order.</p>
        </form>
    <?php endif; ?>
</div>
