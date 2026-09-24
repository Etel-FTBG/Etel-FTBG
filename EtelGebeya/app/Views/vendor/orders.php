<?php
use App\Core\View;
require __DIR__.'/_layout.php';
$items = $items ?? [];
ob_start();

/* ── Summary stats, derived purely from $items already passed in ──
   No new backend query: just counting/summing what the controller
   already handed to this view. */
$statTotal = count($items);
$statPending = 0; $statTransit = 0; $statDelivered = 0; $revenue = 0.0;
foreach ($items as $__it) {
  $st = $__it['item_status'] ?? '';
  if ($st === 'pending') $statPending++;
  elseif (in_array($st, ['confirmed', 'shipped'], true)) $statTransit++;
  elseif ($st === 'delivered') $statDelivered++;
  if (!in_array($st, ['cancelled', 'returned'], true)) $revenue += (float)($__it['total_price'] ?? 0);
}
?>
<div>
  <div class="orders-header">
    <div>
      <h1 class="orders-title">My Orders</h1>
      <p class="orders-sub">Items containing your products — mark them confirmed, shipped or delivered as you fulfill them</p>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="orders-flash orders-flash-<?= $flash['type']==='error'?'error':'ok' ?>">
      <i class="bi <?= $flash['type']==='error'?'bi-exclamation-circle':'bi-check-circle' ?>"></i>
      <?= e($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($items)): ?>
  <div class="orders-stats">
    <div class="ostat-card">
      <div class="ostat-icon" style="background:rgba(96,180,255,.12);color:var(--blue)"><i class="bi bi-receipt"></i></div>
      <div><div class="ostat-val"><?= $statTotal ?></div><div class="ostat-label">Total Items</div></div>
    </div>
    <div class="ostat-card">
      <div class="ostat-icon" style="background:rgba(255,149,0,.12);color:var(--orange)"><i class="bi bi-hourglass-split"></i></div>
      <div><div class="ostat-val"><?= $statPending ?></div><div class="ostat-label">Pending</div></div>
    </div>
    <div class="ostat-card">
      <div class="ostat-icon" style="background:rgba(96,180,255,.12);color:var(--blue)"><i class="bi bi-truck"></i></div>
      <div><div class="ostat-val"><?= $statTransit ?></div><div class="ostat-label">In Transit</div></div>
    </div>
    <div class="ostat-card">
      <div class="ostat-icon" style="background:rgba(46,204,113,.12);color:var(--green)"><i class="bi bi-house-check"></i></div>
      <div><div class="ostat-val"><?= $statDelivered ?></div><div class="ostat-label">Delivered</div></div>
    </div>
    <div class="ostat-card ostat-card-gold">
      <div class="ostat-icon" style="background:var(--gold-dim);color:var(--gold)"><i class="bi bi-gem"></i></div>
      <div><div class="ostat-val">Br <?= number_format($revenue, 2) ?></div><div class="ostat-label">My Revenue</div></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (empty($items)): ?>
    <div class="orders-empty">
      <div class="orders-empty-icon"><i class="bi bi-cart-x"></i></div>
      <h3>No orders yet</h3>
      <p>Orders will appear here when customers buy your products.</p>
    </div>
  <?php else: ?>

  <div class="orders-card">
    <table class="orders-table">
      <thead>
        <tr>
          <th>Order</th>
          <th>Product</th>
          <th>Customer</th>
          <th>Qty</th>
          <th>My Revenue</th>
          <th>Status</th>
          <th>Fulfilment</th>
          <th>Date</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php
        $nextStatus = ['pending' => 'confirmed', 'confirmed' => 'shipped', 'shipped' => 'delivered'];
        $nextLabel  = ['confirmed' => '<i class="bi bi-check-circle"></i> Confirm', 'shipped' => '<i class="bi bi-box-seam"></i> Mark Shipped', 'delivered' => '<i class="bi bi-house-check"></i> Mark Delivered'];
        $statusIcon = ['pending' => 'bi-hourglass-split', 'confirmed' => 'bi-check-circle', 'shipped' => 'bi-truck', 'delivered' => 'bi-house-check', 'cancelled' => 'bi-x-circle', 'returned' => 'bi-arrow-return-left'];
      ?>
      <?php foreach ($items as $it): ?>
        <tr data-label-row="<?= e($it['code']) ?>">
          <td data-label="Order">
            <span class="orders-code"><?= e($it['code']) ?></span>
          </td>
          <td data-label="Product"><?= e($it['product_name']) ?></td>
          <td data-label="Customer">
            <div class="orders-customer"><?= e($it['customer_name']) ?></div>
            <div class="orders-customer-phone"><?= e($it['customer_phone']) ?></div>
          </td>
          <td data-label="Qty"><?= (int)$it['quantity'] ?></td>
          <td data-label="My Revenue" class="orders-revenue">Br <?= number_format((float)$it['total_price'], 2) ?></td>
          <td data-label="Status">
            <span class="badge-status badge-<?= e($it['item_status']) ?>">
              <i class="bi <?= e($statusIcon[$it['item_status']] ?? 'bi-circle') ?>"></i>
              <?= e(ucfirst($it['item_status'])) ?>
            </span>
          </td>
          <td data-label="Fulfilment">
            <?php if (empty($it['is_dropship'])): ?>
              <span class="orders-inhouse">In-house</span>
            <?php else: ?>
              <span class="badge-status badge-dropship"><i class="bi bi-truck"></i> Dropship</span>
              <?php if (!empty($it['supplier_name'])): ?>
                <div class="orders-supplier"><?= e($it['supplier_name']) ?></div>
              <?php endif; ?>
              <?php if (!empty($it['forwarded_at'])): ?>
                <div class="orders-forwarded"><i class="bi bi-check2"></i> Forwarded <?= e($it['forward_method'] === 'email' ? '(emailed)' : '') ?> <?= date('M j', strtotime($it['forwarded_at'])) ?></div>
              <?php else: ?>
                <div class="orders-forward-actions">
                  <form method="POST" action="<?= View::url('/vendor-panel/orders/mark-forwarded') ?>">
                    <?= csrf() ?>
                    <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                    <button type="submit" class="orders-btn-ghost" title="I forwarded this to my supplier myself">Mark Forwarded</button>
                  </form>
                  <?php if (!empty($it['supplier_email'])): ?>
                    <form method="POST" action="<?= View::url('/vendor-panel/orders/email-supplier') ?>" onsubmit="return confirm('Email the order details to <?= e(addslashes($it['supplier_name'] ?? 'this supplier')) ?>?')">
                      <?= csrf() ?>
                      <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                      <button type="submit" class="orders-btn-ghost"><i class="bi bi-envelope"></i> Email Supplier</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td data-label="Date" class="orders-date"><?= date('M j, Y', strtotime($it['created_at'])) ?></td>
          <td data-label="Action">
            <?php if (!empty($it['tracking_number']) || !empty($it['courier_name'])): ?>
              <div class="orders-tracking">
                <?php if (!empty($it['courier_name'])): ?><span><i class="bi bi-truck"></i> <?= e($it['courier_name']) ?></span><?php endif; ?>
                <?php if (!empty($it['tracking_number'])): ?><span class="orders-tracking-num"><?= e($it['tracking_number']) ?></span><?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if (isset($nextStatus[$it['item_status']])): $ns = $nextStatus[$it['item_status']]; ?>
              <form method="POST" action="<?= View::url('/vendor-panel/orders/item-status') ?>" class="orders-status-form">
                <?= csrf() ?>
                <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                <input type="hidden" name="status" value="<?= e($ns) ?>">
                <?php if ($ns === 'shipped'): ?>
                  <div class="orders-ship-fields">
                    <input type="text" name="tracking_number" placeholder="Tracking # (optional)" class="orders-input">
                    <?php if (!empty($couriers)): ?>
                      <select name="courier_id" class="orders-input">
                        <option value="">Courier (optional)</option>
                        <?php foreach ($couriers as $c): ?>
                          <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <button type="submit" class="orders-btn-primary"><?= $nextLabel[$ns] ?></button>
              </form>
            <?php elseif (!in_array($it['item_status'], ['delivered','cancelled'], true)): ?>
              <span class="orders-dash">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<style>
/* ── Header ──────────────────────────────────────────────────── */
.orders-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap}
.orders-title{font-family:'Sora',sans-serif;font-weight:800;font-size:1.7rem;letter-spacing:-.02em;color:var(--dark-text);margin:0 0 .3rem}
.orders-sub{font-family:'Outfit',system-ui,sans-serif;font-size:.92rem;color:var(--dark-muted);margin:0;max-width:52ch}

/* ── Flash ───────────────────────────────────────────────────── */
.orders-flash{display:flex;align-items:center;gap:.6rem;padding:.75rem 1.1rem;border-radius:var(--r-sm);margin-bottom:1.25rem;font-family:'Outfit',sans-serif;font-size:.88rem;border:1px solid currentColor}
.orders-flash-error{background:rgba(231,76,60,.1);color:var(--red)}
.orders-flash-ok{background:rgba(46,204,113,.1);color:var(--green)}

/* ── Summary stat row ────────────────────────────────────────── */
.orders-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.85rem;margin-bottom:1.5rem}
.ostat-card{
  display:flex;align-items:center;gap:.75rem;
  background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--r-md);
  padding:1rem 1.1rem;
  box-shadow:0 16px 40px -16px rgba(0,0,0,.4), 0 0 0 1px rgba(212,175,55,.08);
}
.ostat-card-gold{border-color:rgba(212,175,55,.4)}
.ostat-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.05rem;flex-shrink:0}
.ostat-val{font-family:'Sora',sans-serif;font-weight:800;font-size:1.15rem;color:var(--dark-text);letter-spacing:-.01em;line-height:1.2}
.ostat-label{font-family:'Outfit',sans-serif;font-size:.72rem;color:var(--dark-muted);text-transform:uppercase;letter-spacing:.05em;margin-top:.1rem}

/* ── Empty state ─────────────────────────────────────────────── */
.orders-empty{text-align:center;padding:4rem 2rem;color:var(--dark-muted);font-family:'Outfit',sans-serif}
.orders-empty-icon{font-size:2.75rem;margin-bottom:1rem;color:var(--gold)}
.orders-empty h3{font-family:'Sora',sans-serif;color:var(--dark-text);margin:0 0 .4rem}
.orders-empty p{margin:0}

/* ── Card + table ────────────────────────────────────────────── */
.orders-card{background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:var(--r-md);padding:0;overflow:hidden}
.orders-table{width:100%;border-collapse:collapse;font-family:'Outfit',sans-serif}
.orders-table thead th{
  text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
  color:var(--dark-muted);padding:.9rem 1rem;border-bottom:1px solid var(--dark-border);
  background:rgba(255,255,255,.015);white-space:nowrap;
}
.orders-table tbody tr{border-bottom:1px solid rgba(255,255,255,.05);transition:background .15s var(--ease)}
.orders-table tbody tr:last-child{border-bottom:none}
.orders-table tbody tr:hover{background:rgba(212,175,55,.035)}
.orders-table td{padding:.85rem 1rem;font-size:.86rem;color:var(--dark-text);vertical-align:top}

.orders-code{font-family:ui-monospace,'SF Mono',monospace;font-size:.82rem;color:var(--dark-muted);letter-spacing:.02em}
.orders-customer{font-weight:600}
.orders-customer-phone{font-size:.74rem;color:var(--dark-muted);margin-top:.15rem}
.orders-revenue{font-family:'Sora',sans-serif;font-weight:700;color:var(--dark-text);white-space:nowrap}
.orders-date{color:var(--dark-muted);font-size:.8rem;white-space:nowrap}
.orders-dash{color:var(--dark-muted);font-size:.8rem}
.orders-inhouse{color:var(--dark-muted);font-size:.78rem}
.orders-supplier{font-size:.75rem;color:var(--dark-muted);margin-top:.25rem}
.orders-forwarded{font-size:.72rem;color:var(--green);margin-top:.2rem}
.orders-forward-actions{display:flex;gap:.35rem;margin-top:.4rem;flex-wrap:wrap}
.orders-tracking{display:flex;flex-direction:column;gap:.15rem;font-size:.74rem;color:var(--dark-muted);margin-bottom:.4rem}
.orders-tracking-num{font-family:ui-monospace,monospace}
.orders-status-form{display:flex;flex-direction:column;align-items:flex-start;gap:.4rem}
.orders-ship-fields{display:flex;flex-direction:column;gap:.35rem;min-width:150px}

/* ── Status badges: dot + label per the design system's named rule ── */
.badge-status{
  display:inline-flex;align-items:center;gap:.35rem;
  padding:.28rem .65rem;border-radius:20px;font-size:.72rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.02em;white-space:nowrap;
}
.badge-status i{font-size:.8em}
.badge-pending{background:rgba(255,149,0,.12);color:var(--orange)}
.badge-confirmed{background:rgba(96,180,255,.12);color:var(--blue)}
.badge-shipped{background:rgba(96,180,255,.12);color:var(--blue)}
.badge-delivered{background:rgba(46,204,113,.12);color:var(--green)}
.badge-cancelled,.badge-returned{background:rgba(231,76,60,.12);color:var(--red)}
.badge-dropship{background:rgba(155,89,182,.12);color:var(--purple)}

/* ── Buttons + inputs (per DESIGN.md component spec) ────────── */
.orders-btn-primary{
  background:linear-gradient(135deg,var(--gold),var(--gold-dk));
  color:#080604;font-weight:700;font-family:'Outfit',sans-serif;
  border:none;border-radius:var(--r-sm);padding:.5rem .95rem;font-size:.8rem;
  cursor:pointer;white-space:nowrap;transition:transform .15s var(--spring),box-shadow .15s var(--ease);
}
.orders-btn-primary:hover{transform:translateY(-2px);box-shadow:0 6px 18px -4px rgba(212,175,55,.5)}
.orders-btn-ghost{
  background:transparent;border:1.5px solid var(--dark-border);color:var(--dark-muted);
  border-radius:var(--r-sm);padding:.4rem .75rem;font-size:.74rem;font-family:'Outfit',sans-serif;
  cursor:pointer;white-space:nowrap;transition:border-color .15s var(--ease),color .15s var(--ease);
}
.orders-btn-ghost:hover{border-color:var(--gold);color:var(--gold)}
.orders-input{
  padding:.4rem .6rem;border-radius:10px;border:1.5px solid rgba(255,255,255,.1);
  background:rgba(255,255,255,.05);color:var(--dark-text);font-size:.76rem;font-family:'Outfit',sans-serif;
}
.orders-input:focus{outline:none;border-color:var(--gold)}

/* ── Mobile: collapse the table into stacked cards ──────────── */
@media (max-width: 760px){
  .orders-card{background:none;border:none;overflow:visible}
  .orders-table thead{display:none}
  .orders-table, .orders-table tbody, .orders-table tr, .orders-table td{display:block;width:100%}
  .orders-table tbody tr{
    background:var(--dark-card);border:1.5px solid var(--dark-border);border-radius:var(--r-md);
    margin-bottom:.85rem;padding:.9rem 1rem;
  }
  .orders-table tbody tr:hover{background:var(--dark-card)}
  .orders-table td{
    padding:.4rem 0;border-bottom:1px dashed rgba(255,255,255,.06);
    display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;text-align:right;
  }
  .orders-table td:last-child{border-bottom:none}
  .orders-table td::before{
    content:attr(data-label);font-size:.68rem;font-weight:700;text-transform:uppercase;
    letter-spacing:.05em;color:var(--dark-muted);text-align:left;flex-shrink:0;
  }
  .orders-status-form{align-items:flex-end;width:100%}
}
</style>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), ['__content'=>$__content,'navItems'=>$navItems,'role'=>'vendor','pageTitle'=>$pageTitle]));
