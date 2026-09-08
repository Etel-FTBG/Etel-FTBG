<?php
/**
 * Admin view: QR Cache Backfill (v3.11.0)
 *
 * One-time tool to pre-warm the local QR cache (see class-etelid-qr.php)
 * for every member who existed before that caching fix shipped. Not
 * required for the fix itself to work - each member's cache also builds
 * automatically the next time their card is viewed - this just does all
 * of them up front in one sitting instead of waiting on that.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

$total = EtelID_DB::count_all();
$nonce = wp_create_nonce( 'etelid_admin_qr_pregen' );
?>
<div class="wrap etelid-wrap">
<h1 class="etelid-page-title">🔳 QR Cache Backfill</h1>

<div class="etelid-card" style="max-width:640px;padding:24px;">
    <p style="margin-top:0;">
        Member QR codes used to be fetched live from an external service
        (api.qrserver.com) on every single page view. As of this version,
        each member's QR is fetched once and cached locally instead -
        this tool builds that cache for every member who already existed
        before the fix, so the very first time someone views an older
        member's card, it's already fast and doesn't depend on the
        external service being reachable at that moment.
    </p>
    <p>
        <strong><?php echo (int) $total; ?></strong> members total.
        This runs in small batches to stay well under any PHP execution
        time limit - safe to leave running, and safe to close this page
        and come back later if you need to (just click Start again; it
        skips anything already cached).
    </p>

    <button type="button" id="qr-pregen-btn" class="button button-primary button-hero" onclick="qrPregenStart()">
        ▶ Start Backfill
    </button>

    <div id="qr-pregen-progress" style="display:none;margin-top:20px;">
        <div style="background:#e5e5e5;border-radius:6px;overflow:hidden;height:22px;">
            <div id="qr-pregen-bar" style="background:linear-gradient(135deg,#C8960C,#D4AF37);height:100%;width:0%;transition:width .3s ease;"></div>
        </div>
        <p id="qr-pregen-status" style="margin-top:10px;font-size:13px;color:#555;"></p>
    </div>

    <div id="qr-pregen-done" style="display:none;margin-top:16px;padding:12px 16px;background:#eafaf0;border:1px solid #b7e4c7;border-radius:8px;color:#1e5f3e;">
        ✅ Done - every member's QR code is now cached locally.
    </div>
    <div id="qr-pregen-error" style="display:none;margin-top:16px;padding:12px 16px;background:#fdecea;border:1px solid #f5b7b1;border-radius:8px;color:#943126;"></div>
</div>
</div>

<script>
var _qrPregenNonce = '<?php echo esc_js( $nonce ); ?>';
var _qrPregenAjax  = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
var _qrPregenTotal = <?php echo (int) $total; ?>;

function qrPregenStart() {
    document.getElementById('qr-pregen-btn').disabled = true;
    document.getElementById('qr-pregen-progress').style.display = 'block';
    document.getElementById('qr-pregen-error').style.display = 'none';
    qrPregenBatch(0);
}

function qrPregenBatch(offset) {
    var body = 'action=etelid_admin_pregenerate_qr&nonce=' + encodeURIComponent(_qrPregenNonce)
             + '&offset=' + encodeURIComponent(offset);
    fetch(_qrPregenAjax, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (!res.success) {
            var errBox = document.getElementById('qr-pregen-error');
            errBox.style.display = 'block';
            errBox.textContent = (res.data && res.data.message) ? res.data.message : 'Something went wrong. Please try again.';
            document.getElementById('qr-pregen-btn').disabled = false;
            return;
        }
        var d = res.data;
        var pct = _qrPregenTotal > 0 ? Math.min(100, Math.round((d.next_offset / _qrPregenTotal) * 100)) : 100;
        document.getElementById('qr-pregen-bar').style.width = pct + '%';
        document.getElementById('qr-pregen-status').textContent =
            'Processed ' + Math.min(d.next_offset, _qrPregenTotal) + ' of ' + _qrPregenTotal + ' members…';

        if (d.finished) {
            document.getElementById('qr-pregen-status').textContent =
                'Processed ' + _qrPregenTotal + ' of ' + _qrPregenTotal + ' members.';
            document.getElementById('qr-pregen-done').style.display = 'block';
            document.getElementById('qr-pregen-btn').disabled = false;
            document.getElementById('qr-pregen-btn').textContent = '✔ Backfill complete';
        } else {
            qrPregenBatch(d.next_offset);
        }
    })
    .catch(function () {
        var errBox = document.getElementById('qr-pregen-error');
        errBox.style.display = 'block';
        errBox.textContent = 'Network error. You can click Start again - already-cached members are skipped automatically.';
        document.getElementById('qr-pregen-btn').disabled = false;
    });
}
</script>
