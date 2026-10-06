<?php
/**
 * Public NFC profile template.
 * Rendered by Etelid_Nfc::maybe_render_profile() - $member and $card are
 * already in scope (may be null if the token was invalid/revoked).
 */

if (!defined('ABSPATH')) {
    exit;
}

/* The tapped card's own photo/name/Etel-ID/tier are deliberately NOT
   echoed anywhere below - a bare tap alone must not reveal anyone's
   personal info. $has_member only tells us whether the token itself
   is valid; the actual member fields are fetched by ajax_reveal()
   only after the scanning person proves their own EtelID login. */
$has_member = !empty($member);
$nfc_token = (!empty($card) && isset($card->token)) ? esc_attr($card->token) : '';
$nfc_ajax_url = esc_url(admin_url('admin-ajax.php'));
$nfc_nonce = wp_create_nonce('etelid_public');

/* Cross-company brand medallions - each service row below wears the
   real logo of the Etel Group product it actually hands the request
   to, not a generic icon, so the card reads as one federated system
   rather than a single-app menu. Etel Madeya's public path is
   '/Etel-Madeya' (hyphenated) on the live site even though this repo
   folder is 'EtelMadeya' - same base URL class-etelid-nfc.php's own
   madeya_service_call() already calls out to. */
$home_parts = wp_parse_url(home_url());
$site_root  = (isset($home_parts['scheme']) ? $home_parts['scheme'] : 'https') . '://'
            . (isset($home_parts['host']) ? $home_parts['host'] : $_SERVER['HTTP_HOST']);
$logo_group    = $site_root . '/EtelGroup/icons/etelgroup-logo.png';
$logo_delivery = defined('ETELID_URL') ? ETELID_URL . 'public/images/etel-delivery-coin.webp' : '';
$logo_ride     = defined('ETELID_URL') ? ETELID_URL . 'public/images/logo-ride.webp' : '';
$logo_driver   = defined('ETELID_URL') ? ETELID_URL . 'public/images/logo-driver.webp' : '';
$logo_etelpay  = $site_root . '/Etel.Pay/wallet/logo.png';
$logo_madeya   = $site_root . '/Etel-Madeya/assets/images/etel-madeya-logo-sm.webp';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EtelID</title>
<link rel="stylesheet" href="<?php echo esc_url( ETELID_URL . 'public/css/etelid-fonts.css?v=' . filemtime( ETELID_PATH . 'public/css/etelid-fonts.css' ) ); ?>">
<?php if (defined('ETELID_URL')) : ?>
<link rel="stylesheet" href="<?php echo esc_url( ETELID_URL . 'public/css/etelid-card.css?v=' . filemtime( ETELID_PATH . 'public/css/etelid-card.css' ) ); ?>">
<?php endif; ?>
<style>
  :root {
    --carbon-1: #030205;
    --carbon-2: #0d0d0d;
    --carbon-3: #0a0703;
    --carbon-4: #130d05;
    --carbon-5: #1b1309;
    --gold-1: #C8960C;
    --gold-2: #D4AF37;
    --gold-3: #F2CC6B;
    --gold-4: #FFF6D9;
    --gold-deep-1: #A07820;
    --gold-deep-2: #C9A227;
    --nfc-ink: #f2e8d0;
    --ink-dim: rgba(242,232,208,0.68);
    --ink-faint: rgba(242,232,208,0.5);
    --ok: #7ee8a8;
    --bad: #ff8a7a;
  }
  * { box-sizing: border-box; }
  html, body {
    margin: 0;
    padding: 0;
    min-height: 100%;
  }
  body {
    background:
      radial-gradient(900px 620px at 50% -8%, rgba(255,246,217,0.10), transparent 60%),
      radial-gradient(1100px 800px at 50% 112%, rgba(0,0,0,0.55), transparent 60%),
      linear-gradient(155deg,#1c1100 0%,#3d2705 14%,#7a5012 28%,#b98420 42%,#D4AF37 50%,#b98420 58%,#7a5012 72%,#3d2705 86%,#1c1100 100%);
    background-attachment: fixed;
    font-family: 'Manrope', system-ui, sans-serif;
    color: var(--nfc-ink);
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 26px 10px 28px;
  }
  ::selection { background: rgba(212,175,55,0.38); color: #1b1309; }
  ::-webkit-scrollbar { width: 9px; height: 9px; }
  ::-webkit-scrollbar-track { background: rgba(0,0,0,0.2); }
  ::-webkit-scrollbar-thumb { background: linear-gradient(var(--gold-deep-1), var(--gold-2)); border-radius: 8px; }
  a { color: inherit; }
  :focus-visible { outline: 2px solid var(--gold-3); outline-offset: 2px; border-radius: 4px; }

  /* ---------------- Brand header: the federated Etel Group mark,
     large and unmissable above the card itself - the card is a single
     credential inside a bigger system, not the whole story. -------- */
  .brand-hero {
    width: 100%;
    max-width: 640px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    margin-bottom: 28px;
  }
  .brand-hero-logo-wrap {
    position: relative;
    width: 132px;
    height: 132px;
    margin-bottom: 14px;
  }
  .brand-hero-logo-wrap::before {
    content: "";
    position: absolute;
    inset: -22px;
    background: radial-gradient(circle, rgba(255,246,217,0.55) 0%, rgba(255,246,217,0.14) 45%, transparent 72%);
    filter: blur(2px);
  }
  .brand-hero-logo {
    position: relative;
    width: 100%;
    height: 100%;
    object-fit: contain;
    filter: drop-shadow(0 10px 26px rgba(0,0,0,0.45));
  }
  .brand-hero-word {
    font-family: 'Syne', sans-serif;
    font-weight: 800;
    font-size: clamp(22px, 6vw, 28px);
    letter-spacing: 4px;
    color: #1b1309;
    text-shadow: 0 1px 0 rgba(255,255,255,0.35);
  }
  .brand-hero-word span { color: #fff; }
  .brand-hero-tag {
    margin-top: 6px;
    font-family: 'Manrope', sans-serif;
    font-size: 12.5px;
    font-weight: 600;
    letter-spacing: 1.6px;
    text-transform: uppercase;
    color: rgba(27,19,9,0.72);
  }

  /* ---------------------------- Card ---------------------------- */
  .card {
    position: relative;
    width: 100%;
    max-width: 640px;
    background:
      radial-gradient(620px 260px at 50% -10%, rgba(255,246,217,0.07), transparent 60%),
      linear-gradient(165deg, var(--carbon-5) 0%, var(--carbon-3) 55%, var(--carbon-4) 100%);
    border-radius: 22px;
    overflow: hidden;
    padding: 2px;
    box-shadow:
      0 2px 0 rgba(255,255,255,0.05) inset,
      0 40px 80px -30px rgba(0,0,0,0.75),
      0 12px 30px -12px rgba(0,0,0,0.6);
  }
  .card::before {
    content: "";
    position: absolute;
    inset: 0;
    padding: 1.5px;
    border-radius: 22px;
    background: linear-gradient(150deg, var(--gold-3), var(--gold-deep-1) 30%, rgba(212,175,55,0.25) 55%, var(--gold-2) 78%, var(--gold-4));
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
  }
  .card-inner {
    position: relative;
    padding: 26px 16px 22px;
    text-align: center;
  }
  .card-inner::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--gold-deep-1), var(--gold-2), var(--gold-3), var(--gold-2), var(--gold-deep-1));
  }
  .card-kicker {
    font-family: 'Manrope', sans-serif;
    font-weight: 700;
    font-size: 10.5px;
    letter-spacing: 2.6px;
    text-transform: uppercase;
    color: var(--gold-3);
    margin-bottom: 20px;
    opacity: 0.85;
  }
  .badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-family: 'Manrope', sans-serif;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.6px;
    padding: 7px 16px 7px 12px;
    border-radius: 999px;
    border: 1px solid rgba(212,175,55,0.4);
    background: rgba(212,175,55,0.1);
    color: var(--gold-3);
    margin-bottom: 18px;
  }
  .badge svg { width: 13px; height: 13px; flex: none; }
  .invalid {
    font-family: 'Cormorant Garamond', serif;
    font-size: 22px;
    color: rgba(242,232,208,0.85);
    padding: 28px 0;
  }
  .footer {
    margin-top: 26px;
    font-size: 10px;
    font-weight: 500;
    letter-spacing: 1.4px;
    text-transform: uppercase;
    color: rgba(242,232,208,0.32);
  }

  /* ------------------------- Services list ------------------------ */
  .nfc-services {
    margin-top: 22px;
    padding-top: 22px;
    border-top: 1px solid rgba(212,175,55,0.22);
    text-align: left;
  }
  .nfc-services h2 {
    font-family: 'Manrope', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 2.2px;
    text-transform: uppercase;
    color: var(--ink-faint);
    margin: 0 0 14px;
    text-align: center;
  }

  .nfc-btn {
    display: block;
    width: 100%;
    padding: 13px 16px;
    margin-bottom: 10px;
    border-radius: 12px;
    border: 1px solid rgba(212,175,55,0.32);
    background: rgba(212,175,55,0.07);
    color: var(--gold-3);
    font-family: 'Manrope', sans-serif;
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 0.1px;
    cursor: pointer;
    text-align: center;
    transition: background 160ms ease, border-color 160ms ease, transform 160ms ease, box-shadow 160ms ease;
  }
  .nfc-btn:hover { background: rgba(212,175,55,0.14); border-color: rgba(212,175,55,0.5); }
  .nfc-btn:active { transform: translateY(1px); }
  .nfc-btn.primary {
    background: linear-gradient(135deg,#C8960C,#D4AF37);
    color: #1b1309;
    font-weight: 700;
    border: none;
    box-shadow: 0 8px 20px -8px rgba(200,150,12,0.55);
  }
  .nfc-btn.primary:hover { box-shadow: 0 10px 24px -8px rgba(200,150,12,0.7); transform: translateY(-1px); }
  .nfc-btn:disabled { opacity: 0.55; cursor: default; transform: none; }

  /* Service-row buttons carry the tapped-into company's own medallion
     logo, the role who taps this button, and the action - a labeled
     row, not a bare button, so the card reads as a directory of real
     Etel Group services rather than one generic action list. */
  .nfc-svc-row {
    display: flex;
    align-items: center;
    gap: 13px;
    text-align: left;
    padding: 10px 14px 10px 10px;
    background: linear-gradient(180deg, rgba(255,255,255,0.02), transparent);
  }
  .nfc-svc-row:hover { transform: translateY(-1px); box-shadow: 0 10px 22px -12px rgba(0,0,0,0.6); }
  .nfc-svc-icon {
    flex: none;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    overflow: hidden;
    background: radial-gradient(circle at 35% 30%, rgba(255,246,217,0.35), rgba(212,175,55,0.08) 70%);
    border: 1px solid rgba(212,175,55,0.5);
    box-shadow: 0 4px 10px -4px rgba(0,0,0,0.6);
  }
  .nfc-svc-icon img, .nfc-svc-icon svg { width: 100%; height: 100%; object-fit: cover; display: block; }
  .nfc-svc-text { flex: 1 1 auto; min-width: 0; }
  .nfc-svc-role {
    display: block;
    font-family: 'Manrope', sans-serif;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: rgba(27,19,9,0.55);
    margin-bottom: 2px;
  }
  .nfc-svc-title {
    display: block;
    font-family: 'Manrope', sans-serif;
    font-size: 14.5px;
    font-weight: 700;
    color: #1b1309;
    line-height: 1.25;
  }
  .nfc-svc-chevron {
    flex: none;
    width: 18px;
    height: 18px;
    color: rgba(27,19,9,0.55);
    transition: transform 160ms ease;
  }
  .nfc-svc-row:hover .nfc-svc-chevron { transform: translateX(3px); }

  .nfc-step { display: none; }
  .nfc-step.active { display: block; }
  .nfc-field { margin-bottom: 14px; text-align: left; }
  .nfc-field label {
    display: block;
    font-family: 'Manrope', sans-serif;
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    color: var(--ink-faint);
    margin-bottom: 6px;
  }
  .nfc-field input, .nfc-order-list select {
    width: 100%;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid rgba(212,175,55,0.35);
    background: rgba(0,0,0,0.28);
    color: var(--nfc-ink);
    font-family: 'Manrope', sans-serif;
    font-size: 14px;
    transition: border-color 160ms ease, background 160ms ease;
  }
  .nfc-field input::placeholder { color: rgba(242,232,208,0.32); }
  .nfc-field input:focus { border-color: var(--gold-2); background: rgba(0,0,0,0.4); }

  .nfc-order {
    display: block;
    width: 100%;
    text-align: left;
    padding: 12px 14px;
    margin-bottom: 8px;
    border-radius: 10px;
    border: 1px solid rgba(212,175,55,0.28);
    background: rgba(0,0,0,0.22);
    color: var(--nfc-ink);
    font-family: 'Manrope', sans-serif;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: border-color 160ms ease, background 160ms ease, transform 160ms ease;
  }
  .nfc-order:hover { border-color: var(--gold-2); background: rgba(0,0,0,0.32); transform: translateY(-1px); }

  .nfc-msg { font-family: 'Manrope', sans-serif; font-size: 12.5px; font-weight: 500; margin-top: 10px; text-align: center; }
  .nfc-msg.error { color: var(--bad); }
  .nfc-msg.ok { color: var(--ok); }
  .nfc-msg-loading { display: flex; align-items: center; justify-content: center; gap: 9px; }
  .nfc-spinner {
    width: 15px; height: 15px; flex: none;
    border: 2px solid rgba(212,175,55,0.25);
    border-top-color: var(--gold-2);
    border-radius: 50%;
    animation: nfc-spin 0.8s linear infinite;
  }
  @keyframes nfc-spin { to { transform: rotate(360deg); } }
  @media (prefers-reduced-motion: reduce) {
    .nfc-spinner { animation: none; }
    .nfc-btn, .nfc-order, .nfc-svc-row, .nfc-svc-chevron { transition: none; }
  }

  .nfc-back {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 8px;
    font-family: 'Manrope', sans-serif;
    font-size: 12px;
    font-weight: 600;
    color: var(--ink-faint);
    cursor: pointer;
  }
  .nfc-back:hover { color: var(--gold-3); }
  .nfc-back svg { width: 13px; height: 13px; }

  .nfc-driver-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    font-family: 'Manrope', sans-serif;
    font-size: 12.5px;
    padding: 9px 0;
    border-bottom: 1px solid rgba(212,175,55,0.14);
    text-align: left;
  }
  .nfc-driver-row:last-child { border-bottom: none; }
  .nfc-driver-row span:first-child { color: var(--ink-faint); letter-spacing: 0.3px; font-weight: 500; }
  .nfc-driver-row span:last-child { color: var(--nfc-ink); font-weight: 700; text-align: right; }
  .nfc-badge-ok, .nfc-badge-matched { color: var(--ok); }
  .nfc-badge-bad, .nfc-badge-mismatch { color: var(--bad); }
  .nfc-badge-unverified { color: var(--gold-3); }
  .nfc-license-photo {
    display: block;
    width: 100%;
    max-height: 220px;
    object-fit: contain;
    border-radius: 10px;
    border: 1px solid rgba(212,175,55,0.3);
    background: rgba(0,0,0,0.25);
    margin-bottom: 10px;
  }
  .nfc-receipt-link {
    display: block;
    font-family: 'Manrope', sans-serif;
    font-size: 11.5px;
    font-weight: 600;
    color: #6FE0D6;
    text-decoration: underline;
    text-align: right;
    margin: -6px 0 6px;
  }

  /* ------------------------ Full flip ID card --------------------
     Reuses the exact same premium card design the member's own
     portal renders (templates/card-front.php / card-back.php,
     loaded via public/css/etelid-card.css above) - the tapped card
     shown here is the real card, not a cut-down summary. */
  .nfc-card-scene {
    width: 100%;
    max-width: 520px;
    margin: 0 auto 4px;
    cursor: pointer;
    position: relative;
  }
  .nfc-card-wrap {
    width: 100%;
    aspect-ratio: 460 / 290;
    position: relative;
    filter: drop-shadow(0 16px 34px rgba(0,0,0,0.55));
    transform-style: preserve-3d;
    transition: transform 0.5s ease, filter 0.5s ease;
  }
  .nfc-card-face {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border-radius: 10px;
    overflow: hidden;
    background: #0d0d0d;
    transition: opacity 0.5s ease, transform 0.5s ease;
  }
  .nfc-card-face:first-child { opacity: 1; transform: scale(1); z-index: 2; pointer-events: auto; }
  .nfc-card-face-back { opacity: 0; transform: scale(0.97); z-index: 1; pointer-events: none; }
  .nfc-card-wrap.flipped .nfc-card-face:first-child { opacity: 0; transform: scale(0.97); z-index: 1; pointer-events: none; }
  .nfc-card-wrap.flipped .nfc-card-face-back { opacity: 1; transform: scale(1); z-index: 2; pointer-events: auto; }
  .nfc-card-face .eid-card-front,
  .nfc-card-face .eid-card-back { position: absolute !important; inset: 0; border-radius: 10px; }
  .nfc-card-loading {
    display: flex; align-items: center; justify-content: center;
    height: 100%; color: rgba(212,175,55,0.5);
    font-family: 'JetBrains Mono', monospace; font-size: 11px; letter-spacing: 1.4px;
  }
  .nfc-card-controls {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    margin: 12px 0 20px; flex-wrap: wrap;
  }
  .nfc-card-side {
    font-family: 'JetBrains Mono', monospace; font-size: 9px;
    color: rgba(212,175,55,0.55); letter-spacing: 2px; text-transform: uppercase;
  }
  .nfc-flip-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 18px;
    background: rgba(212,175,55,0.1);
    border: 1.5px solid rgba(212,175,55,0.32);
    border-radius: 22px;
    font-family: 'Manrope', sans-serif; font-size: 11px; font-weight: 700;
    color: var(--gold-3); letter-spacing: 0.6px; text-transform: uppercase;
    cursor: pointer; transition: background 160ms ease, border-color 160ms ease;
  }
  .nfc-flip-btn:hover { background: rgba(212,175,55,0.2); border-color: rgba(212,175,55,0.6); }
  .nfc-flip-btn svg { width: 14px; height: 14px; flex: none; }

  /* ------------------------ Connect & Pay -------------------------
     The card owner's own social/bank accounts (added from their
     portal) - tap a social badge to open their profile, tap a bank
     badge to copy the account number, both without leaving this
     page. */
  .nfc-links { margin-top: 6px; }
  .nfc-links h3 {
    font-family: 'Manrope', sans-serif; font-size: 11px; font-weight: 700;
    letter-spacing: 2.2px; text-transform: uppercase; color: var(--ink-faint);
    margin: 0 0 12px; text-align: center;
  }
  .nfc-links-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(76px, 1fr));
    gap: 10px;
    margin-bottom: 4px;
  }
  .nfc-link-item {
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    padding: 10px 4px 9px;
    border-radius: 12px;
    border: 1px solid rgba(212,175,55,0.2);
    background: rgba(0,0,0,0.18);
    cursor: pointer;
    transition: transform 150ms ease, border-color 150ms ease, background 150ms ease;
    text-align: center;
  }
  .nfc-link-item:hover { transform: translateY(-2px); border-color: rgba(212,175,55,0.5); background: rgba(212,175,55,0.08); }
  .nfc-link-badge {
    width: 38px; height: 38px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Syne', sans-serif; font-weight: 800; font-size: 11px; color: #fff;
    box-shadow: 0 4px 10px -4px rgba(0,0,0,0.6);
    flex: none;
  }
  .nfc-link-label {
    font-family: 'Manrope', sans-serif; font-size: 10px; font-weight: 700;
    color: var(--ink-dim); line-height: 1.25;
    max-width: 74px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .nfc-link-sub {
    font-family: 'JetBrains Mono', monospace; font-size: 8.5px;
    color: var(--ink-faint);
    max-width: 74px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .nfc-toast {
    position: fixed; left: 50%; bottom: 26px;
    transform: translateX(-50%) translateY(16px);
    opacity: 0; pointer-events: none;
    padding: 10px 22px;
    border-radius: 30px;
    background: rgba(19,13,5,0.96);
    border: 1px solid rgba(212,175,55,0.4);
    color: var(--gold-3);
    font-family: 'Manrope', sans-serif; font-size: 12.5px; font-weight: 700;
    z-index: 999;
    transition: opacity 200ms ease, transform 200ms ease;
  }
  .nfc-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
</style>
</head>
<body>
  <div class="brand-hero">
    <div class="brand-hero-logo-wrap">
      <img class="brand-hero-logo" src="<?php echo esc_url($logo_group); ?>" alt="Etel Group" loading="eager">
    </div>
    <div class="brand-hero-word">ETEL<span> GROUP</span></div>
    <div class="brand-hero-tag">Digital Identity &middot; One Card, Every Etel Service</div>
  </div>

  <div class="card">
   <div class="card-inner">
    <div class="card-kicker">EtelID &middot; Verified Digital Card</div>

    <?php if ($has_member) : ?>
      <div id="nfc-step-gate" class="nfc-step active">
        <p class="nfc-role-label" style="font-family:'Manrope',sans-serif;font-size:13px;font-weight:500;color:var(--ink-dim);text-align:center;margin:0 0 18px;">Sign in with your own EtelID to view this card</p>
        <div class="nfc-field">
          <label>Your Phone or Etel-ID</label>
          <input type="text" id="nfc-gate-ident" placeholder="09... or EG-2026-00001" autocomplete="username">
        </div>
        <div class="nfc-field">
          <label>Your EtelID Password</label>
          <input type="password" id="nfc-gate-pass" placeholder="Password" autocomplete="current-password">
        </div>
        <button type="button" class="nfc-btn primary" id="nfc-gate-submit">View Card</button>
        <div id="nfc-gate-msg" class="nfc-msg"></div>
      </div>

      <div id="nfc-revealed" style="display:none;">
        <div class="nfc-card-scene" id="nfc-card-scene" onclick="nfcFlipCard()">
          <div class="nfc-card-wrap" id="nfc-card-3d">
            <div class="nfc-card-face" id="nfc-card-front"><div class="nfc-card-loading">LOADING CARD...</div></div>
            <div class="nfc-card-face nfc-card-face-back" id="nfc-card-back"></div>
          </div>
        </div>
        <div class="nfc-card-controls">
          <span class="nfc-card-side" id="nfc-card-side-lbl">FRONT SIDE</span>
          <button type="button" class="nfc-flip-btn" onclick="event.stopPropagation();nfcFlipCard()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg>
            Flip Card
          </button>
        </div>

        <div class="badge" style="margin:0 auto 20px;width:max-content;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
          Verified Member
        </div>

        <div class="nfc-links" id="nfc-links-section" style="display:none;">
          <h3>Connect &amp; Pay</h3>
          <div class="nfc-links-grid" id="nfc-links-grid"></div>
        </div>

        <div class="nfc-services">
          <h2>Card Services</h2>

          <div id="nfc-step-menu" class="nfc-step active">
            <p id="nfc-menu-empty" class="nfc-msg" style="display:none;">No card services are enabled for your EtelID account yet.</p>
            <button type="button" class="nfc-btn primary nfc-svc-row" data-stage="pickup">
              <span class="nfc-svc-icon"><img src="<?php echo esc_url($logo_delivery); ?>" alt="" loading="lazy"></span>
              <span class="nfc-svc-text"><span class="nfc-svc-role">Service Giver</span><span class="nfc-svc-title">Confirm Pickup</span></span>
              <svg class="nfc-svc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
            </button>
            <button type="button" class="nfc-btn primary nfc-svc-row" data-stage="delivery">
              <span class="nfc-svc-icon"><img src="<?php echo esc_url($logo_delivery); ?>" alt="" loading="lazy"></span>
              <span class="nfc-svc-text"><span class="nfc-svc-role">Customer</span><span class="nfc-svc-title">Confirm Delivery Complete</span></span>
              <svg class="nfc-svc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
            </button>
            <button type="button" class="nfc-btn primary nfc-svc-row" data-stage="ride_start">
              <span class="nfc-svc-icon"><img src="<?php echo esc_url($logo_ride); ?>" alt="" loading="lazy"></span>
              <span class="nfc-svc-text"><span class="nfc-svc-role">Driver</span><span class="nfc-svc-title">Confirm Rider &amp; Start Trip</span></span>
              <svg class="nfc-svc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
            </button>
            <button type="button" class="nfc-btn primary nfc-svc-row" data-stage="driver_status">
              <span class="nfc-svc-icon"><img src="<?php echo esc_url($logo_driver); ?>" alt="" loading="lazy"></span>
              <span class="nfc-svc-text"><span class="nfc-svc-role">Traffic Officer</span><span class="nfc-svc-title">Driver Info</span></span>
              <svg class="nfc-svc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
            </button>
            <button type="button" class="nfc-btn primary nfc-svc-row" data-stage="etelpay_payout">
              <span class="nfc-svc-icon"><img src="<?php echo esc_url($logo_etelpay); ?>" alt="" loading="lazy"></span>
              <span class="nfc-svc-text"><span class="nfc-svc-role">Pay Driver / Cardholder / Collect Payment</span><span class="nfc-svc-title">Pay Out via EtelPay</span></span>
              <svg class="nfc-svc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
            </button>
            <button type="button" class="nfc-btn primary nfc-svc-row" data-stage="etelpay_cardholder_pay">
              <span class="nfc-svc-icon"><img src="<?php echo esc_url($logo_etelpay); ?>" alt="" loading="lazy"></span>
              <span class="nfc-svc-text"><span class="nfc-svc-role">Cardholder Pays (tap to pay)</span><span class="nfc-svc-title">Cardholder Pays via EtelPay</span></span>
              <svg class="nfc-svc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
            </button>
            <button type="button" class="nfc-btn primary nfc-svc-row" data-stage="etelmadeya_register">
              <span class="nfc-svc-icon"><img src="<?php echo esc_url($logo_madeya); ?>" alt="" loading="lazy"></span>
              <span class="nfc-svc-text"><span class="nfc-svc-role">Station Controller</span><span class="nfc-svc-title">Register in Queue</span></span>
              <svg class="nfc-svc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
            </button>
            <button type="button" class="nfc-btn primary nfc-svc-row" data-stage="gebeya_delivery">
              <span class="nfc-svc-icon">
                <svg viewBox="0 0 32 32" role="img" aria-label="Etel Gebeya"><rect width="32" height="32" fill="#130d05"/><defs><linearGradient id="egg" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#FFE066"/><stop offset="100%" stop-color="#B8902E"/></linearGradient></defs><rect width="32" height="32" fill="url(#egg)"/><text x="16" y="21" font-family="Syne,system-ui,sans-serif" font-size="13" font-weight="800" fill="#130d05" text-anchor="middle">EG</text></svg>
              </span>
              <span class="nfc-svc-text"><span class="nfc-svc-role">Etel Gebeya Courier</span><span class="nfc-svc-title">Confirm Gebeya Order</span></span>
              <svg class="nfc-svc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
            </button>
          </div>

          <div id="nfc-step-charge-amount" class="nfc-step">
            <div class="nfc-field">
              <label>Amount (ETB)</label>
              <input type="number" id="nfc-charge-amount" placeholder="0.00" min="0.01" step="0.01" inputmode="decimal">
            </div>
            <div class="nfc-field">
              <label>Note (optional)</label>
              <input type="text" id="nfc-charge-note" placeholder="What is this for?">
            </div>
            <button type="button" class="nfc-btn primary" id="nfc-charge-amount-next">Continue</button>
            <span class="nfc-back" data-back="menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>Cancel</span>
            <div id="nfc-charge-amount-msg" class="nfc-msg"></div>
          </div>

          <div id="nfc-step-cardholder-pay-pin" class="nfc-step">
          <p id="nfc-cardholder-pay-pin-hint" class="nfc-msg"></p>
          <div class="nfc-field">
            <label>Cardholder: Your Phone or Etel-ID</label>
            <input type="text" id="nfc-cardholder-pay-ident" placeholder="09... or EG-2026-00001" autocomplete="username">
          </div>
          <div class="nfc-field">
            <label>Cardholder: Your EtelID Password</label>
            <input type="password" id="nfc-cardholder-pay-pass" placeholder="Password" autocomplete="current-password">
          </div>
          <button type="button" class="nfc-btn primary" id="nfc-cardholder-pay-submit">Approve Payment</button>
          <span class="nfc-back" data-back="menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>Cancel</span>
          <div id="nfc-cardholder-pay-msg" class="nfc-msg"></div>
        </div>

        <div id="nfc-step-madeya-station" class="nfc-step">
          <p id="nfc-madeya-station-hint" class="nfc-msg"></p>
          <div id="nfc-madeya-station-list"></div>
          <span class="nfc-back" data-back="menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>Cancel</span>
          <div id="nfc-madeya-station-msg" class="nfc-msg"></div>
        </div>

        <div id="nfc-step-orders" class="nfc-step">
          <div id="nfc-order-list"></div>
          <span class="nfc-back" data-back="menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>Cancel</span>
        </div>

        <div id="nfc-step-done" class="nfc-step">
          <div id="nfc-done-msg" class="nfc-msg"></div>
          <span class="nfc-back" data-back="menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>Back</span>
        </div>
        </div>
      </div>
    <?php else : ?>
      <p class="invalid">This card is not active.</p>
    <?php endif; ?>

    <div class="footer">ethelmarket.com &middot; EtelId</div>
   </div>
  </div>
  <div class="nfc-toast" id="nfc-toast"></div>
<!-- ETEL AI -- cross-company assistant widget -->
<script src="https://ethelmarket.com/EtelAi/etel-ai-widget.js" data-project="etel-id"></script>
<?php if ($has_member) : ?>
<script>
(function () {
  var AJAX_URL = <?php echo wp_json_encode($nfc_ajax_url); ?>;
  var NONCE = <?php echo wp_json_encode($nfc_nonce); ?>;
  var TOKEN = <?php echo wp_json_encode($nfc_token); ?>;
  var stage = '';
  var actionToken = '';

  function showStep(id) {
    var steps = document.querySelectorAll('.nfc-step');
    for (var i = 0; i < steps.length; i++) steps[i].classList.remove('active');
    document.getElementById(id).classList.add('active');
  }

  var cardFlipped = false;
  window.nfcFlipCard = function () {
    cardFlipped = !cardFlipped;
    var wrap = document.getElementById('nfc-card-3d');
    if (wrap) wrap.classList.toggle('flipped', cardFlipped);
    var lbl = document.getElementById('nfc-card-side-lbl');
    if (lbl) lbl.textContent = cardFlipped ? 'BACK SIDE' : 'FRONT SIDE';
  };

  function showToast(text) {
    var el = document.getElementById('nfc-toast');
    if (!el) return;
    el.textContent = text;
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(function () { el.classList.remove('show'); }, 2200);
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { showToast('Copied to clipboard'); })
        .catch(function () { legacyCopy(text); });
    } else {
      legacyCopy(text);
    }
  }

  function legacyCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { document.execCommand('copy'); showToast('Copied to clipboard'); }
    catch (e) { showToast('Could not copy - long-press to select'); }
    document.body.removeChild(ta);
  }

  // The card owner's own social/bank accounts (added from their
  // portal). A social item opens the profile in a new tab; a bank
  // item copies the account number so the scanner can paste it
  // straight into their banking/mobile-money app - no retyping.
  function renderLinks(links) {
    var section = document.getElementById('nfc-links-section');
    var grid = document.getElementById('nfc-links-grid');
    if (!section || !grid) return;
    grid.innerHTML = '';
    if (!links.length) { section.style.display = 'none'; return; }
    section.style.display = 'block';

    links.forEach(function (l) {
      var item = document.createElement('div');
      item.className = 'nfc-link-item';

      var badge = document.createElement('div');
      badge.className = 'nfc-link-badge';
      if (l.logo) {
        badge.style.background = '#fff';
        var img = document.createElement('img');
        img.src = l.logo;
        img.alt = '';
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;';
        badge.appendChild(img);
      } else {
        badge.style.background = l.color || '#A07820';
        badge.textContent = l.mono || '?';
      }
      item.appendChild(badge);

      var label = document.createElement('div');
      label.className = 'nfc-link-label';
      label.textContent = l.label || '';
      item.appendChild(label);

      var sub = document.createElement('div');
      sub.className = 'nfc-link-sub';
      sub.textContent = l.link_type === 'bank' ? 'Tap to copy' : 'Tap to open';
      item.appendChild(sub);

      item.addEventListener('click', function () {
        if (l.link_type === 'bank') {
          copyText(l.account_value);
        } else if (l.open_url) {
          window.open(l.open_url, '_blank', 'noopener');
        } else {
          copyText(l.account_value);
        }
      });

      grid.appendChild(item);
    });
  }

  // Hides the service rows the signed-in scanner doesn't hold the
  // role for, so the menu shows only what THEY can actually use
  // instead of all 8 actions to every visitor. capabilities is
  // {stage: true|false}; a stage missing from it (an older backend,
  // or a lookup that failed) stays visible rather than vanishing, so
  // this can never silently hide a real action from someone entitled
  // to it.
  function applyCapabilities(capabilities) {
    var rows = document.querySelectorAll('#nfc-step-menu .nfc-btn');
    var visibleCount = 0;
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      var stg = row.getAttribute('data-stage');
      var allowed = !Object.prototype.hasOwnProperty.call(capabilities, stg) || capabilities[stg];
      row.style.display = allowed ? '' : 'none';
      if (allowed) visibleCount++;
    }
    document.getElementById('nfc-menu-empty').style.display = visibleCount ? 'none' : 'block';
  }

  function post(action, data) {
    var body = 'action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(NONCE);
    for (var k in data) {
      if (Object.prototype.hasOwnProperty.call(data, k)) {
        body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
      }
    }
    return fetch(AJAX_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    }).then(function (r) { return r.json(); });
  }

  // Reused for every action after the reveal gate below - the scanning
  // person's identity/password only needs to be typed once per visit.
  var gateIdent = '', gatePass = '';

  document.getElementById('nfc-gate-submit').addEventListener('click', function () {
    var ident = document.getElementById('nfc-gate-ident').value.trim();
    var pass = document.getElementById('nfc-gate-pass').value;
    var msg = document.getElementById('nfc-gate-msg');
    msg.className = 'nfc-msg';
    msg.textContent = '';
    if (!ident || !pass) { msg.className = 'nfc-msg error'; msg.textContent = 'Enter your phone/Etel-ID and password.'; return; }

    this.disabled = true;
    var self = this;
    post('etelid_nfc_reveal', { token: TOKEN, identifier: ident, password: pass })
      .then(function (res) {
        self.disabled = false;
        if (!res.success) {
          msg.className = 'nfc-msg error';
          msg.textContent = res.data && res.data.message ? res.data.message : 'Could not continue.';
          return;
        }
        gateIdent = ident;
        gatePass = pass;

        var d = res.data;
        document.title = d.name + ' - EtelID';

        if (d.card_front_html) document.getElementById('nfc-card-front').innerHTML = d.card_front_html;
        if (d.card_back_html) document.getElementById('nfc-card-back').innerHTML = d.card_back_html;

        renderLinks(d.links || []);
        applyCapabilities(d.capabilities || {});

        document.getElementById('nfc-step-gate').classList.remove('active');
        document.getElementById('nfc-revealed').style.display = 'block';
        showStep('nfc-step-menu');
      })
      .catch(function () {
        self.disabled = false;
        msg.className = 'nfc-msg error';
        msg.textContent = 'Network error, please try again.';
      });
  });

  var backs = document.querySelectorAll('.nfc-back');
  for (var b = 0; b < backs.length; b++) {
    backs[b].addEventListener('click', function () {
      document.getElementById('nfc-charge-amount').value = '';
      document.getElementById('nfc-charge-note').value = '';
      document.getElementById('nfc-charge-amount-msg').textContent = '';
      document.getElementById('nfc-cardholder-pay-ident').value = '';
      document.getElementById('nfc-cardholder-pay-pass').value = '';
      document.getElementById('nfc-cardholder-pay-msg').textContent = '';
      showStep('nfc-step-menu');
    });
  }

  var menuBtns = document.querySelectorAll('#nfc-step-menu .nfc-btn');
  for (var m = 0; m < menuBtns.length; m++) {
    menuBtns[m].addEventListener('click', function () {
      stage = this.getAttribute('data-stage');
      // Both EtelPay stages need an amount first. Every stage's own
      // identity/authorization now comes from the reveal-gate login
      // above (reused via gateIdent/gatePass in startFlow()) - no
      // separate per-action login step anymore.
      var etelpayStages = (stage === 'etelpay_payout' || stage === 'etelpay_cardholder_pay');
      if (etelpayStages) {
        showStep('nfc-step-charge-amount');
      } else {
        startFlow({});
      }
    });
  }

  var chargeAmount = 0, chargeNote = '';
  document.getElementById('nfc-charge-amount-next').addEventListener('click', function () {
    var amtEl = document.getElementById('nfc-charge-amount');
    var msg = document.getElementById('nfc-charge-amount-msg');
    var amt = parseFloat(amtEl.value);
    if (!amt || amt <= 0) { msg.className = 'nfc-msg error'; msg.textContent = 'Enter an amount greater than zero.'; return; }
    chargeAmount = amt;
    chargeNote = document.getElementById('nfc-charge-note').value.trim();
    msg.className = 'nfc-msg';
    msg.textContent = '';
    startFlow({ amount: chargeAmount, note: chargeNote });
  });

  function startFlow(extra) {
    var doneMsg = document.getElementById('nfc-done-msg');
    doneMsg.className = 'nfc-msg nfc-msg-loading';
    doneMsg.innerHTML = '<span class="nfc-spinner" aria-hidden="true"></span>Please wait...';
    showStep('nfc-step-done');

    var postData = { token: TOKEN, stage: stage, identifier: gateIdent, password: gatePass };
    for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) postData[k] = extra[k]; }

    post('etelid_nfc_start', postData)
      .then(function (res) {
        if (!res.success) {
          doneMsg.className = 'nfc-msg error';
          doneMsg.textContent = res.data && res.data.message ? res.data.message : 'Could not continue.';
          return;
        }
        if (res.data.ride_started) {
          doneMsg.className = 'nfc-msg ok';
          doneMsg.textContent = res.data.message || 'Trip started.';
          return;
        }

        if (res.data.driver_status) {
          renderDriverStatus(res.data.driver_status);
          return;
        }

        if (res.data.payout_done) {
          doneMsg.className = 'nfc-msg ok';
          doneMsg.textContent = res.data.message || 'Payment sent.';
          return;
        }

        if (res.data.madeya_register_done) {
          doneMsg.className = 'nfc-msg ok';
          doneMsg.textContent = res.data.message || 'Registered in queue.';
          return;
        }

        if (res.data.station_pending) {
          actionToken = res.data.action_token;
          document.getElementById('nfc-madeya-station-hint').textContent =
            'Registering ' + res.data.driver_name + '. Pick which station:';
          document.getElementById('nfc-madeya-station-msg').textContent = '';
          var stationList = document.getElementById('nfc-madeya-station-list');
          stationList.innerHTML = '';
          var stations = res.data.stations || [];
          if (!stations.length) {
            var noStations = document.createElement('p');
            noStations.className = 'nfc-msg error';
            noStations.textContent = 'No active stations found.';
            stationList.appendChild(noStations);
          } else {
            stations.forEach(function (s) {
              var btn = document.createElement('button');
              btn.type = 'button';
              btn.className = 'nfc-order';
              btn.textContent = s.station_name;
              btn.addEventListener('click', function () { confirmMadeyaStation(s.id); });
              stationList.appendChild(btn);
            });
          }
          showStep('nfc-step-madeya-station');
          return;
        }

        if (res.data.cardholder_pay_pending) {
          actionToken = res.data.action_token;
          document.getElementById('nfc-cardholder-pay-pin-hint').textContent =
            'Charging ' + res.data.payer_name + ' ' + res.data.amount + ' ETB. Hand the device to the cardholder.';
          document.getElementById('nfc-cardholder-pay-msg').textContent = '';
          document.getElementById('nfc-cardholder-pay-ident').value = '';
          document.getElementById('nfc-cardholder-pay-pass').value = '';
          showStep('nfc-step-cardholder-pay-pin');
          return;
        }

        actionToken = res.data.action_token;
        var orders = res.data.orders || [];
        var list = document.getElementById('nfc-order-list');
        list.innerHTML = '';
        if (!orders.length) {
          var p = document.createElement('p');
          p.className = 'nfc-msg';
          p.textContent = 'No matching active orders found for this courier.';
          list.appendChild(p);
        } else {
          orders.forEach(function (o) {
            var el = document.createElement('button');
            el.type = 'button';
            el.className = 'nfc-order';
            var label = o.order_code || ('#' + o.id);
            if (o.from_zone || o.to_zone) {
              label += '  -  ' + o.from_zone + ' to ' + o.to_zone;
            } else if (o.vendor_name || o.amount) {
              label += '  -  ' + (o.vendor_name || '') + (o.amount ? '  (' + o.amount + ' ' + (o.currency || 'ETB') + ')' : '');
            }
            el.textContent = label;
            el.addEventListener('click', function () { confirmOrder(o.id); });
            list.appendChild(el);
          });
        }
        showStep('nfc-step-orders');
      })
      .catch(function () {
        doneMsg.className = 'nfc-msg error';
        doneMsg.textContent = 'Network error, please try again.';
      });
  }

  function renderDriverStatus(d) {
    var doneMsg = document.getElementById('nfc-done-msg');
    doneMsg.className = 'nfc-msg';
    doneMsg.innerHTML = '';

    if (!d.is_driver) {
      doneMsg.textContent = 'This card is not linked to a driver account.';
      return;
    }

    var wrap = document.createElement('div');

    // Print-style receipt built entirely client-side from data the
    // bridge already returned - EtelTaxi has no uploaded receipt photo
    // for association dues (drivers submit a bank/Telebirr reference
    // number, an admin verifies it - see ServiceController.php's
    // last_payment), so this constructs a real text receipt on the fly
    // instead of linking to an image that doesn't exist.
    function buildReceiptUrl(title, rows, badgeText, badgeKind) {
      // Full gold/carbon branded receipt - same design language as the
      // card itself (holo strip, gold gradient title, badge pills),
      // not a bare unstyled table - this is handed to a traffic
      // officer or kept by the driver as a real record.
      var badgeColors = {
        ok:  ['rgba(46,204,113,.14)', '#7ee8a8', 'rgba(46,204,113,.4)'],
        bad: ['rgba(231,76,60,.14)',  '#ff8a7a', 'rgba(231,76,60,.4)'],
        warn:['rgba(242,204,107,.14)','#F2CC6B', 'rgba(242,204,107,.4)']
      };
      var bc = badgeColors[badgeKind] || badgeColors.warn;
      var badgeHtml = badgeText
        ? '<div style="display:inline-block;padding:8px 20px;border-radius:30px;background:' + bc[0] + ';color:' + bc[1] + ';border:1px solid ' + bc[2] + ';font-weight:800;font-size:14px;letter-spacing:.4px;text-transform:uppercase;margin-bottom:22px">' + badgeText + '</div>'
        : '';
      var html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
        + '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        + '<title>' + title + ' Receipt</title>'
        + '<link rel="stylesheet" href="<?php echo esc_url( ETELID_URL . 'public/css/etelid-fonts.css' ); ?>">'
        + '<style>'
        + '*{box-sizing:border-box;margin:0;padding:0}'
        + 'body{font-family:"DM Sans",system-ui,sans-serif;background:'
        +   'radial-gradient(1100px 600px at 15% -10%, rgba(212,175,55,.14), transparent 60%),'
        +   'radial-gradient(900px 500px at 110% 10%, rgba(212,175,55,.1), transparent 55%),#000;'
        +   'min-height:100vh;padding:36px 18px;color:#fff}'
        + '.receipt{max-width:560px;margin:0 auto;background:linear-gradient(165deg,#161616,#0d0d0d);'
        +   'border:1px solid rgba(212,175,55,.32);border-radius:22px;overflow:hidden;'
        +   'box-shadow:0 1px 0 rgba(255,255,255,.04) inset, 0 30px 60px -20px rgba(0,0,0,.7)}'
        + '.holo{height:4px;background:linear-gradient(90deg,#ff6b6b,#ffd93d,#6bcb77,#4d96ff,#ff6b6b,#ffd93d,#6bcb77,#4d96ff);background-size:300%;animation:holo 3s linear infinite}'
        + '@keyframes holo{to{background-position:300% 0}}'
        + '.body{padding:40px 36px 32px;text-align:center}'
        + '.mark{font-family:"Syne",sans-serif;font-weight:800;font-size:13px;letter-spacing:3px;color:#8B6914;text-transform:uppercase;margin-bottom:6px}'
        + 'h1{font-family:"Syne",sans-serif;font-weight:800;font-size:26px;'
        +   'background:linear-gradient(100deg,#C8960C,#D4AF37 45%,#fff6d9 55%,#D4AF37);'
        +   '-webkit-background-clip:text;background-clip:text;color:transparent;margin-bottom:22px}'
        + 'table{width:100%;border-collapse:collapse;text-align:left}'
        + 'td{padding:14px 4px;border-bottom:1px solid rgba(212,175,55,.16);font-size:15px}'
        + 'td:first-child{color:#9a9a9a;font-weight:600;width:42%}'
        + 'td:last-child{font-family:"JetBrains Mono",monospace;font-weight:700;letter-spacing:.2px;text-align:right}'
        + 'tr:last-child td{border-bottom:none}'
        + '.foot{padding:18px 36px 26px;text-align:center;font-size:11px;color:#7a7a7a;border-top:1px solid rgba(212,175,55,.16)}'
        + '.foot strong{color:#D4AF37}'
        + '</style></head><body>'
        + '<div class="receipt"><div class="holo"></div><div class="body">'
        + '<div class="mark">ETEL GROUP &middot; OFFICIAL RECEIPT</div>'
        + '<h1>' + title + '</h1>'
        + badgeHtml
        + '<table>' + rows.map(function (r) { return '<tr><td>' + r[0] + '</td><td>' + (r[1] || '&mdash;') + '</td></tr>'; }).join('') + '</table>'
        + '</div><div class="foot">ethelmarket.com &middot; Printed <strong>' + new Date().toLocaleString() + '</strong></div></div>'
        + '</body></html>';
      // A blob: URL, not data: - Chrome blocks top-level/new-tab
      // navigation to data: URLs as an anti-phishing measure, which on
      // some builds tears down the whole tab/browser instead of just
      // failing quietly. blob: URLs open normally.
      var blob = new Blob([html], { type: 'text/html' });
      return URL.createObjectURL(blob);
    }

    if (d.license_photo_url) {
      row('License Photo', 'On file', null, d.license_photo_url, 'View license');
    }

    function row(label, valueText, badgeClass, receiptUrl, receiptLabel) {
      var r = document.createElement('div');
      r.className = 'nfc-driver-row';
      var l = document.createElement('span');
      l.textContent = label;
      var v = document.createElement('span');
      if (badgeClass) v.className = badgeClass;
      v.textContent = valueText;
      r.appendChild(l);
      r.appendChild(v);
      wrap.appendChild(r);
      if (receiptUrl) {
        var link = document.createElement('a');
        link.href = receiptUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        link.className = 'nfc-receipt-link';
        link.textContent = receiptLabel || 'View receipt';
        wrap.appendChild(link);
      }
    }

    row('Vehicle', (d.vehicle_type || '') + (d.vehicle_plate ? ' - ' + d.vehicle_plate : ''));
    if (d.license_name || d.license_masked) {
      row('License', [d.license_name, d.license_masked].filter(Boolean).join(' '));
    }
    row('Identity', d.identity_match ? d.identity_match.charAt(0).toUpperCase() + d.identity_match.slice(1) : 'Unverified',
      'nfc-badge-' + (d.identity_match || 'unverified'));

    // Association dues - live from EtelTaxi's own database, not a
    // Delivery-side field, so this can also legitimately say
    // "unavailable" (EtelTaxi unreachable) or "not registered" (this
    // driver has no EtelTaxi account at all), not just paid/unpaid.
    var a = d.association || {};
    var assocText, assocClass;
    if (a.status === 'active') { assocText = 'Paid / Active'; assocClass = 'nfc-badge-ok'; }
    else if (a.status === 'grace_period') { assocText = 'Due (grace period until ' + (a.window_end || '?') + ')'; assocClass = 'nfc-badge-unverified'; }
    else if (a.status === 'overdue') { assocText = 'OVERDUE' + (a.penalty_owed ? ' - ETB ' + parseFloat(a.penalty_owed).toFixed(2) + ' penalty' : ''); assocClass = 'nfc-badge-bad'; }
    else if (a.status === 'not_registered') { assocText = 'Not registered with EtelTaxi'; assocClass = 'nfc-badge-unverified'; }
    else { assocText = 'Unavailable'; assocClass = 'nfc-badge-unverified'; }
    var assocReceiptUrl = null;
    if (a.last_payment) {
      var lp = a.last_payment;
      var badgeKind = a.status === 'active' ? 'ok' : (a.status === 'overdue' ? 'bad' : 'warn');
      assocReceiptUrl = buildReceiptUrl('Association Due', [
        ['Association', (a.association ? (a.association.name_en || a.association.name_am) : null)],
        ['Period', lp.period_month],
        ['Amount', 'ETB ' + parseFloat(lp.amount || 0).toFixed(2) + (lp.penalty_amount > 0 ? ' + ETB ' + parseFloat(lp.penalty_amount).toFixed(2) + ' penalty' : '')],
        ['Method', lp.payment_method],
        ['Reference', lp.reference_number],
        ['Verified', lp.verified_at],
      ], assocText, badgeKind);
    }
    row('Association Due', assocText, assocClass, assocReceiptUrl);

    // EtelPay wallet - live from EtelPay's own database, same pattern
    // as Association Due. Deliberately shows status only, never a
    // balance (see etelpay_bridge.php's own comment on why) - this is
    // an identity/compliance check, not a financial one.
    var w = d.wallet || {};
    var walletText, walletClass;
    if (w.status === 'active') { walletText = w.kyc_status === 'verified' ? 'Active - KYC Verified' : 'Active - KYC Pending'; walletClass = w.kyc_status === 'verified' ? 'nfc-badge-ok' : 'nfc-badge-unverified'; }
    else if (w.status === 'not_registered') { walletText = 'No EtelPay wallet'; walletClass = 'nfc-badge-unverified'; }
    else if (w.status === 'unavailable') { walletText = 'Unavailable'; walletClass = 'nfc-badge-unverified'; }
    else { walletText = w.status ? w.status.charAt(0).toUpperCase() + w.status.slice(1) : 'Unavailable'; walletClass = 'nfc-badge-bad'; }
    row('EtelPay Wallet', walletText, walletClass);

    function docRow(label, doc) {
      doc = doc || {};
      var text, cls;
      if (doc.status === 'valid') { text = 'Valid until ' + doc.expiry_date; cls = 'nfc-badge-ok'; }
      else if (doc.status === 'expired') { text = 'EXPIRED (' + doc.expiry_date + ')'; cls = 'nfc-badge-bad'; }
      else { text = 'Not on file'; cls = 'nfc-badge-unverified'; }
      row(label, text, cls, doc.receipt_url);
    }
    docRow('Insurance', d.insurance);
    docRow('Technical Inspection', d.technical_inspection);
    docRow('Road Fund', d.road_fund);

    doneMsg.appendChild(wrap);
  }

  function confirmOrder(orderId) {
    post('etelid_nfc_confirm', { action_token: actionToken, order_id: orderId })
      .then(function (res) {
        var doneMsg = document.getElementById('nfc-done-msg');
        if (res.success) {
          doneMsg.className = 'nfc-msg ok';
          doneMsg.textContent = res.data && res.data.message ? res.data.message : 'Confirmed.';
        } else {
          doneMsg.className = 'nfc-msg error';
          doneMsg.textContent = res.data && res.data.message ? res.data.message : 'Could not confirm.';
        }
        showStep('nfc-step-done');
      })
      .catch(function () {
        var doneMsg = document.getElementById('nfc-done-msg');
        doneMsg.className = 'nfc-msg error';
        doneMsg.textContent = 'Network error, please try again.';
        showStep('nfc-step-done');
      });
  }

  function confirmMadeyaStation(stationId) {
    post('etelid_nfc_madeya_station_confirm', { action_token: actionToken, station_id: stationId })
      .then(function (res) {
        var doneMsg = document.getElementById('nfc-done-msg');
        if (res.success) {
          doneMsg.className = 'nfc-msg ok';
          doneMsg.textContent = res.data && res.data.message ? res.data.message : 'Registered in queue.';
        } else {
          doneMsg.className = 'nfc-msg error';
          doneMsg.textContent = res.data && res.data.message ? res.data.message : 'Could not register.';
        }
        showStep('nfc-step-done');
      })
      .catch(function () {
        var doneMsg = document.getElementById('nfc-done-msg');
        doneMsg.className = 'nfc-msg error';
        doneMsg.textContent = 'Network error, please try again.';
        showStep('nfc-step-done');
      });
  }

  document.getElementById('nfc-cardholder-pay-submit').addEventListener('click', function () {
    var ident = document.getElementById('nfc-cardholder-pay-ident').value.trim();
    var pass = document.getElementById('nfc-cardholder-pay-pass').value;
    var msg = document.getElementById('nfc-cardholder-pay-msg');
    msg.className = 'nfc-msg';
    msg.textContent = '';
    if (!ident || !pass) { msg.className = 'nfc-msg error'; msg.textContent = 'Enter your phone/Etel-ID and password.'; return; }

    this.disabled = true;
    var self = this;
    post('etelid_nfc_cardholder_pay_confirm', { action_token: actionToken, payer_identifier: ident, payer_password: pass })
      .then(function (res) {
        self.disabled = false;
        var doneMsg = document.getElementById('nfc-done-msg');
        if (res.success) {
          doneMsg.className = 'nfc-msg ok';
          doneMsg.textContent = res.data && res.data.message ? res.data.message : 'Payment sent.';
        } else {
          doneMsg.className = 'nfc-msg error';
          doneMsg.textContent = res.data && res.data.message ? res.data.message : 'Payment failed.';
        }
        showStep('nfc-step-done');
      })
      .catch(function () {
        self.disabled = false;
        var doneMsg = document.getElementById('nfc-done-msg');
        doneMsg.className = 'nfc-msg error';
        doneMsg.textContent = 'Network error, please try again.';
        showStep('nfc-step-done');
      });
  });

})();
</script>
<?php endif; ?>
</body>
</html>
