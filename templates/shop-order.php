<?php
/**
 * Default order-confirmation page (ADR 0026). Private (no-store); shown only to
 * the visitor who placed the order (gated by the order cookie). Every value escaped.
 *
 * @var callable(?string):string $e
 * @var string $ref
 * @var array{status:string,total:string,lines:list<array{name:string,sku_code:string,qty:int,unit_price:string,line_total:string}>}|null $order
 */
$order = $order ?? null;
?>
<div class="sf-wrap sf-order">
    <h1>Order received</h1>
    <p>Thank you — your order <strong><?= $e($ref) ?></strong> has been placed.</p>
    <?php if ($order !== null && $order['lines'] !== []): ?>
        <table class="sf-receipt">
            <tbody>
                <?php foreach ($order['lines'] as $line): ?>
                    <tr>
                        <td><?= $e($line['name']) ?> &times; <?= $e((string) $line['qty']) ?></td>
                        <td class="sf-receipt-amt"><?= $e($line['line_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="sf-receipt-total">
                    <td>Total</td>
                    <td class="sf-receipt-amt"><strong><?= $e($order['total']) ?></strong></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>
    <p class="sf-muted">We'll be in touch to arrange payment and delivery.</p>
    <p><a class="sf-btn" href="/shop">Continue shopping</a></p>
</div>
