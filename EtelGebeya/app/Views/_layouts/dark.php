<?php
/**
 * Dark Dashboard Layout -- Admin / Vendor / Courier panels
 * Dark: #0A0A1A * Gold #D4AF37 * Glassmorphism
 *
 * Usage: View::render('admin/dashboard', $data, 'dark');
 *   or: View::render('vendor/products', $data, 'dark');
 *
 * Available data:
 *   $pageTitle   -- string
 *   $user        -- array (from auth)
 *   $navItems    -- array of ['icon', 'label', 'url', 'active', 'badge']
 *   $content     -- passed via $__content (layout closure)
 */
use App\Core\View;
use App\Core\Auth;
$locale    = $GLOBALS['__i18n']->locale();
$user      = Auth::getInstance()->user() ?? [];
$pageTitle = $pageTitle ?? 'Dashboard';
$navItems  = $navItems ?? [];
$roleLabel = $role ?? ($user['roles'][0] ?? 'user');
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> -- Etel Gebeya</title>
  <meta name="theme-color" content="#0A0A1A">
  <link rel="stylesheet" href="<?= View::asset('css/admin.css') ?>">
  <link rel="stylesheet" href="<?= View::asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
</head>
<body>

<div class="dash-wrapper">

  <!-- SIDEBAR -->
  <aside class="dash-sidebar">

    <div class="sidebar-brand">
      <div class="sidebar-logo">E</div>
      <div class="sidebar-brand-text">
        <div class="sidebar-brand-name">Etel Gebeya</div>
        <div class="sidebar-brand-role"><?= e(ucfirst($roleLabel)) ?> Panel</div>
      </div>
    </div>

    <?php if (!empty($seller) && ($roleLabel==='vendor')): ?>
    <div style="margin:0 .75rem .75rem;padding:.85rem 1rem;background:linear-gradient(135deg,rgba(212,175,55,.1),rgba(212,175,55,.03));border:1.5px solid rgba(212,175,55,.2);border-radius:14px">
      <div style="display:flex;align-items:center;gap:.65rem">
        <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#D4AF37,#E8C76B);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:1.1rem;color:#1A1A2E;flex-shrink:0">
          <?= e(mb_substr($seller['store_name']??'S',0,1,'UTF-8')) ?>
        </div>
        <div style="overflow:hidden;flex:1">
          <div style="font-weight:800;font-size:.82rem;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($seller['store_name']??'') ?></div>
          <?php $sellerStatusColor = ($seller['status'] ?? 'active') === 'active' ? '#2ecc71' : (($seller['status'] ?? '') === 'suspended' ? '#e74c3c' : '#D4AF37'); ?>
          <div style="font-size:.68rem;color:<?= $sellerStatusColor ?>;font-weight:700;display:flex;align-items:center;gap:.3rem">
            <span style="width:5px;height:5px;background:<?= $sellerStatusColor ?>;border-radius:50%;display:inline-block;flex-shrink:0"></span>
            Store <?= e(ucfirst($seller['status']??'active')) ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <nav class="sidebar-nav">
      <div class="nav-section-label">Main</div>

      <?php foreach ($navItems as $item): ?>
        <?php
        $isActive = ($item['active'] ?? false);
        $url = $item['url'] ?? '#';
        $icon = $item['icon'] ?? 'bi-circle';
        $label = $item['label'] ?? '';
        $badge = $item['badge'] ?? null;
        ?>
        <a href="<?= e($url) ?>" class="nav-item<?= $isActive ? ' active' : '' ?>">
          <i class="bi <?= e($icon) ?> nav-icon"></i>
          <span><?= e($label) ?></span>
          <?php if ($badge): ?>
            <span class="nav-badge"><?= e($badge) ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>

      <div class="nav-section-label" style="margin-top:1rem;">Account</div>

      <form action="<?= View::url('/logout') ?>" method="post" style="margin-top:.5rem;">
        <?= csrf() ?>
        <button type="submit" class="nav-item" style="width:100%;background:none;border:none;text-align:left;cursor:pointer;font:inherit;">
          <i class="bi bi-box-arrow-right nav-icon"></i>
          <span>Logout</span>
        </button>
      </form>
    </nav>
  </aside>

  <!-- MAIN -->
  <main class="dash-main">

    <header class="dash-topbar">
      <div class="topbar-left">
        <span class="page-title"><?= e($pageTitle) ?></span>
      </div>
      <div class="topbar-right">
        <div class="topbar-stat">
          <div class="topbar-stat-dot"></div>
          Systems Online
        </div>
        <div class="topbar-btn">
          <i class="bi bi-bell"></i>
          <?php if (!empty($notifCount)): ?>
            <span class="nav-badge" style="margin-left:.25rem;"><?= $notifCount ?></span>
          <?php endif; ?>
        </div>
        <a href="<?= View::url('/') ?>" class="topbar-btn" title="View storefront">
          <i class="bi bi-globe2"></i>
        </a>
        <a href="<?= View::url('/lang/' . ($locale === 'am' ? 'en' : 'am')) ?>" class="topbar-btn" title="Toggle language">
          <?= $locale === 'am' ? 'EN' : 'AM' ?>
        </a>
        <div class="user-avatar" title="<?= e($user['full_name'] ?? 'User') ?>">
          <?= e(mb_substr($user['full_name'] ?? 'U', 0, 1, 'UTF-8')) ?>
        </div>
      </div>
    </header>

    <!-- Page content injected here -->
    <div class="dash-content">
      <?= $__content ?? '' ?>
    </div>

  </main>
</div>

<script>
document.querySelectorAll('.nav-item').forEach(el => {
  el.addEventListener('click', () => {
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    el.classList.add('active');
  });
});
</script>
</body>
</html>
