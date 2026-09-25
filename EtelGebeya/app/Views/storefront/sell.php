<?php
use App\Core\View;

$locale = $GLOBALS['__i18n']->locale();
ob_start();
?>
<section style="min-height:80vh;display:flex;align-items:center;justify-content:center;padding:2rem 1rem">
  <div style="width:100%;max-width:460px">
    <div style="text-align:center;margin-bottom:2rem">
      <div style="font-size:2.5rem;margin-bottom:.5rem"><i class="bi bi-shop"></i></div>
      <h1 style="font-size:1.8rem;font-weight:900;background:linear-gradient(135deg,#FFE066,#D4AF37);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text"><?= e(t('storefront.footer.become_vendor')) ?></h1>
      <p style="color:var(--text2);margin-top:.5rem"><?= e(t('storefront.sell.subtitle')) ?></p>
    </div>

    <?php if (!empty($flash)): ?>
      <div style="padding:.8rem 1.2rem;border-radius:10px;margin-bottom:1.2rem;background:<?= $flash['type']==='error'?'rgba(239,68,68,.15)':'rgba(16,185,129,.15)' ?>;color:<?= $flash['type']==='error'?'#ef4444':'#10b981' ?>;border:1px solid currentColor;font-size:.9rem">
        <?= e($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="<?= View::url('/sell') ?>" style="background:var(--card);border:1.5px solid var(--border);border-radius:20px;padding:2rem">

      <?php
      $inp = 'width:100%;padding:.7rem 1rem;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.1);border-radius:12px;color:#f0f0f0;font-size:.95rem;outline:none;box-sizing:border-box';
      $lbl = 'display:block;font-size:.82rem;color:var(--text2);margin-bottom:.4rem;font-weight:600';
      $grp = 'margin-bottom:1rem';
      ?>

      <div style="<?= $grp ?>">
        <label style="<?= $lbl ?>"><?= e(t('auth.full_name')) ?> *</label>
        <input type="text" name="name" required placeholder="<?= e(t('storefront.sell.your_full_name')) ?>" style="<?= $inp ?>">
      </div>
      <div style="<?= $grp ?>">
        <label style="<?= $lbl ?>"><?= e(t('auth.email')) ?> *</label>
        <input type="email" name="email" required placeholder="you@example.com" style="<?= $inp ?>">
      </div>
      <div style="<?= $grp ?>">
        <label style="<?= $lbl ?>"><?= e(t('storefront.checkout.phone_label')) ?></label>
        <input type="tel" name="phone" placeholder="+251 9xx xxx xxx" style="<?= $inp ?>">
      </div>
      <div style="<?= $grp ?>">
        <label style="<?= $lbl ?>"><?= e(t('storefront.sell.store_name')) ?> *</label>
        <input type="text" name="store_name" required placeholder="e.g. Addis Fashion House" style="<?= $inp ?>">
      </div>
      <div style="margin-bottom:1.5rem">
        <label style="<?= $lbl ?>"><?= e(t('auth.password')) ?> * <span style="color:var(--text2);font-weight:400">(<?= e(t('storefront.sell.min_8_chars_hint')) ?>)</span></label>
        <input type="password" name="password" required minlength="8" placeholder="<?= e(t('storefront.sell.choose_strong_password')) ?>" style="<?= $inp ?>">
      </div>

      <button type="submit" style="width:100%;padding:.85rem;background:linear-gradient(135deg,#FFE066,#D4AF37,#B8902E);color:#080604;font-weight:900;font-size:1rem;border:none;border-radius:12px;cursor:pointer;transition:opacity .2s" onmouseover="this.style.opacity='.9'" onmouseout="this.style.opacity='1'">
        <i class="bi bi-shop"></i> <?= e(t('storefront.sell.create_my_store')) ?>
      </button>

      <p style="text-align:center;margin-top:1.2rem;font-size:.85rem;color:var(--text2)">
        <?= e(t('auth.have_account')) ?> <a href="<?= View::url('/login') ?>" style="color:var(--g1)"><?= e(t('storefront.product.log_in')) ?></a>
      </p>
    </form>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-top:2rem;text-align:center">
      <?php foreach ([['bi-check-circle',t('storefront.sell.free_to_join')],['bi-box-seam',t('storefront.sell.list_unlimited')],['bi-credit-card',t('storefront.sell.get_paid_fast')]] as [$icon,$text]): ?>
        <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1rem .75rem">
          <div style="font-size:1.5rem;margin-bottom:.4rem"><i class="bi <?= $icon ?>"></i></div>
          <div style="font-size:.8rem;color:var(--text2)"><?= $text ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/public', [
    '__content' => $__content,
    'pageTitle'  => t('storefront.footer.become_vendor') . ' -- Etel Gebeya',
    'locale'     => $locale,
]);
