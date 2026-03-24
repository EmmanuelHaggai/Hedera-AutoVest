<?php
// card-topup-complete.php
//
// Verifies a Stripe PaymentIntent, then performs on-chain credit (HBAR/HKSH)
// and WhatsApp notification, similar to the M-Pesa top-up flow.

require __DIR__ . '/stripe/vendor/autoload.php';

// Core helpers (convertKesToHbar, custom_AutoVest_text_whatsapp, etc.)
require_once '/var/www/aws1/v2-functions.php';
require_once '/var/www/AutoVest.hedera.co.ke/api/callback/hedera_functions.php';

header('Content-Type: application/json');

// Load .env
$envPath = '/var/www/AutoVest.hedera.co.ke/.env';
if (!file_exists($envPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '.env not found']);
    exit;
}
$env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
if ($env === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'failed to parse .env']);
    exit;
}

$stripeSecretKey = $env['STRIPE_SECRET_KEY'] ?? '';
if (!$stripeSecretKey) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'STRIPE_SECRET_KEY missing in .env']);
    exit;
}

// Stripe config
\Stripe\Stripe::setApiKey($stripeSecretKey);

// DB config (env or process env)
$dbHost = getenv('DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost');
$dbUser = getenv('DB_USER') ?: ($env['DB_USER'] ?? 'root');
$dbPass = getenv('DB_PASS') ?: ($env['DB_PASS'] ?? '');
$dbName = getenv('DB_NAME') ?: ($env['DB_NAME'] ?? 'hedera_ai');

// FX + fee config
$usdToKesRate     = isset($env['USD_KES_RATE']) ? (float)$env['USD_KES_RATE'] : 130.0;
$hkshPerKes       = isset($env['HKSH_PER_KES']) ? (float)$env['HKSH_PER_KES'] : 1.0;
$stripeFeePercent = isset($env['STRIPE_FEE_PERCENT']) ? (float)$env['STRIPE_FEE_PERCENT'] : 2.9;
$stripeFeeFixed   = isset($env['STRIPE_FEE_FIXED_USD']) ? (float)$env['STRIPE_FEE_FIXED_USD'] : 0.30;

// Hedera API host
$envPort   = getenv('LOCAL_JS_PORT') ?: getenv('HEDERA_JS_PORT') ?: getenv('PORT') ?: '5050';
$hederaAPI = "http://127.0.0.1:{$envPort}";
$hkshToken = getenv('HKSH_TOKEN_ID') ?: '0.0.7162525';

// Parse JSON input
$inputJson = file_get_contents('php://input');
$input     = json_decode($inputJson, true) ?: [];

$refCode         = trim((string)($input['ref_code'] ?? ''));
$paymentIntentId = trim((string)($input['payment_intent_id'] ?? ''));

if ($refCode === '' || $paymentIntentId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing ref_code or payment_intent_id']);
    exit;
}

// Connect DB
$db = mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);
if (mysqli_connect_errno()) {
    error_log("DB connection failed: " . mysqli_connect_error());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
    exit;
}

// Lookup card top-up link
$refEsc = mysqli_real_escape_string($db, $refCode);
$sql    = "SELECT * FROM autovest_card_topup_links WHERE link_code='{$refEsc}' LIMIT 1";
$res    = mysqli_query($db, $sql);
$row    = $res ? mysqli_fetch_assoc($res) : null;

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Invalid or unknown reference']);
    mysqli_close($db);
    exit;
}

// Avoid double-credit
if (!in_array($row['status'], ['pending', 'processing'], true)) {
    echo json_encode([
        'ok'     => true,
        'note'   => 'Already processed or not in pending state',
        'status' => $row['status'],
    ]);
    mysqli_close($db);
    exit;
}

// Retrieve PaymentIntent from Stripe to verify payment
try {
    /** @var \Stripe\PaymentIntent $pi */
    $pi = \Stripe\PaymentIntent::retrieve($paymentIntentId);
} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Stripe error: ' . $e->getMessage()]);
    mysqli_close($db);
    exit;
}

// Basic validation
if ($pi->status !== 'succeeded') {
    echo json_encode(['ok' => false, 'error' => 'PaymentIntent not in succeeded state']);
    mysqli_close($db);
    exit;
}
if (strtolower($pi->currency) !== 'usd') {
    echo json_encode(['ok' => false, 'error' => 'Unexpected currency: ' . $pi->currency]);
    mysqli_close($db);
    exit;
}

// Cross-check ref in metadata if present
$metaRef = isset($pi->metadata['ref_code']) ? (string)$pi->metadata['ref_code'] : '';
if ($metaRef && $metaRef !== $refCode) {
    echo json_encode(['ok' => false, 'error' => 'Metadata ref_code mismatch']);
    mysqli_close($db);
    exit;
}

// Amount in USD (favor amount_received if present)
$amountUsd = null;
if (isset($pi->amount_received) && $pi->amount_received > 0) {
    $amountUsd = $pi->amount_received / 100.0;
} else {
    $amountUsd = $pi->amount / 100.0;
}

// Compute net USD after Stripe fees
$feeUsd = ($amountUsd * ($stripeFeePercent / 100.0)) + $stripeFeeFixed;
$netUsd = max($amountUsd - $feeUsd, 0.0);

// Convert to KES
$kesAmount = $netUsd * $usdToKesRate;

// Token choice from DB row
$tokenChoice = strtoupper($row['token_choice'] ?? 'HKSH');
$sendHBAR    = ($tokenChoice === 'HBAR');

// Find user Hedera account based on WhatsApp number
$waPlus    = $row['wa_id'];                         // stored as +254...
$waEsc     = mysqli_real_escape_string($db, $waPlus);
$walletQry = "SELECT account_id, hedera_private_key 
              FROM hksh_AutoVest_clients 
              WHERE whatsapp_phone='{$waEsc}' AND status='1' 
              LIMIT 1";
$walletRes = mysqli_query($db, $walletQry);
$walletRow = $walletRes ? mysqli_fetch_assoc($walletRes) : null;

$toAccountId = $walletRow['account_id'] ?? '';
$userPrivKey = $walletRow['hedera_private_key'] ?? '';

if (!$toAccountId) {
    $msg = "Card payment received, but your Hedera wallet is not ready yet. Please try again shortly.";
    custom_AutoVest_text_whatsapp($waPlus, $msg);
    mysqli_query($db, "INSERT INTO `AutoVest_prompt_history`(`wa_id`,`query`,`reply`) 
                       VALUES ('{$waEsc}','Card Top-Up','{$msg}')");

    mysqli_query(
        $db,
        "UPDATE autovest_card_topup_links 
         SET status='wallet_pending', payment_intent_id='" . mysqli_real_escape_string($db, $paymentIntentId) . "',
             updated_at=NOW()
         WHERE id=" . (int)$row['id']
    );

    echo json_encode(['ok' => false, 'error' => 'Wallet not ready']);
    mysqli_close($db);
    exit;
}

$memo = "Card top up via Hedera AutoVest";

// On-chain credit
$txOk       = false;
$txId       = null;
$credited   = 0.0;
$unit       = $sendHBAR ? 'HBAR' : 'HKSH';

// HBAR or HKSH path
if ($sendHBAR) {
    // Use same conversion logic as M-Pesa (KES -> HBAR with spread)
    $hbarAmt = convertKesToHbar((float)$kesAmount, 'buy', 150, 50, 500);

    $payload = json_encode([
        'toAccountId' => $toAccountId,
        'hbar'        => $hbarAmt,
        'memo'        => $memo,
    ]);

    $ch = curl_init("{$hederaAPI}/fund");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if (!$err && $resp) {
        $j = json_decode($resp, true);
        $txOk = !empty($j['ok']);
        $txId = $j['txId'] ?? null;
    }

    $credited = $hbarAmt;

    $disp = rtrim(rtrim(number_format($hbarAmt, 6, '.', ''), '0'), '.');
    $msg = $txOk && $txId
        ? "✅ Card top up successful.\n\nCredited: {$disp} HBAR\nHashScan: https://hashscan.io/testnet/transaction/" . urlencode($txId)
        : "Card payment received, but HBAR transfer could not be confirmed. Ref: {$refCode}.";

    custom_AutoVest_text_whatsapp($waPlus, $msg);
    mysqli_query(
        $db,
        "INSERT INTO `AutoVest_prompt_history`(`wa_id`,`query`,`reply`) 
         VALUES ('{$waEsc}','Card Top-Up HBAR','{$msg}')"
    );

} else {
    // HKSH via token transfer
    if (empty($userPrivKey)) {
        $msg = "Card payment received, but your wallet needs a quick security update before receiving HKSH. Ref: {$refCode}.";
        custom_AutoVest_text_whatsapp($waPlus, $msg);
        mysqli_query(
            $db,
            "INSERT INTO `AutoVest_prompt_history`(`wa_id`,`query`,`reply`) 
             VALUES ('{$waEsc}','Card Top-Up HKSH','{$msg}')"
        );

        mysqli_query(
            $db,
            "UPDATE autovest_card_topup_links 
             SET status='wallet_pending', payment_intent_id='" . mysqli_real_escape_string($db, $paymentIntentId) . "',
                 updated_at=NOW()
             WHERE id=" . (int)$row['id']
        );

        echo json_encode(['ok' => false, 'error' => 'Wallet needs upgrade for HKSH']);
        mysqli_close($db);
        exit;
    }

    // Associate HKSH token if needed
    $assocPayload = json_encode([
        'accountId' => $toAccountId,
        'privKey'   => $userPrivKey,
        'tokenId'   => $hkshToken,
    ]);
    $h = curl_init("{$hederaAPI}/tokens/associate");
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $assocPayload,
    ]);
    $assocResp = curl_exec($h);
    curl_close($h);
    // You can inspect $assocResp if you want

    // Amount of HKSH (1 HKSH = 1 KES, like your M-Pesa logic)
    $tokenAmount = (int)round($kesAmount);

    $tPayload = json_encode([
        'tokenId'     => $hkshToken,
        'toAccountId' => $toAccountId,
        'amount'      => $tokenAmount * 100, // assuming 2 decimals
        'memo'        => $memo,
    ]);

    $t = curl_init("{$hederaAPI}/tokens/transfer");
    curl_setopt_array($t, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $tPayload,
    ]);
    $tResp = curl_exec($t);
    $tErr  = curl_error($t);
    curl_close($t);

    if (!$tErr && $tResp) {
        $j = json_decode($tResp, true);
        $txOk = !empty($j['ok']);
        $txId = $j['txId'] ?? null;
    }

    $credited = (float)$tokenAmount;

    $msg = $txOk && $txId
        ? "✅ Card top up successful.\n\nCredited: {$tokenAmount} HKSH\nHashScan: https://hashscan.io/testnet/transaction/" . urlencode($txId)
        : "Card payment received, but HKSH transfer could not be confirmed. Ref: {$refCode}.";

    custom_AutoVest_text_whatsapp($waPlus, $msg);
    mysqli_query(
        $db,
        "INSERT INTO `AutoVest_prompt_history`(`wa_id`,`query`,`reply`) 
         VALUES ('{$waEsc}','Card Top-Up HKSH','{$msg}')"
    );
}

// Update link status and store some metadata
$status = $txOk ? 'paid' : 'paid_onchain_failed';

$updSql = sprintf(
    "UPDATE autovest_card_topup_links 
     SET status='%s',
         payment_intent_id='%s',
         updated_at=NOW()
     WHERE id=%d",
    mysqli_real_escape_string($db, $status),
    mysqli_real_escape_string($db, $paymentIntentId),
    (int)$row['id']
);
mysqli_query($db, $updSql);

mysqli_close($db);

echo json_encode([
    'ok'           => $txOk,
    'status'       => $status,
    'token_choice' => $tokenChoice,
    'credited'     => $credited,
    'unit'         => $unit,
    'txId'         => $txId,
]);
