<?php
/**
 * Etel Gebeya v2 -- Public layout (Alibaba-inspired gold/dark)
 * Fixes: UTF-8 charset header + Bootstrap Icons + new CSS
 */
use App\Core\View;
header('Content-Type: text/html; charset=UTF-8');
$locale = $GLOBALS['__i18n']->locale();
$cfg    = $GLOBALS['__config'];
$user   = $GLOBALS['__auth']->user();
$flash  = isset($flash) && is_callable($flash) ? $flash() : ($flash ?? null);
$cartCount = (int)($_SESSION['cart_count'] ?? array_sum(array_column($_SESSION['cart'] ?? [], 'qty')));
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= isset($pageTitle) ? e($pageTitle) . ' -- ' : '' ?>Etel Gebeya</title>
  <meta name="description" content="<?= e(t('storefront.meta_description')) ?>">
  <meta name="theme-color" content="#D4AF37">
  <link rel="icon" type="image/svg+xml" href="<?= View::url('/public/favicon.svg') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=Noto+Sans+Ethiopic:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= View::asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
  <link rel="stylesheet" href="<?= View::asset('css/etel-v2.css') ?>">
  <!-- etel.css supplies classes several storefront pages (category, product,
       search, sell, vendor) still use, plus a --eg-* variable compat layer
       aliased onto etel-v2.css's tokens -- see its own backwards-compat
       block. It was written to be loaded alongside etel-v2.css but the
       <link> was never added, leaving those pages unstyled. -->
  <link rel="stylesheet" href="<?= View::asset('css/etel.css') ?>">
  <script>
  // Defined in <head> (not the footer) so it exists before any product image's
  // onerror can fire during initial parsing -- broken photo -> neutral placeholder.
  function egImgFallback(img) {
    img.onerror = null;
    var ph = document.createElement('div');
    ph.className = 'eg-card-img-placeholder';
    ph.innerHTML = '<i class="bi bi-image"></i>';
    img.replaceWith(ph);
  }
  </script>
</head>
<body>

<!-- TOP BAR -->
<div class="eg-topbar">
  <div class="eg-topbar-left">
    <span><?= e(t('storefront.topbar.pay_line')) ?></span>
    <span class="eg-topbar-divider"></span>
    <a href="<?= View::url('/sell') ?>"><i class="bi bi-shop"></i> <?= e(t('storefront.topbar.sell_link')) ?></a>
  </div>
  <div class="eg-topbar-right">
    <div class="eg-lang">
      <a href="<?= View::url('/lang/en') ?>" class="<?= $locale==='en'?'active':'' ?>">EN</a>
      <a href="<?= View::url('/lang/am') ?>" class="<?= $locale==='am'?'active':'' ?>">Am</a>
    </div>
    <span class="eg-topbar-divider"></span>
    <span><?= e(t('storefront.topbar.currency_line')) ?></span>
  </div>
</div>

<!-- MAIN HEADER -->
<header class="eg-header">
  <a href="<?= View::url('/') ?>" class="eg-logo">
    <div class="eg-logo-mark">&#x12A4;</div>
    <div>
      <div class="eg-logo-name">Etel Gebeya</div>
      <div class="eg-logo-tag">&#x12A4;&#x1275;&#x12A4;&#x120D; &#x308;&#x120D;</div>
    </div>
  </a>

  <div class="eg-search">
    <form action="<?= View::url('/search') ?>" method="GET" style="display:flex;flex:1">
      <input type="search" name="q" placeholder="<?= e(t('site.search.placeholder')) ?>"
             value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off">
      <button type="submit" class="eg-search-btn"><i class="bi bi-search"></i></button>
    </form>
  </div>

  <div class="eg-lang eg-lang-mobile">
    <a href="<?= View::url('/lang/en') ?>" class="<?= $locale==='en'?'active':'' ?>">EN</a>
    <a href="<?= View::url('/lang/am') ?>" class="<?= $locale==='am'?'active':'' ?>">Am</a>
  </div>

  <div class="eg-header-actions">
    <a href="<?= View::url('/cart') ?>" class="eg-header-btn">
      <i class="bi bi-cart3 ico"></i>
      <span><?= e(t('storefront.nav.cart')) ?></span>
      <?php if ($cartCount > 0): ?>
        <span class="eg-cart-badge"><?= $cartCount > 9 ? '9+' : $cartCount ?></span>
      <?php endif; ?>
    </a>

    <?php if ($user): ?>
      <a href="<?= View::url('/account') ?>" class="eg-header-btn">
        <i class="bi bi-person-circle ico"></i>
        <span><?= e(explode(' ', $user['full_name'] ?? t('storefront.nav.account'))[0]) ?></span>
      </a>
      <a href="<?= View::url('/account/orders') ?>" class="eg-header-btn">
        <i class="bi bi-receipt ico"></i>
        <span><?= e(t('storefront.nav.orders')) ?></span>
      </a>
    <?php else: ?>
      <a href="<?= View::url('/login') ?>" class="eg-header-btn">
        <i class="bi bi-person ico"></i>
        <span><?= e(t('storefront.nav.login')) ?></span>
      </a>
      <a href="<?= View::url('/register') ?>" class="eg-header-btn" style="color:var(--g2)">
        <i class="bi bi-person-plus ico"></i>
        <span><?= e(t('storefront.nav.register')) ?></span>
      </a>
    <?php endif; ?>
  </div>
</header>

<!-- CATEGORY NAV BAR -->
<div class="eg-catnav">
  <div class="eg-shell">
    <div class="eg-catnav-inner">
      <a href="<?= View::url('/') ?>" class="eg-catnav-item <?= !isset($_GET['cat']) && strpos($_SERVER['REQUEST_URI']??'','/category/') === false ? 'active' : '' ?>">
        <i class="bi bi-house"></i> <?= e(t('storefront.catnav.home')) ?>
      </a>
      <a href="<?= View::url('/search') ?>" class="eg-catnav-item">
        <i class="bi bi-grid"></i> <?= e(t('storefront.catnav.all_products')) ?>
      </a>
      <a href="<?= View::url('/search?q=electronics') ?>" class="eg-catnav-item">
        <i class="bi bi-phone"></i> <?= e(t('storefront.catnav.electronics')) ?>
      </a>
      <a href="<?= View::url('/search?q=fashion') ?>" class="eg-catnav-item">
        <i class="bi bi-bag"></i> <?= e(t('storefront.catnav.fashion')) ?>
      </a>
      <a href="<?= View::url('/search?q=home') ?>" class="eg-catnav-item">
        <i class="bi bi-house-door"></i> <?= e(t('storefront.catnav.home_living')) ?>
      </a>
      <a href="<?= View::url('/search?q=food') ?>" class="eg-catnav-item">
        <i class="bi bi-egg-fried"></i> <?= e(t('storefront.catnav.food_grocery')) ?>
      </a>
      <a href="<?= View::url('/search?q=health') ?>" class="eg-catnav-item">
        <i class="bi bi-heart-pulse"></i> <?= e(t('storefront.catnav.health')) ?>
      </a>
      <a href="<?= View::url('/search?q=office') ?>" class="eg-catnav-item">
        <i class="bi bi-briefcase"></i> <?= e(t('storefront.catnav.office')) ?>
      </a>
      <?php if ($user): ?>
        <?php if ($GLOBALS['__auth']->isAdmin()): ?>
          <a href="<?= View::url('/admin') ?>" class="eg-catnav-item" style="color:var(--g2)">
            <i class="bi bi-shield-check"></i> <?= e(t('storefront.catnav.admin')) ?>
          </a>
        <?php endif; ?>
        <?php if ($GLOBALS['__auth']->isVendor()): ?>
          <a href="<?= View::url('/vendor-panel') ?>" class="eg-catnav-item" style="color:var(--g2)">
            <i class="bi bi-shop"></i> <?= e(t('storefront.catnav.my_store')) ?>
          </a>
        <?php endif; ?>
        <form action="<?= View::url('/logout') ?>" method="POST" style="display:inline">
          <?= csrf() ?>
          <button type="submit" class="eg-catnav-item" style="font-size:12.5px">
            <i class="bi bi-box-arrow-right"></i> <?= e(t('storefront.catnav.logout')) ?>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- PAGE CONTENT -->
<main>
  <div class="eg-shell">
    <?php if ($flash): ?>
      <div class="eg-flash eg-flash-<?= e($flash['type'] ?? 'info') ?>">
        <?= e($flash['msg'] ?? $flash['message'] ?? '') ?>
      </div>
    <?php endif; ?>
    <?= $__content ?? '' ?>
  </div>
</main>

<!-- FOOTER -->
<footer class="eg-footer">
  <div class="eg-footer-grid">
    <div>
      <div class="eg-footer-logo-mark">Etel Gebeya</div>
      <div class="eg-footer-tagline"><?= e(t('storefront.footer.tagline')) ?></div>
      <div style="font-size:11.5px;color:var(--text3)">
        <i class="bi bi-geo-alt"></i> <?= e(t('storefront.footer.location')) ?>
      </div>
    </div>
    <div>
      <div class="eg-footer-col-title"><?= e(t('storefront.footer.shop_head')) ?></div>
      <div class="eg-footer-links">
        <a href="<?= View::url('/search') ?>"><?= e(t('storefront.catnav.all_products')) ?></a>
        <a href="<?= View::url('/search?q=electronics') ?>"><?= e(t('storefront.catnav.electronics')) ?></a>
        <a href="<?= View::url('/search?q=fashion') ?>"><?= e(t('storefront.catnav.fashion')) ?></a>
        <a href="<?= View::url('/search?q=food') ?>"><?= e(t('storefront.catnav.food_grocery')) ?></a>
        <a href="<?= View::url('/search?q=home') ?>"><?= e(t('storefront.catnav.home_living')) ?></a>
      </div>
    </div>
    <div>
      <div class="eg-footer-col-title"><?= e(t('storefront.footer.sell_head')) ?></div>
      <div class="eg-footer-links">
        <a href="<?= View::url('/sell') ?>"><?= e(t('storefront.footer.become_vendor')) ?></a>
        <a href="<?= View::url('/vendor-panel') ?>"><?= e(t('storefront.footer.vendor_dashboard')) ?></a>
        <a href="<?= View::url('/sell') ?>"><?= e(t('storefront.footer.seller_guide')) ?></a>
      </div>
    </div>
    <div>
      <div class="eg-footer-col-title"><?= e(t('storefront.footer.account_head')) ?></div>
      <div class="eg-footer-links">
        <a href="<?= View::url('/account') ?>"><?= e(t('storefront.footer.my_account')) ?></a>
        <a href="<?= View::url('/account/orders') ?>"><?= e(t('storefront.footer.my_orders')) ?></a>
        <a href="<?= View::url('/account/wishlist') ?>"><?= e(t('storefront.footer.wishlist')) ?></a>
        <a href="<?= View::url('/account/addresses') ?>"><?= e(t('storefront.footer.addresses')) ?></a>
      </div>
    </div>
    <div>
      <div class="eg-footer-col-title"><?= e(t('storefront.footer.pay_with_head')) ?></div>
      <div class="eg-footer-links">
        <a href="#"><i class="bi bi-phone"></i> Telebirr</a>
        <a href="#"><i class="bi bi-bank"></i> CBE Birr</a>
        <a href="#"><i class="bi bi-credit-card"></i> <?= e(t('storefront.footer.bank_transfer')) ?></a>
        <a href="#"><i class="bi bi-cash-coin"></i> <?= e(t('storefront.footer.cash_on_delivery')) ?></a>
      </div>
    </div>
  </div>
  <div class="eg-footer-bottom">
    <span>&copy; <?= date('Y') ?> <strong>Etel Gebeya</strong> &mdash; <?= e(t('storefront.footer.copyright_line')) ?></span>
    <span style="color:var(--text3)"><?= e(t('storefront.footer.built_with')) ?> <span style="color:var(--g2)">&#9733;</span> <?= e(t('storefront.footer.in_ethiopia')) ?></span>
  </div>
</footer>

<script>
// Cart AJAX
function egAddToCart(id, qty, btn) {
  var orig = btn.innerHTML;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Adding...';
  btn.disabled = true;
  fetch('<?= View::url('/cart/add') ?>', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
    body: 'product_id=' + id + '&qty=' + qty + '&_token=<?= $GLOBALS['__auth']->csrfToken() ?>'
  })
  .then(function(r){return r.json()})
  .then(function(d){
    if (d.ok) {
      btn.innerHTML = '<i class="bi bi-check-lg"></i> Added!';
      btn.style.background = 'linear-gradient(135deg,#2ecc71,#27ae60)';
      var badge = document.querySelector('.eg-cart-badge');
      if (badge) badge.textContent = d.count || '';
      else if (d.count) {
        var cb = document.createElement('span');
        cb.className = 'eg-cart-badge';
        cb.textContent = d.count;
        document.querySelector('[href*="/cart"]').appendChild(cb);
      }
      setTimeout(function(){btn.innerHTML=orig;btn.disabled=false;btn.style.background='';}, 2000);
    } else {
      btn.innerHTML = '<i class="bi bi-exclamation-circle"></i> Failed';
      setTimeout(function(){btn.innerHTML=orig;btn.disabled=false;}, 2000);
    }
  })
  .catch(function(){btn.innerHTML=orig;btn.disabled=false;});
}

// Wishlist AJAX toggle
function egToggleWishlist(id, btn) {
  var icon = btn.querySelector('i');
  var wasActive = btn.classList.contains('active');
  var action = wasActive ? '/account/wishlist/remove' : '/account/wishlist/add';
  fetch('<?= View::url('') ?>' + action, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
    body: 'product_id=' + id + '&_token=<?= $GLOBALS['__auth']->csrfToken() ?>'
  })
  .then(function(r){return r.json()})
  .then(function(d){
    if (d.login_required) { window.location.href = '<?= View::url('/login') ?>'; return; }
    if (d.ok) {
      btn.classList.toggle('active', !wasActive);
      if (icon) icon.className = !wasActive ? 'bi bi-heart-fill' : 'bi bi-heart';
    }
  })
  .catch(function(){});
}
</script>
<!-- Admin Health Monitor -- activates only when eg_admin_token present in localStorage -->
<script src="<?= View::url('/health-monitor.js') ?>?v=1" defer></script>
<!-- ETEL AI -- cross-company assistant widget -->
<script src="https://ethelmarket.com/EtelAi/etel-ai-widget.js" data-project="gebeya"></script>
</body>
</html>
