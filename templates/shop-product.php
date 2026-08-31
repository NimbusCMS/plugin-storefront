<?php
/**
 * Default storefront product page (ADR 0023). Rendered inside the theme layout;
 * a theme overrides by shipping its own `shop-product`. Every value escaped via $e.
 *
 * @var callable(?string):string $e
 * @var callable(?int):?array{url:string,alt:?string} $media
 * @var string $cspNonce
 * @var array<string,mixed> $item
 * @var string $cart_csrf
 */
$labels = ['in_stock' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock'];
$img    = $media($item['image_media_id']);
?>
<style nonce="<?= $e($cspNonce) ?>">
.sf-product{max-width:60rem;margin:0 auto;padding:1.5rem 1rem;display:grid;grid-template-columns:1fr;gap:1.5rem}
@media (min-width:44rem){.sf-product{grid-template-columns:1fr 1fr}}
.sf-product img{width:100%;border-radius:.5rem;background:rgba(128,128,128,.08)}
.sf-back{display:inline-block;margin:1rem 0 0 1rem;text-decoration:none}
.sf-p-price{font-size:1.5rem;font-variant-numeric:tabular-nums}
.sf-p-avail{font-size:.85rem;font-weight:600;text-transform:uppercase;letter-spacing:.03em}
.sf-p-avail.in_stock{color:#137333}.sf-p-avail.low{color:#b06000}.sf-p-avail.out{opacity:.6}
.sf-p-desc{line-height:1.6;white-space:pre-line}
</style>

<p class="sf-back"><a href="/shop">&larr; Back to shop</a></p>
<div class="sf-product">
    <div>
        <?php if ($img !== null): ?>
            <img src="<?= $e($img['url']) ?>" alt="<?= $e($img['alt'] ?? $item['name']) ?>">
        <?php endif; ?>
    </div>
    <div>
        <h1><?= $e($item['name']) ?></h1>
        <p class="sf-p-price"><?= $e($item['price']) ?><?= $item['unit'] !== null ? ' <span>/ ' . $e($item['unit']) . '</span>' : '' ?></p>
        <p class="sf-p-avail <?= $e($item['availability']) ?>"><?= $e($labels[$item['availability']] ?? $item['availability']) ?></p>
        <?php if ($item['availability'] !== 'out'): ?>
            <form method="post" action="/ext/shop/cart/add" class="sf-add">
                <input type="hidden" name="_cart_csrf" value="<?= $e($cart_csrf ?? '') ?>">
                <input type="hidden" name="sku" value="<?= $e($item['sku_code']) ?>">
                <input type="number" name="qty" value="1" min="1" max="999" inputmode="numeric" aria-label="Quantity">
                <button type="submit" class="sf-btn sf-btn-primary">Add to cart</button>
            </form>
        <?php endif; ?>
        <?php if ($item['category'] !== null): ?>
            <p class="sf-p-cat"><?= $e($item['category']) ?></p>
        <?php endif; ?>
        <?php if ($item['description'] !== null): ?>
            <p class="sf-p-desc"><?= $e($item['description']) ?></p>
        <?php endif; ?>
    </div>
</div>
