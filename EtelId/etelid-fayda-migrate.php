<?php
/**
 * EtelID - Fayda ID photo migration page (admin only).
 *
 * Deploy as: wp-content/plugins/etel-id-v3/etelid-fayda-migrate.php
 * Open:      https://ethelmarket.com/EtelId/wp-content/plugins/etel-id-v3/etelid-fayda-migrate.php
 * (sign in to the EtelID portal as an admin first, in the same browser)
 *
 * DELETE THIS FILE from the server when the migration is finished.
 *
 * Steps (each safe to repeat):
 *   1. Copy   - writes every database photo to a private file. Nothing is removed.
 *   2. Verify - re-reads every file and compares it with its SHA-256 and the database copy.
 *   3. Clean  - removes the database copy, only where verify passes AND you confirm a backup.
 *   4. Stop   - new uploads stop keeping a database copy.
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

if ( ! class_exists( 'EtelID_Auth' ) || ! class_exists( 'EtelID_Files' ) || ! class_exists( 'EtelID_DB' ) ) {
    http_response_code( 500 );
    echo 'EtelID files are not all uploaded (class-etelid-files.php and class-etelid-db.php are required).';
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
    if ( ! wp_verify_nonce( $nonce, 'etelid_fayda_migrate' ) ) {
        echo json_encode( array( 'ok' => false, 'error' => 'Page expired - reload it.' ) ); exit;
    }
    @set_time_limit( 120 );
    $act   = isset( $_POST['act'] ) ? (string) $_POST['act'] : '';
    $after = isset( $_POST['after'] ) ? (int) $_POST['after'] : 0;
    if ( $act === 'status' ) {
        echo json_encode( array( 'ok' => true, 'status' => EtelID_Files::status() ) ); exit;
    }
    if ( $act === 'copy' ) {
        echo json_encode( array( 'ok' => true, 'r' => EtelID_Files::migrate_batch( 3, $after ) ) ); exit;
    }
    if ( $act === 'verify' ) {
        echo json_encode( array( 'ok' => true, 'r' => EtelID_Files::verify_batch( 10, $after ) ) ); exit;
    }
    if ( $act === 'clean' ) {
        $confirm = isset( $_POST['confirm'] ) ? trim( (string) $_POST['confirm'] ) : '';
        if ( $confirm !== 'I HAVE A BACKUP' ) {
            echo json_encode( array( 'ok' => false, 'error' => 'Type I HAVE A BACKUP to confirm.' ) ); exit;
        }
        echo json_encode( array( 'ok' => true, 'r' => EtelID_Files::cleanup_batch( 5, $after ) ) ); exit;
    }
    if ( $act === 'stop' ) {
        $st = EtelID_Files::status();
        if ( ! empty( $st['to_copy'] ) || ! empty( $st['to_clean'] ) ) {
            echo json_encode( array( 'ok' => false, 'error' => 'Finish copy and clean first.' ) ); exit;
        }
        EtelID_Files::set_db_copy( false );
        echo json_encode( array( 'ok' => true, 'status' => EtelID_Files::status() ) ); exit;
    }
    echo json_encode( array( 'ok' => false, 'error' => 'Unknown action.' ) ); exit;
}

$etel_nonce = wp_create_nonce( 'etelid_fayda_migrate' );
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>EtelID ID-photo storage</title>
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
<h1>EtelID ID-photo storage</h1>
<div class="muted">Moves Fayda ID photos from the database into a private folder. Safe to repeat. Delete this file when finished.</div>

<div class="card"><table id="st"><tr><td>Loading status...</td></tr></table>
<button id="bRefresh">Refresh status</button></div>

<div class="card"><b>1. Copy photos to private files</b>
<div class="muted">Does not remove anything from the database.</div>
<button id="bCopy">Start copy</button></div>

<div class="card"><b>2. Verify every file</b>
<div class="muted">Read-only. Compares each file with its SHA-256 and with the database copy.</div>
<button id="bVerify">Verify files</button></div>

<div class="card"><b>3. Remove the database copies</b>
<div class="muted">Only after step 2 shows no problems AND you have a fresh full database backup (this cannot be undone from here).</div>
<input type="text" id="confirm" placeholder="Type: I HAVE A BACKUP" autocomplete="off">
<button class="warn" id="bClean">Remove database copies</button></div>

<div class="card"><b>4. Stop keeping new copies in the database</b>
<div class="muted">Only available when steps 1 and 3 are complete.</div>
<button id="bStop">Stop database copies</button></div>

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
function busy(on){ ['bRefresh','bCopy','bVerify','bClean','bStop'].forEach(function(i){ document.getElementById(i).disabled = on; }); }
function showStatus(s){
  var rows = [['Members', s.members_total],['Members with photos in database', s.with_db_photos],['Photos in database (MB)', s.db_photo_mb],
    ['Private folder ready', s.dir_ok ? 'yes' : 'NO'],['Schema upgraded', s.columns_ready ? 'yes' : 'NO - open the portal once'],
    ['Still to copy', s.to_copy],['Copied, database copy still present', s.to_clean],['Members with files', s.files_recorded],
    ['New uploads keep a database copy', s.db_copy_on ? 'yes' : 'no']];
  document.getElementById('st').innerHTML = rows.map(function(r){ return '<tr><td>'+r[0]+'</td><td class="v">'+(r[1]===undefined?'-':r[1])+'</td></tr>'; }).join('');
}
function refresh(){ return post('status').then(function(d){ if(d.ok) showStatus(d.status); else log('Status: '+d.error); }); }
function loop(act, extra, label){
  busy(true); var after = 0, total = 0, problems = [];
  function step(){
    var p = {after: after}; for (var k in (extra||{})) p[k] = extra[k];
    return post(act, p).then(function(d){
      if(!d.ok){ log(label+': '+d.error); return; }
      var r = d.r; total += r.processed;
      (r.failed||r.bad||r.skipped||[]).forEach(function(m){ problems.push(m); log('  ! '+m); });
      after = r.last_id;
      log(label+': '+total+' member(s) processed, last id '+after);
      if (r.processed > 0) return step();
    });
  }
  return step().then(function(){ log(label+' finished. Problems: '+problems.length); return refresh(); }).then(function(){ busy(false); }, function(e){ log('Error: '+e); busy(false); });
}
document.getElementById('bRefresh').onclick = refresh;
document.getElementById('bCopy').onclick = function(){ loop('copy', {}, 'Copy'); };
document.getElementById('bVerify').onclick = function(){ loop('verify', {}, 'Verify'); };
document.getElementById('bClean').onclick = function(){
  var c = document.getElementById('confirm').value;
  if (!confirm('Remove the database copies for every verified photo? Make sure the backup exists.')) return;
  loop('clean', {confirm: c}, 'Clean');
};
document.getElementById('bStop').onclick = function(){ busy(true); post('stop').then(function(d){ log(d.ok ? 'New uploads no longer keep a database copy.' : 'Stop: '+d.error); return refresh(); }).then(function(){ busy(false); }); };
refresh();
</script></body></html>
