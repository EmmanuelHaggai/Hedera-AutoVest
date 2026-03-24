<?php
//   $msg = "AutoVest Subscription request received";
//   $wa_id = "254715586044";
//   custom_AutoVest_text_whatsapp('+' . $wa_id, $msg);
//   die();

// Switch db connection
mysqli_close($db);

// Load environment variables from AWS kms
require_once '/var/www/AutoVest.hedera.co.ke/bootstrap_secrets.php';

try {
    $DEBUG = filter_var(getenv('DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);

    if ($DEBUG) {
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);
    }

    $AWS_REGION = getenv('AWS_REGION') ?: 'eu-west-1';
    $AWS_SECRET_ID = getenv('AWS_SECRET_ID') ?: 'prod/autovest/app';

    $env = loadAwsSecrets($AWS_SECRET_ID, $AWS_REGION);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'bootstrap_failed']);
    error_log($e->getMessage());
    exit;
}

// Retrieve database credentials from environment
$dbHost = env('DB_HOST') ?: 'localhost';
$dbUser = env('DB_USER') ?: 'root';
$dbPass = env('DB_PASS') ?: '';
$dbName = env('DB_NAME') ?: 'hedera_ai';

// Connect to the database
$db = $con = mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);

// Error handling
if (mysqli_connect_errno()) {
    error_log("Database connection failed: " . mysqli_connect_error());
    http_response_code(500);
    die(json_encode(['ok' => false, 'error' => 'Database connection failed']));
}


//------------------------------------------------------------------------------------

/**
 * autovest_airtime_callback2.php
 *
 * Handles button replies like:
 *  - pay_hbar_50          (50 = airtime_request.id)
 *  - pay_hksh_50
 *  - cancel_airtime2_50
 *
 * airtime_request schema:
 *   id, wa_id, amount, airtime_phone, airtime_user, status, code, notes, date_time
 *
 * Assumes the following are already available in the parent script:
 *  - $button_id       (e.g. "pay_hbar_50")
 *  - $user_wa_id      (e.g. "254707039040")
 *  - $db              (mysqli connection)
 *  - functions: custom_AutoVest_text_whatsapp(), buy_airtime(),
 *               convertKesToHbar(), getHederaBalances(),
 *               normalize_msisdn_ke(), hashscan_base_from_network()
 */

if (!isset($button_id) || !$button_id) {
    return;
}

if (!isset($db) || !($db instanceof mysqli)) {
    if (isset($user_wa_id)) {
        custom_AutoVest_text_whatsapp('+'.$user_wa_id, "Service unavailable. Please try again later.");
    }
    return;
}

// Small helpers, reused from other scripts if not defined
if (!function_exists('envv')) {
    function envv($k, $def=''){ $v=getenv($k); return $v!==false?$v:$def; }
}

if (!function_exists('http_post_json')) {
    function http_post_json($url, array $payload): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode($payload),
            CURLOPT_TIMEOUT=>45
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err || !$resp) return ['ok'=>false,'error'=>$err?:'no_response'];
        $j = json_decode($resp, true);
        return is_array($j) ? $j : ['ok'=>false,'error'=>'bad_json','raw'=>$resp];
    }
}

if (!function_exists('remove_plus')) {
    function remove_plus(string $phone): string {
        return str_replace('+', '', $phone);
    }
}

/**
 * Ensure the phone is a valid Kenyan MSISDN.
 * Returns normalized `2547XXXXXXXX` or `false` if invalid.
 */
if (!function_exists('normalize_ke_airtime_phone')) {
    function normalize_ke_airtime_phone(string $raw) {
        // If you already have normalize_msisdn_ke globally, use it first:
        if (function_exists('normalize_msisdn_ke')) {
            $p = normalize_msisdn_ke($raw);
        } else {
            $p = preg_replace('/\D+/', '', $raw);
            if (strpos($p,'254') === 0) {
                // already starts with 254
            } elseif (strpos($p,'0') === 0) {
                $p = '254'.substr($p,1);
            }
        }

        // Strip any plus signs, just in case
        $p = remove_plus($p);

        // Basic Kenya airtime constraint: 254 + 9 digits = 12 digits total
        if (strlen($p) !== 12) {
            return false;
        }
        if (substr($p,0,3) !== '254') {
            return false;
        }

        return $p;
    }
}

/**
 * Check if there is a recent airtime request for the same phone+amount
 * within the last $windowMinutes minutes, excluding the current request id.
 *
 * Returns the existing row (array) if duplicate, or null if safe to proceed.
 */
if (!function_exists('find_recent_duplicate_airtime')) {
    function find_recent_duplicate_airtime(mysqli $db, string $phone, int $amountKES, int $current_id, int $windowMinutes = 5) {
        $phone         = trim($phone);
        $amountKES     = (int)$amountKES;
        $windowMinutes = max(1, (int)$windowMinutes);

        // Consider duplicate-risk if:
        //  - same phone
        //  - same amount
        //  - created within last X minutes
        //  - status in (0 = pending, 1 = completed)
        //  - not the same id
        $sql = "
            SELECT id, wa_id, amount, airtime_phone, status, date_time
            FROM airtime_request
            WHERE airtime_phone = ?
              AND amount = ?
              AND id <> ?
              AND status IN (0,1)
              AND date_time >= (NOW() - INTERVAL {$windowMinutes} MINUTE)
            ORDER BY id DESC
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log('find_recent_duplicate_airtime: prepare failed: '.$db->error);
            return null;
        }

        $stmt->bind_param("sii", $phone, $amountKES, $current_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}

$wa_id = $user_wa_id;
$whatsapp_reciever = '+'.$wa_id;

// Parse button id: pay_hbar_50 → function="pay_hbar", reference_id=50
$parts = explode('_', $button_id);

if (count($parts) < 3) {
    // Not our format
    return;
}

$function     = implode('_', array_slice($parts, 0, -1)); // "pay_hbar", "pay_hksh", "cancel_airtime2"
$reference_id = (int) end($parts);                        // airtime_request.id

if ($reference_id <= 0) {
    custom_AutoVest_text_whatsapp($whatsapp_reciever, "Invalid airtime reference. Please start again.");
    return;
}

// Load Hedera client row for this WhatsApp user
$stmt = $db->prepare("
    SELECT whatsapp_phone, account_id, hedera_private_key, hedera_public_key, hedera_network, original_phone
    FROM hksh_AutoVest_clients
    WHERE whatsapp_phone = ? AND status = '1'
    LIMIT 1
");
$stmt->bind_param("s", $whatsapp_reciever);
$stmt->execute();
$res    = $stmt->get_result();
$client = $res->fetch_assoc();
$stmt->close();

if (!$client) {
    custom_AutoVest_text_whatsapp($whatsapp_reciever, "We could not locate your Hedera wallet. Say 'start' to set it up.");
    return;
}

$accountId   = $client['account_id'];
$privKey     = $client['hedera_private_key'];
$userNetwork = $client['hedera_network'] ?: envv('HEDERA_NETWORK', 'testnet');
$scanBase    = hashscan_base_from_network($userNetwork);

// --------------------------------------------------
// Load the airtime_request by ID (this holds the real amount + phone)
// --------------------------------------------------
$stmt = $db->prepare("
    SELECT id, wa_id, amount, airtime_phone, airtime_user, status, code, notes
    FROM airtime_request
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $reference_id);
$stmt->execute();
$res     = $stmt->get_result();
$request = $res->fetch_assoc();
$stmt->close();

if (!$request) {
    custom_AutoVest_text_whatsapp(
        $whatsapp_reciever,
        "We could not find that airtime request. It may have expired or been removed. Please start a new airtime purchase."
    );
    return;
}

$airtime_id     = (int)$request['id'];
$request_wa_id  = $request['wa_id'];
$amountKES      = (int)$request['amount'];
$airtime_phone  = $request['airtime_phone'];
$airtime_user   = $request['airtime_user'];
$status         = (int)$request['status']; // 0 = pending, 1 = completed, 2 = cancelled, 3 = failed/other

if ($amountKES <= 0) {
    custom_AutoVest_text_whatsapp(
        $whatsapp_reciever,
        "This airtime request has an invalid amount. Please start a new airtime purchase."
    );
    return;
}

// Optional: ensure this airtime_request belongs to the same wa_id
if (!empty($request_wa_id) && $request_wa_id !== $whatsapp_reciever) {
    custom_AutoVest_text_whatsapp(
        $whatsapp_reciever,
        "This airtime request does not belong to your account. Please start a new airtime purchase."
    );
    return;
}

// Normalize and validate Kenyan airtime phone
$raw_phone = $airtime_phone ?: $airtime_user ?: $client['whatsapp_phone'] ?: $wa_id;
$msisdn    = normalize_ke_airtime_phone($raw_phone);

if ($msisdn === false) {
    custom_AutoVest_text_whatsapp(
        $whatsapp_reciever,
        "Airtime is only available for Kenyan phone numbers (starting with 254 and 12 digits long). Please update your number and try again."
    );
    return;
}

// Env for Node API and tokens
$API   = rtrim(envv('HEDERA_API_BASE','http://127.0.0.1:5050'),'/');
$TREAS = envv('TREASURY_ACCOUNT_ID');
$TOPIC = envv('HCS_TOPIC_ID');
$HKSH  = envv('HKSH_TOKEN_ID');

// Fetch live balances first (HBAR + HKSH)
$hksh_token_id = $HKSH ?: envv('HKSH_TOKEN_ID', '0.0.XXXXXXX');

if (!function_exists('getHederaBalances')) {
    custom_AutoVest_text_whatsapp($whatsapp_reciever, "Balance checker is unavailable. Please try again later.");
    return;
}

$balances = getHederaBalances($accountId, $userNetwork, $hksh_token_id);

if (!($balances['ok'] ?? false)) {
    $fb = $balances['feedback'] ?? "I could not fetch your wallet balance right now. Please try again later.";
    custom_AutoVest_text_whatsapp($whatsapp_reciever, $fb);
    return;
}

$user_hbar_balance = (float)($balances['hbar'] ?? 0.0);
$user_hksh_balance = (float)($balances['hksh'] ?? 0.0);

$txId     = null;
$explorer = null;

// --------------------------------------------------
// Handle different functions
// --------------------------------------------------

// First handle cancellation (it does not need balance checks)
if ($function === 'cancel_airtime2') {

    if ($status === 1) {
        $response_message = "❌ This airtime request for KES {$amountKES} to +{$msisdn} has already been completed.";
    } elseif ($status === 2) {
        $response_message = "⚠️ This airtime request for KES {$amountKES} to +{$msisdn} was already cancelled earlier.";
    } elseif ($status === 0) {
        $stmt = $db->prepare("UPDATE airtime_request SET status = 2, notes='cancelled via airtime2 button' WHERE id = ?");
        $stmt->bind_param("i", $airtime_id);
        $stmt->execute();
        $stmt->close();

        $response_message = "✅ Your airtime request for KES {$amountKES} to +{$msisdn} has been cancelled. No amount has been deducted.";
    } else {
        $response_message = "⚠️ This airtime request is no longer pending. If you have questions, please contact support.";
    }

    custom_AutoVest_text_whatsapp($whatsapp_reciever, $response_message);

    // Log cancel action
    $ph = $db->prepare("INSERT INTO AutoVest_prompt_history(wa_id,query,reply) VALUES (?,?,?)");
    $q  = "Airtime2: Cancel #{$airtime_id}";
    $ph->bind_param("sss", $whatsapp_reciever, $q, $response_message);
    $ph->execute();
    $ph->close();

    return;
}

// For payment functions, block if already completed/cancelled/failed
if ($status === 1) {
    $msg = "This airtime request for KES {$amountKES} to +{$msisdn} has already been completed. "
         . "If you did not receive the airtime, please contact support.";
    custom_AutoVest_text_whatsapp($whatsapp_reciever, $msg);
    return;
} elseif ($status === 2) {
    $msg = "This airtime request for KES {$amountKES} to +{$msisdn} was already cancelled. "
         . "Start a new airtime purchase if you want to try again.";
    custom_AutoVest_text_whatsapp($whatsapp_reciever, $msg);
    return;
} elseif ($status === 3) {
    $msg = "This airtime request is marked as failed. Please start a new airtime purchase.";
    custom_AutoVest_text_whatsapp($whatsapp_reciever, $msg);
    return;
}

// At this point, status should be 0 (pending)

// --------------------------------------------------
// HBAR payment
// --------------------------------------------------
if ($function === 'pay_hbar') {

    // Block recent duplicate same phone+amount before touching balances or Statum
    $dup = find_recent_duplicate_airtime($db, $msisdn, $amountKES, $airtime_id, 5);
    if ($dup) {
        $msgdup = "You recently bought KES {$amountKES} airtime for +{$msisdn}. "
            . "For your safety, similar top-ups to the same number may be blocked for a few minutes.\n\n"
            . "Please wait a bit or change the amount, then try again.";

        custom_AutoVest_text_whatsapp($whatsapp_reciever, $msgdup);

        $u = $db->prepare("UPDATE airtime_request SET status = 3, code = 7, notes = 'blocked as recent duplicate (airtime2)' WHERE id = ?");
        $u->bind_param("i", $airtime_id);
        $u->execute();
        $u->close();

        return;
    }

    // Compute HBAR needed for this KES amount
    $neededHBAR = convertKesToHbar($amountKES, 'buy');
    $neededHBAR = round((float)$neededHBAR, 8); // clamp

    // Validate HBAR balance
    if ($user_hbar_balance + 1e-8 < $neededHBAR) {
        $needed_fmt = number_format($neededHBAR, 3);
        $have_fmt   = number_format($user_hbar_balance, 3);
        $msgfail    = "Insufficient HBAR balance for this airtime purchase.\n"
            . "You need about {$needed_fmt} HBAR but your wallet has about {$have_fmt} HBAR.\n\n"
            . "Send /bal to see your full wallet summary or top up first.";
        custom_AutoVest_text_whatsapp($whatsapp_reciever, $msgfail);

        $u=$db->prepare("UPDATE airtime_request SET status=3, code=4, notes='insufficient HBAR (airtime2)' WHERE id=?");
        $u->bind_param("i",$airtime_id); $u->execute();
        $u->close();
        return;
    }

    // Move HBAR from user → treasury (user-signed via Node)
    $resp = http_post_json("$API/transfer", [
        "fromPrivKey"   => $privKey,
        "fromAccountId" => $accountId,
        "toAccountId"   => $TREAS,
        "hbar"          => $neededHBAR,
        "memo"          => "Airtime2 purchase KES $amountKES (#{$airtime_id})"
    ]);

    if (!($resp['ok'] ?? false)) {
        $msgfail = "Payment failed to submit. Please try again.";
        custom_AutoVest_text_whatsapp($whatsapp_reciever, $msgfail);
        $u=$db->prepare("UPDATE airtime_request SET status=3, code=3, notes=? WHERE id=?");
        $u->bind_param("si",$msgfail,$airtime_id); $u->execute();
        $u->close();
        return;
    }

    $txId     = $resp['txId'] ?? null;
    $explorer = $txId ? "$scanBase/transaction/$txId" : null;

    // Fulfil airtime; on provider failure refund
    if (buy_airtime($msisdn, (string)$amountKES)) {
        $ok = "📲 Airtime sent successfully!\n\n"
            . "💰 Spent ~{$neededHBAR} HBAR for KES {$amountKES} to +{$msisdn}."
            . ($explorer ? "\n\n🔗 {$explorer}" : "");
        custom_AutoVest_text_whatsapp($whatsapp_reciever, $ok);

        $u=$db->prepare("UPDATE airtime_request SET status=1, notes='completed via HBAR (airtime2)' WHERE id=?");
        $u->bind_param("i",$airtime_id); $u->execute();
        $u->close();

        // Optional HCS receipt
        if ($TOPIC) {
            http_post_json("$API/topics/message", [
                "topicId" => $TOPIC,
                "message" => json_encode([
                    "type"          => "airtime_receipt",
                    "method"        => "HBAR",
                    "network"       => $userNetwork,
                    "wa_id"         => $whatsapp_reciever,
                    "accountId"     => $accountId,
                    "amount_kes"    => $amountKES,
                    "amount_hbar"   => $neededHBAR,
                    "phone"         => "+".$msisdn,
                    "airtime_req_id"=> $airtime_id,
                    "txId"          => $txId,
                    "ts"            => date('c')
                ])
            ]);
        }

        // History log
        $ph = $db->prepare("INSERT INTO AutoVest_prompt_history(wa_id,query,reply) VALUES (?,?,?)");
        $q  = "Airtime2: Pay HBAR (req #{$airtime_id}, KES {$amountKES})";
        $ph->bind_param("sss", $whatsapp_reciever, $q, $ok);
        $ph->execute();
        $ph->close();

    } else {
        // Refund from treasury → user
        http_post_json("$API/transfer", [
            "toAccountId" => $accountId,
            "hbar"        => $neededHBAR,
            "memo"        => "Refund: failed airtime2 KES $amountKES (#{$airtime_id})"
        ]);

        $fail = "Airtime provider failed. Your HBAR was refunded.";
        custom_AutoVest_text_whatsapp($whatsapp_reciever, $fail);

        $u=$db->prepare("UPDATE airtime_request SET status=3, code=2, notes='provider error, refunded HBAR (airtime2)' WHERE id=?");
        $u->bind_param("i",$airtime_id); $u->execute();
        $u->close();
        return;
    }

    return;
}

// --------------------------------------------------
// HKSH payment
// --------------------------------------------------
if ($function === 'pay_hksh') {

    if (!$HKSH) {
        $msgfail = "HKSH token not configured. Try again later.";
        custom_AutoVest_text_whatsapp($whatsapp_reciever, $msgfail);
        $u=$db->prepare("UPDATE airtime_request SET status=3, code=5, notes=? WHERE id=?");
        $u->bind_param("si",$msgfail,$airtime_id); $u->execute();
        $u->close();
        return;
    }

    // Block recent duplicate same phone+amount before touching HKSH or Statum
    $dup = find_recent_duplicate_airtime($db, $msisdn, $amountKES, $airtime_id, 5);
    if ($dup) {
        $msgdup = "You recently bought KES {$amountKES} airtime for +{$msisdn}. "
            . "For your safety, similar top-ups to the same number may be blocked for a few minutes.\n\n"
            . "Please wait a bit or change the amount, then try again.";

        custom_AutoVest_text_whatsapp($whatsapp_reciever, $msgdup);

        $u = $db->prepare("UPDATE airtime_request SET status = 3, code = 7, notes = 'blocked as recent duplicate (airtime2)' WHERE id = ?");
        $u->bind_param("i", $airtime_id);
        $u->execute();
        $u->close();

        return;
    }

    // Validate HKSH balance (1 HKSH = 1 KES)
    if ($user_hksh_balance + 1e-8 < $amountKES) {
        $need_hksh = number_format($amountKES, 2);
        $have_hksh = number_format($user_hksh_balance, 2);
        $msgfail   = "Insufficient HKSH balance for this airtime purchase.\n"
            . "You need about {$need_hksh} HKSH but your wallet has about {$have_hksh} HKSH.\n\n"
            . "Send /bal to see your full wallet summary or top up first.";
        custom_AutoVest_text_whatsapp($whatsapp_reciever, $msgfail);

        $u=$db->prepare("UPDATE airtime_request SET status=3, code=4, notes='insufficient HKSH (airtime2)' WHERE id=?");
        $u->bind_param("i",$airtime_id); $u->execute();
        $u->close();
        return;
    }

    // 2 decimals → multiply by 100, cast to int
    $hksh_units = (int) round($amountKES * 100);

    // Move HKSH user → treasury (signed by user via Node)
    $resp = http_post_json("$API/tokens/transfer-user", [
        "tokenId"       => $HKSH,
        "fromAccountId" => $accountId,
        "fromPrivKey"   => $privKey,
        "toAccountId"   => $TREAS,
        "amount"        => $hksh_units,
        "memo"          => "Airtime2 purchase KES $amountKES (#{$airtime_id})"
    ]);

    if (!($resp['ok'] ?? false)) {
        $msgfail = "Payment failed to submit. Please try again.";
        custom_AutoVest_text_whatsapp($whatsapp_reciever, $msgfail);
        $u=$db->prepare("UPDATE airtime_request SET status=3, code=3, notes='HKSH submit failed (airtime2)' WHERE id=?");
        $u->bind_param("i",$airtime_id); $u->execute();
        $u->close();
        return;
    }

    $txId     = $resp['txId'] ?? null;
    $explorer = $txId ? "$scanBase/transaction/$txId" : null;

    // Fulfil airtime; on provider failure refund from treasury
    if (buy_airtime($msisdn, (string)$amountKES)) {
        $ok = "📲 Airtime sent successfully!\n\n"
            . "💰 Spent {$amountKES} HKSH for KES {$amountKES} to +{$msisdn}."
            . ($explorer ? "\n\n🔗 {$explorer}" : "");
        custom_AutoVest_text_whatsapp($whatsapp_reciever, $ok);

        $u=$db->prepare("UPDATE airtime_request SET status=1, notes='completed via HKSH (airtime2)' WHERE id=?");
        $u->bind_param("i",$airtime_id); $u->execute();
        $u->close();

        // Optional HCS receipt
        if ($TOPIC) {
            http_post_json("$API/topics/message", [
                "topicId" => $TOPIC,
                "message" => json_encode([
                    "type"          => "airtime_receipt",
                    "method"        => "HKSH",
                    "network"       => $userNetwork,
                    "wa_id"         => $whatsapp_reciever,
                    "accountId"     => $accountId,
                    "amount_kes"    => $amountKES,
                    "amount_hksh"   => (float)$amountKES,
                    "phone"         => "+".$msisdn,
                    "airtime_req_id"=> $airtime_id,
                    "txId"          => $txId,
                    "ts"            => date('c')
                ])
            ]);
        }

        // History
        $ph = $db->prepare("INSERT INTO AutoVest_prompt_history(wa_id,query,reply) VALUES (?,?,?)");
        $q  = "Airtime2: Pay HKSH (req #{$airtime_id}, KES {$amountKES})";
        $ph->bind_param("sss", $whatsapp_reciever, $q, $ok);
        $ph->execute();
        $ph->close();

    } else {
        // refund from treasury
        http_post_json("$API/tokens/transfer", [
            "tokenId"     => $HKSH,
            "toAccountId" => $accountId,
            "amount"      => $hksh_units,
            "memo"        => "Refund: failed airtime2 KES $amountKES (#{$airtime_id})"
        ]);

        $fail = "Airtime provider failed. Your HKSH was refunded.";
        custom_AutoVest_text_whatsapp($whatsapp_reciever, $fail);

        $u=$db->prepare("UPDATE airtime_request SET status=3, code=2, notes='provider error, refunded HKSH (airtime2)' WHERE id=?");
        $u->bind_param("i",$airtime_id); $u->execute();
        $u->close();
        return;
    }

    return;
}

// Not an airtime2 function we handle
return;


//---------------------------------------------------------------------------------------
// Switch db connection
mysqli_close($db);