<?php
/**
 * Coming Soon -- shown when DB isn't connected yet (before /install runs).
 */
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= t('site.name') ?> -- Setup Required</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Segoe UI', system-ui, sans-serif;
      background: #F8F9FA;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .card {
      background: #fff;
      border: 1px solid #E5E7EB;
      border-radius: 16px;
      padding: 3rem 2.5rem;
      max-width: 500px;
      text-align: center;
      box-shadow: 0 4px 24px rgba(0,0,0,.08);
    }
    .logo { font-size: 3rem; font-weight: 900; color: #0F47AF; margin-bottom: .5rem; }
    h1 { font-size: 1.5rem; margin-bottom: .5rem; color: #1F2937; }
    p { color: #6B7280; line-height: 1.7; margin-bottom: 1.5rem; }
    .btn {
      display: inline-flex; align-items: center; gap: .5rem;
      background: #0F47AF; color: #fff;
      padding: .75rem 2rem;
      border-radius: 8px;
      font-size: 1rem; font-weight: 600;
      text-decoration: none;
      transition: .2s;
    }
    .btn:hover { background: #0a3391; color: #fff; transform: translateY(-1px); }
    .steps { text-align: left; margin: 1.5rem 0; background: #F8F9FA; border-radius: 12px; padding: 1.25rem; }
    .steps li { padding: .4rem 0; color: #374151; font-size: .9rem; list-style: none; display: flex; gap: .75rem; }
    .steps li::before { content: '?'; color: #078930; font-weight: 700; flex-shrink: 0; }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo">???</div>
    <h1><?= t('site.name') ?></h1>
    <p>Ethiopia's online marketplace. Running on <?= $_SERVER['HTTP_HOST'] ?? '' ?>.</p>

    <div class="steps">
      <li>Database connection not yet configured</li>
      <li>Run the install wizard to set up MySQL</li>
      <li>Create your admin account</li>
    </div>

    <a href="/install" class="btn">
      <span>/* U+1F680 */</span> Run Setup Wizard
    </a>

    <p style="margin-top:1.5rem;font-size:.8rem;">
      Need help? Contact support@etelgebeya.com
    </p>
  </div>
</body>
</html>
