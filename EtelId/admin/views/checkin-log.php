<?php
/**
 * Admin view: Biometric Check-in Log (v3.0.0)
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

$stats = EtelID_DB::bio_checkin_stats();
$logs  = EtelID_DB::get_bio_log( 0, 300 );
?>
<div class="wrap etelid-wrap">
<h1 class="etelid-page-title">📋 Biometric Check-in Log</h1>

<div class="etelid-stat-row" style="margin-bottom:20px;">
    <div class="etelid-stat"><div class="s-val"><?php echo $stats['today']; ?></div><div class="s-lbl">Today</div></div>
    <div class="etelid-stat"><div class="s-val"><?php echo $stats['week']; ?></div><div class="s-lbl">This Week</div></div>
    <div class="etelid-stat bio-fp"><div class="s-val"><?php echo $stats['success']; ?></div><div class="s-lbl">✅ Success</div></div>
    <div class="etelid-stat" style="border-left:3px solid #e74c3c;"><div class="s-val" style="color:#e74c3c;"><?php echo $stats['failed']; ?></div><div class="s-lbl">❌ Failed</div></div>
    <div class="etelid-stat bio-fp"><div class="s-val"><?php echo $stats['fingerprint']; ?></div><div class="s-lbl">🔏 Fingerprint</div></div>
    <div class="etelid-stat bio-face"><div class="s-val"><?php echo $stats['face']; ?></div><div class="s-lbl">👤 Face</div></div>
    <div class="etelid-stat bio-nfc"><div class="s-val"><?php echo $stats['nfc']; ?></div><div class="s-lbl">📡 NFC</div></div>
    <div class="etelid-stat bio-webauthn"><div class="s-val"><?php echo $stats['webauthn']; ?></div><div class="s-lbl">🔑 WebAuthn</div></div>
</div>

<?php if ( empty( $logs ) ) : ?>
<div class="etelid-card" style="text-align:center;padding:48px;color:#666;">
    <div style="font-size:40px;margin-bottom:12px;">📋</div>
    <p>No check-in events yet. Events appear here when members use NFC cards, fingerprint readers, or the WebAuthn terminal.</p>
</div>
<?php else : ?>
<table class="etelid-table">
    <thead>
        <tr>
            <th>Time</th>
            <th>Etel-ID</th>
            <th>Method</th>
            <th>Result</th>
            <th>Confidence</th>
            <th>Location</th>
            <th>Device</th>
            <th>IP</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ( $logs as $l ) :
        $info = EtelID_Biometric::method_info( $l->method );
        $result_cls = $l->result === 'success' ? 'status-active' : 'status-suspended';
    ?>
    <tr>
        <td style="font-family:'JetBrains Mono',monospace;font-size:11px;"><?php echo esc_html( $l->created_at ); ?></td>
        <td style="font-family:'JetBrains Mono',monospace;font-size:12px;color:#D4AF37;">
            <?php if ( $l->member_id ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=etel-id-biometric&member=' . $l->member_id ) ); ?>" style="color:#D4AF37;">
                <?php echo esc_html( $l->etel_id ); ?>
            </a>
            <?php else : ?>
            <?php echo esc_html( $l->etel_id ); ?>
            <?php endif; ?>
        </td>
        <td><?php echo $info['icon']; ?> <?php echo esc_html( $info['label'] ); ?></td>
        <td><span class="status-pill <?php echo $result_cls; ?>"><?php echo esc_html( $l->result ); ?></span></td>
        <td>
            <?php if ( $l->confidence ) : ?>
            <div style="display:flex;align-items:center;gap:6px;">
                <div style="width:50px;height:4px;background:#333;border-radius:2px;">
                    <div style="width:<?php echo (int)$l->confidence; ?>%;height:100%;background:<?php echo $l->result === 'success' ? '#2ecc71' : '#e74c3c'; ?>;border-radius:2px;"></div>
                </div>
                <span style="font-size:11px;font-family:'JetBrains Mono',monospace;"><?php echo (int)$l->confidence; ?>%</span>
            </div>
            <?php else : ?>
            <span style="color:#555;">—</span>
            <?php endif; ?>
        </td>
        <td><?php echo esc_html( $l->location ); ?></td>
        <td style="font-family:'JetBrains Mono',monospace;font-size:10px;color:#888;"><?php echo esc_html( $l->device_id ); ?></td>
        <td style="font-family:'JetBrains Mono',monospace;font-size:10px;color:#666;"><?php echo esc_html( $l->actor_ip ); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</div>
