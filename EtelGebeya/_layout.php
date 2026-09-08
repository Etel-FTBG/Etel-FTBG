<?php
/**
 * Customer account sidebar layout.
 * Wrap content in ob_start / ob_get_clean, then include this file.
 * Required vars: $__content, $pageTitle, $locale, $user, $auth
 */
use App\Core\View;
$u        = $user ?? [];
$locale   = $locale ?? 'en';
$current  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base     = rtrim(parse_url(View::url('/'), PHP_URL_PATH) ?? '', '/');
$rel      = substr($current, strlen($base)) ?: '/';

$navItems = [
    ['bi-speedometer2','/account',          'Dashboard',  str_starts_with($rel,'/account')&&!str_contains($rel,'orders')&&!str_contains($rel,'addresses')],
    ['bi-receipt',     '/account/orders',   'My Orders',  str_contains($rel,'orders')],
    ['bi-geo-alt',     '/account/addresses','Addresses',  str_contains($rel,'addresses')],
    ['bi-heart',       '/account/wishlist', 'Wishlist',   str_contains($rel,'wishlist')],
    ['bi-person-gear', '/account',          'Profile',    false],
];
$isAdmin = isset($auth) && $auth->isAdmin();
?>
<style>
/* ?? CUSTOMER PANEL LAYOUT ???????????????????????????????? */
.cp-wrapper{max-width:1100px;margin:0 auto;padding:2rem 1rem;display:grid;grid-template-columns:260px 1fr;gap:1.5rem;align-items:start}
@media(max-width:720px){.cp-wrapper{grid-template-columns:1fr}.cp-sidebar{display:none}}

/* Sidebar */
.cp-sidebar{position:sticky;top:1rem}
.cp-user-card{background:linear-gradient(135deg,rgba(212,175,55,.12),rgba(212,175,55,.04) 60%,rgba(0,0,0,.3));border:1.5px solid var(--gold-line,rgba(212,175,55,.22));border-radius:20px;padding:1.4rem 1.2rem;margin-bottom:1rem;position:relative;overflow:hidden}
.cp-user-card::before{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;background:radial-gradient(circle,rgba(212,175,55,.15),transparent 70%);pointer-events:none}
.cp-avatar{width:58px;height:58px;border-radius:16px;background:var(--grad-gold,linear-gradient(135deg,#FFE066,#D4AF37,#B8902E));display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:900;color:#1a0e00;margin-bottom:.9rem;box-shadow:0 8px 24px rgba(212,175,55,.35);position:relative;z-index:1}
.cp-uname{font-size:1rem;font-weight:900;color:var(--text,#F7EFD9);margin:0 0 .2rem;position:relative;z-index:1}
.cp-uemail{font-size:.72rem;color:var(--text-dim,#C9B88A);position:relative;z-index:1;word-break:break-all}
.cp-ustatus{display:inline-flex;align-items:center;gap:.35rem;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2);border-radius:99px;padding:.2rem .6rem;font-size:.68rem;font-weight:700;color:#10b981;margin-top:.6rem;position:relative;z-index:1}
.cp-ustatus::before{content:'';width:5px;height:5px;background:#10b981;border-radius:50%}

.cp-nav{background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:18px;overflow:hidden;margin-bottom:1rem}
.cp-nav-item{display:flex;align-items:center;gap:.7rem;padding:.75rem 1.1rem;font-size:.85rem;font-weight:700;color:var(--text-dim,#C9B88A);text-decoration:none;border-bottom:1px solid var(--border-dim,rgba(212,175,55,.06));transition:all .2s;position:relative}
.cp-nav-item:last-child{border-bottom:none}
.cp-nav-item::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--g2,#D4AF37);border-radius:0 3px 3px 0;opacity:0;transition:opacity .2s}
.cp-nav-item:hover{background:rgba(212,175,55,.05);color:var(--text,#F7EFD9)}
.cp-nav-item.active{background:linear-gradient(90deg,rgba(212,175,55,.12),rgba(212,175,55,.03));color:var(--g1,#FFE066)}
.cp-nav-item.active::before{opacity:1}
.cp-nav-item i{font-size:1.05rem;width:20px;text-align:center;flex-shrink:0}

.cp-logout{display:flex;align-items:center;gap:.7rem;padding:.7rem 1.1rem;font-size:.82rem;font-weight:700;color:var(--err,#ef4444);text-decoration:none;background:rgba(239,68,68,.04);border:1.5px solid rgba(239,68,68,.1);border-radius:14px;transition:all .2s;cursor:pointer}
.cp-logout:hover{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.25)}

.cp-admin-btn{display:flex;align-items:center;gap:.7rem;padding:.75rem 1.1rem;font-size:.85rem;font-weight:800;color:#FFE066;text-decoration:none;background:linear-gradient(135deg,rgba(212,175,55,.18),rgba(212,175,55,.06));border:1.5px solid rgba(212,175,55,.4);border-radius:14px;margin-bottom:.6rem;transition:all .2s;cursor:pointer;width:100%}
.cp-admin-btn:hover{background:linear-gradient(135deg,rgba(212,175,55,.28),rgba(212,175,55,.1));border-color:#D4AF37;transform:translateY(-1px);box-shadow:0 6px 20px rgba(212,175,55,.2)}
.cp-admin-btn i{color:#D4AF37;font-size:1.05rem}

/* Main content */
.cp-main{}
.cp-page-title{font-size:1.4rem;font-weight:900;color:var(--text,#F7EFD9);margin:0 0 1.25rem;display:flex;align-items:center;gap:.65rem}
.cp-page-title i{color:var(--g2,#D4AF37)}
</style>

<div class="cp-wrapper">

  <!-- Sidebar -->
  <aside class="cp-sidebar">
    <!-- User card -->
    <div class="cp-user-card">
      <div class="cp-avatar"><?= mb_strtoupper(mb_substr($u['full_name']??'U',0,1,'UTF-8'),'UTF-8') ?></div>
      <div class="cp-uname"><?= e($u['full_name'] ?? 'My Account') ?></div>
      <div class="cp-uemail"><?= e($u['email'] ?? '') ?></div>
      <div class="cp-ustatus">Verified Customer</div>
    </div>

    <!-- Nav -->
    <div class="cp-nav">
      <?php foreach ($navItems as [$icon,$url,$label,$active]): ?>
        <a href="<?= View::url($url) ?>" class="cp-nav-item<?= $active?' active':'' ?>">
          <i class="bi <?= $icon ?>"></i>
          <span><?= $label ?></span>
          <?php if ($active): ?><i class="bi bi-chevron-right" style="margin-left:auto;font-size:.7rem;opacity:.5"></i><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($isAdmin):
      // Generate a 90-second HMAC nonce — signed server-side, verified by api/admin.php
      // No session sharing needed: PHP already confirmed admin status above.
      $eg_nonce_ts  = time();
      $eg_nonce_uid = $auth->id() ?? 0;
      $eg_nonce_sig = hash_hmac('sha256', $eg_nonce_ts . ':' . $eg_nonce_uid, 'CHANGE_ME.admin');
      $eg_nonce     = base64_encode($eg_nonce_ts . ':' . $eg_nonce_uid . ':' . $eg_nonce_sig);
      $eg_base      = rtrim(parse_url($GLOBALS['__config']['app']['url'] ?? '', PHP_URL_PATH) ?? '', '/');
    ?>
    <!-- Admin switcher — only visible to EtelID admins -->
    <button type="button" class="cp-admin-btn" onclick="goToAdmin()" id="admin-switch-btn">
      <i class="bi bi-shield-fill-check"></i>
      <span>Admin Panel</span>
      <i class="bi bi-arrow-right-circle" style="margin-left:auto;font-size:.85rem;opacity:.7"></i>
    </button>
    <?php endif; ?>

    <!-- Store link -->
    <a href="<?= View::url('/') ?>" style="display:flex;align-items:center;gap:.65rem;padding:.7rem 1.1rem;font-size:.82rem;font-weight:700;color:var(--text-dim,#C9B88A);text-decoration:none;background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:14px;margin-bottom:.6rem;transition:all .2s" onmouseover="this.style.borderColor='rgba(212,175,55,.25)'" onmouseout="this.style.borderColor=''">
      <i class="bi bi-shop" style="color:var(--g2,#D4AF37)"></i> Back to Store
    </a>

    <!-- Logout -->
    <form method="POST" action="<?= View::url('/logout') ?>">
      <button type="submit" class="cp-logout" style="width:100%;border-radius:14px">
        <i class="bi bi-box-arrow-right"></i> Sign Out
      </button>
    </form>

    <?php if ($isAdmin): ?>
    <script>
    async function goToAdmin() {
      const btn = document.getElementById('admin-switch-btn');
      btn.innerHTML = '<i class="bi bi-hourglass-split"></i> <span>Opening…</span>';
      btn.style.opacity = '0.7'; btn.disabled = true;
      try {
        const base = '<?= $eg_base ?>';
        const r = await fetch(base + '/api/admin.php?action=nonce_login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'nonce=<?= rawurlencode($eg_nonce) ?>'
        });
        const d = await r.json();
        if (d.ok && d.data?.token) {
          localStorage.setItem('eg_admin_token', d.data.token);
          window.location.href = base + '/admin';
        } else {
          alert('Admin access denied: ' + (d.error || 'unknown'));
          btn.innerHTML = '<i class="bi bi-shield-fill-check"></i><span>Admin Panel</span>';
          btn.style.opacity = '1'; btn.disabled = false;
        }
      } catch(e) {
        alert('Network error: ' + e.message);
        btn.innerHTML = '<i class="bi bi-shield-fill-check"></i><span>Admin Panel</span>';
        btn.style.opacity = '1'; btn.disabled = false;
      }
    }
    </script>
    <?php endif; ?>
  </aside>

  <!-- Main -->
  <div class="cp-main">
    <?= $__content ?? '' ?>
  </div>

</div>
<!-- Admin Health Monitor widget -->
<script src="<?= View::url('/health-monitor.js') ?>?v=1" defer></script>
<!-- ETEL AI -- cross-company assistant widget -->
<script src="https://ethelmarket.com/EtelAi/etel-ai-widget.js" data-project="gebeya"></script>
