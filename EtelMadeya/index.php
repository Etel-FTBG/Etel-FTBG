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
                <span>☎ <?= SITE_PHONE ?> | ✉ <?= SITE_EMAIL ?></span>
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
                    <a href="#" onclick="navigate('check')">🔍 <span data-t="Check Position|ቦታ ፈልግ">Check Position</span></a>
                    <a href="#" onclick="navigate('register')">📝 <span data-t="Register|ተመዝገብ">Register</span></a>
                    <a href="#" onclick="navigate('about')">ℹ️ <span data-t="About|ስለ እኛ">About</span></a>
                </div>
                <div>
                    <h4 data-t="CONTACT|ያግኙን">CONTACT</h4>
                    <p>📍 <?= SITE_ADDRESS ?></p>
                    <p>☎ <?= SITE_PHONE ?></p>
                    <p>✉ <?= SITE_EMAIL ?></p>
                    <p>🕐 <?= SITE_HOURS ?></p>
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
    <script src="assets/js/driver-app.js"></script>
</body>
</html>
