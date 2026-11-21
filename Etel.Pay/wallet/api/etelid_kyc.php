<?php
/**
 * EtelPay - EtelID KYC Bridge v1.0
 * PHP 5.6+ / cPanel - pure curl, zero WP dependency
 * Place at: Etel.Pay/wallet/api/etelid_kyc.php
 *
 * Config (add to config.php):
 *   define('ETELID_SERVICE_URL','https://ethelmarket.com/EtelId/wp-json/etelid/v1');
 *   define('ETELID_SERVICE_KEY','your-shared-secret-min-32-chars');
 */
if (!defined('EP_DB_HOST')) exit;

function etelid_call($ep, $pl) {
    if (!defined('ETELID_SERVICE_URL')||!defined('ETELID_SERVICE_KEY'))
        return array('ok'=>false,'error'=>'EtelID not configured');
    $ch = curl_init(rtrim(ETELID_SERVICE_URL,'/').'/'.ltrim($ep,'/'));
    curl_setopt_array($ch,array(
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($pl),
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>12,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_HTTPHEADER=>array(
            'Content-Type: application/json',
            'X-Etel-Service-Key: '.ETELID_SERVICE_KEY,
            'X-Etel-App: EtelPay/2.0',
        ),
    ));
    $resp=curl_exec($ch); $err=curl_error($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) return array('ok'=>false,'error'=>$err);
    $d=json_decode($resp,true);
    return $d ? array('ok'=>true,'data'=>$d,'http'=>$code) : array('ok'=>false,'error'=>'Bad JSON HTTP '.$code);
}
function etelid_resolve($id,$type='phone'){ return etelid_call('service/resolve',array('identifier'=>$id,'type'=>$type)); }
/* Etel Code (member-chosen short code such as E1616): starts with a letter, so it is never a phone number. */
function ep_looks_like_code($s){ return (bool)preg_match('/^[Ee][A-Za-z0-9 _-]{3,14}$/', trim((string)$s)); }
/* Resolve an Etel Code to the EtelID member (active only, must have a phone). null when unknown. */
function ep_code_member($code){
    $r = etelid_resolve(trim((string)$code));
    if (empty($r['ok']) || empty($r['data']['member']) || !is_array($r['data']['member'])) return null;
    $m = $r['data']['member'];
    if (empty($m['phone'])) return null;
    if (isset($m['status']) && $m['status'] !== 'active') return null;
    return $m;
}
/* At most 20 code look-ups a minute per member, so the code list cannot be walked. File-based: no schema. */
function ep_resolve_throttled($uid){
    $f = rtrim(sys_get_temp_dir(), '/\\') . '/ep_resolve_' . (int)$uid . '.txt';
    $now = time(); $hits = array();
    $old = @file_get_contents($f);
    if ($old) { foreach (explode(',', $old) as $t) { if ((int)$t > $now - 60) $hits[] = (int)$t; } }
    if (count($hits) >= 20) return true;
    $hits[] = $now; @file_put_contents($f, implode(',', $hits), LOCK_EX);
    return false;
}
function etelid_authenticate($phone,$pin,$totp=''){
    $req = array('identifier'=>$phone,'password'=>$pin,'app'=>'pay');
    if ($totp !== '') $req['totp_code'] = $totp;
    return etelid_call('service/authenticate',$req);
}
/* The member's own choice "Etel Pay asks for my PIN, not the 6-digit code" (EtelID service/pay_2fa_mode).
   mode: status | skip | ask. skip needs the password AND a valid code, ask needs only the password; EtelID checks both.
   Returns array(http status, array to send back as JSON). A missing code or a wrong one is answered like the sign-in does
   (401 with requires_2fa) so the payment boxes can ask for it. */
function etelid_pay_code_mode($phone, $password, $mode, $totp = '') {
    $req = array('identifier' => $phone, 'password' => $password, 'mode' => $mode, 'app' => 'pay');
    if ($totp !== '') $req['totp_code'] = $totp;
    $r = etelid_call('service/pay_2fa_mode', $req);
    if (empty($r['ok'])) return array(502, array('success' => false, 'data' => array('message' => 'EtelID is not reachable. Try again.')));
    $d = is_array($r['data']) ? $r['data'] : array();
    if (!empty($d['ok'])) {
        return array(200, array('success' => true, 'data' => array('twofa_enabled' => !empty($d['twofa_enabled']), 'pay_skip' => !empty($d['pay_skip'])), 'message' => 'Saved'));
    }
    $code = isset($d['code']) ? (string)$d['code'] : '';
    $msg  = !empty($d['message']) ? (string)$d['message'] : 'Could not change this setting.';
    if ($code === 'rest_no_route' || (isset($r['http']) && (int)$r['http'] === 404 && $code === 'no_route')) {
        return array(404, array('success' => false, 'data' => array('message' => 'This option is not available yet. Please try again later.', 'code' => 'not_available')));
    }
    if ($code === 'requires_2fa' || $code === 'bad_2fa') {
        return array(401, array('success' => false, 'data' => array('message' => $msg, 'requires_2fa' => true, 'code' => $code)));
    }
    $http = isset($d['data']['status']) ? (int)$d['data']['status'] : 400;
    if ($http < 400 || $http > 599) $http = 400;
    return array($http, array('success' => false, 'data' => array('message' => $msg, 'code' => $code)));
}
/* True when EtelID refused only because it wants the two-step code (or the code was wrong). */
function etelid_needs_2fa($auth){
    if (!is_array($auth) || empty($auth['data']) || !is_array($auth['data'])) return false;
    $d = $auth['data'];
    return !empty($d['data']['requires_2fa']) || (isset($d['code']) && in_array($d['code'], array('requires_2fa','bad_2fa'), true));
}
/* Answer the page with a "ask for the code" reply and stop. */
function etelid_2fa_respond($auth){
    $msg = !empty($auth['data']['message']) ? $auth['data']['message'] : 'Enter your two-step code.';
    http_response_code(401);
    echo json_encode(array('success'=>false,'data'=>array('message'=>$msg,'requires_2fa'=>true),'v'=>EP_VER));
    exit;
}

function ep_sync_etelid_kyc($uid,$phone){
    $r=etelid_resolve($phone);
    if(!$r['ok']) return array('success'=>false,'message'=>'EtelID unreachable: '.$r['error']);
    $m=isset($r['data']['member'])?$r['data']['member']:null;
    if(!$m) return array(
        'success'=>false,
        'message'=>'No EtelID for '.$phone.'. Register at ethelmarket.com/EtelId',
        'register_url'=>'https://ethelmarket.com/EtelId/register',
    );
    // Limited (Ride-only) EtelID: simplified Quick Register, no photo / Fayda ID on file yet.
    if(!empty($m['profile_limited'])) return array(
        'success'=>false,
        'message'=>'Your Etel-ID is limited to Ride. Add your profile photo and both sides of your Fayda ID in the EtelID portal to unlock Etel Pay.',
        'portal_url'=>'https://ethelmarket.com/EtelId/portal',
    );
    $st=strtolower(isset($m['status'])?$m['status']:'');
    $lv=(int)(isset($m['kyc_level'])?$m['kyc_level']:0);
    $fin=isset($m['fin'])?$m['fin']:'';
    $name=isset($m['full_name'])?$m['full_name']:'';
    $photo=isset($m['photo_url'])?$m['photo_url']:'';
    $kyc= ($st==='active'&&$lv>=2)?'verified':($st==='active'&&$lv>=1?'pending':'none');
    try {
        $pdo=ep_db();
        // Add EtelID columns if missing
        try { $pdo->query('SELECT etelid_fin FROM '.EP_TBL_USERS.' LIMIT 0'); }
        catch(Exception $e){
            $pdo->exec('ALTER TABLE '.EP_TBL_USERS.'
                ADD COLUMN etelid_fin VARCHAR(30) DEFAULT NULL,
                ADD COLUMN etelid_kyc_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN etelid_synced_at DATETIME DEFAULT NULL');
        }
        $pdo->prepare('UPDATE '.EP_TBL_USERS.' SET
            kyc_status=?,etelid_fin=?,etelid_kyc_level=?,etelid_synced_at=NOW(),
            full_name=COALESCE(NULLIF(full_name,""),?),
            avatar_url=COALESCE(NULLIF(avatar_url,""),?),
            updated_at=NOW() WHERE id=?')
        ->execute(array($kyc,$fin,$lv,$name,$photo,$uid));
    } catch(Exception $ex){ return array('success'=>false,'message'=>$ex->getMessage()); }
    $msgs=array('verified'=>'KYC complete via EtelID '.$fin.' (Level '.$lv.')','pending'=>'EtelID found - document verification pending','none'=>'EtelID found but not yet verified');
    return array('success'=>true,'kyc_status'=>$kyc,'etelid_fin'=>$fin,'kyc_level'=>$lv,
        'full_name'=>$name,'photo_url'=>$photo,'message'=>$msgs[$kyc],
        'portal_url'=>'https://ethelmarket.com/EtelId/portal');
}
