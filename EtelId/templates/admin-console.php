<?php
/**
 * Etel-ID Premium - standalone Admin Console (no WordPress chrome).
 * Route: /console/  (site admin only - see EtelID_Rewrite::route).
 * Uses the same admin-only AJAX endpoints as the member portal's Admin Tools
 * (etelid_panel_*, nonce 'etelid_auth').
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$member = EtelID_Auth::get_current_member();
if ( ! $member || ! EtelID_Auth::is_site_admin( $member ) ) { wp_redirect( home_url( '/portal/' ) ); exit; }
$org_name = get_option( 'etelid_org_name', 'Etel Group' );
$cfg = array(
    'ajax'   => admin_url( 'admin-ajax.php' ),
    'nonce'  => wp_create_nonce( 'etelid_auth' ),
    'url'    => ETELID_URL,
    'portal' => home_url( '/portal/' ),
    'print'  => home_url( '/print/' ),
    'wp'     => array(
        'issue'     => admin_url( 'admin.php?page=etel-id-issue' ),
        'fayda'     => admin_url( 'admin.php?page=etel-id-fayda' ),
        'print'     => admin_url( 'admin.php?page=etel-id-print' ),
        'biometric' => admin_url( 'admin.php?page=etel-id-biometric' ),
        'checkin'   => admin_url( 'admin.php?page=etel-id-checkin-log' ),
        'settings'  => admin_url( 'admin.php?page=etel-id-settings' ),
        'updater'   => admin_url( 'admin.php?page=etel-id-updater' ),
        'password'  => admin_url( 'admin.php?page=etel-id-password-reset' ),
        'qr'        => admin_url( 'admin.php?page=etel-id-qr-pregen' ),
        'members'   => admin_url( 'admin.php?page=etel-id' ),
    ),
    'patch_nonce' => wp_create_nonce( 'etelid_patch_upload' ),
    'name'   => $member->full_name,
    'id'     => $member->etel_id,
    'org'    => $org_name,
);
$asset = function ( $rel ) { return esc_url( ETELID_URL . $rel . '?v=' . (int) @filemtime( ETELID_PATH . $rel ) ); };
?><!DOCTYPE html>
<html lang="en" data-eid-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#D4AF37">
<title>Etel-ID Admin Console · <?php echo esc_html( $org_name ); ?></title>
<link rel="stylesheet" href="<?php echo $asset( 'public/css/etelid-fonts.css' ); ?>">
<link rel="stylesheet" href="<?php echo $asset( 'public/css/etelid-console.css' ); ?>">
<script>window.EtelIDConsole=<?php echo wp_json_encode( $cfg ); ?>;
(function(){var t=null;try{t=localStorage.getItem('theme');}catch(e){}if(t!=='dark'&&t!=='light')t=(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';document.documentElement.setAttribute('data-eid-theme',t);})();</script>
</head>
<body>
<div class="ec-shell">
  <aside class="ec-side" id="ec-side">
    <div class="ec-brand">
      <span class="ec-logos"><img src="<?php echo esc_url( ETELID_URL . 'public/images/etel-master-coin-128.webp' ); ?>" alt="ETEL"><img src="<?php echo esc_url( ETELID_URL . 'public/images/etel-delivery-coin.webp' ); ?>" alt="ETEL"></span>
      <div><b>Etel-ID Admin</b><small>ኢትኤል ቡድን · Console</small></div>
    </div>
    <nav class="ec-nav" id="ec-nav">
      <div class="ec-nav-h">Overview</div>
      <a data-view="overview" class="on"><i>🏠</i>Dashboard</a>
      <div class="ec-nav-h">Identity</div>
      <a data-view="members"><i>🪪</i>Members</a>
      <a data-view="issue"><i>➕</i>Issue new ID</a>
      <a data-view="fayda"><i>🆔</i>Fayda register</a>
      <a data-view="roles"><i>🏢</i>Company roles</a>
      <a data-view="print"><i>🖨</i>Print cards</a>
      <div class="ec-nav-h">Security</div>
      <a data-view="biometric"><i>🔏</i>Biometric</a>
      <a data-view="checkin"><i>📋</i>Check-in log</a>
      <a data-view="audit"><i>🧾</i>Audit log</a>
      <a data-view="password"><i>🔐</i>Password reset</a>
      <div class="ec-nav-h">System</div>
      <a data-view="settings"><i>⚙️</i>Settings</a>
      <a data-view="updater"><i>🔄</i>Updater</a>
      <a data-view="qr"><i>🔳</i>QR cache</a>
      <a data-view="tools"><i>🔗</i>Other consoles</a>
      <a href="<?php echo esc_url( home_url( '/portal/' ) ); ?>"><i>👤</i>My portal</a>
    </nav>
    <div class="ec-side-foot"><b><?php echo esc_html( $member->full_name ); ?></b><?php echo esc_html( $member->etel_id ); ?></div>
  </aside>
  <div class="ec-scrim" id="ec-scrim"></div>
  <div class="ec-body">
    <header class="ec-top">
      <button class="ec-burger" id="ec-burger" type="button" aria-label="Menu">☰</button>
      <div><h1 id="ec-title">Dashboard</h1><div class="sub" id="ec-sub">Your Etel-ID system at a glance</div></div>
      <div class="ec-spacer"></div>
      <button class="ec-theme" id="ec-theme" type="button" aria-label="Toggle theme">🌙</button>
      <a class="btn btn-danger btn-sm" href="<?php echo esc_url( home_url( '/logout/' ) ); ?>">Sign out</a>
    </header>
    <main class="ec-main" id="ec-view"><div class="ec-loading">Loading…</div></main>
  </div>
</div>
<div class="ec-drawer-bg" id="ec-drawer-bg"><aside class="ec-drawer" id="ec-drawer"></aside></div>
<div class="ec-toast" id="ec-toast"></div>
<script src="<?php echo $asset( 'public/js/etelid-console.js' ); ?>"></script>
</body>
</html>
