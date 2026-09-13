<?php
/**
 * ETEL Group  Telegram Bot Webhook (Group-wide)
 * Serves: ETEL Delivery  ETEL Ride  E Pay  EtelID
 *
 * One bot (@EtelDeliveryRideBot) for the entire Etel Group.
 * Writes tg_chat_id to Etelid_etelid_members (etelid DB) so all
 * apps can send free OTPs via Telegram using the EtelID phone as key.
 *
 * Setup:
 *   1. api/.env: TG_BOT_TOKEN=... TG_BOT_NAME=EtelDeliveryRideBot TG_WEBHOOK_SECRET=<random-string>
 *   2. Register webhook once, WITH the secret token so Telegram signs every request:
 *      https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://ethelmarket.com/delivery/api/telegram_webhook.php&secret_token=<same-random-string>
 *   3. Every incoming request is rejected unless it carries the matching
 *      X-Telegram-Bot-Api-Secret-Token header - this is the only thing that
 *      stops anyone who finds this URL from forging /start +251<phone> updates
 *      and hijacking OTP delivery for that phone number.
 *
 * User flow:
 *   /start +251XXXXXXXXX   links phone to chat_id in EtelID
 *   /unlink                removes link
 *   /status                shows current link status
 */

error_reporting(0); ini_set('display_errors','0');
http_response_code(200);
header('Content-Type: application/json');

/*  Load .env  */
$_ep_env = array();
$_env    = __DIR__ . '/.env';
if (file_exists($_env)) {
    foreach (@file($_env, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
        $line = ltrim($line, "\xEF\xBB\xBF"); $t = ltrim($line);
        if ($t===''||$t[0]==='#'||strpos($t,'=')=== false) continue;
        list($k,$v) = explode('=',$t,2); $k=trim($k); $v=trim($v);
        if (strlen($v)>=2&&(($v[0]==='"'&&$v[-1]==='"')||($v[0]==="'"&&$v[-1]==="'"))) $v=substr($v,1,-1);
        if ($k!==''&&preg_match('/^[A-Z][A-Z0-9_]*$/',$k)) $_ep_env[$k]=$v;
    }
}
function tg_env($k,$d=''){global $_ep_env;if(isset($_ep_env[$k])&&$_ep_env[$k]!=='')return $_ep_env[$k];$g=getenv($k);if($g!==false&&$g!=='')return $g;return $d;}

$TG_TOKEN = tg_env('TG_BOT_TOKEN');
$TG_NAME  = tg_env('TG_BOT_NAME','EtelDeliveryRideBot');
if (!$TG_TOKEN) { echo '{"ok":true}'; exit; }

/*  Require Telegram's secret-token header on every request.
    Without this, anyone who discovers this URL can POST forged updates
    (e.g. "/start +251<victim phone>") and hijack that phone's OTP delivery. */
$TG_WEBHOOK_SECRET = tg_env('TG_WEBHOOK_SECRET');
$hdr_secret = isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '';
if ($TG_WEBHOOK_SECRET === '' || !hash_equals($TG_WEBHOOK_SECRET, $hdr_secret)) {
    http_response_code(401);
    echo '{"ok":false}';
    exit;
}

/*  Delivery DB (has users table)  */
function del_db() {
    static $p=null; if($p) return $p;
    try {
        $p=new PDO('mysql:host='.tg_env('DB_HOST','localhost').';dbname='.tg_env('DB_NAME').';charset=utf8mb4',
            tg_env('DB_USER'),tg_env('DB_PASS'),
            array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
    } catch(Exception $e){return null;}
    return $p;
}

/*  EtelID DB (has etelid_members table)  */
function eid_db() {
    static $p=null; if($p) return $p;
    /* Parse EtelID wp-config.php to get credentials */
    $cfg_paths = array(
        dirname(dirname(dirname(__DIR__))) . '/EtelId/wp-config.php',
        $_SERVER['DOCUMENT_ROOT'] . '/EtelId/wp-config.php',
    );
    foreach ($cfg_paths as $cfg) {
        if (!@is_readable($cfg)) continue;
        $src = file_get_contents($cfg);
        $name=$user=$pass=$host=null; $pfx='Etelid_';
        if (preg_match("/define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]*)['\"]/", $src, $m)) $name=$m[1];
        if (preg_match("/define\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]*)['\"]/", $src, $m)) $user=$m[1];
        if (preg_match("/define\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]*)['\"]/", $src, $m)) $pass=$m[1];
        if (preg_match("/define\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]*)['\"]/", $src, $m)) $host=$m[1];
        if (preg_match("/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]/", $src, $m)) $pfx=$m[1];
        if (!$name||!$user) continue;
        try {
            $p=new PDO("mysql:host=".($host?:'localhost').";dbname=$name;charset=utf8mb4",$user,$pass?:'',
                array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
            $p->_pfx = $pfx; /* store prefix */
            return $p;
        } catch(Exception $e){ continue; }
    }
    return null;
}

/*  Send Telegram message  */
function tg_send($chat_id, $text) {
    global $TG_TOKEN;
    $ch=curl_init('https://api.telegram.org/bot'.$TG_TOKEN.'/sendMessage');
    curl_setopt_array($ch,array(
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode(array('chat_id'=>$chat_id,'text'=>$text,'parse_mode'=>'HTML')),
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8,
        CURLOPT_HTTPHEADER=>array('Content-Type: application/json'),
    ));
    curl_exec($ch); curl_close($ch);
}

/*  Normalise Ethiopian phone  */
function normalise_phone($raw) {
    $d = preg_replace('/[^\d]/','',$raw);
    if (strlen($d)===9 && $d[0]==='9') return '+251'.$d;
    if (strlen($d)===10 && $d[0]==='0') return '+251'.substr($d,1);
    if (strlen($d)===12 && substr($d,0,3)==='251') return '+'.$d;
    if (strlen($d)===13 && $d[0]==='+') return $raw;
    return '+'.ltrim($raw,'+');
}

/*  Process incoming update  */
$raw    = file_get_contents('php://input');
$update = $raw ? @json_decode($raw,true) : null;
if (!$update || !isset($update['message'])) { echo '{"ok":true}'; exit; }

$msg      = $update['message'];
$chat_id  = (string)$msg['chat']['id'];
$text     = isset($msg['text']) ? trim($msg['text']) : '';
$from     = $msg['from'];
$tg_user  = isset($from['username']) ? $from['username'] : '';
$fname    = isset($from['first_name']) ? $from['first_name'] : 'there';

/*  /start [phone]  */
if (strpos($text,'/start')===0) {
    $parts = preg_split('/\s+/',$text,2);
    $raw_phone = isset($parts[1]) ? trim($parts[1]) : '';
    $phone = $raw_phone ? normalise_phone($raw_phone) : '';

    if (!$phone) {
        tg_send($chat_id,
            " <b>! Welcome to ETEL Group Bot</b>\n\n".
            " Link your phone to receive free OTPs for:\n".
            "   ETEL Delivery   ETEL Ride\n".
            "   E Pay   EtelID Portal\n\n".
            "Send your registered phone:\n".
            "<code>/start +251XXXXXXXXX</code>\n\n".
            "    Hawassa ");
        echo '{"ok":true}'; exit;
    }

    /* Check in Delivery users (linked via EtelID) */
    $del = del_db();
    $eid = eid_db();
    $member_name = $fname;
    $found_delivery = false;
    $found_etelid   = false;

    /* Check EtelID first - it's the master identity */
    if ($eid) {
        $pfx = isset($eid->_pfx) ? $eid->_pfx : 'Etelid_';
        $tbl = $pfx . 'etelid_members';
        /* PHONE-FORMAT FIX: normalise_phone() above always converts
         * whatever the user types into international format
         * (+251XXXXXXXXX), but real records in etelid_members are
         * stored in local format (0XXXXXXXXX) - confirmed directly
         * against a live export, every member's phone starts with a
         * bare "0", none has a country code. The old comparison only
         * stripped +/space/- and never reconciled the two different
         * formats, so a completely correct phone number typed by a
         * real member could never match its own stored record - every
         * /start <phone> came back "not found", independent of the
         * webhook-secret issue fixed just before this. Comparing on
         * the last 9 digits instead is format-agnostic: it's the one
         * substring both "0966734664" and "+251966734664" share, and
         * it's exactly the significant part of any Ethiopian mobile
         * number regardless of which prefix convention was used when
         * it was entered. */
        $s = $eid->prepare("SELECT id,full_name,etel_id,status FROM `$tbl`
            WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''), 9)
                = RIGHT(REPLACE(REPLACE(REPLACE(?  ,'+',''),' ',''),'-',''), 9) LIMIT 1");
        $s->execute(array($phone));
        $eid_row = $s->fetch();
        if ($eid_row) {
            $found_etelid = true;
            $member_name  = $eid_row['full_name'] ?: $fname;
            if ($eid_row['status'] === 'suspended') {
                tg_send($chat_id," This EtelID is <b>suspended</b>. Contact security@etelgroup.com.");
                echo '{"ok":true}'; exit;
            }
            /* Write tg_chat_id to etelid_members */
            $eid->prepare("UPDATE `$tbl` SET tg_chat_id=?, tg_username=? WHERE id=?")
                ->execute(array($chat_id, $tg_user, $eid_row['id']));
        }
    }

    /* Also check delivery users table */
    if ($del) {
        $s = $del->prepare("SELECT id,name FROM users
            WHERE RIGHT(REPLACE(REPLACE(phone,'+',''),' ',''), 9)
                = RIGHT(REPLACE(REPLACE(?   ,'+',''),' ',''), 9) LIMIT 1");
        $s->execute(array($phone));
        $del_row = $s->fetch();
        if ($del_row) {
            $found_delivery = true;
            if (!$found_etelid) $member_name = $del_row['name'] ?: $fname;
            /* Write to tg_chat_ids table as fallback */
            $del->exec("CREATE TABLE IF NOT EXISTS `tg_chat_ids` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `phone` VARCHAR(30) NOT NULL,
                `chat_id` VARCHAR(30) NOT NULL,
                `username` VARCHAR(100) NOT NULL DEFAULT '',
                `linked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), UNIQUE KEY `uq_phone` (`phone`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $del->prepare("INSERT INTO tg_chat_ids (phone,chat_id,username) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE chat_id=VALUES(chat_id),username=VALUES(username)")
                ->execute(array($phone,$chat_id,$tg_user));
        }
    }

    /* Also write to Etel Pay if configured */
    $ep_dsn = tg_env('EP_DB_NAME','');
    if ($ep_dsn) {
        try {
            $ep = new PDO('mysql:host='.tg_env('DB_HOST','localhost').';dbname='.$ep_dsn.';charset=utf8mb4',
                tg_env('DB_USER'),tg_env('DB_PASS'),
                array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
            $pfx = tg_env('EP_TBL_PREFIX','Etelpay_');
            $ep->prepare("UPDATE {$pfx}ep_users SET tg_chat_id=? WHERE phone=?")
               ->execute(array($chat_id,$phone));
        } catch(Exception $e){ /* Pay DB not accessible from here  OK */ }
    }

    if (!$found_etelid && !$found_delivery) {
        tg_send($chat_id,
            " Phone <b>".htmlspecialchars($phone)."</b> not found in ETEL Group.\n\n".
            "Register at:\n".
            " <a href='https://ethelmarket.com/delivery/login'>ETEL Delivery</a>\n".
            " <a href='https://ethelmarket.com/EtelId'>Get an EtelID</a>\n".
            " <a href='https://ethelmarket.com/Etel.Pay/wallet'>E Pay</a>");
        echo '{"ok":true}'; exit;
    }

    $apps = array();
    if ($found_etelid)   $apps[] = ' EtelID';
    if ($found_delivery) $apps[] = ' ETEL Delivery   ETEL Ride';
    $apps[] = ' E Pay';

    tg_send($chat_id,
        " <b>! Account linked, ".htmlspecialchars($member_name)."!</b>\n\n".
        " Phone: <code>".htmlspecialchars($phone)."</code>\n\n".
        " You'll receive free OTPs here for:\n  ".implode("\n  ",$apps)."\n\n".
        "No more SMS costs  Telegram delivers instantly.\n".
        "    ethelmarket.com ");
    echo '{"ok":true}'; exit;
}

/*  /unlink  */
if ($text==='/unlink'||$text==='/stop') {
    $del = del_db(); $eid = eid_db(); $unlinked = false;
    if ($eid) {
        $pfx = isset($eid->_pfx)?$eid->_pfx:'Etelid_';
        $n = $eid->prepare("UPDATE `{$pfx}etelid_members` SET tg_chat_id='',tg_username='' WHERE tg_chat_id=?");
        $n->execute(array($chat_id));
        if ($n->rowCount()>0) $unlinked=true;
    }
    if ($del) {
        try { $del->prepare("DELETE FROM tg_chat_ids WHERE chat_id=?")->execute(array($chat_id)); $unlinked=true; }
        catch(Exception $e){}
    }
    tg_send($chat_id, $unlinked
        ? " Your phone has been unlinked from all ETEL Group OTP notifications."
        : " No linked account found for this Telegram.");
    echo '{"ok":true}'; exit;
}

/*  /status  */
if ($text==='/status') {
    $eid = eid_db(); $linked_phone=''; $linked_name='';
    if ($eid) {
        $pfx = isset($eid->_pfx)?$eid->_pfx:'Etelid_';
        $s = $eid->prepare("SELECT phone,full_name,etel_id FROM `{$pfx}etelid_members` WHERE tg_chat_id=? LIMIT 1");
        $s->execute(array($chat_id));
        $r = $s->fetch();
        if ($r) { $linked_phone=$r['phone']; $linked_name=$r['full_name']; }
    }
    if ($linked_phone) {
        tg_send($chat_id,
            " <b>Linked Account</b>\n".
            " ".$linked_name."\n".
            " ".$linked_phone."\n\n".
            "Send /unlink to remove.");
    } else {
        tg_send($chat_id," No account linked. Send <code>/start +251XXXXXXXXX</code> to link.");
    }
    echo '{"ok":true}'; exit;
}

/*  Default help  */
tg_send($chat_id,
    " <b>ETEL Group OTP Bot</b>  @".$TG_NAME."\n\n".
    "/start +251XXXXXXXXX  link your phone\n".
    "/status  check linked account\n".
    "/unlink  remove link\n\n".
    "Free OTP delivery for all Etel Group apps.\n".
    "    ethelmarket.com");

echo '{"ok":true}';
