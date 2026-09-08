<?php /** @var array $checks */ /** @var array $cfg */ /** @var string $php */ /** @var array|null $errors */ /** @var array|null $old */
$errors = $errors ?? [];
$old    = $old ?? [];
$cur = $cfg['db'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Install * Etel Gebeya</title>
  <link rel="stylesheet" href="/public/assets/css/install.css">
</head>
<body>
  <main class="install">
    <header>
      <h1>Etel Gebeya -- Install</h1>
      <p class="muted">??? ??? * v0.1</p>
    </header>

    <section class="checks">
      <h2>System checks</h2>
      <ul>
        <?php foreach ($checks as [$name, $ok, $extra]): ?>
          <li class="<?= $ok ? 'ok' : 'fail' ?>">
            <span class="dot"></span>
            <span class="lbl"><?= htmlspecialchars($name) ?></span>
            <?php if ($extra): ?><span class="ex"><?= htmlspecialchars((string)$extra) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="muted">PHP: <strong><?= htmlspecialchars($php) ?></strong></p>
    </section>

    <?php if ($errors): ?>
      <section class="errors">
        <h2>Errors</h2>
        <ul>
          <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <form method="post" action="/install" class="form">
      <h2>Database</h2>
      <label>Host
        <input name="db_host" value="<?= htmlspecialchars($old['db_host'] ?? $cur['host']) ?>" required>
      </label>
      <label>Database name
        <input name="db_name" value="<?= htmlspecialchars($old['db_name'] ?? $cur['name']) ?>" required>
      </label>
      <label>Username
        <input name="db_user" value="<?= htmlspecialchars($old['db_user'] ?? $cur['user']) ?>" required>
      </label>
      <label>Password
        <input name="db_pass" type="password" value="<?= htmlspecialchars($cur['pass']) ?>">
      </label>

      <h2>Admin account</h2>
      <label>Name
        <input name="admin_name" value="<?= htmlspecialchars($old['admin_name'] ?? 'Site Admin') ?>" required>
      </label>
      <label>Email
        <input name="admin_email" type="email" value="<?= htmlspecialchars($old['admin_email'] ?? 'admin@etelgebeya.local') ?>" required>
      </label>
      <label>Password (min 8 chars)
        <input name="admin_password" type="password" minlength="8" required>
      </label>

      <button type="submit" class="btn">Install</button>
    </form>

    <p class="muted small">
      Tip: if the form fails on write permissions, copy <code>storage/</code> and <code>config/</code> manually with
      your shell user. For dev, run with <code>php -S localhost:8080 -t public public/index.php</code>.
    </p>
  </main>
</body>
</html>
