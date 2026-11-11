<?php if (isset($_GET['route']) && $_GET['route'] === 'api') { include __DIR__ . '/api/index.php'; exit; } ?>
<?php require_once __DIR__ . '/includes/config.php'; require_once __DIR__ . '/includes/site_content.php';
$SC = madeyaSite(); $MEM = madeyaDriverMember(); $IS_ADMIN = isset($_SESSION['admin_id']);
$E = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$T = function ($pair) use ($E) { return '<span' . ($pair['am'] !== '' ? ' data-t="' . $E($pair['en'] . '|' . $pair['am']) . '"' : '') . '>' . $E($pair['en']) . '</span>'; }; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etel Madeya - የኢትል ማደያ የነዳጅ ወረፋ</title>
    <meta name="description" content="Etel Madeya Gas Station Queue Management System">
    <meta name="keywords" content="gas station, queue, fuel, Ethiopia, Etel Madeya">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@300;400;500;600;700&family=Cormorant+Garamond:wght@500;600;700&family=Rajdhani:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/driver-style.css">
    <link rel="stylesheet" href="assets/css/etel-premium.css">
    <link rel="stylesheet" href="assets/css/madeya-pro-r2.css">
    <link rel="stylesheet" href="assets/css/madeya-vehicles.css">
    <link rel="stylesheet" href="assets/css/madeya-plates-r3.css">
    <link rel="stylesheet" href="assets/css/madeya-stations-r4.css">
    <link rel="stylesheet" href="assets/css/madeya-access.css">
    <script src="assets/js/madeya-pro-r2.js"></script>
    <link rel="icon" href="assets/img/etel-logo.png">
</head>
<body>
    <div id="app">
        <!-- Top Bar -->
        <div class="topbar">
            <div class="container topbar-inner">
                <span><svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 2 .7 3a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.2-1.3a2 2 0 0 1 2.1-.5c1 .4 2 .6 3 .7a2 2 0 0 1 1.7 2Z"/></svg> <?= $E($SC['phone']) ?> | <svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg> <?= $E($SC['email']) ?></span>
                <div class="topbar-right">
                    <button onclick="toggleLang()" class="lang-btn" id="langBtn">🇪🇹 አማ</button>
                    <?php if ($MEM): ?>
                    <span class="mad-user" title="EtelID">👤 <?= $E($MEM['name'] ?: 'EtelID') ?></span>
                    <button type="button" class="admin-link" onclick="madLogout()" data-t="Sign out|ውጣ">Sign out</button>
                    <?php else: ?>
                    <button type="button" class="admin-link mad-login-btn" onclick="madLogin()" data-t="EtelID login|በኢትልአይዲ ግባ">EtelID login</button>
                    <?php endif; ?>
                    <?php if ($IS_ADMIN): ?>
                    <a href="admin/index.php" class="admin-link">Admin</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Header -->
        <header class="header" id="header">
            <div class="container header-inner">
                <a href="#" onclick="navigate('home')" class="logo">
                    <img src="assets/img/etel-logo.png" alt="Etel Madeya" class="logo-img">
                    <div>
                        <div class="logo-name">Etel Madeya</div>
                        <div class="logo-sub" data-t="Gas Queue System|የነዳጅ ወረፋ ስርዓት">Gas Queue System</div>
                    </div>
                </a>
                <nav class="nav-links">
                    <a href="#" onclick="navigate('home')" class="nav-link active" data-page="home" data-t="Home|መነሻ">Home</a>
                    <a href="#" onclick="navigate('check')" class="nav-link" data-page="check" data-t="Check Position|ቦታ ፈልግ">Check Position</a>
                    <a href="#" onclick="navigate('stations')" class="nav-link" data-page="stations" data-t="Live Stations|ቀጥታ ማደያዎች">Live Stations</a>
                    <a href="#" onclick="navigate('about')" class="nav-link hide-mobile" data-page="about" data-t="About|ስለ እኛ">About</a>
                </nav>
            </div>
        </header>

        <!-- Notification -->
        <div id="notification" class="notification" style="display:none"></div>

        <!-- Pages -->
        <main id="mainContent"></main>

        <!-- Footer -->
        <footer class="footer">
            <div class="container footer-grid">
                <div class="footer-brand">
                    <div class="logo" style="margin-bottom:16px">
                        <img src="assets/img/etel-logo.png" alt="Etel Madeya" class="logo-img">
                        <div>
                            <div class="logo-name">Etel Madeya</div>
                            <div class="logo-sub">GAS QUEUE SYSTEM</div>
                        </div>
                    </div>
                    <p><?= $T($SC['tagline']) ?></p>
                </div>
                <div>
                    <h4 data-t="QUICK LINKS|ፈጣን ማገናኛዎች">QUICK LINKS</h4>
                    <?php foreach ($SC['links'] as $lk): $u = $lk['url']; ?>
                    <?php if ($u[0] === '#'): ?><a href="#" onclick="navigate('<?= $E(substr($u, 1)) ?>');return false;">› <?= $E($lk['label']) ?></a>
                    <?php else: ?><a href="<?= $E($u) ?>"<?= preg_match('~^https?://~i', $u) ? ' target="_blank" rel="noopener"' : '' ?>>› <?= $E($lk['label']) ?></a>
                    <?php endif; endforeach; ?>
                </div>
                <div>
                    <h4 data-t="CONTACT|ያግኙን">CONTACT</h4>
                    <p><svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg> <?= $E($SC['address']) ?></p>
                    <p><svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 2 .7 3a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.2-1.3a2 2 0 0 1 2.1-.5c1 .4 2 .6 3 .7a2 2 0 0 1 1.7 2Z"/></svg> <?= $E($SC['phone']) ?></p>
                    <p><svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg> <?= $E($SC['email']) ?></p>
                    <p><svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg> <?= $E($SC['hours']) ?></p>
                </div>
                <div>
                    <h4 data-t="STATION INFO|የጣቢያ መረጃ">STATION INFO</h4>
                    <?php foreach ($SC['info'] as $ln): ?><p><?= $T($ln) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="footer-bottom">
                <span>© <?= date('Y') ?> <?= $E($SC['copyright']) ?></span>
                <span>⛽ <?= $T($SC['powered']) ?> ⛽</span>
            </div>
        </footer>
    </div>

    <script>
    const API = 'index.php?route=api';
    const SITE_NAME = '<?= SITE_NAME ?>';
    window.MAD = { requireLogin: <?= $SC['require_login'] ? 'true' : 'false' ?>, member: <?= $MEM ? json_encode($MEM['name']) : 'null' ?>, privileged: <?= (isset($_SESSION['controller_id']) || $IS_ADMIN) ? 'true' : 'false' ?> };
    </script>
    <script src="assets/js/madeya-vehicles-r4.js"></script>
    <script src="assets/js/madeya-access.js"></script>
    <script src="assets/js/madeya-plates-r2.js"></script>
    <script src="assets/js/icons.js"></script>
    <script src="assets/js/driver-stations-r8.js"></script>
    <script src="assets/js/driver-app-r7.js"></script>
<script src="/delivery/js/etel-help-fab.js" data-app="madeya" defer></script>
</body>
</html>
