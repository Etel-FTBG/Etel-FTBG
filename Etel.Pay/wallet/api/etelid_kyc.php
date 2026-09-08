<?php
/**
 * EtelPay — EtelID KYC Bridge v1.0
 * PHP 5.6+ / cPanel — pure curl, zero WP dependency
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
function etelid_authenticate($phone,$pin){ return etelid_call('service/authenticate',array('identifier'=>$phone,'password'=>$pin)); }

function ep_sync_etelid_kyc($uid,$phone){
    $r=etelid_resolve($phone);
    if(!$r['ok']) return array('success'=>false,'message'=>'EtelID unreachable: '.$r['error']);
    $m=isset($r['data']['member'])?$r['data']['member']:null;
    if(!$m) return array(
        'success'=>false,
        'message'=>'No EtelID for '.$phone.'. Register at ethelmarket.com/EtelId',
        'register_url'=>'https://ethelmarket.com/EtelId/register',
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
    $msgs=array('verified'=>'KYC complete via EtelID '.$fin.' (Level '.$lv.')','pending'=>'EtelID found — document verification pending','none'=>'EtelID found but not yet verified');
    return array('success'=>true,'kyc_status'=>$kyc,'etelid_fin'=>$fin,'kyc_level'=>$lv,
        'full_name'=>$name,'photo_url'=>$photo,'message'=>$msgs[$kyc],
        'portal_url'=>'https://ethelmarket.com/EtelId/portal');
}
