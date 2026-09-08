<?php
/** 404 Not Found page */
use App\Core\View;
?>
<!DOCTYPE html>
<html lang="<?= $GLOBALS['__i18n']->locale() ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>404 -- Page Not Found | <?= t('site.name') ?></title>
  <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:#F8F9FA;">

  <div style="text-align:center;padding:2rem;">
    <div style="font-size:6rem;font-weight:900;color:#0F47AF;line-height:1;">404</div>
    <h1 style="margin:1rem 0 .5rem;font-size:1.5rem;">Page Not Found</h1>
    <p style="color:#6B7280;margin-bottom:2rem;">The page <code style="background:#E5E7EB;padding:.2rem .4rem;border-radius:4px;"><?= e($path ?? '/') ?></code> doesn't exist.</p>
    <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;">
      <a href="<?= View::url('/') ?>" class="btn btn-primary">
        <i class="bi bi-house"></i> <?= t('nav.home') ?>
      </a>
      <a href="<?= View::url('/search') ?>" class="btn btn-outline">
        <i class="bi bi-search"></i> Browse Products
      </a>
    </div>
  </div>

</body>
</html>
