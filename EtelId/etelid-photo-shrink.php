<?php
/**
 * EtelID - profile photo shrink page (admin only).
 *
 * Deploy as: wp-content/plugins/etel-id-v3/etelid-photo-shrink.php
 * Open:      https://ethelmarket.com/EtelId/wp-content/plugins/etel-id-v3/etelid-photo-shrink.php
 * (sign in to the EtelID portal as an admin first, in the same browser)
 *
 * DELETE THIS FILE from the server when the work is finished.
 *
 * Steps (each safe to repeat):
 *   1. Preview - shows what each oversized profile photo would shrink to. Writes nothing.
 *   2. Shrink  - resizes each oversized profile photo (long side 480 px, JPEG). This is
 *                lossy and cannot be undone from here, so it needs a fresh backup.
 *
 * Pure ASCII, PHP 5.6 compatible, no external resources.
 */
error_reporting( 0 );
@ini_set( 'display_errors', '0' );

/* ---- find and load WordPress (nearest wp-load.php above this file) ---- */
$etel_wp = false;
$etel_dir = dirname( __FILE__ );
for ( $i = 0; $i < 7; $i++ ) {
    if ( file_exists( $etel_dir . '/wp-load.php' ) ) { $etel_wp = $etel_dir . '/wp-load.php'; break; }
    $up = dirname( $etel_dir );
    if ( $up === $etel_dir ) break;
    $etel_dir = $up;
}
if ( ! $etel_wp ) { http_response_code( 500 ); echo 'WordPress not found.'; exit; }
require_once $etel_wp;

header( 'Cache-Control: no-store' );
header( 'X-Frame-Options: DENY' );
header( 'X-Content-Type-Options: nosniff' );

if ( ! class_exists( 'EtelID_Auth' ) || ! class_exists( 'EtelID_Photos' ) || ! class_exists( 'EtelID_DB' ) ) {
    http_response_code( 500 );
    echo 'EtelID files are not all uploaded (class-etelid-photos.php and class-etelid-db.php are required).';
    exit;
}

$etel_member = EtelID_Auth::get_current_member();
if ( ! $etel_member || ! EtelID_Auth::is_site_admin( $etel_member ) ) {
    http_response_code( 403 );
    echo '<!doctype html><meta charset="utf-8"><title>Admin sign-in required</title>'
       . '<body style="font-family:sans-serif;padding:24px">Sign in to the EtelID portal as an admin in this browser, then reload this page.</body>';
    exit;
}

/* ---- JSON actions ---- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    header( 'Content-Type: application/json; charset=utf-8' );
    $nonce = isset( $_POST['nonce'] ) ? (string) $_POST['nonce'] : '';
    if ( ! wp_verify_nonce( $nonce, 'etelid_photo_shrink' ) ) {
        echo json_encode( array( 'ok' => false, 'error' => 'Page expired - reload it.' ) ); exit;
    }
    @set_time_limit( 120 );
    $act   = isset( $_POST['act'] ) ? (string) $_POST['act'] : '';
    $after = isset( $_POST['after'] ) ? (int) $_POST['after'] : 0;
    if ( $act === 'status' ) {
        echo json_encode( array( 'ok' => true, 'status' => EtelID_Photos::status() ) ); exit;
    }
    if ( $act === 'preview' ) {
        echo json_encode( array( 'ok' => true, 'r' => EtelID_Photos::shrink_batch( 5, $after, false ) ) ); exit;
    }
    if ( $act === 'shrink' ) {
        $confirm = isset( $_POST['confirm'] ) ? trim( (string) $_POST['confirm'] ) : '';
        if ( $confirm !== 'I HAVE A BACKUP' ) {
            echo json_encode( array( 'ok' => false, 'error' => 'Type I HAVE A BACKUP to confirm.' ) ); exit;
        }
        echo json_encode( array( 'ok' => true, 'r' => EtelID_Photos::shrink_batch( 3, $after, true ) ) ); exit;
    }
    echo json_encode( array( 'ok' => false, 'error' => 'Unknown action.' ) ); exit;
}

$etel_nonce = wp_create_nonce( 'etelid_photo_shrink' );
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>EtelID profile photo shrink</title>
<style>
body{font-family:system-ui,Arial,sans-serif;background:#0d0d0d;color:#eee;margin:0;padding:16px}
.wrap{max-width:720px;margin:0 auto}
h1{font-size:20px;color:#D4AF37;margin:0 0 4px}
.card{background:#161208;border:1px solid rgba(212,175,55,.35);border-radius:10px;padding:14px;margin:12px 0}
button{background:#D4AF37;color:#111;border:0;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer;margin:4px 6px 4px 0}
button:disabled{opacity:.4;cursor:default}
button.warn{background:#c0392b;color:#fff}
input[type=text]{padding:9px;border-radius:6px;border:1px solid #555;background:#111;color:#eee;width:100%;box-sizing:border-box;margin:6px 0}
pre{background:#000;border-radius:8px;padding:10px;max-height:240px;overflow:auto;font-size:12px;white-space:pre-wrap}
td{padding:3px 12px 3px 0;font-size:14px}.v{color:#F2CC6B;font-weight:700}
.muted{color:#aaa;font-size:13px}
</style></head><body><div class="wrap">
<h1>EtelID profile photo shrink</h1>
<div class="muted">Resizes oversized profile photos so pages load fast. Delete this file when finished.</div>

<div class="card"><table id="st"><tr><td>Loading status...</td></tr></table>
<button id="bRefresh">Refresh status</button></div>

<div class="card"><b>1. Preview</b>
<div class="muted">Shows the before and after size of each oversized photo. Writes nothing.</div>
<button id="bPreview">Preview</button></div>

<div class="card"><b>2. Shrink the photos</b>
<div class="muted">Lossy and permanent. Take a fresh full database backup first.</div>
<input type="text" id="confirm" placeholder="Type: I HAVE A BACKUP" autocomplete="off">
<button class="warn" id="bShrink">Shrink photos</button></div>

<pre id="log"></pre>
</div>
<script>
var NONCE = <?php echo json_encode( $etel_nonce ); ?>;
var logEl = document.getElementById('log');
function log(m){ logEl.textContent += m + "\n"; logEl.scrollTop = logEl.scrollHeight; }
function post(act, extra){
  var fd = new FormData(); fd.append('nonce', NONCE); fd.append('act', act);
  for (var k in (extra||{})) fd.append(k, extra[k]);
  return fetch(location.pathname, {method:'POST', body: fd, credentials:'same-origin'}).then(function(r){ return r.json(); });
}
function busy(on){ ['bRefresh','bPreview','bShrink'].forEach(function(i){ document.getElementById(i).disabled = on; }); }
function showStatus(s){
  var rows = [['Members', s.members_total],['Oversized profile photos', s.oversized],['Size of oversized photos (MB)', s.oversized_mb],
    ['All profile photos (MB)', s.all_photos_mb],['Image library (GD) ready', s.gd_ready ? 'yes' : 'NO - ask the host to enable GD'],
    ['Photos are shrunk to (px, long side)', s.max_px]];
  document.getElementById('st').innerHTML = rows.map(function(r){ return '<tr><td>'+r[0]+'</td><td class="v">'+(r[1]===undefined?'-':r[1])+'</td></tr>'; }).join('');
}
function refresh(){ return post('status').then(function(d){ if(d.ok) showStatus(d.status); else log('Status: '+d.error); }); }
function loop(act, extra, label){
  busy(true); var after = 0, total = 0, saved = 0;
  function step(){
    var p = {after: after}; for (var k in (extra||{})) p[k] = extra[k];
    return post(act, p).then(function(d){
      if(!d.ok){ log(label+': '+d.error); return; }
      var r = d.r; total += r.processed; saved += r.saved_kb;
      (r.changed||[]).forEach(function(m){ log('  ' + m); });
      (r.skipped||[]).forEach(function(m){ log('  ! ' + m); });
      after = r.last_id;
      if (r.processed > 0) return step();
    });
  }
  return step().then(function(){ log(label+' finished: '+total+' photo(s), about '+saved+' KB saved.'); return refresh(); }).then(function(){ busy(false); }, function(e){ log('Error: '+e); busy(false); });
}
document.getElementById('bRefresh').onclick = refresh;
document.getElementById('bPreview').onclick = function(){ loop('preview', {}, 'Preview'); };
document.getElementById('bShrink').onclick = function(){
  var c = document.getElementById('confirm').value;
  if (!confirm('Shrink every oversized profile photo? This cannot be undone. Make sure the backup exists.')) return;
  loop('shrink', {confirm: c}, 'Shrink');
};
refresh();
</script></body></html>
