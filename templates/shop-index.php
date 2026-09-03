<?php
/**
 * Default storefront listing (ADR 0023). Rendered inside the active theme's
 * layout; a theme overrides this by shipping its own `shop-index`. CSP-clean: one
 * nonce'd <style>, a no-JS GET filter form, every value escaped via $e.
 *
 * @var callable(?string):string $e         escape helper (injected)
 * @var callable(?int):?array{url:string,alt:?string} $media media resolver (injected)
 * @var string $cspNonce
 * @var list<array<string,mixed>> $items
 * @var list<array{id:int,name:string,slug:string,parent_id:?int}> $categories
 * @var array{category:string,q:string,sort:string} $current
 * @var int $page
 * @var int $pages
 * @var int $total
 * @var bool $available
 * @var string $cart_csrf
 * @var array{sku:string,name:string}|null $added the just-added item (flash), or null
 * @var ?string $notice  a validated notice code, or null
 */
$notices = [
    'unavailable' => 'That item is unavailable right now.',
    'expired'     => 'Your session expired — please try again.',
    'empty'       => 'Your cart is empty.',
    'stock'       => 'Sorry, that item just went out of stock.',
];
$labels = ['in_stock' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock'];
$sorts  = ['featured' => 'Featured', 'name' => 'Name', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low'];
// Preserve the active filters when building a pagination link.
$pageUrl = static function (int $n) use ($current, $e): string {
    $q = array_filter(['category' => $current['category'], 'q' => $current['q'], 'sort' => $current['sort'], 'page' => $n > 1 ? (string) $n : '']);
    return '/shop' . ($q === [] ? '' : '?' . http_build_query($q));
};
?>
<style nonce="<?= $e($cspNonce) ?>">
.sf-wrap{max-width:70rem;margin:0 auto;padding:1.5rem 1rem}
.sf-filters{display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;margin:0 0 1.5rem}
.sf-filters label{display:block;font-size:.8rem;font-weight:600;margin:0 0 .2rem}
.sf-filters select,.sf-filters input{padding:.5rem .6rem;font:inherit;min-height:44px;box-sizing:border-box}
.sf-filters .sf-field{flex:1 1 12rem}
.sf-btn{min-height:44px;padding:.5rem 1rem;font:inherit;cursor:pointer}
.sf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,14rem),1fr));gap:1.25rem}
.sf-card{display:flex;flex-direction:column;border:1px solid rgba(128,128,128,.25);border-radius:.5rem;overflow:hidden}
.sf-card img{width:100%;aspect-ratio:1/1;object-fit:cover;background:rgba(128,128,128,.08)}
.sf-card .sf-body{padding:.75rem;display:flex;flex-direction:column;gap:.35rem}
.sf-name{font-weight:600;text-decoration:none}
.sf-price{font-variant-numeric:tabular-nums}
.sf-avail{font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.03em}
.sf-avail.in_stock{color:#137333}.sf-avail.low{color:#b06000}.sf-avail.out{opacity:.6}
.sf-pager{display:flex;gap:1rem;justify-content:center;align-items:center;margin:2rem 0 0}
.sf-empty{padding:3rem 1rem;text-align:center;opacity:.7}
.sf-flash{margin:0 0 1.25rem;padding:.75rem 1rem;border-radius:.5rem;border:1px solid rgba(128,128,128,.3)}
.sf-flash-ok{border-color:rgba(19,115,51,.4);background:rgba(19,115,51,.08)}
.sf-flash-warn{border-color:rgba(176,96,0,.4);background:rgba(176,96,0,.08)}
</style>

<div class="sf-wrap">
    <h1>Shop</h1>

    <?php if (($added ?? null) !== null): ?>
        <p class="sf-flash sf-flash-ok" role="status">Added <strong><?= $e($added['name']) ?></strong> to your cart.</p>
    <?php elseif (($notice ?? null) !== null && isset($notices[$notice])): ?>
        <p class="sf-flash sf-flash-warn" role="status"><?= $e($notices[$notice]) ?></p>
    <?php endif; ?>

    <form class="sf-filters" method="get" action="/shop" role="search">
        <div class="sf-field">
            <label for="sf-q">Search</label>
            <input id="sf-q" type="search" name="q" value="<?= $e($current['q']) ?>" placeholder="Search products">
        </div>
        <?php if ($categories !== []): ?>
            <div class="sf-field">
                <label for="sf-category">Category</label>
                <select id="sf-category" name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $e($c['slug']) ?>"<?= $current['category'] === $c['slug'] ? ' selected' : '' ?>>
                            <?= $c['parent_id'] !== null ? '— ' : '' ?><?= $e($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="sf-field">
            <label for="sf-sort">Sort</label>
            <select id="sf-sort" name="sort">
                <?php foreach ($sorts as $value => $label): ?>
                    <option value="<?= $e($value) ?>"<?= $current['sort'] === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="sf-btn" type="submit">Apply</button>
    </form>

    <?php if (!$available): ?>
        <p class="sf-empty">The catalog is unavailable right now.</p>
    <?php elseif ($items === []): ?>
        <p class="sf-empty">No products found<?= $current['q'] !== '' ? ' for “' . $e($current['q']) . '”' : '' ?>.</p>
    <?php else: ?>
        <div class="sf-grid">
            <?php foreach ($items as $it): ?>
                <?php $img = $media($it['image_media_id']); ?>
                <article class="sf-card">
                    <a href="/shop/<?= $e(rawurlencode($it['sku_code'])) ?>">
                        <?php if ($img !== null): ?>
                            <img src="<?= $e($img['url']) ?>" alt="<?= $e($img['alt'] ?? $it['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <img alt="" aria-hidden="true">
                        <?php endif; ?>
                    </a>
                    <div class="sf-body">
                        <a class="sf-name" href="/shop/<?= $e(rawurlencode($it['sku_code'])) ?>"><?= $e($it['name']) ?></a>
                        <span class="sf-price"><?= $e($it['price']) ?><?= $it['unit'] !== null ? ' <span>/ ' . $e($it['unit']) . '</span>' : '' ?></span>
                        <span class="sf-avail <?= $e($it['availability']) ?>"><?= $e($labels[$it['availability']] ?? $it['availability']) ?></span>
                        <?php if ($it['availability'] !== 'out'): ?>
                            <form method="post" action="/ext/shop/cart/add" class="sf-add">
                                <input type="hidden" name="_cart_csrf" value="<?= $e($cart_csrf ?? '') ?>">
                                <input type="hidden" name="sku" value="<?= $e($it['sku_code']) ?>">
                                <input type="hidden" name="qty" value="1">
                                <input type="hidden" name="return" value="shop">
                                <input type="hidden" name="category" value="<?= $e($current['category']) ?>">
                                <input type="hidden" name="q" value="<?= $e($current['q']) ?>">
                                <input type="hidden" name="sort" value="<?= $e($current['sort']) ?>">
                                <input type="hidden" name="page" value="<?= $e((string) $page) ?>">
                                <button type="submit" class="sf-btn">Add to cart</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="sf-pager" aria-label="Pagination">
                <?php if ($page > 1): ?><a class="sf-btn" href="<?= $e($pageUrl($page - 1)) ?>">&larr; Previous</a><?php endif; ?>
                <span>Page <?= $e((string) $page) ?> of <?= $e((string) $pages) ?></span>
                <?php if ($page < $pages): ?><a class="sf-btn" href="<?= $e($pageUrl($page + 1)) ?>">Next &rarr;</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
