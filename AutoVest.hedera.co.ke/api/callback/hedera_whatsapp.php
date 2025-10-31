<?php
// declare(strict_types=1);

// // // During testing
// error_reporting(E_ALL); // Report all types of errors
// ini_set('display_errors', 1); // Display errors in the browser
// ini_set('display_startup_errors', 1); // Display startup errors

require_once '/var/www/AutoVest.hedera.co.ke/api/callback/hedera_functions.php';


// $message_from = "254715586044";
// $feedback = "Hello from Hedera AutoVest";
// $whatsapp_reciever = "+".$message_from;
// $whatsapp_feedback = custom_AutoVest_text_whatsapp($whatsapp_reciever,$feedback);



// Log MQR requests only 

if (!empty($original_raw_data)) {
    $file = '/var/www/AutoVest.hedera.co.ke/log-whats-AutoVest_hook.txt';  
    $data =json_encode($original_raw_data)."\n";  
    file_put_contents($file, $data, FILE_APPEND | LOCK_EX);  
}

// Load environment variables from .env
$envPath = '/var/www/AutoVest.hedera.co.ke/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) continue;

        // Split key=value pairs
        list($key, $value) = array_map('trim', explode('=', $line, 2));
        if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

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

//    // For debugging
//    $message_from = "254715586044";
//     $feedback = "Hello from Hedera AutoVest".$Body." Account ID: ".$account_id;
//     $whatsapp_reciever = "+".$message_from;
//     $whatsapp_feedback = custom_AutoVest_text_whatsapp($whatsapp_reciever,$feedback);
//     die();

//--------------------------------------
// check if user is registered: 

$whatsapp_reciever = "+".$message_from;
$client_query = "SELECT * FROM `hksh_AutoVest_clients` WHERE `whatsapp_phone` = '$whatsapp_reciever' AND `status` = '1'";
$run_client_query = mysqli_query($db, $client_query);
// $count_client_query = mysqli_num_rows($run_client_query); //slow while testing, so we will avoid this aproach
$client_query_results = mysqli_fetch_array($run_client_query);

$account_id = $address = @$client_query_results['account_id'];
$hedera_private_key = @$client_query_results['algo_secret'];

if (!empty($account_id)) {
    // CONTINUE WITH OTHER REQUESTS
    // e.g check balance or normal AI stuff

    $feedback = "Yeah...";

    if ($type === 'text') {

        if (strtolower($Body) === "/bal" || strtolower($Body) === "bal" || strtolower($Body) === "/ bal" || strtolower($Body) === "/balance") {

            // Get Wallet Account Balance (HBAR + HKSH) and reply nicely on WhatsApp

            // Inputs you already have in your scope:
            // $account_id        = $account_id;
            $network           = getenv('HEDERA_NETWORK') ?: 'testnet';
            $hksh_token_id     = getenv('HKSH_TOKEN_ID') ?: '0.0.XXXXXXX';
            $whatsapp_reciever = isset($whatsapp_reciever) ? $whatsapp_reciever : '+2547XXXXXXX'; // your existing var
            // $Body              = isset($Body) ? $Body : 'Check balance';

            // 1) Fetch balances
            $balances = getHederaBalances($account_id, $network, $hksh_token_id);

            // 2) Build WhatsApp-friendly message
            if (!empty($balances['ok'])) {
                // You can tweak this line if you want even shorter copy
                $feedback = $balances['feedback'];
            } else {
                $msg = !empty($balances['error']) ? $balances['error'] : 'Unknown error';
                $feedback = "⚠️ I could not fetch your balance right now. Please try again in a moment.\n\nDetails: {$msg}";
            }

            // 4) Save prompt history (light sanitization helpers)
            $ai_generate = false;

            if (!function_exists('sanitize')) {
                function sanitize($s) {
                    return substr(trim((string)$s), 0, 2000);
                }
            }

            $db_query = sanitize($Body);
            $db_reply = sanitize("[Action: Checked Hedera balance] - " . $feedback);
            $wa_id    = sanitize($whatsapp_reciever);

            // Ensure table exists (no-op if already there)
            if (isset($db) && $db instanceof mysqli) {

                // Insert record
                $stmt = mysqli_prepare($db, "INSERT INTO `AutoVest_prompt_history` (`wa_id`,`query`,`reply`) VALUES (?,?,?)");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "sss", $wa_id, $db_query, $db_reply);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            }

        } elseif (strtolower($Body) === "/addr" || strtolower($Body) === "addr" || strtolower($Body) === "/ addr" || strtolower($Body) === "/account"  || strtolower($Body) === "/accountID" || strtolower($Body) === "/ account" ) {

            $network = getenv('HEDERA_NETWORK') ?: 'testnet'; // testnet | mainnet | previewnet

            $feedback = "🔐 *Your Hedera AutoVest Wallet Account:*\n"
                . "*" . $account_id . "*\n\n"
                . "🔎 View on HashScan:\n"
                . "https://hashscan.io/{$network}/account/" . urlencode($account_id) . "\n\n"
                . "You can use this account to receive *HBAR* and *HKSH* tokens, "
                . "or connect with Hedera AutoVest AI for investments and tracking.";

            $ai_generate = false;

            // Save action to DB
            $db_query = $Body;
            $db_reply = "[Action: Checked Hedera Wallet Address] - " . $feedback;

        } else {

            //check with the AI
            $menu_ai_option = choose_AutoVest_option_AI($Body, $whatsapp_reciever);
            $menu_ai_option = cleanAssistantPrefix_AND_MORE($menu_ai_option);

            // For future refined responces
            // Step 1: Remove the ```json and ``` parts
            // $cleanInput = preg_replace('/```json|```/', '', $response_content);
            // Step 2: Decode the cleaned string into a PHP associative array
            // $inputArray = json_decode($cleanInput, true);
            // $action = $inputArray['action'];

            if (!empty($menu_ai_option)) {

                //if its balance
                // $normalized_input = strtolower(trim($menu_ai_option));
                $normalized_input = strtolower(trim(preg_replace('/[\x00-\x1F\x7F\xA0\x{200B}]/u', '', $menu_ai_option)));


                // balance inputs
                $balance_variants = [
                    // Correct and expected inputs
                    "/bal", "bal", "/ bal", "balance", "check balance", "my balance",
                    "show balance", "wallet balance", "wallet amount", "funds",
                    "get balance", "current balance", "display balance", "account balance",

                    // Common typos and AI/autocorrect hallucinations
                    "/bol", "bol", "/bl", "balnce", "blance", "balace", "/bala",
                    "bals", "bql", "val", "/val", "baln", "/baln", "/balance", "balane",
                    "banlance", "ballance", "balacne", "blanace", "balamce", "bslance",
                    "bwlance", "balknce", "bqlance", "blqnce", "b@l", "/b@l", "b@lance",

                    // AI hallucinations and phonetic/autocomplete errors
                    "/ben", "ben", "/ban", "ban", "/bell", "/bel", "bell", "bel",
                    "/bill", "bill", "/balaance", "balaance", "/belans", "belans",
                    "/blnce", "blnce", "blance", "b@1", "/ba1", "ba1", "ba|",

                    // Extremely distorted but possibly triggered hallucinations
                    "balans", "balence", "balansce", "/banance", "benlance", "balanse",
                    "/bq", "bq", "/bsl", "bsl", "bqlnce", "/bln", "bln", "/bain", "bain",
                    "/bail", "bail", "/bali", "bali", "/balanee", "balanee", "bala.nce"
                ];


                //address inputs
                $address_variants = [
                    "/addr", "addr", "/ addr", "address", "my address", "wallet address",
                    "show address", "get address", "receive address", "receive",
                    
                    // Common typos and AI errors
                    "/adr", "adr", "/add", "add", "adress", "addres", "adresss", 
                    "addressee", "receiving address", "walet address", "addrr"
                ];


                if (in_array($normalized_input, $balance_variants)) {
                    // Get Wallet Account Balance (HBAR + HKSH) and reply nicely on WhatsApp

                    // $account_id        = $account_id;
                    $network           = getenv('HEDERA_NETWORK') ?: 'testnet';
                    $hksh_token_id     = getenv('HKSH_TOKEN_ID') ?: '0.0.XXXXXXX';
                    $whatsapp_reciever = isset($whatsapp_reciever) ? $whatsapp_reciever : '+2547XXXXXXX'; // your existing var
                    // $Body              = isset($Body) ? $Body : 'Check balance';

                    // 1) Fetch balances
                    $balances = getHederaBalances($account_id, $network, $hksh_token_id);

                    // 2) Build WhatsApp-friendly message
                    if (!empty($balances['ok'])) {
                        // You can tweak this line if you want even shorter copy
                        $feedback = $balances['feedback'];
                    } else {
                        $msg = !empty($balances['error']) ? $balances['error'] : 'Unknown error';
                        $feedback = "⚠️ I could not fetch your balance right now. Please try again in a moment.\n\nDetails: {$msg}";
                    }

                    // 4) Save prompt history (light sanitization helpers)
                    $ai_generate = false;

                    if (!function_exists('sanitize')) {
                        function sanitize($s) {
                            return substr(trim((string)$s), 0, 2000);
                        }
                    }

                    $db_query = sanitize($Body);
                    $db_reply = sanitize("[Action: Checked Hedera balance] - " . $feedback);
                    $wa_id    = sanitize($whatsapp_reciever);

                    // Save to the DB
                    if (isset($db) && $db instanceof mysqli) {

                        // Insert record
                        $stmt = mysqli_prepare($db, "INSERT INTO `AutoVest_prompt_history` (`wa_id`,`query`,`reply`) VALUES (?,?,?)");
                        if ($stmt) {
                            mysqli_stmt_bind_param($stmt, "sss", $wa_id, $db_query, $db_reply);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);
                        }
                    }

                }  elseif (in_array($normalized_input, $address_variants)) {
                    $network = getenv('HEDERA_NETWORK') ?: 'testnet'; // testnet | mainnet | previewnet

                    $feedback = "🔐 *Your Hedera AutoVest Wallet Account:*\n"
                        . "*" . $account_id . "*\n\n"
                        . "🔎 View on HashScan:\n"
                        . "https://hashscan.io/{$network}/account/" . urlencode($account_id) . "\n\n"
                        . "You can use this account to receive *HBAR* and *HKSH* tokens, "
                        . "or connect with Hedera AutoVest AI for investments and tracking.";

                    $ai_generate = false;

                    // Save action to DB
                    $db_query = $Body;
                    $db_reply = "[Action: Checked Hedera Wallet Address] - " . $feedback;
                } else {
                    // lets remove the quotes
                    $menu_ai_option = whatsapp_optimize($menu_ai_option);

                    //return AI feedback
                    $feedback = $menu_ai_option;

                    // save action to DB 
                    $db_query = $Body;
                    $db_reply = $feedback;
                }

            } else {
                $feedback = "An error occured and I could't answer that, please try again or ask a diffrent question 🛠️";

                // save action to DB 
                $db_query = $Body;
                $db_reply = $feedback;
            }

        }

    } else {
        // logic for other types of non text message
        $feedback = "Oops! I couldn't respond to that at the moment. Please try again or ask a different question.";
        $ai_generate = false;

        // save action to DB 
        $db_query = $Body;
        $db_reply = $feedback;
    }

    // Send the feedback text
    if(!empty($feedback)) {
        $whatsapp_feedback = custom_AutoVest_text_whatsapp($whatsapp_reciever,$feedback);

        // Check if its a request to send algo, topup or withdraw
        $unified_intent_feedback  = check_AutoVest_intent($Body,$feedback);

        if($unified_intent_feedback === "1") {
            Hbar_SEND_init_HKSH_whatsapp($whatsapp_reciever);

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: Triggered HBAR send flow] - " . $feedback;

        } elseif ($unified_intent_feedback === "2") {
            MPESA_TOPUP_init_AutoVest_whatsapp($whatsapp_reciever);

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: Triggered M-Pesa top-up flow] - " . $feedback;
        } elseif ($unified_intent_feedback === "3") {
            HKSH_WITHDRAW_MPESA_init_AutoVest_whatsapp($whatsapp_reciever);

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: Triggered M-Pesa withdrawal flow] - " . $feedback;
        } elseif ($unified_intent_feedback === "4") {
            HKSH_HBAR_CONVERSION_init_whatsapp($whatsapp_reciever);

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: Triggered HBAR <-> HKSH conversion flow] - " . $feedback;
        } else {
            //do nothing for now
        }


    }

    //update prompt db - insert
    $whatsapp_reciever = sanitize($whatsapp_reciever);
    $db_query = sanitize($db_query);
    $db_reply = sanitize($db_reply);

    $save_prompt_query = "INSERT INTO `AutoVest_prompt_history`(`wa_id`, `query`, `reply`) VALUES ('$whatsapp_reciever','$db_query','$db_reply')";
    $run_save_prompt_query = mysqli_query($db, $save_prompt_query);


} else {

    // if empty $message_from - not a whatsapp request
    if(empty($message_from)) {
        die();
    }

    /**
     * Hedera flow:
     * 1) Create new Hedera account (testnet)
     * 2) Fund it with 2 HBAR from operator
     * 3) Associate HKSH token to the new account
     * 4) Save account details to DB
     * 5) Prepare WhatsApp feedback
     */

    // Config
    $HEDERA_API = getenv('HEDERA_LOCAL_API') ?: 'http://127.0.0.1:5050';
    $HEDERA_NETWORK = getenv('HEDERA_NETWORK') ?: 'testnet'; // testnet|mainnet|previewnet
    $HKSH_TOKEN_ID = getenv('HKSH_TOKEN_ID') ?: '0.0.XXXXXXX';
    $INITIAL_HBAR = 2.0;


    // Helpers
    function post_json(string $url, array $payload, array $headers = [], int $timeout = 45): array {
        $ch = curl_init($url);
        $hdrs = array_merge(['Content-Type: application/json'], $headers);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) return ['ok' => false, 'error' => "curl error: $err", 'http_code' => $code];
        $json = json_decode($resp, true);
        if (!is_array($json)) return ['ok' => false, 'error' => "invalid JSON: $resp", 'http_code' => $code];
        if (!isset($json['ok'])) $json['ok'] = ($code >= 200 && $code < 300);
        $json['http_code'] = $code;
        return $json;
    }

    // 1) Create account (with 0 HBAR initial, we fund in step 2)
    $createResp = post_json(rtrim($HEDERA_API, '/') . '/accounts', [
        'initialHbar' => 0
    ]);
    
    if (!($createResp['ok'] ?? false)) {
        // handle error
        $feedback = "Account creation failed. Please try again.";
        // optionally log $createResp
        goto SEND_FEEDBACK_AND_LOG;
    }

    $newAccountId = $createResp['accountId'] ?? null;
    $newPubKey    = $createResp['publicKey'] ?? null;
    $newPrivKey   = $createResp['privateKey'] ?? null;
    $createTxId   = $createResp['txId'] ?? null;

   

    if (!$newAccountId || !$newPrivKey) {
        $feedback = "Account created, but details are missing. Please contact support.";
        goto SEND_FEEDBACK_AND_LOG;
    }

    // 2) Fund the new account with 2 HBAR
    $fundResp = post_json(rtrim($HEDERA_API, '/') . '/fund', [
        'toAccountId' => $newAccountId,
        'hbar'        => $INITIAL_HBAR,
        'memo'        => 'AutoVest AI initial funding'
    ]);
    
    $fundTxId = $fundResp['txId'] ?? null;

    // 3) Associate HKSH token to the new account
    // This uses an /tokens/associate route in your Node API that signs with the user's key.
    // Body: { accountId, privKey, tokenId }
    $assocResp = post_json(rtrim($HEDERA_API, '/') . '/tokens/associate', [
        'accountId' => $newAccountId,
        'privKey'   => $newPrivKey,
        'tokenId'   => $HKSH_TOKEN_ID
    ]);
    $assocOk = $assocResp['ok'] ?? false;


        // 4) Save to DB
        $whatsapp_reciever = "+" . $message_from; // comes from your existing scope
        $account_id              = mysqli_real_escape_string($db, $newAccountId);
        $hedera_public_key       = mysqli_real_escape_string($db, $newPubKey ?? '');
        $hedera_private_key      = mysqli_real_escape_string($db, $newPrivKey);
        $wa_id                   = mysqli_real_escape_string($db, $whatsapp_reciever);

        $saveClientSql = sprintf(
            "INSERT INTO `hksh_AutoVest_clients` (`whatsapp_phone`,`account_id`,`hedera_public_key`,`hedera_private_key`,`status`)
            VALUES ('%s','%s','%s','%s','1')
            ON DUPLICATE KEY UPDATE hedera_public_key=VALUES(hedera_public_key), hedera_private_key=VALUES(hedera_private_key), status=VALUES(status)",
            $wa_id, $account_id, $hedera_public_key, $hedera_private_key
        );
        $run_create_client_query = mysqli_query($db, $saveClientSql);

        $test_error = "no db error";
        if (!$run_create_client_query) {
            error_log("DB Error: " . mysqli_error($db));
            $test_error = "db error";
        }


        // 5) Build WhatsApp feedback
        $accountExplorer = "https://hashscan.io/{$HEDERA_NETWORK}/account/" . urlencode($newAccountId);
        $fundTxExplorer  = $fundTxId ? "https://hashscan.io/{$HEDERA_NETWORK}/transaction/" . urlencode($fundTxId) : null;

        if ($run_create_client_query && ($fundResp['ok'] ?? false)) {
            $fundedAmt = number_format($INITIAL_HBAR, 3);
            $assocLine = $assocOk
                ? "✔️ Your account is now subscribed to *HKSH* (`{$HKSH_TOKEN_ID}`)."
                : "ℹ️ Token subscription pending. If you see any delay, it will auto-complete shortly.";

        $feedback = "🏦 *Account ID:*\n{$newAccountId}\n\n"
                . "💰 *Starting Balance:*\n{$fundedAmt} HBAR\n\n"
                . "🔗 *View on Explorer:*\n{$accountExplorer}\n"
                . ($fundTxExplorer ? "🧾 *Funding Transaction:*\n{$fundTxExplorer}\n\n" : "\n")
                . "{$assocLine}\n\n"
                . "✨ *You can now:*\n"
                . "• Receive and send HBAR\n"
                . "• Hold or stake HKSH to access AutoVest AI insights\n"
                . "• *Manually buy stocks* listed on the NSE using your HKSH balance\n"
                . "• Use *AutoVest AI* to automate your investments\n\n"
                . "📊 Whether you prefer hands-on trading or automated investing, your journey starts here!\n\n"
                . "Next → Would you like to *view your wallet*, *buy stocks manually*, or *enable AutoVest AI*?";

            $db_query = $Body;
            $db_reply = "[Action: Hedera account creation] - " . $feedback;

        } else {
            $feedback = "An error occurred while setting up your Hedera wallet. Please try again.";
            $db_query = $Body;
            $db_reply = "[Action: Hedera account creation] - " . $feedback;
        }

        // send feedback
        SEND_FEEDBACK_AND_LOG:
        $smart_feedback = build_welcome_reply($wa_id, $Body, $feedback);
        $smart_feedback = cleanAssistantPrefix_AND_MORE($smart_feedback);

        if(empty($smart_feedback)) {
            $smart_feedback = $feedback;
        }

        $whatsapp_feedback = custom_AutoVest_text_whatsapp($whatsapp_reciever, $smart_feedback);

        // save prompt history
        $wa_id_sql = sanitize($whatsapp_reciever);
        $db_query_sql = sanitize($db_query ?? $Body);
        $db_reply_sql = sanitize($db_reply ?? $feedback);

        mysqli_query(
            $db,
            "INSERT INTO `AutoVest_prompt_history`(`wa_id`,`query`,`reply`) VALUES ('{$wa_id_sql}','{$db_query_sql}','{$db_reply_sql}')"
        );


}






die();



