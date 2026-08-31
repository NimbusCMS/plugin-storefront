<?php
/**
 * Default order-confirmation page (ADR 0026). Private (no-store); shown only to
 * the visitor who placed the order (gated by the order cookie). Every value escaped.
 *
 * @var callable(?string):string $e
 * @var string $ref
 */
?>
<div class="sf-wrap sf-order">
    <h1>Order received</h1>
    <p>Thank you — your order <strong><?= $e($ref) ?></strong> has been placed.</p>
    <p class="sf-muted">We'll be in touch to arrange payment and delivery.</p>
    <p><a class="sf-btn" href="/shop">Continue shopping</a></p>
</div>
