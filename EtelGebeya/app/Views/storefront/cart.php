<?php
declare(strict_types=1);
use App\Core\View;

$locale = $locale ?? ($GLOBALS['__i18n']->locale());
$cart   = $cart ?? [];
$total  = $total ?? 0.0;
$cfg    = $GLOBALS['__config']['courier'] ?? [];
$ship   = (float)($cfg['fee_flat_etb'] ?? 30);
$grand  = $total + ($total > 0 ? $ship : 0);
ob_start();
?>
<style>
.cart-page { padding: 2rem 0 4rem; }
.cart-heading {
  font-size: 1.6rem; font-weight: 900; margin-bottom: 1.75rem;
  background: linear-gradient(135deg,#FFE066,#D4AF37);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.cart-layout { display: grid; grid-template-columns: 1fr 340px; gap: 2rem; align-items: start; }
@media(max-width:900px){ .cart-layout { grid-template-columns: 1fr; } }

.cart-items { display: flex; flex-direction: column; gap: .75rem; }

.cart-row {
  display: grid; grid-template-columns: 80px 1fr auto;
  gap: 1rem; align-items: center;
  background: linear-gradient(145deg,#1c1208,#130d05);
  border: 1px solid rgba(212,175,55,.18);
  border-radius: 16px; padding: 1rem 1.25rem;
  transition: border-color .2s;
}
.cart-row:hover { border-color: rgba(212,175,55,.35); }

.cart-img {
  width: 80px; height: 80px;
  border-radius: 10px; overflow: hidden;
  background: #1a1208; flex-shrink: 0;
}
.cart-img img { width:100%; height:100%; object-fit:cover; }
.cart-img-placeholder {
  width:100%; height:100%; display:flex; align-items:center; justify-content:center;
  font-size:2rem; color:#5C4209;
}

.cart-info {}
.cart-name {
  font-weight: 700; font-size: .95rem; color: #F7EFD9;
  text-decoration: none; display: block; margin-bottom: .2rem;
}
.cart-name:hover { color: #FFE066; }
.cart-vendor { font-size: .75rem; color: #7A6840; margin-bottom: .5rem; }
.cart-price  { font-size: .95rem; font-weight: 800; color: #D4AF37; }

.cart-controls { display: flex; flex-direction: column; align-items: flex-end; gap: .65rem; }

.qty-ctrl {
  display: flex; align-items: center;
  border: 1px solid rgba(212,175,55,.22); border-radius: 8px; overflow: hidden;
}
.qty-btn {
  background: none; border: none; color: #C9B88A; padding: .4rem .7rem;
  cursor: pointer; font-size: 1.1rem; transition: background .2s, color .2s;
}
.qty-btn:hover { background: rgba(212,175,55,.1); color: #FFE066; }
.qty-val {
  padding: .4rem .6rem; min-width: 36px; text-align: center;
  font-weight: 700; font-size: .9rem; color: #F7EFD9;
  background: none; border: none; width: 50px;
  -moz-appearance: textfield;
}
.qty-val::-webkit-inner-spin-button { display: none; }

.remove-btn {
  background: none; border: none; color: #5C4209; cursor: pointer;
  font-size: .8rem; transition: color .2s;
  display: flex; align-items: center; gap: .3rem;
}
.remove-btn:hover { color: #EF4444; }

.item-total { font-size: .85rem; color: #C9B88A; font-weight: 600; white-space: nowrap; }

/* Summary */
.order-summary {
  background: linear-gradient(145deg,#1c1208,#130d05);
  border: 1px solid rgba(212,175,55,.22);
  border-radius: 16px; padding: 1.5rem;
  position: sticky; top: 80px;
}
.order-summary h3 {
  font-size: 1.05rem; font-weight: 800; color: #D4AF37; margin-bottom: 1.25rem;
}
.summary-row {
  display: flex; justify-content: space-between; align-items: center;
  font-size: .9rem; padding: .5rem 0; border-bottom: 1px solid rgba(212,175,55,.08);
  color: #C9B88A;
}
.summary-row:last-of-type { border-bottom: none; }
.summary-row.total {
  font-weight: 900; font-size: 1.1rem; color: #F7EFD9;
  border-top: 1px solid rgba(212,175,55,.22); margin-top: .5rem; padding-top: 1rem;
}
.summary-row.total span:last-child { color: #FFE066; }

.checkout-btn {
  display: flex; align-items: center; justify-content: center; gap: .5rem;
  width: 100%; padding: .9rem;
  background: linear-gradient(135deg,#FFE066,#D4AF37,#B8902E);
  color: #080604; font-weight: 800; font-size: 1rem;
  border: none; border-radius: 12px; cursor: pointer; text-decoration: none;
  margin-top: 1.25rem;
  transition: opacity .2s, transform .15s, box-shadow .2s;
}
.checkout-btn:hover { opacity:.92; transform:translateY(-1px); box-shadow:0 8px 28px rgba(212,175,55,.35); }

.continue-link {
  display: flex; align-items: center; justify-content: center; gap: .4rem;
  margin-top: .75rem; font-size: .85rem; color: #7A6840; text-decoration: none;
  transition: color .2s;
}
.continue-link:hover { color: #D4AF37; }

/* Empty cart */
.cart-empty {
  text-align: center; padding: 5rem 2rem;
  background: linear-gradient(145deg,#1c1208,#130d05);
  border: 1px solid rgba(212,175,55,.14); border-radius: 20px;
}
.cart-empty .icon { font-size: 4rem; margin-bottom: 1rem; opacity: .5; }
.cart-empty h2 { color: #C9B88A; font-size: 1.4rem; font-weight: 700; margin-bottom: .5rem; }
.cart-empty p  { color: #7A6840; margin-bottom: 1.5rem; }
.shop-btn {
  display: inline-flex; align-items: center; gap: .5rem;
  background: linear-gradient(135deg,#FFE066,#D4AF37);
  color: #080604; font-weight: 700; padding: .75rem 1.75rem;
  border-radius: 12px; text-decoration: none;
  transition: transform .2s, box-shadow .2s;
}
.shop-btn:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(212,175,55,.3); }

.cart-header-row {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 1rem;
}
.clear-btn {
  background: none; border: 1px solid rgba(239,68,68,.3); color: #EF4444;
  font-size: .78rem; padding: .3rem .75rem; border-radius: 8px; cursor: pointer;
  transition: background .2s;
}
.clear-btn:hover { background: rgba(239,68,68,.1); }
</style>

<div class="container cart-page">

  <?php if (empty($cart)): ?>
    <div class="cart-empty">
      <div class="icon"><i class="bi bi-cart-x"></i></div>
      <h2><?= e(t('storefront.cart.empty_title')) ?></h2>
      <p><?= e(t('storefront.cart.empty_sub')) ?></p>
      <a href="<?= View::url('/') ?>" class="shop-btn"><i class="bi bi-shop"></i> <?= e(t('storefront.cart.start_shopping')) ?></a>
    </div>

  <?php else: ?>
    <div class="cart-header-row">
      <h1 class="cart-heading"><i class="bi bi-cart3"></i> <?= e(t('storefront.cart.heading')) ?></h1>
      <button class="clear-btn" onclick="clearCart()"><i class="bi bi-trash"></i> <?= e(t('storefront.cart.clear_all')) ?></button>
    </div>

    <div class="cart-layout">
      <!-- Items -->
      <div class="cart-items" id="cart-items">
        <?php foreach ($cart as $id => $item): ?>
          <div class="cart-row" id="row-<?= $id ?>">
            <div class="cart-img">
              <?php if ($item['image']): ?>
                <img src="<?= e($item['image']) ?>" alt="<?= e($item['name_en']) ?>" loading="lazy">
              <?php else: ?>
                <div class="cart-img-placeholder"><i class="bi bi-image"></i></div>
              <?php endif; ?>
            </div>
            <div class="cart-info">
              <a href="<?= View::url('/product/' . e($item['slug'])) ?>" class="cart-name"><?= e($locale === 'am' && $item['name_am'] ? $item['name_am'] : $item['name_en']) ?></a>
              <?php if ($item['vendor']): ?>
                <div class="cart-vendor"><i class="bi bi-shop"></i> <?= e($item['vendor']) ?></div>
              <?php endif; ?>
              <div class="cart-price">Br <?= number_format($item['price'], 2) ?></div>
            </div>
            <div class="cart-controls">
              <div class="qty-ctrl">
                <button class="qty-btn" onclick="updateQty(<?= $id ?>, -1)"><i class="bi bi-dash-lg"></i></button>
                <input type="number" class="qty-val" id="qty-<?= $id ?>" value="<?= (int)$item['qty'] ?>" min="1" max="<?= (int)$item['stock'] ?>" onchange="setQty(<?= $id ?>, this.value)">
                <button class="qty-btn" onclick="updateQty(<?= $id ?>, 1)">+</button>
              </div>
              <div class="item-total" id="sub-<?= $id ?>">Br <?= number_format($item['price'] * $item['qty'], 2) ?></div>
              <button class="remove-btn" onclick="removeItem(<?= $id ?>)">
                <i class="bi bi-x-circle"></i> <?= e(t('storefront.cart.remove')) ?>
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Summary -->
      <div class="order-summary">
        <h3><?= e(t('storefront.cart.order_summary')) ?></h3>
        <div class="summary-row"><span><?= e(t('storefront.cart.items_count', ['n' => array_sum(array_column($cart, 'qty'))])) ?></span><span id="sum-subtotal">Br <?= number_format($total, 2) ?></span></div>
        <div class="summary-row"><span><?= e(t('storefront.cart.shipping')) ?></span><span>Br <?= number_format($ship, 2) ?></span></div>
        <div class="summary-row total"><span><?= e(t('storefront.cart.total')) ?></span><span id="sum-total">Br <?= number_format($grand, 2) ?></span></div>

        <a href="<?= View::url('/checkout') ?>" class="checkout-btn">
          <i class="bi bi-lock"></i> <?= e(t('storefront.cart.proceed_to_checkout')) ?>
        </a>
        <a href="<?= View::url('/') ?>" class="continue-link">
          <i class="bi bi-arrow-left"></i> <?= e(t('storefront.cart.continue_shopping')) ?>
        </a>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
const BASE = '<?= rtrim($GLOBALS['__config']['app']['url'] ?? '', '/') ?>';
const SHIP = <?= $ship ?>;

async function updateQty(id, delta) {
  const inp = document.getElementById('qty-' + id);
  const newQty = Math.max(1, parseInt(inp.value) + delta);
  await setQty(id, newQty);
}

async function setQty(id, qty) {
  qty = Math.max(1, parseInt(qty));
  const inp = document.getElementById('qty-' + id);
  inp.value = qty;

  const fd = new FormData();
  fd.append('product_id', id);
  fd.append('qty', qty);

  const r = await fetch(BASE + '/cart/update', { method: 'POST', body: fd });
  const d = await r.json();
  if (d.ok) {
    document.getElementById('sub-' + id).textContent = 'Br ' + fmtMoney(d.subtotal || 0);
    updateTotals(d.total);
  }
}

async function removeItem(id) {
  const fd = new FormData();
  fd.append('product_id', id);
  const r = await fetch(BASE + '/cart/remove', { method: 'POST', body: fd });
  const d = await r.json();
  if (d.ok) {
    const row = document.getElementById('row-' + id);
    if (row) { row.style.opacity = '0'; row.style.transform = 'translateX(-20px)'; setTimeout(() => row.remove(), 300); }
    updateTotals(d.total);
    if (d.count === 0) setTimeout(() => location.reload(), 400);
  }
}

async function clearCart() {
  const items = document.querySelectorAll('.cart-row');
  for (const item of items) {
    const id = item.id.replace('row-', '');
    const fd = new FormData(); fd.append('product_id', id);
    await fetch(BASE + '/cart/remove', { method: 'POST', body: fd });
  }
  location.reload();
}

function updateTotals(subtotal) {
  document.getElementById('sum-subtotal').textContent = 'Br ' + fmtMoney(subtotal);
  document.getElementById('sum-total').textContent    = 'Br ' + fmtMoney(subtotal > 0 ? subtotal + SHIP : 0);
}

function fmtMoney(n) {
  return parseFloat(n).toLocaleString('en-ET', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/public', [
    '__content' => $__content,
    'pageTitle'  => $pageTitle ?? t('storefront.cart.heading') . ' -- Etel Gebeya',
    'locale'     => $locale,
]);
