<?php
// declare(strict_types=1);

// // // During testing
// error_reporting(E_ALL); // Report all types of errors
// ini_set('display_errors', 1); // Display errors in the browser
// ini_set('display_startup_errors', 1); // Display startup errors

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

// Load environment variables from AWS KMS
require_once '/var/www/AutoVest.hedera.co.ke/bootstrap_secrets.php';

try {
    $DEBUG = filter_var(env('DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);

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

// Switch db connection
mysqli_close($db);

// Retrieve database credentials from environment
$dbHost = $db_host = env('DB_HOST') ?: 'localhost';
$dbUser = $db_user = env('DB_USER') ?: 'root';
$dbPass = $db_pass = env('DB_PASS') ?: '';
$dbName = $db_name = env('DB_NAME') ?: 'hedera_ai';

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
            $network           = env('HEDERA_NETWORK') ?: 'testnet';
            $hksh_token_id     = env('HKSH_TOKEN_ID') ?: '0.0.XXXXXXX';
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

            $network = env('HEDERA_NETWORK') ?: 'testnet'; // testnet | mainnet | previewnet

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

        } elseif (strtolower($Body) === "/topup" || strtolower($Body) === "topup" || strtolower($Body) === "/ topup" ) {

            MPESA_TOPUP_init_AutoVest_whatsapp($whatsapp_reciever);

            $ai_generate = false;
            $feedback = "";

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: Triggered M-Pesa top-up flow via /topup command] - " . $feedback;

        }  elseif (strtolower($Body) === "/cardtopup" || strtolower($Body) === "cardtopup" || strtolower($Body) === "/ cardtopup" ) {

            // Start card top-up flow
            CARD_TOPUP_init_AutoVest_whatsapp($whatsapp_reciever);

            $ai_generate = false;
            $feedback = "";

            // Save action to DB
            $db_query = $Body;
            $db_reply = "[Action: Triggered Card Top-Up flow via /cardtopup command] - " . $feedback;


        } elseif (strtolower($Body) === "/airtime" || strtolower($Body) === "airtime" || strtolower($Body) === "/ airtime" ) {

            AIRTIME_TEMPLATE_init_AutoVest_whatsapp($whatsapp_reciever);

            $ai_generate = false;
            $feedback = "";

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: Triggered M-Pesa top-up flow via /topup command] - " . $feedback;

        } elseif (strtolower($Body) === "/send" || strtolower($Body) === "send" || strtolower($Body) === "/ send" ) {

            Hbar_SEND_init_HKSH_whatsapp($whatsapp_reciever);

            $ai_generate = false;
            $feedback = "";

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: Triggered HBAR send flow] - " . $feedback;

        } elseif (strtolower($Body) === "/convert" || strtolower($Body) === "convert" || strtolower($Body) === "/ convert" ) {

            $ai_generate = false;
            $feedback = "";

            HKSH_HBAR_CONVERSION_init_whatsapp($whatsapp_reciever);

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: Triggered HBAR <-> HKSH conversion flow] - " . $feedback;

        } elseif (strtolower($Body) === "/buy" || strtolower($Body) === "buy shares" || strtolower($Body) === "/ buy shares" ) {

            $ai_generate = false;
            $feedback = "";

            HKSH_HBAR_NSE_BUY_whatsapp($whatsapp_reciever);

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: Triggered NSE Securities Purchase Flow] - " . $feedback;

        } elseif (strtolower($Body) === "/auto" || strtolower($Body) === "auto" || strtolower($Body) === "/ auto" ) {

            $ai_generate = false;
            $feedback = "";

            HKSH_HBAR_NSE_AutoVest_AI_Subscribe($whatsapp_reciever);

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: AutoVest Subscription Init] - " . $feedback;

        }  elseif (strtolower($Body) === "/linkbank" || strtolower($Body) === "linkbank" || strtolower($Body) === "/ linkbank" ) {

            $ai_generate = false;
            $feedback = "You can’t link your bank account right now because you’re currently using a testnet account. Please switch to a live account to enable bank connections and transactions.";

            // HKSH_HBAR_NSE_AutoVest_LinkBank($whatsapp_reciever);

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: AutoVest Subscription Init] - " . $feedback;

        }  elseif (strtolower($Body) === "/verify" || strtolower($Body) === "verify" || strtolower($Body) === "/ verify" ) {

            $ai_generate = false;
            $db_query = $Body;

            $phoneDigits = preg_replace('/\D+/', '', $whatsapp_reciever) ?? '';
            global $db;

            $latest = getLatestKycSubmission($db, $phoneDigits);

            $canStartNew = true;

            if ($latest) {
                $status = $latest['status'] ?? '';
                $flowToken = $latest['flow_token'] ?? '';

                // compute "stuck" window
                $updatedAt = $latest['updated_at'] ?? ($latest['created_at'] ?? null);
                $ageSeconds = $updatedAt ? (time() - strtotime($updatedAt)) : 0;
                $isStale = ($ageSeconds > 0.5 * 60 * 60); // 1 hour

                if ($status === 'awaiting_approval') {
                    $canStartNew = false;
                    $feedback = "🕒 Your identity verification is already under review.\n\n"
                            . "Please wait as we review the documents you submitted. "
                            . "You’ll be notified once it’s complete.";
                    $db_reply = "[Action: Identity Verification] - under_review";

                } elseif ($status === 'approved') {
                    $canStartNew = false;
                    $feedback = "✅ You’re already verified.\n\n"
                            . "If you ever need to update your documents, contact support.";
                    $db_reply = "[Action: Identity Verification] - already_verified";

                } elseif ($status === 'sent' || $status === 'started') {
                    if (!$isStale) {
                        // In progress recently → don't start another one
                        $canStartNew = false;
                        $feedback = "🪪 Your identity verification is already in progress.\n\n"
                                . "Please complete the verification form you started. "
                                . "If you can’t find it, type */verify* again after 30 minutes.";
                        $db_reply = "[Action: Identity Verification] - in_progress";
                    } else {
                        // Stale/incomplete → allow fresh start, and expire old one
                        expireKycSubmission($db, $flowToken);
                        $canStartNew = true;
                    }

                } elseif ($status === 'declined' || $status === 'error') {
                    // Declined → allow resubmission
                    $canStartNew = true;
                }
            }

            if ($canStartNew) {
                $started = kyc_init_AutoVest_whatsapp($whatsapp_reciever);

                if ($started) {
                    // $feedback = "🪪 Identity verification started.\n\n"
                    //         . "Please follow the steps to upload your documents. "
                    //         . "We’ll notify you once your verification is reviewed.";
                    $feedback = "";
                    $db_reply = "[Action: Identity Verification Init] - success";
                } else {
                    $feedback = "⚠️ We couldn’t start identity verification right now.\n"
                            . "Please try again in a few minutes.";
                    $db_reply = "[Action: Identity Verification Init] - failed";
                }
            }

            $db->close();

        }  elseif (strtolower($Body) === "/linkgoogle" || strtolower($Body) === "linkoogle" || strtolower($Body) === "/ linkoogle" ) {

            $ai_generate = false;
            $feedback = "You can’t connect your Google account right now because you’re using a testnet account. Please switch to a live account to enable Google integrations and access.";

            // HKSH_HBAR_NSE_AutoVest_LinkGoogle($whatsapp_reciever);

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: AutoVest Subscription Init] - " . $feedback;

        }elseif(strtolower($Body) === "/portfolio" || strtolower($Body) === "portfolio" || strtolower($Body) === "/ portfolio") {
            /**
             * Portfolio (HBAR + HKSH + NSE tokens) using DB metadata:
             * - Discovers subscribed tokens from Mirror Node
             * - Enriches token info from `tokens` table
             * - Fetches NSE stock prices directly from DB (`nse_ticks` or `nse_quotes`)
             * - HKSH pegged 1 KES
             *
             * env: HEDERA_NETWORK, HKSH_TOKEN_ID
             * needs: $account_id, optional $db (mysqli), optional $Body, $whatsapp_reciever
             */

            $network           = env('HEDERA_NETWORK') ?: 'testnet';
            $hksh_token_id     = env('HKSH_TOKEN_ID') ?: '0.0.XXXXXXX';
            $whatsapp_reciever = isset($whatsapp_reciever) ? $whatsapp_reciever : '+2547XXXXXXX';


            // Lets now get the job done
            $portfolio = getHederaPortfolioWithDB(
                account_id: $account_id,
                network: $network,
                hksh_token_id: $hksh_token_id,
                db: isset($db) && $db instanceof mysqli ? $db : null
            );

            $feedback = !empty($portfolio['ok'])
                ? $portfolio['feedback']
                : ("⚠️ I could not fetch your portfolio right now. Please try again in a moment.\n\nDetails: " . ($portfolio['error'] ?? 'Unknown error'));

            // optional history log
            if (!function_exists('sanitize')) { function sanitize($s){ return substr(trim((string)$s), 0, 2000); } }
            $db_query = sanitize(isset($Body) ? $Body : 'Check portfolio');
            $db_reply = sanitize("[Action: Checked Hedera portfolio] - " . $feedback);
            $wa_id    = sanitize($whatsapp_reciever);
            if (isset($db) && $db instanceof mysqli) {
                if ($stmt = mysqli_prepare($db, "INSERT INTO `AutoVest_prompt_history` (`wa_id`,`query`,`reply`) VALUES (?,?,?)")) {
                    mysqli_stmt_bind_param($stmt, "sss", $wa_id, $db_query, $db_reply);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            }


        } elseif (
            strtolower($Body) === "/feedback" ||
            strtolower($Body) === "feedback" ||
            strtolower($Body) === "/ feedback"
        ) {

            // Trigger WhatsApp Feedback Flow
            AutoVest_FeedbackFlow_Init($whatsapp_reciever);

            $ai_generate = false;
            $feedback = "";

            // Save action to DB
            $db_query = $Body;
            $db_reply = "[Action: Triggered Feedback Flow] - " . $feedback;
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

                // portfolio inputs
                $portfolio_variants = [
                    "/portfolio", "portfolio", "/ portfolio",
                    "my portfolio", "show portfolio", "view portfolio", "check portfolio",
                    "see portfolio", "portfolio balance", "my holdings", "show holdings",
                    "view holdings", "check holdings", "see holdings",
                    "my assets", "show assets", "view assets", "check assets",
                    "my stocks", "show stocks", "view stocks", "check stocks",
                    "my investments", "show investments", "view investments", "check investments",

                    // Common typos and short forms
                    "/port", "port", "portfo", "portfollio", "portolio", "portfoli", "portf",
                    "my port", "show port", "view port", "prtfolio", "portfolia",
                    "protfolio", "protfoilio",

                    // AI misunderstandings that might appear
                    "show my balance summary", "overall balance", "total balance",
                    "all balances", "combined balance", "everything I own"
                ];


                if (in_array($normalized_input, $balance_variants)) {
                    // Get Wallet Account Balance (HBAR + HKSH) and reply nicely on WhatsApp

                    // $account_id        = $account_id;
                    $network           = env('HEDERA_NETWORK') ?: 'testnet';
                    $hksh_token_id     = env('HKSH_TOKEN_ID') ?: '0.0.XXXXXXX';
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
                    $network = env('HEDERA_NETWORK') ?: 'testnet'; // testnet | mainnet | previewnet

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
                }  elseif (in_array($normalized_input, $portfolio_variants)) {
                    /**
                     * Portfolio (HBAR + HKSH + NSE tokens) using DB metadata:
                     * - Discovers subscribed tokens from Mirror Node
                     * - Enriches token info from `tokens` table
                     * - Fetches NSE stock prices directly from DB (`nse_ticks` or `nse_quotes`)
                     * - HKSH pegged 1 KES
                     *
                     * env: HEDERA_NETWORK, HKSH_TOKEN_ID
                     * needs: $account_id, optional $db (mysqli), optional $Body, $whatsapp_reciever
                     */

                    $network           = env('HEDERA_NETWORK') ?: 'testnet';
                    $hksh_token_id     = env('HKSH_TOKEN_ID') ?: '0.0.XXXXXXX';
                    $whatsapp_reciever = isset($whatsapp_reciever) ? $whatsapp_reciever : '+2547XXXXXXX';


                    // Lets now get the job done
                    $portfolio = getHederaPortfolioWithDB(
                        account_id: $account_id,
                        network: $network,
                        hksh_token_id: $hksh_token_id,
                        db: isset($db) && $db instanceof mysqli ? $db : null
                    );

                    $feedback = !empty($portfolio['ok'])
                        ? $portfolio['feedback']
                        : ("⚠️ I could not fetch your portfolio right now. Please try again in a moment.\n\nDetails: " . ($portfolio['error'] ?? 'Unknown error'));

                    // optional history log
                    if (!function_exists('sanitize')) { function sanitize($s){ return substr(trim((string)$s), 0, 2000); } }
                    $db_query = sanitize(isset($Body) ? $Body : 'Check portfolio');
                    $db_reply = sanitize("[Action: Checked Hedera portfolio] - " . $feedback);
                    $wa_id    = sanitize($whatsapp_reciever);
                    if (isset($db) && $db instanceof mysqli) {
                        if ($stmt = mysqli_prepare($db, "INSERT INTO `AutoVest_prompt_history` (`wa_id`,`query`,`reply`) VALUES (?,?,?)")) {
                            mysqli_stmt_bind_param($stmt, "sss", $wa_id, $db_query, $db_reply);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);
                        }
                    }

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
        } elseif ($unified_intent_feedback === "5") {
            HKSH_HBAR_NSE_BUY_whatsapp($whatsapp_reciever);

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: Triggered NSE Securities Purchase Flow] - " . $feedback;
        } elseif ($unified_intent_feedback === "6") {
            HKSH_HBAR_NSE_AutoVest_AI_Subscribe($whatsapp_reciever);

            // save action to DB 
            $db_query = $Body;
            $db_reply = "[Action: AutoVest Subscription Init] - " . $feedback;
        } elseif ($unified_intent_feedback === "7") {
            // Trigger WhatsApp Feedback Flow
            AutoVest_FeedbackFlow_Init($whatsapp_reciever);

            // // Save action to DB
            $db_query = $Body;
            $db_reply = "[Action: Triggered Feedback Flow] - " . $feedback;
        } elseif ($unified_intent_feedback === "8") {
            // Start card top-up flow
            CARD_TOPUP_init_AutoVest_whatsapp($whatsapp_reciever);


            // Save action to DB
            $db_query = $Body;
            $db_reply = "[Action: Triggered Card Top-Up flow] - " . $feedback;

        } elseif ($unified_intent_feedback === "9") {
            // Start KYC flow, if needed

            $db_query = $Body;

            $phoneDigits = preg_replace('/\D+/', '', $whatsapp_reciever) ?? '';
            global $db;

            $latest = getLatestKycSubmission($db, $phoneDigits);

            $canStartNew = true;

            if ($latest) {
                $status = $latest['status'] ?? '';
                $flowToken = $latest['flow_token'] ?? '';

                // compute "stuck" window
                $updatedAt = $latest['updated_at'] ?? ($latest['created_at'] ?? null);
                $ageSeconds = $updatedAt ? (time() - strtotime($updatedAt)) : 0;
                $isStale = ($ageSeconds > 0.5 * 60 * 60); // 1 hour

                if ($status === 'awaiting_approval') {
                    $canStartNew = false;
                    $feedback = "We could not start a new verification request because your previous KYC submission is already under review.\n\n"
                                . "Please give us a moment to review your documents. "
                                . "You’ll be notified as soon as the process is complete.";
                    $db_reply = "[Action: Identity Verification] - under_review";

                } elseif ($status === 'approved') {
                    $canStartNew = false;
                    $feedback = "We could not start the verification process because your identity has already been successfully verified.\n\n"
                                . "If you ever need to update your documents, please contact support.";

                    $db_reply = "[Action: Identity Verification] - already_verified";

                } elseif ($status === 'sent' || $status === 'started') {
                    if (!$isStale) {
                        // In progress recently → don't start another one
                        $canStartNew = false;
                        $feedback = "🪪 We could not start a new verification request because your identity verification is already in progress.\n\n"
                                    . "Please complete the verification form you started. "
                                    . "If you can’t find it, type */verify* again after 30 minutes.";

                        $db_reply = "[Action: Identity Verification] - in_progress";
                    } else {
                        // Stale/incomplete → allow fresh start, and expire old one
                        expireKycSubmission($db, $flowToken);
                        $canStartNew = true;
                    }

                } elseif ($status === 'declined' || $status === 'error') {
                    // Declined → allow resubmission
                    $canStartNew = true;
                }
            }

            if ($canStartNew) {
                $started = kyc_init_AutoVest_whatsapp($whatsapp_reciever);

                if ($started) {
                    // $feedback = "🪪 Identity verification started.\n\n"
                    //         . "Please follow the steps to upload your documents. "
                    //         . "We’ll notify you once your verification is reviewed.";
                    $feedback = "";
                    $db_reply = "[Action: Identity Verification Init] - success";
                } else {
                    $feedback = "⚠️ We couldn’t start identity verification right now.\n"
                            . "Please try again in a few minutes.";
                    $db_reply = "[Action: Identity Verification Init] - failed";
                }
            }

            //Output that an error occured while trying to triger kyc
            if(!empty($feedback)) {
                $whatsapp_feedback = custom_AutoVest_text_whatsapp($whatsapp_reciever, $feedback);
            }

            // $db->close();

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
    $HEDERA_API = env('HEDERA_LOCAL_API') ?: 'http://127.0.0.1:5050';
    $HEDERA_NETWORK = env('HEDERA_NETWORK') ?: 'testnet'; // testnet|mainnet|previewnet
    $HKSH_TOKEN_ID = env('HKSH_TOKEN_ID') ?: '0.0.XXXXXXX';
    $INITIAL_HBAR = 2.0;
    $tUSDC_TOKEN_ID = env('tUSDC_TOKEN_ID') ?: '0.0.XXXXXXX';


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
    $assocResp = post_json(rtrim($HEDERA_API, '/') . '/tokens/associate', [
        'accountId' => $newAccountId,
        'privKey'   => $newPrivKey,
        'tokenId'   => $HKSH_TOKEN_ID
    ]);
    $assocOk = (bool)($assocResp['ok'] ?? false);

    // 3B) Associate tUSDC token to the new account (same flow as HKSH)
    $assocRespB = post_json(rtrim($HEDERA_API, '/') . '/tokens/associate', [
        'accountId' => $newAccountId,
        'privKey'   => $newPrivKey,
        'tokenId'   => $tUSDC_TOKEN_ID
    ]);
    $assocOkB = (bool)($assocRespB['ok'] ?? false);

    // // OPTIONAL: If we want to treat association as mandatory, uncomment this.
    // // If we keep it commented, the wallet is still created and funded, and tokens can be retried later. - we'll implement it after the other critical parts
    // if (!$assocOk || !$assocOkB) {
    //     $feedback = "Wallet funded, but token subscription is still pending. Please try again shortly.";
    //     goto SEND_FEEDBACK_AND_LOG;
    // }


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

            $hkshLine = $assocOk
                ? "✔️ Your account is now subscribed to *HKSH* (`{$HKSH_TOKEN_ID}`)."
                : "ℹ️ *HKSH* subscription pending. If you see any delay, it will auto-complete shortly.";

            $tusdcLine = $assocOkB
                ? "✔️ Your account is now subscribed to *tUSDC* (`{$tUSDC_TOKEN_ID}`)."
                : "ℹ️ *tUSDC* subscription pending. If you see any delay, it will auto-complete shortly.";

            // What you should claim the user can do depends on association success
            $supportedAssets = "HBAR";
            if ($assocOk)  $supportedAssets .= ", HKSH";
            if ($assocOkB) $supportedAssets .= ", tUSDC";

            // $feedback = "🏦 *Account ID:*\n{$newAccountId}\n\n"
            //     . "💰 *Starting Balance:*\n{$fundedAmt} HBAR\n\n"
            //     . "🔗 *View on Explorer:*\n{$accountExplorer}\n"
            //     . ($fundTxExplorer ? "🧾 *Funding Transaction:*\n{$fundTxExplorer}\n\n" : "\n")
            //     . "{$hkshLine}\n{$tusdcLine}\n\n"
            //     . "✨ *You can now:*\n"
            //     . "• Receive and send {$supportedAssets}\n"
            //     . "• Hold or stake HKSH to access AutoVest AI insights\n"
            //     . "• *Manually buy stocks* listed on the NSE using your HKSH balance\n"
            //     . "• Use *AutoVest AI* to automate your investments\n\n"
            //     . "📊 Whether you prefer hands-on trading or automated investing, your journey starts here!\n\n"
            //     . "Next → Would you like to *view your wallet*, *buy stocks manually*, or *enable AutoVest AI*?";

            $feedback = "🏦 *Your Wallet*\n"
                . "*Account ID:*\n{$newAccountId}\n\n"

                . "💰 *Starting Balance:*\n{$fundedAmt} HBAR\n\n"

                . "🔗 *View on Explorer:*\n{$accountExplorer}\n"
                . ($fundTxExplorer ? "🧾 *Funding Transaction:*\n{$fundTxExplorer}\n\n" : "\n")

                . "{$hkshLine}\n{$tusdcLine}\n\n"

                . "🤖 *AutoVest AI Status*\n"
                . "Active and ready. No setup required.\n\n"

                . "✨ *What you can do right now*\n"
                . "• Check your balance or account ID\n"
                . "• Send HBAR, HKSH, or tUSDC to someone\n"
                . "• Top up via M-Pesa or card\n"
                . "• Buy airtime instantly\n"
                . "• Convert between HBAR, HKSH, and tUSDC\n"
                . "• Buy NSE stocks and track your portfolio\n"
                . "• Share feedback anytime\n\n"

                . "➡️ *What would you like to do next?*\n"
                . "Reply with:\n"
                . "`Check balance`, `Buy stocks`, `Top up`, or `Send crypto`";


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



