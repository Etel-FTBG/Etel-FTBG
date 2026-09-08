<?php
use App\Core\View;
require __DIR__.'/_layout.php';
$pageTitle = ucfirst('settings');
ob_start();
?>
<style>
.ag-bar-title{font-size:18px;font-weight:800;color:var(--dark-text);margin-bottom:16px}
.ag-card{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--r-md);overflow:hidden}
.ag-tbl{width:100%;border-collapse:collapse}
.ag-tbl th{background:rgba(255,255,255,.03);padding:10px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--dark-muted);text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--dark-border);white-space:nowrap}
.ag-tbl td{padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12.5px;color:var(--dark-muted);vertical-align:middle}
.ag-tbl tr:last-child td{border-bottom:none}
.ag-tbl tr:hover td{background:rgba(255,255,255,.02)}
.flash-success{background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.25);color:var(--green);padding:11px 15px;border-radius:var(--r-sm);margin-bottom:16px;font-size:13px}
.flash-error{background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.25);color:var(--red);padding:11px 15px;border-radius:var(--r-sm);margin-bottom:16px;font-size:13px}
.badge{display:inline-block;padding:3px 8px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap}
.badge-paid,.badge-active,.badge-admin{background:rgba(46,204,113,.15);color:var(--green);border:1px solid rgba(46,204,113,.25)}
.badge-unpaid,.badge-customer{background:rgba(212,175,55,.12);color:var(--gold);border:1px solid rgba(212,175,55,.2)}
.badge-pending{background:rgba(96,180,255,.15);color:var(--blue);border:1px solid rgba(96,180,255,.25)}
.badge-vendor{background:rgba(155,89,182,.15);color:var(--purple);border:1px solid rgba(155,89,182,.25)}
.badge-courier{background:rgba(78,205,196,.15);color:var(--teal);border:1px solid rgba(78,205,196,.25)}
.ag-inp{background:rgba(255,255,255,.05);border:1px solid var(--dark-border);color:var(--dark-text);font-family:inherit;font-size:12.5px;padding:8px 12px;border-radius:var(--r-sm);outline:none;width:100%;max-width:340px}
.ag-inp:focus{border-color:var(--gold)}
.ag-sel{background:rgba(255,255,255,.05);border:1px solid var(--dark-border);color:var(--dark-text);font-family:inherit;font-size:12.5px;padding:8px 12px;border-radius:var(--r-sm);cursor:pointer;outline:none}
.ag-sel option{background:#0A0A1A}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:6px;font-size:11.5px;font-weight:700;cursor:pointer;border:none;transition:opacity .12s;text-decoration:none}
.btn-gold-xs{background:linear-gradient(135deg,var(--gold-br),var(--gold));color:#1A1A2E}
.btn-ghost-xs{background:rgba(212,175,55,.1);color:var(--gold);border:1px solid rgba(212,175,55,.2)}
.btn-xs:hover{opacity:.8}
.ag-pagination{display:flex;gap:5px;align-items:center;justify-content:center;padding:14px;border-top:1px solid var(--dark-border)}
.pg-link{padding:5px 11px;border-radius:6px;border:1px solid var(--dark-border);color:var(--dark-muted);font-size:12px;text-decoration:none}
.pg-link:hover,.pg-link.cur{background:rgba(212,175,55,.12);color:var(--gold);border-color:rgba(212,175,55,.3)}
.empty-s{text-align:center;padding:48px 20px;color:var(--dark-muted);font-size:13px}
</style>

<?php if (!empty($flash)): ?>
  <div class="flash-<?= e($flash['type'] ?? 'success') ?>"><?= e($flash['msg'] ?? '') ?></div>
<?php endif; ?>

<div class="ag-bar-title"><?= ucfirst('settings') ?></div>


<div class="ag-card" style="padding:24px;max-width:640px">
  <div style="font-size:14px;font-weight:700;color:var(--dark-text);margin-bottom:18px">Platform Settings</div>
  <form method="POST" action="<?= View::url('/admin/settings') ?>">
    <?= csrf() ?>
    <div style="display:grid;gap:14px">
      <div>
        <label style="font-size:11px;color:var(--dark-muted);display:block;margin-bottom:5px;font-weight:700">App Name</label>
        <input type="text" name="app_name" class="ag-inp" value="<?= e($cfg['app']['name'] ?? 'Etel Gebeya') ?>" style="max-width:none;width:100%">
      </div>
      <div>
        <label style="font-size:11px;color:var(--dark-muted);display:block;margin-bottom:5px;font-weight:700">App URL</label>
        <input type="text" name="app_url" class="ag-inp" value="<?= e($cfg['app']['url'] ?? '') ?>" style="max-width:none;width:100%">
      </div>
      <div>
        <label style="font-size:11px;color:var(--dark-muted);display:block;margin-bottom:5px;font-weight:700">Debug Mode</label>
        <select name="app_debug" class="ag-sel">
          <option value="0" <?= empty($cfg['app']['debug'])?'selected':'' ?>>Off (production)</option>
          <option value="1" <?= !empty($cfg['app']['debug'])?'selected':'' ?>>On (development)</option>
        </select>
      </div>
      <div style="padding-top:8px;border-top:1px solid var(--dark-border)">
        <p style="font-size:11.5px;color:var(--dark-muted);margin-bottom:12px">
          Settings are stored in <code style="background:rgba(255,255,255,.06);padding:2px 6px;border-radius:4px;font-size:10.5px">config/app.php</code>.
          File-write access may be limited on shared hosting -- changes applied here take effect on next deploy.
        </p>
        <button type="submit" class="btn-xs btn-gold-xs" style="padding:8px 20px;font-size:13px">Save Settings</button>
      </div>
    </div>
  </form>
</div>

<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), [
    '__content' => $__content,
    'navItems'  => $navItems,
    'role'      => 'admin',
    'pageTitle' => $pageTitle,
]));
