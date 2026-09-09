<?php if (isset($_GET['route']) && $_GET['route'] === 'api') { include __DIR__ . '/api/index.php'; exit; } ?>
<?php require_once __DIR__ . '/includes/config.php'; ?>
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
    <link rel="icon" href="assets/img/etel-logo.png">
</head>
<body>
    <div id="app">
        <!-- Top Bar -->
        <div class="topbar">
            <div class="container topbar-inner">
                <span><svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 2 .7 3a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.2-1.3a2 2 0 0 1 2.1-.5c1 .4 2 .6 3 .7a2 2 0 0 1 1.7 2Z"/></svg> <?= SITE_PHONE ?> | <svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg> <?= SITE_EMAIL ?></span>
                <div class="topbar-right">
                    <button onclick="toggleLang()" class="lang-btn" id="langBtn">🇪🇹 አማ</button>
                    <a href="admin/index.php" class="admin-link">Admin</a>
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
                    <p data-t="Modern gas station queue management for Ethiopia.|ለኢትዮጵያ ዘመናዊ የነዳጅ ወረፋ አስተዳደር።">Modern gas station queue management.</p>
                </div>
                <div>
                    <h4 data-t="QUICK LINKS|ፈጣን ማገናኛዎች">QUICK LINKS</h4>
                    <a href="#" onclick="navigate('check')"><svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.35-4.35"/></svg> <span data-t="Check Position|ቦታ ፈልግ">Check Position</span></a>
                    <a href="#" onclick="navigate('about')">ℹ️ <span data-t="About|ስለ እኛ">About</span></a>
                </div>
                <div>
                    <h4 data-t="CONTACT|ያግኙን">CONTACT</h4>
                    <p><svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg> <?= SITE_ADDRESS ?></p>
                    <p><svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 2 .7 3a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.2-1.3a2 2 0 0 1 2.1-.5c1 .4 2 .6 3 .7a2 2 0 0 1 1.7 2Z"/></svg> <?= SITE_PHONE ?></p>
                    <p><svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg> <?= SITE_EMAIL ?></p>
                    <p><svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg> <?= SITE_HOURS ?></p>
                </div>
                <div>
                    <h4 data-t="STATION INFO|የጣቢያ መረጃ">STATION INFO</h4>
                    <p data-t="Real-time queue updates|የቅጽበታዊ ወረፋ ዝመናዎች">Real-time queue updates</p>
                    <p data-t="Fair queue management|ፍትሃዊ ወረፋ አስተዳደር">Fair queue management</p>
                    <p data-t="Multiple vehicle types|በርካታ የተሽከርካሪ ዓይነቶች">Multiple vehicle types</p>
                </div>
            </div>
            <div class="footer-bottom">
                <span>© <?= date('Y') ?> Etel Madeya. All rights reserved.</span>
                <span>⛽ <span data-t="Powered by ETEL|በኢትል የተጎለበተ">Powered by ETEL</span> ⛽</span>
            </div>
        </footer>
    </div>

    <script>
    const API = 'index.php?route=api';
    const SITE_NAME = '<?= SITE_NAME ?>';
    </script>
    <script src="assets/js/icons.js"></script>
    <script src="assets/js/driver-app.js"></script>
</body>
</html>
