<?php
/** 500 Internal Server Error page */
use App\Core\View;
?>
<!DOCTYPE html>
<html lang="<?= $GLOBALS['__i18n']->locale() ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>500 -- Server Error | <?= t('site.name') ?></title>
  <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:#F8F9FA;">

  <div style="text-align:center;padding:2rem;">
    <div style="font-size:6rem;font-weight:900;color:#C8102E;line-height:1;">500</div>
    <h1 style="margin:1rem 0 .5rem;font-size:1.5rem;">Something went wrong</h1>
    <p style="color:#6B7280;margin-bottom:1.5rem;">We encountered an unexpected error. Our team has been notified.</p>
    <?php if (!empty($code)): ?>
      <p style="font-size:.8rem;color:#9CA3AF;margin-bottom:2rem;">Error code: <code>EG-<?= e($code) ?></code></p>
    <?php endif; ?>
    <a href="<?= View::url('/') ?>" class="btn btn-primary">
      <i class="bi bi-house"></i> Go Home
    </a>
  </div>

</body>
</html>
