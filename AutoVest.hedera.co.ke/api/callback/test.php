<?php

// // // During testing
// error_reporting(E_ALL); // Report all types of errors
// ini_set('display_errors', 1); // Display errors in the browser
// ini_set('display_startup_errors', 1); // Display startup errors

require_once '/var/www/aws1/v2-functions.php';
require_once '/var/www/AutoVest.hedera.co.ke/api/callback/hedera_functions.php';

// Log MQR requests only 

if (!empty($original_raw_date)) {
    $file = 'log-whats-AutoVest_hook.txt';  
    $data =json_encode($original_raw_date)."\n";  
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


// $message_from = "254715586044";
// $feedback = "Hello from Hedera AutoVest";
// $whatsapp_reciever = "+".$message_from;
// $whatsapp_feedback = custom_AutoVest_text_whatsapp($whatsapp_reciever,$feedback);


// // To change the hidden whatsapp update
// curl -X POST "https://graph.facebook.com/v20.0/887649351092468/whatsapp_business_profile" \
//   -H "Authorization: Bearer EAAKqEfrkE8kBOxwtypziDqCHA807N0263rJ2HAY5iAmZAM4fKDxA4kQJmSHgvZBB7OxjZARGk83ZBmIFqqm5fI6z4rqjmvaNIsgdq5fmVYDbvB1UnfErryZCOqmZAnNuaj4e7FmvuA5A4wxVbmCIHKXpVYQtxp6CvWxm7y0LpURfPFQqVt2H9FOiZBIHm6adhaBVwZDZD" \
//   -H "Content-Type: application/json" \
//   -d '{
//     "messaging_product": "whatsapp",
//     "about": "Built on Hedera • Backed by Real-Time Insights"
//   }'


// True to include the stock information
// echo getSystemStatsSummary_AutoVest_Hedera(true);

// if (!shouldIncludeNSEData("Hello")) {
//     echo "Include NSE data";
// } else {
//     echo "No need for NSE data";
// }


$wa_id = "+254715586044";
$user_prompt = "The latest prompt for testing..";
$final_prompt = build_prompt_with_history_AutoVest($wa_id, $user_prompt);
d($final_prompt);



// //hedera functions
// - create account - testnet Account
// - fund the testnet Account
// - function to create token - save details to db
// - function to mint this token - save details to token db and transactions db 
// - function to send hbar from one account to another
// - create transactions topic
// - function to save a message to this topic

// // test creating account
// $payload = json_encode(["initialHbar" => 1.5]);
// $ch = curl_init("http://127.0.0.1:5050/accounts");
// curl_setopt_array($ch, [
//   CURLOPT_RETURNTRANSFER => true,
//   CURLOPT_POST => true,
//   CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
//   CURLOPT_POSTFIELDS => $payload
// ]);
// $resp = curl_exec($ch);
// curl_close($ch);
// echo $resp;

// Responce  
// {"ok":true,"accountId":"0.0.7162489","publicKey":"fe9e40cbea51949511c7416e041db59ffe8a06e35828969a59eae6de37bc3314","privateKey":"ddb4461a9de2702779a21b20bc68b6682469799949d0a7b57180ccf97930add1","txId":"0.0.7100232@1761846499.672154888","status":"SUCCESS"}



// // Fund an account:
// $payload = json_encode([
//   "toAccountId" => "0.0.1234567",
//   "hbar" => 2.0,
//   "memo" => "seed test user"
// ]);
// $ch = curl_init("http://127.0.0.1:5050/fund");
// curl_setopt_array($ch, [
//   CURLOPT_RETURNTRANSFER => true,
//   CURLOPT_POST => true,
//   CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
//   CURLOPT_POSTFIELDS => $payload
// ]);
// $resp = curl_exec($ch);
// curl_close($ch);
// echo $resp;

// Responce
// {"ok":true,"txId":"0.0.7100232@1761846724.858957894","status":"SUCCESS"}


// // Create a fungible token:
// $payload = json_encode([
//   "name" => "HKSH Credits",
//   "symbol" => "HKSH",
//   "decimals" => 2,
//   "initialSupply" => 0,
//   "supplyType" => "FINITE",
//   "maxSupply" => 1000000000000,
//   // "treasuryAccountId" => "0.0.x" // optional
// ]);
// $ch = curl_init("http://127.0.0.1:5050/tokens");
// curl_setopt_array($ch, [
//   CURLOPT_RETURNTRANSFER => true,
//   CURLOPT_POST => true,
//   CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
//   CURLOPT_POSTFIELDS => $payload
// ]);
// $resp = curl_exec($ch);
// curl_close($ch);
// echo $resp;

// Responce
// {"ok":true,"tokenId":"0.0.7162525","status":"SUCCESS","supplyKey":"34711095be5a5f2f42566781c29bd808f2f515d84845dc275344b08de5c8aa95"}



// // Associate a token (e.g., HKSH) with a Hedera account
// $accountId = "0.0.7162489";       // the account to associate the token with
// $privateKey = "ddb4461a9de2702779a21b20bc68b6682469799949d0a7b57180ccf97930add1"; // private key of that account
// $tokenId = "0.0.7162525";         // HKSH token ID you created

// $payload = json_encode([
//   "accountId" => $accountId,
//   "privKey"   => $privateKey,
//   "tokenId"   => $tokenId
// ]);

// $ch = curl_init("http://127.0.0.1:5050/tokens/associate");
// curl_setopt_array($ch, [
//   CURLOPT_RETURNTRANSFER => true,
//   CURLOPT_POST           => true,
//   CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
//   CURLOPT_POSTFIELDS     => $payload
// ]);

// $response = curl_exec($ch);
// $error = curl_error($ch);
// curl_close($ch);

// if ($error) {
//   echo "❌ cURL Error: $error\n";
// } else {
//   echo "✅ Response from Hedera API:\n";
//   echo $response . "\n";

//   $json = json_decode($response, true);
//   if (!empty($json['txId'])) {
//     echo "\n🔗 Association successful!\n";
//     echo "Transaction ID: " . $json['txId'] . "\n";
//     echo "View on HashScan:\n";
//     echo "https://hashscan.io/testnet/transaction/" . urlencode($json['txId']) . "\n";
//   }
// }

// Responce
// {"ok":true,"topicId":"0.0.7162539","status":"SUCCESS","txId":"0.0.7100232@1761847267.449619056"}{"ok":true,"txId":"0.0.7100232@1761847268.905197210","status":"SUCCESS","sequenceNumber":1}



// // Mint fungible supply:
// $payload = json_encode([
//   "tokenId" => "0.0.7162525",
//   "amount" => 50000
//   // "supplyKey" => "..." // optional; falls back to DB
// ]);
// $ch = curl_init("http://127.0.0.1:5050/tokens/mint");
// curl_setopt_array($ch, [
//   CURLOPT_RETURNTRANSFER => true,
//   CURLOPT_POST => true,
//   CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
//   CURLOPT_POSTFIELDS => $payload
// ]);
// $resp = curl_exec($ch);
// curl_close($ch);
// echo $resp;

// Responce
// {"ok":true,"txId":"0.0.7100232@1761847045.057961692","status":"SUCCESS","serials":[]}


// // Create a topic and submit a message:
// // create topic
// $ch = curl_init("http://127.0.0.1:5050/topics");
// curl_setopt_array($ch, [
//   CURLOPT_RETURNTRANSFER => true,
//   CURLOPT_POST => true,
//   CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
//   CURLOPT_POSTFIELDS => json_encode(["memo" => "AutoVest tx log"])
// ]);
// $topicResp = curl_exec($ch); curl_close($ch);
// echo $topicResp;

// // submit message
// $topic = json_decode($topicResp, true)["topicId"];
// $ch = curl_init("http://127.0.0.1:5050/topics/message");
// curl_setopt_array($ch, [
//   CURLOPT_RETURNTRANSFER => true,
//   CURLOPT_POST => true,
//   CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
//   CURLOPT_POSTFIELDS => json_encode(["topicId" => $topic, "message" => "Hello, Hedera!"])
// ]);
// echo curl_exec($ch); curl_close($ch);

// Responce
// {"ok":true,"topicId":"0.0.7162539","status":"SUCCESS","txId":"0.0.7100232@1761847267.449619056"}
// {"ok":true,"txId":"0.0.7100232@1761847268.905197210","status":"SUCCESS","sequenceNumber":1}
// https://hashscan.io/testnet/transaction/0.0.7100232@1761847267.449619056







//--------------------------------------------------------------------------------------------------------------------------------------
// Hedera balance checker (HBAR + HKSH token) via Mirror Node

// $account_id = "0.0.7162489";
// $hksh_token_id = getenv('HKSH_TOKEN_ID') ?: '0.0.XXXXXXX';
// $balances = getHederaBalances($account_id, 'testnet', $hksh_token_id);

// if ($balances['ok']) {
//     echo $balances['feedback'];
// } else {
//     echo "Error: " . $balances['error'];
// }
//------------------------------------------------------------------------------------------------------------------------------------------



// //Test currency conversions
// try {
//     $kes = 1000;
//     $hbar = convertKesToHbar($kes);
//     $kesBack = convertHbarToKes($hbar);
//     $hksh = convertHbarToHKSH(2.5); // assumes 1 HKSH = 1 KES
//     echo "KES {$kes} ≈ {$hbar} HBAR; 2.5 HBAR ≈ {$hksh} HKSH\n";
// } catch (Throwable $e) {
//     // handle error
// }