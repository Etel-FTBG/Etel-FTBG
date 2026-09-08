<?php
/**
 * Etel Gebeya v2 -- Product card (Alibaba-style, object-safe)
 * Product properties: id, slug, nameEn, nameAm, price, comparePrice,
 *   stockQty, soldCount, ratingAvg, ratingCount, createdAt, isFeatured
 * Product methods: name($locale), primaryImage(), hasDiscount(), discountPct()
 */
use App\Core\View;

if (!function_exists('eg_product_card')) {
    function eg_product_card($p, string $locale = 'en'): string
    {
        // All property access uses object notation -- Product is always an object here
        $pid   = (int)$p->id;
        $url   = View::url('/product/' . e($p->slug));
        $img   = $p->primaryImage();
        $name  = e($p->name($locale));
        $price = number_format($p->price, 2) . ' Br';
        $sold  = (int)$p->soldCount;

        $hasDisc  = $p->hasDiscount();
        $disc     = $hasDisc ? $p->discountPct() : 0;
        $compare  = $hasDisc ? (number_format((float)$p->comparePrice, 2) . ' Br') : '';

        $rAvg   = (float)$p->ratingAvg;
        $rCount = (int)$p->ratingCount;
        $isNew  = $p->createdAt && (time() - strtotime($p->createdAt)) < 7 * 86400;

        // Stars HTML
        $stars = '';
        $full = (int)round($rAvg);
        for ($i = 0; $i < 5; $i++) {
            $stars .= $i < $full ? '&#9733;' : '&#9734;';
        }

        // Badges
        $badges = '';
        if ($isNew)   $badges .= '<span class="eg-badge-new">NEW</span>';
        if ($disc > 0) $badges .= '<span class="eg-badge-disc">-' . $disc . '%</span>';

        // Image
        $imgHtml = $img
            ? '<img src="' . e($img) . '" alt="' . $name . '" loading="lazy">'
            : '<div class="eg-card-img-placeholder"><i class="bi bi-image"></i></div>';

        // Compare price
        $compareHtml = $hasDisc
            ? '<span class="eg-card-compare">ETB&nbsp;' . $compare . '</span>'
            : '';

        // Stars row
        $starHtml = $rCount > 0
            ? '<div class="eg-card-stars">' . $stars . ' <span>(' . $rCount . ')</span></div>'
            : '';

        // Add to cart button
        $addBtn = $pid > 0
            ? '<div class="eg-card-add-wrap">'
              . '<button class="eg-card-add" type="button"'
              . ' onclick="event.preventDefault();egAddToCart(' . $pid . ',1,this)">'
              . '<i class="bi bi-cart-plus"></i> Add to Cart</button></div>'
            : '';

        return '<a href="' . $url . '" class="eg-card">'
             . '<div class="eg-card-img">'
             .   $imgHtml
             .   ($badges ? '<div class="eg-card-badges">' . $badges . '</div>' : '')
             .   '<button class="eg-card-wish" onclick="event.preventDefault();" type="button">'
             .     '<i class="bi bi-heart"></i></button>'
             . '</div>'
             . '<div class="eg-card-body">'
             .   '<div class="eg-card-cat">Etel Gebeya</div>'
             .   '<div class="eg-card-name">' . $name . '</div>'
             .   $starHtml
             .   '<div class="eg-card-price-row">'
             .     '<span class="eg-card-price">ETB&nbsp;' . $price . '</span>'
             .     $compareHtml
             .   '</div>'
             . '</div>'
             . '<div class="eg-card-footer">'
             .   '<span>Etel Gebeya</span>'
             .   '<span>' . ($sold > 0 ? $sold . ' sold' : 'In stock') . '</span>'
             . '</div>'
             . $addBtn
             . '</a>';
    }
}

// Backwards compat alias used by old views
if (!function_exists('product_card')) {
    function product_card($p, string $locale = 'en'): string {
        return eg_product_card($p, $locale);
    }
}
