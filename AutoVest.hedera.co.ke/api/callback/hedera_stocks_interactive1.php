<?php

/**
 * Process Buy Stocks interactive reply.
 *
 * Expects:
 *   $db      : mysqli connection
 *   $parts   : array like ["cancel","stocks","<client_order_id>"] or ["pay","hbar","<client_order_id>"]
 *
 * Env:
 *   HEDERA_API_BASE
 *   TREASURY_ACCOUNT_ID
 *   HKSH_TOKEN_ID
 *
 * Tables:
 *   stock_orders (from your schema)
 *   hksh_AutoVest_clients (whatsapp_phone, account_id, hedera_private_key, hedera_network, status)
 *   tokens (token_id, decimals, treasury_account_id)  // to resolve mint precision and treasury
 */


// Switch db connection
mysqli_close($db);
// Retrieve database credentials from environment
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbName = getenv('DB_NAME') ?: 'hedera_ai';

// Connect to the database
$db = $con = mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);

// Error handling
if (mysqli_connect_errno()) {
    error_log("Database connection failed: " . mysqli_connect_error());
    http_response_code(500);
    die(json_encode(['ok' => false, 'error' => 'Database connection failed']));
}

// //-----------debugging-----------------------------------------
// $message_from = "254715586044";
// $feedback = "Hello from Hedera AutoVest: ".json_encode($parts);
// $whatsapp_reciever = "+".$message_from;
// $whatsapp_feedback = custom_AutoVest_text_whatsapp($whatsapp_reciever,$feedback);

// $parts = ["cancel","stocks","32699ab0970eb7fa"];
// $parts = ["pay","hbar","32699ab0970eb7fa"];

// -------- entrypoint example --------
// $parts is provided by your interactive handler
process_stock_interactive($db, $parts);

function http_post_json(string $url, array $payload, int $timeout = 45): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => $timeout
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err || !$resp) return ['ok' => false, 'error' => $err ?: 'no_response'];
    $j = json_decode($resp, true);
    return is_array($j) ? $j : ['ok' => false, 'error' => 'bad_json', 'raw' => $resp];
}

function get_token_meta(mysqli $db, string $tokenId): ?array {
    $sql = "SELECT decimals, treasury_account_id FROM tokens WHERE token_id = ? LIMIT 1";
    $st  = $db->prepare($sql);
    $st->bind_param("s", $tokenId);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

function load_order(mysqli $db, string $client_order_id): ?array {
    $st = $db->prepare("SELECT * FROM stock_orders WHERE client_order_id = ? LIMIT 1");
    $st->bind_param("s", $client_order_id);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

function mark_order_status(mysqli $db, string $client_order_id, string $status, ?string $pay_source = null): void {
    if ($pay_source) {
        $st = $db->prepare("UPDATE stock_orders SET status = ?, pay_source = ?, updated_at = NOW() WHERE client_order_id = ? LIMIT 1");
        $st->bind_param("sss", $status, $pay_source, $client_order_id);
    } else {
        $st = $db->prepare("UPDATE stock_orders SET status = ?, updated_at = NOW() WHERE client_order_id = ? LIMIT 1");
        $st->bind_param("ss", $status, $client_order_id);
    }
    $st->execute();
    $st->close();
}

function load_client_by_wa(mysqli $db, string $wa): ?array {
    $st = $db->prepare("
        SELECT account_id, hedera_private_key, hedera_network
        FROM hksh_AutoVest_clients
        WHERE whatsapp_phone = ? AND status = '1'
        LIMIT 1
    ");
    $st->bind_param("s", $wa);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

/** Optional: your convertKesToHbar exists already; we’ll call it when needed. */
// function convertKesToHbar(float $kesAmount, string $side = 'buy', int $profit_bps = 150, int $buffer_bps = 50, ?int $cap_bps = 500): float { ... }

function process_stock_interactive(mysqli $db, array $parts): void {
    if (count($parts) < 3) return;

    $action = strtolower(trim($parts[0]));   // "cancel" | "pay"
    $second = strtolower(trim($parts[1]));   // "stocks" on cancel OR "hbar"/"hksh" on pay
    $id     = trim($parts[2]);               // client_order_id

    // 1) Load order
    $order = load_order($db, $id);
    if (!$order) {
        $wa_to = "+".$wa_id;
        $msg = "We couldn't find your order {$id}. It may have expired or been cancelled. Please request a new quote and try again.";
        if ($wa_to && function_exists('custom_AutoVest_text_whatsapp')) {
            custom_AutoVest_text_whatsapp($wa_to, $msg);
        }
        error_log("[stocks] Order not found: client_order_id={$id}");
        // Optional: record in history if you keep one
        if ($wa_to) {
            if ($ph = $db->prepare("INSERT INTO AutoVest_prompt_history(wa_id,query,reply) VALUES (?,?,?)")) {
                $q = "Stocks Interactive: order not found";
                $ph->bind_param("sss", $wa_to, $q, $msg);
                $ph->execute();
                $ph->close();
            }
        }
        return;
    }

    // 2) Stop if already in a terminal state
    if (in_array($order['status'], ['CANCELLED', 'COMPLETED', 'FAILED', 'EXPIRED', 'INSUFFICIENT_FUNDS'], true)) {
        $status = strtoupper($order['status']);
        $msg = match ($status) {
            'CANCELLED' => "❌ This order ({$id}) was already cancelled. No further action is required.",
            'COMPLETED' => "✅ This order ({$id}) has already been completed successfully.",
            'FAILED'    => "⚠️ This order ({$id}) previously failed. Please request a new quote to try again.",
            'EXPIRED'   => "⏰ This order ({$id}) has expired and can no longer be processed.",
            'INSUFFICIENT_FUNDS' => "💸 This order ({$id}) could not proceed because your wallet balance was too low.\nPlease top up your HBAR or HKSH and request a new quote.",
            default     => "ℹ️ This order ({$id}) is not active anymore.",
        };

        // Notify the user if we know their WhatsApp ID
        if (function_exists('custom_AutoVest_text_whatsapp')) {
            custom_AutoVest_text_whatsapp($order['wa_id'], $msg);
        }

        // Optional: record a small trace in history for analytics or debugging
        if ($ph = $db->prepare("INSERT INTO AutoVest_prompt_history(wa_id,query,reply) VALUES (?,?,?)")) {
            $q = "Stocks Interactive: terminal order status {$status}";
            $ph->bind_param("sss", $order['wa_id'], $q, $msg);
            $ph->execute();
            $ph->close();
        }

        // Log server-side for audit/debugging
        error_log("[stocks] Attempted action on terminal order {$id} (status={$status}) by {$order['wa_id']}");

        return;
    }

    // Expiry check
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $exp = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $order['quote_expires_at'], new DateTimeZone('UTC'));
    if ($exp instanceof DateTimeImmutable && $now > $exp) {
        mark_order_status($db, $id, 'EXPIRED');
        // optional: notify user
        if (function_exists('custom_AutoVest_text_whatsapp')) {
            custom_AutoVest_text_whatsapp($order['wa_id'], "Quote expired for order {$id}. Please request a new quote.");
        }
        return;
    }

    // 2) Handle cancel
    if ($action === 'cancel') {
        mark_order_status($db, $id, 'CANCELLED');
        if (function_exists('custom_AutoVest_text_whatsapp')) {
            custom_AutoVest_text_whatsapp($order['wa_id'], "Order {$id} cancelled.");
        }
        return;
    }


    // 3) Handle confirm pay
    if ($action !== 'pay') return;

    $method =  $order['pay_source']; //($second === 'hbar') ? 'HBAR' : (($second === 'hksh') ? 'HKSH' : strtoupper($second));

    // 3a) Load client wallet for debit and delivery
    $client = load_client_by_wa($db, $order['wa_id']);
    if (!$client) {
        mark_order_status($db, $id, 'FAILED');
        if (function_exists('custom_AutoVest_text_whatsapp')) {
            custom_AutoVest_text_whatsapp($order['wa_id'], "We couldn't find your Hedera wallet. Say 'start' to set it up.");
        }
        return;
    }
    $userAccountId = $client['account_id'];
    $userPrivKey   = $client['hedera_private_key'];
    $userNetwork   = strtolower((string)$client['hedera_network']) ?: 'testnet';

    // 3b) Resolve token meta for mint/transfer
    $stockTokenId = $order['token_id'];
    $meta = get_token_meta($db, $stockTokenId);

    if (!$meta || !isset($meta['decimals'])) {
        mark_order_status($db, $id, 'FAILED');
        if (function_exists('custom_AutoVest_text_whatsapp')) {
            custom_AutoVest_text_whatsapp($order['wa_id'], "Stock token metadata missing for {$stockTokenId}.");
        }
        return;
    }
    $decimals = (int)$meta['decimals'];
    $treasury = (string)($meta['treasury_account_id'] ?? getenv('TREASURY_ACCOUNT_ID') ?: '');

    if ($treasury === '') {
        mark_order_status($db, $id, 'FAILED');
        if (function_exists('custom_AutoVest_text_whatsapp')) {
            custom_AutoVest_text_whatsapp($order['wa_id'], "Treasury account not configured.");
        }
        return;
    }

    // 3c) Env and amounts
    $API      = rtrim(getenv('HEDERA_API_BASE') ?: 'http://127.0.0.1:5050', '/');
    $HKSH_ID  = getenv('HKSH_TOKEN_ID') ?: ''; // required for HKSH debits

    $shares   = (float)$order['shares'];
    $pxKes    = (float)$order['px_kes'];
    $totalKes = (float)$order['total_kes'];
    $totalHk  = (float)$order['total_hksh'];
    $totalHb  = (float)$order['total_hbar'];

    // Ensure total_hbar reference is available if paying HBAR
    if ($method === 'HBAR' && ($totalHb <= 0.0)) {
        try {
            $totalHb = convertKesToHbar($totalKes, 'buy');
        } catch (Throwable $e) {
            // Fallback to quoted rate directly
            try {
                $rate = getQuotedRateKesPerHbar('buy'); // KES per HBAR
                $totalHb = ($rate > 0) ? round($totalKes / $rate, 6) : 0.0;
            } catch (Throwable $e2) {
                $totalHb = 0.0;
            }
        }
    }

    // ------ Pre-debit balance checks using getHederaBalances() ------
    $balances = getHederaBalances($userAccountId, $userNetwork, $HKSH_ID ?: '0.0.XXXXXXX');
    // If Mirror Node is reachable, use its numbers; otherwise skip pre-checks and let the debit path handle errors.
    if (!empty($balances['ok'])) {
        if ($method === 'HBAR') {
            // Small fee buffer so users aren't blocked by network fees
            $bufferHbar = 0.02; // adjust to your fee model
            $have = (float)($balances['hbar'] ?? 0.0);
            $need = max(0.0, (float)$totalHb) + $bufferHbar;

            if ($have + 1e-8 < $need) {
                mark_order_status($db, $id, 'INSUFFICIENT_FUNDS', 'HBAR');
                if (function_exists('custom_AutoVest_text_whatsapp')) {
                    $needFmt = number_format($need, 6);
                    $haveFmt = number_format(max(0.0, $have), 6);
                    $link    = "https://hashscan.io/{$userNetwork}/account/" . rawurlencode($userAccountId);
                    $msg = "Insufficient HBAR for order {$id}.\n"
                        . "Needed about {$needFmt} HBAR (incl. fees), you have {$haveFmt} HBAR.\n"
                        . "Top up your wallet, then try again.\n\n"
                        . "🔎 View your wallet: {$link}";
                    custom_AutoVest_text_whatsapp($order['wa_id'], $msg);
                }
                return;
            }
        } elseif ($method === 'HKSH') {
            // Compare in human units (getHederaBalances already descales using token decimals)
            $have = (float)($balances['hksh'] ?? 0.0);
            $need = (float)$totalHk;

            if ($have + 1e-8 < $need) {
                mark_order_status($db, $id, 'INSUFFICIENT_FUNDS', 'HKSH');
                if (function_exists('custom_AutoVest_text_whatsapp')) {
                    $decimals = (int)(getenv('HKSH_DECIMALS') ?: 2);
                    $needFmt  = number_format($need, $decimals);
                    $haveFmt  = number_format(max(0.0, $have), $decimals);
                    $link     = "https://hashscan.io/{$userNetwork}/account/" . rawurlencode($userAccountId);
                    $msg = "Insufficient HKSH for order {$id}.\n"
                        . "Needed {$needFmt} HKSH, you have {$haveFmt} HKSH.\n"
                        . "Top up and try again.\n\n"
                        . "🔎 View your wallet: {$link}";
                    custom_AutoVest_text_whatsapp($order['wa_id'], $msg);
                }
                return;
            }
        }
    }
    // ------ End pre-debit balance checks ------


    // 3d) Take payment
    if ($method === 'HBAR') {
        if ($totalHb <= 0.0) {
            mark_order_status($db, $id, 'FAILED');
            if (function_exists('custom_AutoVest_text_whatsapp')) {
                custom_AutoVest_text_whatsapp($order['wa_id'], "Payment quote unavailable. Please request a new quote.");
            }
            return;
        }
        $pay = http_post_json("$API/transfer", [
            "fromPrivKey"   => $userPrivKey,
            "fromAccountId" => $userAccountId,
            "toAccountId"   => $treasury,
            "hbar"          => $totalHb,
            "memo"          => "Buy {$order['symbol']} x{$shares} @ KES {$pxKes} (Order {$id})"
        ]);

        if (!(bool)($pay['ok'] ?? false)) {
            mark_order_status($db, $id, 'FAILED', 'HBAR');
            if (function_exists('custom_AutoVest_text_whatsapp')) {
                custom_AutoVest_text_whatsapp($order['wa_id'], "HBAR payment failed. Please try again.");
            }
            return;
        }
    } elseif ($method === 'HKSH') {
        if ($HKSH_ID === '') {
            mark_order_status($db, $id, 'FAILED');
            if (function_exists('custom_AutoVest_text_whatsapp')) {
                custom_AutoVest_text_whatsapp($order['wa_id'], "HKSH token not configured.");
            }
            return;
        }
        $humanAmount = (float)$totalHk; // 1 HKSH = 1 KES

        $decimals      = (int) (getenv('HKSH_DECIMALS') ?: 2);
        $scale         = $decimals > 0 ? (10 ** $decimals) : 1;
        $scaledAmount  = (int) round($humanAmount * $scale);

        $pay = http_post_json("$API/tokens/transfer-user", [
            "tokenId"       => $HKSH_ID,
            "fromAccountId" => $userAccountId,
            "fromPrivKey"   => $userPrivKey,
            "toAccountId"   => $treasury,
            "amount"        => $scaledAmount,
            "memo"          => "Buy {$order['symbol']} x{$shares} @ KES {$pxKes} (Order {$id})"
        ]);


        if (!(bool)($pay['ok'] ?? false)) {
            mark_order_status($db, $id, 'FAILED', 'HKSH');
            if (function_exists('custom_AutoVest_text_whatsapp')) {
                custom_AutoVest_text_whatsapp($order['wa_id'], "HKSH payment failed. Please try again.");
            }
            return;
        }
    } else {
        mark_order_status($db, $id, 'FAILED');
        if (function_exists('custom_AutoVest_text_whatsapp')) {
            custom_AutoVest_text_whatsapp($order['wa_id'], "Unknown payment method.");
        }
        return;
    }

    // 3e) Mint stock tokens to treasury, then deliver to user
    $units = (int)round($shares * (10 ** $decimals)); // integer units to mint/transfer
    if ($units <= 0) {
        mark_order_status($db, $id, 'FAILED', $method);
        if (function_exists('custom_AutoVest_text_whatsapp')) {
            custom_AutoVest_text_whatsapp($order['wa_id'], "Invalid share quantity.");
        }
        return;
    }

    // Mint
    $mint = http_post_json("$API/tokens/mint", [
        "tokenId" => $stockTokenId,
        "amount"  => $units
        // supplyKey handled by Node server or DB
    ]);

    if (!(bool)($mint['ok'] ?? false)) {
        mark_order_status($db, $id, 'FAILED', $method);
        if (function_exists('custom_AutoVest_text_whatsapp')) {
            custom_AutoVest_text_whatsapp($order['wa_id'], "Mint failed. Support has been notified.");
        }
        return;
    }

    // --- Deliver minted stock to buyer (associate if needed, then transfer) ---

    // 1) Try transfer once (fast path)
    $xfer = http_post_json("$API/tokens/transfer", [
        "tokenId"     => $stockTokenId,
        "toAccountId" => $userAccountId,
        "amount"      => $units,
        "memo"        => "Deliver {$order['symbol']} x{$shares} (Order {$id})"
    ]);

    $needsAssociation = false;

    // Detect the common "not associated" failure (adjust the check to your Node API's error schema)
    if (!(bool)($xfer['ok'] ?? false)) {
        $errText = strtolower(json_encode($xfer));
        if (strpos($errText, 'not associated') !== false || strpos($errText, 'token_not_associated') !== false) {
            $needsAssociation = true;
        }
    }

    if ($needsAssociation) {
        // 2) Associate user's account to the stock token
        $assoc = http_post_json("$API/tokens/associate", [
            "accountId" => $userAccountId,
            "privKey"   => $userPrivKey,
            "tokenId"   => $stockTokenId
        ]);

        if (!(bool)($assoc['ok'] ?? false)) {
            // Association failed → notify and fail order
            mark_order_status($db, $id, 'FAILED', $method);
            if (function_exists('custom_AutoVest_text_whatsapp')) {
                custom_AutoVest_text_whatsapp(
                    $order['wa_id'],
                    "We couldn’t complete delivery because your wallet isn’t linked to {$order['symbol']} yet, and the link attempt failed. Please try again."
                );
            }
            error_log("[stocks] Association failed for {$userAccountId} -> {$stockTokenId}: " . json_encode($assoc));
            return;
        }

        // 3) Retry transfer after successful association
        $xfer = http_post_json("$API/tokens/transfer", [
            "tokenId"     => $stockTokenId,
            "toAccountId" => $userAccountId,
            "amount"      => $units,
            "memo"        => "Deliver {$order['symbol']} x{$shares} (Order {$id})"
        ]);
    }

    // 4) Final check
    if (!(bool)($xfer['ok'] ?? false)) {
        mark_order_status($db, $id, 'FAILED', $method);
        if (function_exists('custom_AutoVest_text_whatsapp')) {
            custom_AutoVest_text_whatsapp(
                $order['wa_id'],
                "Delivery failed after payment. Our team has been notified. We’ll sort this out soon."
            );
        }
        error_log("[stocks] Transfer failed for {$userAccountId} {$stockTokenId} units={$units}: " . json_encode($xfer));
        return;
    }

    // 3f) Mark completed
    mark_order_status($db, $id, 'COMPLETED', $method);

    // 3f.1) Publish a receipt to HCS (non-blocking)
    $topicTxId = null;
    $API   = rtrim(getenv('HEDERA_API_BASE') ?: 'http://127.0.0.1:5050', '/');
    $TOPIC = getenv('HCS_TOPIC_ID') ?: '';

    if ($TOPIC) {
        // Build a compact on-chain receipt
        $receipt = [
            'type'            => 'stock_order_receipt',
            'status'          => 'COMPLETED',
            'order'           => [
                'client_order_id' => $id,
                'symbol'          => $order['symbol'],
                'shares'          => (float)$shares,
                'price_kes'       => (float)$pxKes,
                'total_kes'       => (float)$totalKes,
                'pay_method'      => $method,
                'buyer_wa'        => $order['wa_id'],
                'buyer_account'   => $userAccountId,
            ],
            'tx' => [
                'payment'     => isset($pay['txId'])   ? (string)$pay['txId']   : null,
                'association' => isset($assoc['txId']) ? (string)$assoc['txId'] : null,
                'mint'        => isset($mint['txId'])  ? (string)$mint['txId']  : null,
                'delivery'     => isset($xfer['txId'])  ? (string)$xfer['txId']  : null,
            ],
            'network' => $userNetwork ?: 'testnet',
            'ts'      => date('c'),
        ];

        $hcs = http_post_json("$API/topics/message", [
            "topicId" => $TOPIC,
            "message" => json_encode($receipt)
        ]);

        if (!empty($hcs['ok']) && !empty($hcs['txId'])) {
            $topicTxId = (string)$hcs['txId'];
        } else {
            // Log quietly; do not fail the order
            error_log("[stocks] HCS receipt publish failed for order {$id}: " . json_encode($hcs));
        }
    }

    // 3g) Notify user (with HashScan proof links, including HCS receipt if present)
    if (function_exists('custom_AutoVest_text_whatsapp')) {
        $scanBase = function_exists('hashscan_base_from_network')
            ? hashscan_base_from_network($userNetwork ?? null)
            : 'https://hashscan.io/testnet';

        $txPay   = isset($pay['txId'])   ? (string)$pay['txId']   : null;
        $txAssoc = isset($assoc['txId']) ? (string)$assoc['txId'] : null;  // set only if you attempted association
        $txMint  = isset($mint['txId'])  ? (string)$mint['txId']  : null;
        $txXfer  = isset($xfer['txId'])  ? (string)$xfer['txId']  : null;

        $paidLine = ($method === 'HBAR')
            ? "Paid ~" . number_format((float)$totalHb, 6) . " HBAR."
            : "Paid " . number_format((float)$totalHk, 2) . " HKSH.";

        $proofLines = [];
        if ($txPay)     $proofLines[] = "• Payment: {$scanBase}/transaction/" . urlencode($txPay);
        if ($txAssoc)   $proofLines[] = "• Association: {$scanBase}/transaction/" . urlencode($txAssoc);
        if ($txMint)    $proofLines[] = "• Mint: {$scanBase}/transaction/" . urlencode($txMint);
        if ($txXfer)    $proofLines[] = "• Delivery: {$scanBase}/transaction/" . urlencode($txXfer);
        if ($topicTxId) $proofLines[] = "• Receipt (HCS): {$scanBase}/transaction/" . urlencode($topicTxId);

        $msg = "✅ Order {$id} completed.\n"
            . "You bought {$shares} {$order['symbol']} at KES " . number_format($pxKes, 2) . ".\n"
            . "{$paidLine}\n"
            . "Your tokens have been delivered to {$userAccountId}."
            . (count($proofLines) ? "\n\n🔗 On-chain transaction proofs (public explorer):\n" . implode("\n", $proofLines) : "");

        custom_AutoVest_text_whatsapp($order['wa_id'], $msg);
    }


}



mysqli_close($db);


