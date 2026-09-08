<?php
/**
 * Admin view: Biometric Enrollment Center (v3.0.0)
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

$member_id   = isset( $_GET['member'] ) ? (int) $_GET['member'] : 0;
$all_members = EtelID_DB::get_all( array( 'limit' => 500, 'orderby' => 'full_name', 'order' => 'ASC' ) );
$stats       = EtelID_DB::biometric_global_stats();
$m           = $member_id ? EtelID_DB::get_member( $member_id ) : null;
$bio         = $m ? EtelID_DB::get_biometric( $m->id ) : null;
$score       = EtelID_Biometric::enrollment_score( $bio );
$token       = $m ? EtelID_Biometric::generate_enrollment_token( $m->id ) : '';
$liveness    = $bio ? EtelID_Biometric::liveness_label( $bio->liveness_level ) : EtelID_Biometric::liveness_label(0);
?>
<div class="wrap etelid-wrap">
<h1 class="etelid-page-title">🔏 Biometric Enrollment Center <span class="etelid-ver">v3.0.0</span></h1>

<!-- Global stats bar -->
<div class="etelid-bio-stats-row">
    <?php
    $stat_items = array(
        array( 'icon' => '🔏', 'val' => $stats['fingerprint'], 'lbl' => 'Fingerprint', 'cls' => 'bio-fp' ),
        array( 'icon' => '👤', 'val' => $stats['face'],        'lbl' => 'Face',         'cls' => 'bio-face' ),
        array( 'icon' => '📡', 'val' => $stats['nfc'],         'lbl' => 'NFC',          'cls' => 'bio-nfc' ),
        array( 'icon' => '👁',  'val' => $stats['iris'],        'lbl' => 'Iris',         'cls' => 'bio-iris' ),
        array( 'icon' => '🎤', 'val' => $stats['voice'],       'lbl' => 'Voice',        'cls' => 'bio-voice' ),
        array( 'icon' => '🔑', 'val' => $stats['webauthn'],    'lbl' => 'WebAuthn',     'cls' => 'bio-webauthn' ),
        array( 'icon' => '✅', 'val' => $stats['liveness3'],   'lbl' => 'Level 3',      'cls' => 'bio-full' ),
        array( 'icon' => '👥', 'val' => $stats['total'],       'lbl' => 'Enrolled',     'cls' => '' ),
    );
    foreach ( $stat_items as $si ) :
        $pct = $stats['total'] > 0 ? round( $si['val'] / $stats['total'] * 100 ) : 0;
    ?>
    <div class="etelid-stat <?php echo esc_attr( $si['cls'] ); ?>">
        <div class="s-icon"><?php echo $si['icon']; ?></div>
        <div class="s-val"><?php echo $si['val']; ?></div>
        <div class="s-lbl"><?php echo esc_html( $si['lbl'] ); ?></div>
        <div class="s-bar"><div style="width:<?php echo $pct; ?>%"></div></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="etelid-bio-layout">

    <!-- ── Left: member selector ── -->
    <div class="etelid-card etelid-bio-sidebar">
        <h3 style="color:var(--gold);margin-bottom:12px;">Select Member</h3>
        <form method="get">
            <input type="hidden" name="page" value="etel-id-biometric">
            <select name="member" onchange="this.form.submit()" style="width:100%;background:#111;border:1px solid #3A3228;color:#E8D9B5;padding:8px;border-radius:6px;font-size:14px;">
                <option value="">— choose member —</option>
                <?php foreach ( $all_members as $mem ) : ?>
                <option value="<?php echo $mem->id; ?>" <?php selected( $member_id, $mem->id ); ?>>
                    <?php echo esc_html( $mem->full_name . ' · ' . $mem->etel_id ); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ( $m ) : ?>
        <!-- Member card -->
        <div class="etelid-bio-member-card">
            <?php if ( $m->photo_url ) : ?>
            <img src="<?php echo esc_url( $m->photo_url ); ?>" class="ebmc-photo" alt="">
            <?php else : ?>
            <div class="ebmc-photo ebmc-placeholder">👤</div>
            <?php endif; ?>
            <div class="ebmc-name"><?php echo esc_html( $m->full_name ); ?></div>
            <?php if ( $m->full_name_am ) : ?>
            <div class="ebmc-am"><?php echo esc_html( $m->full_name_am ); ?></div>
            <?php endif; ?>
            <div class="ebmc-id"><?php echo esc_html( $m->etel_id ); ?></div>
            <div class="ebmc-company"><?php echo esc_html( $m->primary_company . ' · ' . $m->tier ); ?></div>
            <!-- Liveness badge -->
            <div class="ebmc-liveness" style="color:<?php echo esc_attr( $liveness['color'] ); ?>;margin-top:8px;font-size:12px;">
                ● <?php echo esc_html( $liveness['label'] ); ?>
            </div>
            <!-- Enrollment score bar -->
            <div class="ebmc-score-wrap" style="margin-top:10px;">
                <div style="height:5px;background:#333;border-radius:3px;">
                    <div style="height:100%;width:<?php echo $score; ?>%;background:#D4AF37;border-radius:3px;transition:width .4s;"></div>
                </div>
                <div style="font-size:11px;color:#888;margin-top:3px;font-family:'JetBrains Mono',monospace;"><?php echo $score; ?>% enrolled</div>
            </div>
        </div>

        <!-- Scanner App token -->
        <div class="etelid-token-box">
            <div class="etb-title">📱 Scanner App Token</div>
            <div class="etb-desc">30-min one-time token. Share with the Etel-ID Android scanner app to enroll biometrics from a physical device.</div>
            <div class="etb-token"><?php echo esc_html( $token ); ?></div>
            <button class="etelid-btn etelid-btn-sm" onclick="navigator.clipboard.writeText('<?php echo esc_js( $token ); ?>');this.textContent='✓ Copied!'">Copy Token</button>
        </div>

        <!-- REST API reference -->
        <div class="etelid-token-box" style="margin-top:10px;">
            <div class="etb-title">🔗 REST Endpoint</div>
            <div class="etb-token" style="font-size:10px;"><?php echo esc_html( home_url('/wp-json/etelid/v1/') ); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Right: enrollment panels ── -->
    <?php if ( $m ) : ?>
    <div class="etelid-bio-panels">
    <?php
    $modalities = array(
        array(
            'method'  => 'fingerprint',
            'icon'    => '🔏',
            'label'   => 'Fingerprint',
            'std'     => 'ISO/IEC 19794-2 · WSQ template → SHA-256',
            'desc'    => 'Enroll an ISO-standard fingerprint template. Paste the raw WSQ template bytes (base64) or the pre-computed SHA-256 hash. The scanner app can post this automatically via REST.',
            'hash_field' => 'fingerprint_hash',
            'qual_field' => 'fingerprint_quality',
            'input_placeholder' => 'Paste WSQ template (base64) or SHA-256 hash…',
        ),
        array(
            'method'  => 'face',
            'icon'    => '👤',
            'label'   => 'Face Recognition',
            'std'     => 'FaceNet / DeepFace 128-D embedding → SHA-256',
            'desc'    => 'Enroll a facial embedding. Paste the 128-D embedding vector or its SHA-256 hash. The scanner app captures the face with the front camera and posts the hash via REST.',
            'hash_field' => 'face_hash',
            'qual_field' => 'face_quality',
            'input_placeholder' => 'Paste face embedding or SHA-256 hash…',
        ),
        array(
            'method'  => 'nfc',
            'icon'    => '📡',
            'label'   => 'NFC / RFID Card',
            'std'     => 'ISO 14443 / ISO 15693 · UID stored as HEX',
            'desc'    => 'Link the member\'s physical NFC/RFID card. Enter the card UID in HEX format (e.g. A3:F1:04:BC). Supports MIFARE Classic, MIFARE DESFire, NTAG213/215/216, FeliCa, ISO 15693.',
            'hash_field' => 'nfc_uid',
            'qual_field' => null,
            'input_placeholder' => 'NFC UID — e.g. A3:F1:04:BC:9E',
        ),
        array(
            'method'  => 'iris',
            'icon'    => '👁',
            'label'   => 'Iris Recognition',
            'std'     => 'ISO/IEC 19794-6 · IrisCode → SHA-256',
            'desc'    => 'Enroll an IrisCode template. Requires an IR iris camera or a scanner app with iris capture support. The SHA-256 of the IrisCode binary is stored.',
            'hash_field' => 'iris_hash',
            'qual_field' => 'iris_quality',
            'input_placeholder' => 'Paste IrisCode or SHA-256 hash…',
        ),
        array(
            'method'  => 'voice',
            'icon'    => '🎤',
            'label'   => 'Voice Print',
            'std'     => 'MFCC Voice-Print Vector → SHA-256',
            'desc'    => 'Enroll an MFCC voice-print vector. The member speaks 3 standard phrases; the scanner app computes the voice-print and posts the SHA-256 hash.',
            'hash_field' => 'voice_hash',
            'qual_field' => 'voice_quality',
            'input_placeholder' => 'Paste MFCC voice-print or SHA-256 hash…',
        ),
        array(
            'method'  => 'webauthn',
            'icon'    => '🔑',
            'label'   => 'WebAuthn / FIDO2',
            'std'     => 'FIDO2 · W3C WebAuthn Level 2',
            'desc'    => 'Register a FIDO2 device credential. Uses the browser\'s built-in biometric: Touch ID (Mac), Face ID (iPhone), Windows Hello, fingerprint sensor (Android), or a hardware YubiKey. The private key never leaves the device.',
            'hash_field' => 'webauthn_credential_id',
            'qual_field' => null,
            'input_placeholder' => 'credential_id|public_key (or use the Register button below)',
        ),
    );
    foreach ( $modalities as $mod ) :
        $is_enrolled = $bio && ! empty( $bio->{$mod['hash_field']} );
        $quality_val = ( $mod['qual_field'] && $bio ) ? (int) $bio->{$mod['qual_field']} : 0;
    ?>
    <div class="etelid-bio-panel <?php echo $is_enrolled ? 'enrolled' : ''; ?>" id="panel-<?php echo $mod['method']; ?>">
        <div class="ebp-header">
            <span class="ebp-icon"><?php echo $mod['icon']; ?></span>
            <div class="ebp-titles">
                <span class="ebp-title"><?php echo esc_html( $mod['label'] ); ?></span>
                <span class="ebp-std"><?php echo esc_html( $mod['std'] ); ?></span>
            </div>
            <div class="ebp-badges">
                <?php if ( $is_enrolled ) : ?>
                <span class="ebp-badge enrolled">✓ Enrolled</span>
                <?php if ( $quality_val ) : ?>
                <span class="ebp-badge quality">Quality <?php echo $quality_val; ?>%</span>
                <?php endif; ?>
                <?php if ( $mod['method'] === 'nfc' && $bio ) : ?>
                <span class="ebp-badge mono-uid"><?php echo esc_html( $bio->nfc_uid ); ?></span>
                <?php endif; ?>
                <?php else : ?>
                <span class="ebp-badge not-enrolled">Not enrolled</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="ebp-body">
            <p class="ebp-desc"><?php echo esc_html( $mod['desc'] ); ?></p>

            <?php if ( $mod['method'] === 'webauthn' ) : ?>
            <!-- WebAuthn: show browser register button -->
            <button class="etelid-btn etelid-btn-gold" id="webauthn-register-btn" data-mid="<?php echo $m->id; ?>" data-name="<?php echo esc_attr( $m->full_name ); ?>" data-id="<?php echo esc_attr( $m->etel_id ); ?>">
                🔑 Register WebAuthn Device (Browser)
            </button>
            <div style="margin-top:10px;font-size:12px;color:#888;">Or paste credential manually:</div>
            <?php endif; ?>

            <div style="margin-top:<?php echo $mod['method'] === 'webauthn' ? '8' : '0'; ?>px;">
                <input type="text"
                    id="bio-val-<?php echo $mod['method']; ?>"
                    class="etelid-bio-input mono"
                    placeholder="<?php echo esc_attr( $mod['input_placeholder'] ); ?>"
                    value="<?php echo ( $mod['method'] === 'nfc' && $bio ) ? esc_attr( $bio->nfc_uid ) : ''; ?>">
            </div>

            <?php if ( $mod['qual_field'] ) : ?>
            <div style="margin-top:8px;display:flex;align-items:center;gap:10px;">
                <label style="font-size:11px;font-family:'JetBrains Mono',monospace;color:#888;text-transform:uppercase;letter-spacing:1px;">Quality Score</label>
                <input type="number" id="bio-qual-<?php echo $mod['method']; ?>" class="etelid-bio-qual"
                    min="0" max="100" value="<?php echo $quality_val ?: 80; ?>"
                    style="width:70px;background:#111;border:1px solid #3A3228;color:#E8D9B5;padding:5px 8px;border-radius:4px;font-family:'JetBrains Mono',monospace;">
                <span style="font-size:11px;color:#666;">/ 100</span>
            </div>
            <?php endif; ?>

            <div style="margin-top:12px;display:flex;align-items:center;gap:12px;">
                <button class="etelid-btn etelid-btn-gold etelid-bio-enroll-btn"
                    data-method="<?php echo $mod['method']; ?>"
                    data-mid="<?php echo $m->id; ?>">
                    <?php echo $is_enrolled ? '🔄 Re-enroll ' . esc_html( $mod['label'] ) : '+ Enroll ' . esc_html( $mod['label'] ); ?>
                </button>
                <span class="ebp-msg" id="bio-msg-<?php echo $mod['method']; ?>"></span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Physical biometrics panel -->
    <div class="etelid-bio-panel">
        <div class="ebp-header">
            <span class="ebp-icon">📋</span>
            <div class="ebp-titles">
                <span class="ebp-title">Physical Biometrics</span>
                <span class="ebp-std">Height · Weight · Eye Color · Hair · Distinguishing Marks</span>
            </div>
        </div>
        <div class="ebp-body">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
                <div><label class="etelid-bio-label">Height (cm)</label><input type="number" id="phys-height" class="etelid-bio-input" value="<?php echo $bio ? (int)$bio->height_cm : ''; ?>" placeholder="170"></div>
                <div><label class="etelid-bio-label">Weight (kg)</label><input type="number" id="phys-weight" class="etelid-bio-input" value="<?php echo $bio ? (int)$bio->weight_kg : ''; ?>" placeholder="65"></div>
                <div><label class="etelid-bio-label">Eye Color</label><input type="text" id="phys-eye" class="etelid-bio-input" value="<?php echo $bio ? esc_attr($bio->eye_color) : ''; ?>" placeholder="Brown"></div>
                <div><label class="etelid-bio-label">Hair Color</label><input type="text" id="phys-hair" class="etelid-bio-input" value="<?php echo $bio ? esc_attr($bio->hair_color) : ''; ?>" placeholder="Black"></div>
                <div><label class="etelid-bio-label">Skin Tone</label><input type="text" id="phys-skin" class="etelid-bio-input" value="<?php echo $bio ? esc_attr($bio->skin_tone) : ''; ?>" placeholder="Medium"></div>
            </div>
            <div style="margin-top:10px;">
                <label class="etelid-bio-label">Distinguishing Marks</label>
                <textarea id="phys-marks" class="etelid-bio-input" rows="2" placeholder="Scars, tattoos, birthmarks…" style="width:100%;"><?php echo $bio ? esc_textarea($bio->distinguishing_marks) : ''; ?></textarea>
            </div>
            <button class="etelid-btn etelid-btn-gold etelid-bio-enroll-btn" style="margin-top:10px;" data-method="physical" data-mid="<?php echo $m->id; ?>">
                💾 Save Physical Biometrics
            </button>
            <span class="ebp-msg" id="bio-msg-physical"></span>
        </div>
    </div>

    <!-- Recent check-ins for this member -->
    <?php
    $recent_logs = EtelID_DB::get_bio_log( $m->id, 10 );
    if ( $recent_logs ) :
    ?>
    <div class="etelid-bio-panel">
        <div class="ebp-header"><span class="ebp-icon">📋</span><span class="ebp-title">Recent Check-ins</span></div>
        <table class="etelid-table" style="margin-top:0;">
            <thead><tr><th>Method</th><th>Result</th><th>Confidence</th><th>Location</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ( $recent_logs as $cl ) :
                $info = EtelID_Biometric::method_info( $cl->method );
            ?>
            <tr>
                <td><?php echo $info['icon']; ?> <?php echo esc_html( $info['label'] ); ?></td>
                <td><span class="status-pill status-<?php echo $cl->result === 'success' ? 'active' : 'suspended'; ?>"><?php echo esc_html( $cl->result ); ?></span></td>
                <td><?php echo (int)$cl->confidence; ?>%</td>
                <td><?php echo esc_html( $cl->location ); ?></td>
                <td style="font-family:'JetBrains Mono',monospace;font-size:11px;"><?php echo esc_html( $cl->created_at ); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    </div><!-- /bio-panels -->
    <?php else : ?>
    <div class="etelid-card" style="display:flex;align-items:center;justify-content:center;min-height:340px;">
        <div style="text-align:center;color:#666;">
            <div style="font-size:48px;margin-bottom:12px;">🔏</div>
            <p>Select a member on the left to manage their biometric enrollment.</p>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /bio-layout -->
</div>
