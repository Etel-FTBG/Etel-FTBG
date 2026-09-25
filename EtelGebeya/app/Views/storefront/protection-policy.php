<?php
use App\Core\View;
$locale = $locale ?? 'en';
ob_start();
?>
<style>
.pp-wrap{max-width:820px;margin:0 auto;padding:1.5rem 1rem 3rem}
.pp-section{background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:18px;padding:1.4rem 1.6rem;margin-bottom:1.1rem}
.pp-section h2{font-size:1.05rem;color:var(--g2,#D4AF37);margin:0 0 .7rem;display:flex;align-items:center;gap:.5rem}
.pp-section p, .pp-section li{font-size:.88rem;line-height:1.8;color:var(--text-dim,#C9B88A)}
.pp-section ul{margin:.3rem 0;padding-left:1.3rem}
.pp-badge{display:inline-block;background:rgba(212,175,55,.1);color:var(--g2,#D4AF37);border:1px solid rgba(212,175,55,.2);border-radius:8px;padding:.15rem .6rem;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem}
</style>

<div class="pp-wrap">
  <div style="text-align:center;margin-bottom:1.5rem">
    <h1 class="cp-page-title"><i class="bi bi-shield-check"></i> <?= e(t('storefront.policy.page_title')) ?></h1>
    <p style="color:var(--text-muted,#7A6840);font-size:.85rem"><?= e(t('storefront.policy.page_sub')) ?></p>
  </div>

  <div class="pp-section">
    <span class="pp-badge"><?= e(t('storefront.policy.for_buyers')) ?></span>
    <h2><i class="bi bi-lock"></i> <?= e(t('storefront.policy.buyers_heading')) ?></h2>
    <p><?= e(t('storefront.policy.buyers_intro')) ?></p>
    <ul>
      <li><?= t('storefront.policy.buyers_li1') ?></li>
      <li><?= t('storefront.policy.buyers_li2') ?></li>
      <li><?= t('storefront.policy.buyers_li3') ?></li>
      <li><?= t('storefront.policy.buyers_li4') ?></li>
    </ul>
  </div>

  <div class="pp-section">
    <span class="pp-badge"><?= e(t('storefront.policy.for_sellers')) ?></span>
    <h2><i class="bi bi-shop"></i> <?= e(t('storefront.policy.sellers_heading')) ?></h2>
    <p><?= e(t('storefront.policy.sellers_intro')) ?></p>
    <ul>
      <li><?= e(t('storefront.policy.sellers_li1')) ?></li>
      <li><?= e(t('storefront.policy.sellers_li2')) ?></li>
      <li><?= e(t('storefront.policy.sellers_li3')) ?></li>
    </ul>
  </div>

  <div class="pp-section">
    <span class="pp-badge"><?= e(t('storefront.policy.dispute_process')) ?></span>
    <h2><i class="bi bi-flag"></i> <?= e(t('storefront.policy.dispute_heading')) ?></h2>
    <p><?= e(t('storefront.policy.dispute_intro')) ?></p>
    <ul>
      <li><?= t('storefront.policy.dispute_li1') ?></li>
      <li><?= t('storefront.policy.dispute_li2') ?></li>
    </ul>
    <p><?= e(t('storefront.policy.dispute_outro')) ?></p>
  </div>
</div>

<?php
$__content = ob_get_clean();
echo \App\Core\View::capture('_layouts/public', [
    '__content' => $__content,
    'pageTitle'  => $pageTitle ?? t('storefront.policy.page_title') . ' -- Etel Gebeya',
    'locale'     => $locale ?? 'en',
]);
