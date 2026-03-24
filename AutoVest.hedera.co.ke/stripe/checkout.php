<?php
// // During testing
error_reporting(E_ALL); // Report all types of errors
ini_set('display_errors', 1); // Display errors in the browser
ini_set('display_startup_errors', 1); // Display startup errors

require __DIR__ . "/vendor/autoload.php";

// Load env
////////////////////////////////////////////////////////////
$envPath = '/var/www/AutoVest.hedera.co.ke/.env';
if (!file_exists($envPath)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'.env not found']); exit; }
$env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
if ($env === false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'failed to parse .env']); exit; }

$stripe_secret_key = $env['STRIPE_SECRET_KEY'] ?? ' ';


\Stripe\Stripe::setApiKey($stripe_secret_key);

$amount_in_cents = "2000";
$description = "Add funds to your wallet using Stripe. Your balance will be updated in HBAR once the payment is confirmed.";
//"Securely add funds to your AutoVest wallet. You’ll be charged in USD, and your balance will be credited in HKSH (pegged 1:1 to Kenyan shillings)."


$checkout_session = \Stripe\Checkout\Session::create([
    "mode" => "payment",
    "success_url" => "http://AutoVest.hedera.co.ke/stripe/success.php",
    "cancel_url" => "http://AutoVest.hedera.co.ke/stripe/index.php",
    "locale" => "auto",
    "line_items" => [
        [
            "quantity" => 1,
            "price_data" => [
                "currency" => "usd",
                "unit_amount" => $amount_in_cents,
                "product_data" => [
                    "name" => "AutoVest Wallet Top-Up",
                    "description" => "Securely add funds to your AutoVest wallet. You’ll be charged in USD, and your balance will be credited in HKSH (pegged 1:1 to Kenyan shillings)."
                ]
            ]
        ]
        
    ]
]);

http_response_code(303);
header("Location: " . $checkout_session->url);