<?php
/**
 * Admin "Write NFC Card" template.
 * ASSUMPTION: loaded as a modal/page inside the existing portal Admin Tools,
 * the same way the Members Controller modal is loaded. Wire this into the
 * admin menu/modal registry wherever that happens in the existing plugin -
 * this file only outputs its own markup and does not register itself.
 *
 * Requires ETELID_NFC_ADMIN_CAP capability (see class-etelid-nfc.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can(ETELID_NFC_ADMIN_CAP)) {
    wp_die('Not allowed.');
}

$nonce = wp_create_nonce('etelid_nfc_admin');
$ajax_url = admin_url('admin-ajax.php');

wp_enqueue_script(
    'etelid-nfc-writer',
    plugins_url('assets/js/etelid-nfc-writer.js', dirname(__FILE__)),
    array(),
    '1.0',
    true
);

wp_localize_script('etelid-nfc-writer', 'EtelidNfcWriter', array(
    'ajaxUrl' => $ajax_url,
    'nonce'   => $nonce,
));
?>
<div id="etelid-nfc-writer-panel" class="etelid-gold-card">
  <h2 class="etelid-nfc-title">Write NFC Card</h2>
  <p class="etelid-nfc-sub">Issue a profile link for a member, then write it to a physical NFC tag.</p>

  <div class="etelid-nfc-field">
    <label for="etelid-nfc-search">Find member</label>
    <input type="text" id="etelid-nfc-search" placeholder="EtelID number or name" autocomplete="off">
    <div id="etelid-nfc-results" class="etelid-nfc-results"></div>
  </div>

  <div id="etelid-nfc-selected" class="etelid-nfc-selected" style="display:none;">
    <p>Selected: <strong id="etelid-nfc-selected-name"></strong> (<span id="etelid-nfc-selected-id"></span>)</p>
    <input type="hidden" id="etelid-nfc-member-id" value="">
    <button type="button" id="etelid-nfc-issue-btn" class="etelid-gold-btn">Generate Profile Link</button>
  </div>

  <div id="etelid-nfc-issued" class="etelid-nfc-issued" style="display:none;">
    <p class="etelid-nfc-url-label">Profile URL</p>
    <div class="etelid-nfc-url-row">
      <input type="text" id="etelid-nfc-url" readonly>
      <button type="button" id="etelid-nfc-copy-btn" class="etelid-gold-btn-outline">Copy</button>
    </div>

    <div class="etelid-nfc-actions">
      <a id="etelid-nfc-test-link" href="#" target="_blank" class="etelid-gold-btn-outline">Open / Test on This Phone</a>
      <button type="button" id="etelid-nfc-write-btn" class="etelid-gold-btn">Write to NFC Tag</button>
    </div>

    <p id="etelid-nfc-write-status" class="etelid-nfc-status"></p>
    <p class="etelid-nfc-hint">
      No physical tag yet? "Open / Test on This Phone" loads the exact page a
      tap would show - it confirms the lookup and template are working before
      you buy NTAG213 cards.
    </p>
  </div>
</div>

<style>
  .etelid-gold-card {
    background: linear-gradient(165deg, #130d05 0%, #0a0703 100%);
    border: 1px solid rgba(212,175,55,0.35);
    border-radius: 14px;
    padding: 24px;
    max-width: 480px;
    color: #f2e8d0;
    font-family: 'JetBrains Mono', monospace;
    position: relative;
    overflow: hidden;
  }
  .etelid-gold-card::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, #A07820, #D4AF37, #F2CC6B, #D4AF37, #A07820);
  }
  .etelid-nfc-title {
    font-family: 'Syne', sans-serif;
    font-weight: 800;
    font-size: 18px;
    color: #fff;
    margin: 6px 0 4px;
  }
  .etelid-nfc-sub {
    font-size: 12px;
    color: rgba(242,232,208,0.6);
    margin: 0 0 18px;
  }
  .etelid-nfc-field label {
    display: block;
    font-size: 11px;
    letter-spacing: 0.5px;
    color: #D4AF37;
    margin-bottom: 6px;
  }
  .etelid-nfc-field input[type="text"] {
    width: 100%;
    background: #0d0d0d;
    border: 1px solid rgba(212,175,55,0.3);
    border-radius: 8px;
    padding: 10px 12px;
    color: #f2e8d0;
    font-family: inherit;
    font-size: 13px;
  }
  .etelid-nfc-results {
    margin-top: 8px;
  }
  .etelid-nfc-result-item {
    padding: 8px 10px;
    border: 1px solid rgba(212,175,55,0.2);
    border-radius: 6px;
    margin-bottom: 6px;
    cursor: pointer;
    font-size: 12px;
  }
  .etelid-nfc-result-item:hover {
    background: rgba(212,175,55,0.08);
  }
  .etelid-nfc-selected, .etelid-nfc-issued {
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px solid rgba(212,175,55,0.15);
  }
  .etelid-gold-btn, .etelid-gold-btn-outline {
    font-family: 'Syne', sans-serif;
    font-weight: 700;
    font-size: 12px;
    letter-spacing: 0.5px;
    border-radius: 8px;
    padding: 10px 16px;
    cursor: pointer;
    border: none;
    display: inline-block;
    text-decoration: none;
    text-align: center;
  }
  .etelid-gold-btn {
    background: linear-gradient(135deg,#C8960C,#D4AF37);
    color: #0d0d0d;
  }
  .etelid-gold-btn-outline {
    background: transparent;
    color: #D4AF37;
    border: 1px solid rgba(212,175,55,0.4);
  }
  .etelid-nfc-url-row {
    display: flex;
    gap: 8px;
    margin-bottom: 14px;
  }
  .etelid-nfc-url-row input {
    flex: 1;
    background: #0d0d0d;
    border: 1px solid rgba(212,175,55,0.3);
    border-radius: 8px;
    padding: 10px 12px;
    color: #f2e8d0;
    font-family: inherit;
    font-size: 12px;
  }
  .etelid-nfc-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 12px;
  }
  .etelid-nfc-status {
    font-size: 12px;
    color: #F2CC6B;
    min-height: 16px;
  }
  .etelid-nfc-hint {
    font-size: 11px;
    color: rgba(242,232,208,0.45);
    line-height: 1.5;
  }
</style>
