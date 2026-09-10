<?php /** @var bool $already */ /** @var string|null $admin_email */ /** @var string|null $site_url */ ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Installed * Etel Gebeya</title>
  <link rel="stylesheet" href="<?= url('/public/assets/css/install.css') ?>">
</head>
<body>
  <main class="install done">
    <h1>Etel Gebeya is ready</h1>
    <?php if ($already): ?>
      <p>The system is already installed. You can access it below.</p>
    <?php else: ?>
      <p>Database created, schema applied, admin account created.</p>
      <p>Admin email: <code><?= htmlspecialchars($admin_email ?? '') ?></code></p>
    <?php endif; ?>

    <ul class="links">
      <li><a class="btn" href="<?= url('/') ?>">Homepage</a></li>
      <li><a class="btn" href="<?= url('/login') ?>">Login</a></li>
      <li><a class="btn" href="<?= url('/admin') ?>">Admin</a></li>
      <li><a class="btn ghost" href="<?= url('/sell') ?>">Become a vendor</a></li>
    </ul>

    <p class="muted small">Site URL: <?= htmlspecialchars($site_url ?? '') ?></p>
  </main>
</body>
</html>
