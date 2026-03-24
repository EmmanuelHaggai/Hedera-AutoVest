<?php
// create-payment-intent2.php

require __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json');

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

$stripeSecretKey = $env['STRIPE_SECRET_KEY'] ?? '';
if (!$stripeSecretKey) {
    http_response_code(500);
    echo json_encode(['error' => 'STRIPE_SECRET_KEY missing in .env']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$amountCents   = isset($input['amount_cents']) ? (int)$input['amount_cents'] : 0;
$refCode       = $input['ref_code'] ?? '';
$tokenChoice   = $input['token_choice'] ?? '';

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
        'description' => 'AutoVest Wallet Top-Up Card',
        'payment_method_options' => [
            'card' => [
                'request_three_d_secure' => 'any',
            ],
        ],
        'metadata' => [
            'topup_type'   => 'wallet_card',
            'ref_code'     => $refCode,
            'token_choice' => $tokenChoice,
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
