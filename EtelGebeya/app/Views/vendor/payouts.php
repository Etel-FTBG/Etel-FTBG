<?php
use App\Core\View;
require __DIR__.'/_layout.php';
$earned    = $earned ?? 0.0;
$available = $available ?? 0.0;
$history   = $history ?? [];
ob_start();
?>
<div>
  <div class="dash-header">
    <div><h1 class="dash-title">Payouts</h1><p class="dash-sub">Track your earnings and withdrawal requests</p></div>
  </div>

  <?php if (!empty($flash)): ?>
    <div style="padding:.8rem 1.2rem;border-radius:10px;margin-bottom:1rem;background:<?= $flash['type']==='error'?'rgba(239,68,68,.15)':'rgba(16,185,129,.15)' ?>;color:<?= $flash['type']==='error'?'#ef4444':'#10b981' ?>;border:1px solid currentColor">
      <?= e($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem">
    <div class="form-card">
      <div style="font-size:.78rem;color:var(--eg-text-dim,#aaa);font-weight:700;text-transform:uppercase;letter-spacing:.05em">Total Earned</div>
      <div style="font-size:1.8rem;font-weight:900;color:#F7EFD9;margin-top:.3rem">Br <?= number_format($earned, 2) ?></div>
      <div style="font-size:.75rem;color:var(--eg-text-dim,#aaa);margin-top:.2rem">From delivered orders</div>
    </div>
    <div class="form-card">
      <div style="font-size:.78rem;color:var(--eg-text-dim,#aaa);font-weight:700;text-transform:uppercase;letter-spacing:.05em">Available to Withdraw</div>
      <div style="font-size:1.8rem;font-weight:900;color:var(--eg-gold,#D4AF37);margin-top:.3rem">Br <?= number_format($available, 2) ?></div>
      <div style="font-size:.75rem;color:var(--eg-text-dim,#aaa);margin-top:.2rem">After pending/paid payouts</div>
    </div>
  </div>

  <form method="POST" action="<?= View::url('/vendor-panel/payouts/request') ?>">
    <?= csrf() ?>
    <div class="form-card" style="max-width:500px;margin-bottom:1.5rem">
      <h3 class="form-card-title">Request a Payout</h3>
      <div class="form-group">
        <label>Amount (ETB)</label>
        <input type="number" name="amount" min="1" step="0.01" max="<?= $available ?>" placeholder="e.g. 500" required>
      </div>
      <div style="font-size:.78rem;color:var(--eg-text-dim,#aaa);margin-bottom:1rem">
        Paid out via ኢE Pay to <strong><?= e($seller['phone'] ?? 'no phone on file') ?></strong>
        <?php if (empty($seller['phone'])): ?>
          &mdash; <a href="<?= View::url('/vendor-panel/settings') ?>" style="color:var(--eg-gold,#D4AF37)">add one in Settings</a>
        <?php endif; ?>
      </div>
      <button type="submit" class="btn-gold" <?= ($available <= 0 || empty($seller['phone'])) ? 'disabled style="opacity:.5;cursor:not-allowed"' : '' ?>>
        <i class="bi bi-cash-coin"></i> Request Payout
      </button>
    </div>
  </form>

  <div class="form-card">
    <h3 class="form-card-title">Payout History</h3>
    <?php if (empty($history)): ?>
      <p style="color:var(--eg-text-dim,#aaa);font-size:.85rem">No payout requests yet.</p>
    <?php else: ?>
      <table style="width:100%;border-collapse:collapse;font-size:.85rem">
        <thead>
          <tr style="text-align:left;color:var(--eg-text-dim,#aaa);font-size:.72rem;text-transform:uppercase">
            <th style="padding:.5rem 0">Date</th><th>Amount</th><th>Status</th><th>Reference</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr style="border-top:1px solid rgba(255,255,255,.06)">
            <td style="padding:.6rem 0"><?= date('M j, Y', strtotime($h['requested_at'])) ?></td>
            <td>Br <?= number_format((float)$h['amount'], 2) ?></td>
            <td>
              <span style="padding:.15rem .55rem;border-radius:99px;font-size:.7rem;font-weight:800;text-transform:uppercase;
                background:<?= $h['status']==='completed'?'rgba(16,185,129,.15)':($h['status']==='rejected'?'rgba(239,68,68,.15)':'rgba(212,175,55,.15)') ?>;
                color:<?= $h['status']==='completed'?'#10b981':($h['status']==='rejected'?'#ef4444':'var(--eg-gold,#D4AF37)') ?>">
                <?= e($h['status']) ?>
              </span>
            </td>
            <td style="font-family:monospace;font-size:.78rem"><?= e($h['txn_ref'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<style>
.btn-gold{display:inline-flex;align-items:center;gap:.4rem;background:linear-gradient(135deg,#FFE066,#D4AF37);color:#080604;font-weight:800;padding:.6rem 1.3rem;border-radius:10px;text-decoration:none;font-size:.9rem;border:none;cursor:pointer}
.form-card{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:16px;padding:1.5rem}
.form-card-title{font-size:.95rem;font-weight:800;margin:0 0 1.2rem;color:var(--eg-gold,#D4AF37)}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:.82rem;color:var(--eg-text-dim,#aaa);margin-bottom:.4rem;font-weight:600}
.form-group input{width:100%;padding:.65rem .9rem;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.1);border-radius:10px;color:#f0f0f0;font-size:.9rem;outline:none;box-sizing:border-box}
.form-group input:focus{border-color:var(--eg-gold,#D4AF37)}
</style>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), ['__content'=>$__content,'navItems'=>$navItems,'role'=>'vendor','pageTitle'=>$pageTitle]));
