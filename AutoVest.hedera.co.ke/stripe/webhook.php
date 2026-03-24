<?php
// stripe/webhook.php

// For debugging in dev; disable in production
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require __DIR__ . '/vendor/autoload.php';

// Load env
$envPath = '/var/www/AutoVest.hedera.co.ke/.env';
if (!file_exists($envPath)) {
    http_response_code(500);
    echo 'Env file not found';
    exit;
}

$env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
if ($env === false) {
    http_response_code(500);
    echo 'Failed to parse .env';
    exit;
}

$stripeSecretKey    = $env['STRIPE_SECRET_KEY']    ?? '';
$stripeWebhookSecret = $env['STRIPE_WEBHOOK_SECRET'] ?? '';

if (!$stripeSecretKey || !$stripeWebhookSecret) {
    http_response_code(500);
    echo 'Stripe keys missing in .env';
    exit;
}

\Stripe\Stripe::setApiKey($stripeSecretKey);

// Grab payload and signature from headers
$payload    = @file_get_contents('php://input');
$sigHeader  = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!$payload || !$sigHeader) {
    http_response_code(400);
    echo 'Missing payload or signature';
    exit;
}

// Verify event
try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sigHeader,
        $stripeWebhookSecret
    );
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    echo 'Invalid signature';
    exit;
} catch (\Throwable $e) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

// Handle only payment_intent.succeeded for card top-ups
if ($event->type === 'payment_intent.succeeded') {
    /** @var \Stripe\PaymentIntent $intent */
    $intent = $event->data->object;

    $paymentIntentId = $intent->id;
    $metadata        = $intent->metadata ?? new \stdClass();
    $refCode         = isset($metadata->ref_code) ? (string)$metadata->ref_code : '';

    // Only process if we have a ref_code from the card-topup flow
    if ($refCode !== '') {
        // Call the same backend logic as the front-end uses:
        // stripe/card-topup-complete.php
        $baseUrl = $env['AUTOVEST_BASE_URL'] ?? 'https://autovest.hedera.co.ke';
        $endpoint = rtrim($baseUrl, '/') . '/stripe/card-topup-complete.php';

        $postBody = json_encode([
            'ref_code'          => $refCode,
            'payment_intent_id' => $paymentIntentId,
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $postBody,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Log but always return 200 to Stripe so it doesn't keep retrying forever.
        if ($err || $code >= 400) {
            error_log('card-topup-complete error: HTTP ' . $code . ' err=' . $err . ' resp=' . $resp);
        }
    }
}

// Respond to Stripe
http_response_code(200);
echo 'ok';
