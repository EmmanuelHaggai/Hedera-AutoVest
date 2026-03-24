<?php
// create-payment-intent2.php

require __DIR__ . '/vendor/autoload.php'; // adjust if your autoload path is different

header('Content-Type: application/json');

// Load env
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

$env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
if ($env === false) {
    http_response_code(500);
    echo json_encode(['error' => 'failed to parse .env']);
    exit;
}

$stripeSecretKey = $env['STRIPE_SECRET_KEY'] ?? '';
if (!$stripeSecretKey) {
    http_response_code(500);
    echo json_encode(['error' => 'STRIPE_SECRET_KEY missing in .env']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$amountCents = isset($input['amount_cents']) ? (int)$input['amount_cents'] : 0;

if ($amountCents <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid amount']);
    exit;
}

try {
    \Stripe\Stripe::setApiKey($stripeSecretKey);

    $paymentIntent = \Stripe\PaymentIntent::create([
        'amount' => $amountCents,
        'currency' => 'usd',
        'description' => 'AutoVest Wallet Top-Up',
        'payment_method_options' => [
            'card' => [
                'request_three_d_secure' => 'any', // or 'automatic'
            ],
        ],
        'metadata' => [
            // You can pass your own identifiers here
            'topup_type' => 'wallet',
            // 'user_id' => '123', // for example
        ],
    ]);

    echo json_encode([
        'clientSecret' => $paymentIntent->client_secret
    ]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected error creating PaymentIntent']);
}
