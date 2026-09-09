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
  <meta name="description" content="Ethiopia's premium marketplace. Shop electronics, fashion, food and more.">
  <meta name="theme-color" content="#D4AF37">
  <link rel="icon" type="image/svg+xml" href="<?= View::url('/public/favicon.svg') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800;900&family=Noto+Sans+Ethiopic:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= View::asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
  <link rel="stylesheet" href="<?= View::asset('css/etel-v2.css') ?>">
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
    <span>Buy in Birr. Pay with Telebirr, CBE Birr or Cards.</span>
    <span class="eg-topbar-divider"></span>
    <a href="<?= View::url('/sell') ?>"><i class="bi bi-shop"></i> Sell on Etel Gebeya</a>
  </div>
  <div class="eg-topbar-right">
    <div class="eg-lang">
      <a href="<?= View::url('/lang/en') ?>" class="<?= $locale==='en'?'active':'' ?>">EN</a>
      <a href="<?= View::url('/lang/am') ?>" class="<?= $locale==='am'?'active':'' ?>">Am</a>
    </div>
    <span class="eg-topbar-divider"></span>
    <span>ETB &bull; Ethiopian Birr</span>
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
      <input type="search" name="q" placeholder="Search products, brands, categories..."
             value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off">
      <button type="submit" class="eg-search-btn"><i class="bi bi-search"></i></button>
    </form>
  </div>

  <div class="eg-header-actions">
    <a href="<?= View::url('/cart') ?>" class="eg-header-btn">
      <i class="bi bi-cart3 ico"></i>
      <span>Cart</span>
      <?php if ($cartCount > 0): ?>
        <span class="eg-cart-badge"><?= $cartCount > 9 ? '9+' : $cartCount ?></span>
      <?php endif; ?>
    </a>

    <?php if ($user): ?>
      <a href="<?= View::url('/account') ?>" class="eg-header-btn">
        <i class="bi bi-person-circle ico"></i>
        <span><?= e(explode(' ', $user['full_name'] ?? 'Account')[0]) ?></span>
      </a>
      <a href="<?= View::url('/account/orders') ?>" class="eg-header-btn">
        <i class="bi bi-receipt ico"></i>
        <span>Orders</span>
      </a>
    <?php else: ?>
      <a href="<?= View::url('/login') ?>" class="eg-header-btn">
        <i class="bi bi-person ico"></i>
        <span>Login</span>
      </a>
      <a href="<?= View::url('/register') ?>" class="eg-header-btn" style="color:var(--g2)">
        <i class="bi bi-person-plus ico"></i>
        <span>Register</span>
      </a>
    <?php endif; ?>
  </div>
</header>

<!-- CATEGORY NAV BAR -->
<div class="eg-catnav">
  <div class="eg-shell">
    <div class="eg-catnav-inner">
      <a href="<?= View::url('/') ?>" class="eg-catnav-item <?= !isset($_GET['cat']) && strpos($_SERVER['REQUEST_URI']??'','/category/') === false ? 'active' : '' ?>">
        <i class="bi bi-house"></i> Home
      </a>
      <a href="<?= View::url('/search') ?>" class="eg-catnav-item">
        <i class="bi bi-grid"></i> All Products
      </a>
      <a href="<?= View::url('/search?q=electronics') ?>" class="eg-catnav-item">
        <i class="bi bi-phone"></i> Electronics
      </a>
      <a href="<?= View::url('/search?q=fashion') ?>" class="eg-catnav-item">
        <i class="bi bi-bag"></i> Fashion
      </a>
      <a href="<?= View::url('/search?q=home') ?>" class="eg-catnav-item">
        <i class="bi bi-house-door"></i> Home &amp; Living
      </a>
      <a href="<?= View::url('/search?q=food') ?>" class="eg-catnav-item">
        <i class="bi bi-egg-fried"></i> Food &amp; Grocery
      </a>
      <a href="<?= View::url('/search?q=health') ?>" class="eg-catnav-item">
        <i class="bi bi-heart-pulse"></i> Health
      </a>
      <a href="<?= View::url('/search?q=office') ?>" class="eg-catnav-item">
        <i class="bi bi-briefcase"></i> Office
      </a>
      <?php if ($user): ?>
        <?php if ($GLOBALS['__auth']->isAdmin()): ?>
          <a href="<?= View::url('/admin') ?>" class="eg-catnav-item" style="color:var(--g2)">
            <i class="bi bi-shield-check"></i> Admin
          </a>
        <?php endif; ?>
        <?php if ($GLOBALS['__auth']->isVendor()): ?>
          <a href="<?= View::url('/vendor-panel') ?>" class="eg-catnav-item" style="color:var(--g2)">
            <i class="bi bi-shop"></i> My Store
          </a>
        <?php endif; ?>
        <form action="<?= View::url('/logout') ?>" method="POST" style="display:inline">
          <?= csrf() ?>
          <button type="submit" class="eg-catnav-item" style="font-size:12.5px">
            <i class="bi bi-box-arrow-right"></i> Logout
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
      <div class="eg-footer-tagline">Ethiopia's premium marketplace. Buy and sell anything, delivered to your door.</div>
      <div style="font-size:11.5px;color:var(--text3)">
        <i class="bi bi-geo-alt"></i> Hawassa, Sidama, Ethiopia
      </div>
    </div>
    <div>
      <div class="eg-footer-col-title">Shop</div>
      <div class="eg-footer-links">
        <a href="<?= View::url('/search') ?>">All Products</a>
        <a href="<?= View::url('/search?q=electronics') ?>">Electronics</a>
        <a href="<?= View::url('/search?q=fashion') ?>">Fashion</a>
        <a href="<?= View::url('/search?q=food') ?>">Food &amp; Grocery</a>
        <a href="<?= View::url('/search?q=home') ?>">Home &amp; Living</a>
      </div>
    </div>
    <div>
      <div class="eg-footer-col-title">Sell</div>
      <div class="eg-footer-links">
        <a href="<?= View::url('/sell') ?>">Become a Vendor</a>
        <a href="<?= View::url('/vendor-panel') ?>">Vendor Dashboard</a>
        <a href="<?= View::url('/sell') ?>">Seller Guide</a>
      </div>
    </div>
    <div>
      <div class="eg-footer-col-title">Account</div>
      <div class="eg-footer-links">
        <a href="<?= View::url('/account') ?>">My Account</a>
        <a href="<?= View::url('/account/orders') ?>">My Orders</a>
        <a href="<?= View::url('/account/wishlist') ?>">Wishlist</a>
        <a href="<?= View::url('/account/addresses') ?>">Addresses</a>
      </div>
    </div>
    <div>
      <div class="eg-footer-col-title">Pay With</div>
      <div class="eg-footer-links">
        <a href="#"><i class="bi bi-phone"></i> Telebirr</a>
        <a href="#"><i class="bi bi-bank"></i> CBE Birr</a>
        <a href="#"><i class="bi bi-credit-card"></i> Bank Transfer</a>
        <a href="#"><i class="bi bi-cash-coin"></i> Cash on Delivery</a>
      </div>
    </div>
  </div>
  <div class="eg-footer-bottom">
    <span>&copy; <?= date('Y') ?> <strong>Etel Gebeya</strong> &mdash; Etel Group &bull; Hawassa, Ethiopia</span>
    <span style="color:var(--text3)">Built with <span style="color:var(--g2)">&#9733;</span> in Ethiopia</span>
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
