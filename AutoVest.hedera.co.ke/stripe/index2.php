<?php
// index2.php

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

$stripePublishableKey = $env['STRIPE_PUBLISHABLE_KEY'] ?? '';
if (!$stripePublishableKey) {
    http_response_code(500);
    echo "STRIPE_PUBLISHABLE_KEY missing in .env";
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>AutoVest Wallet Top-Up</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <style>
    :root {
      --light-primary-color: #A8E6E0;
      --primary-color: #00A896;
      --primary-color-2: #00C8A8;
      --primary-color-3: #4FD1C5;
      --secondary-color: #2E3A45;
      --secondary-color-2: #242A2E;
      --tertiary-color: #6C757D;
      --tertiary-color-2: #8B939A;
      --tertiary-color-3: #A3A9AF;
      --primary-white: #FFFFFF;
      --main-white: #FFFFFF;
      --title-color: #1F1F1F;
      --primary-gray: #F5F8FA;
      --main-gray: #EBEFF3;
      --primary-light-gray: #EBEFF3;
      --primary-light-gray-2: #C8D0D5;
      --primary-light-gray-3: #D8DCDF;
      --bg-black-transparent: rgba(0, 0, 0, 0.7);
      --border-radius: 0.4rem;
    }

    * {
      box-sizing: border-box;
    }

    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: var(--secondary-color-2);
      color: var(--title-color);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      margin: 0;
      padding: 16px;
    }

    .card {
      background: var(--primary-white);
      border-radius: 16px;
      padding: 24px;
      max-width: 420px;
      width: 100%;
      box-shadow: 0 18px 40px rgba(0,0,0,0.25);
      border: 1px solid var(--primary-light-gray-3);
    }

    h1 {
      font-size: 20px;
      margin: 0 0 8px;
      color: var(--secondary-color);
    }

    p {
      font-size: 14px;
      margin: 0 0 16px;
      color: var(--tertiary-color);
    }

    label {
      font-size: 14px;
      display: block;
      margin-bottom: 6px;
      color: var(--secondary-color);
    }

    input[type="number"] {
      width: 100%;
      padding: 8px 10px;
      border-radius: 8px;
      border: 1px solid var(--primary-light-gray-2);
      background: var(--primary-gray);
      color: var(--secondary-color);
      margin-bottom: 12px;
      font-size: 14px;
      outline: none;
    }

    input[type="number"]::placeholder {
      color: var(--tertiary-color-2);
    }

    input[type="number"]:focus {
      border-color: var(--primary-color);
      background: var(--primary-white);
      box-shadow: 0 0 0 1px rgba(0,168,150,0.3);
    }

    #payment-form {
      margin-top: 12px;
    }

    #card-element {
      padding: 10px 8px;
      border-radius: 8px;
      background: var(--primary-gray);
      border: 1px solid var(--primary-light-gray-2);
      margin-bottom: 16px;
    }

    button {
      width: 100%;
      border: none;
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 15px;
      cursor: pointer;
      background: var(--primary-color);
      color: var(--primary-white);
      transition: background 0.18s ease, transform 0.1s ease;
    }

    button:hover:not(:disabled) {
      background: var(--primary-color-2);
      transform: translateY(-1px);
    }

    button:disabled {
      opacity: 0.6;
      cursor: default;
      transform: none;
    }

    #message {
      margin-top: 12px;
      font-size: 13px;
      min-height: 18px;
      color: var(--tertiary-color);
    }
  </style>
</head>
<body>
  <div class="card">
    <h1>Top Up AutoVest Wallet</h1>
    <p>
      Enter your card details to securely add funds. You’ll be charged in USD and
      your HKSH / HBAR balance will update inside AutoVest after payment.
    </p>

    <form id="payment-form">
      <label for="amount-usd">Amount (USD)</label>
      <input
        type="number"
        id="amount-usd"
        name="amount-usd"
        min="1"
        step="0.01"
        placeholder="10.00"
        required
      />

      <div id="card-element"></div>

      <button id="submit">Pay</button>
      <div id="message"></div>
    </form>
  </div>

  <script src="https://js.stripe.com/v3/"></script>
  <script>
    const stripe = Stripe("<?= htmlspecialchars($stripePublishableKey, ENT_QUOTES, 'UTF-8') ?>");

    const elements = stripe.elements();

    // Styled card element so text and placeholder are visible
    const cardElement = elements.create('card', {
      hidePostalCode: true,
      style: {
        base: {
          color: '#2E3A45',                // secondary-color
          fontSize: '14px',
          '::placeholder': {
            color: '#8B939A'              // tertiary-color-2
          }
        },
        invalid: {
          color: '#e53935',
          iconColor: '#e53935'
        }
      }
    });

    cardElement.mount('#card-element');

    const form = document.getElementById('payment-form');
    const message = document.getElementById('message');
    const submitBtn = document.getElementById('submit');
    const amountInput = document.getElementById('amount-usd');

    async function createPaymentIntent(amountUsd) {
      const amountCents = Math.round(amountUsd * 100);

      const res = await fetch('create-payment-intent2.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ amount_cents: amountCents })
      });

      if (!res.ok) {
        throw new Error('Failed to create PaymentIntent');
      }

      return res.json();
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      message.textContent = '';
      submitBtn.disabled = true;

      const amountUsd = parseFloat(amountInput.value);
      if (isNaN(amountUsd) || amountUsd <= 0) {
        message.textContent = 'Please enter a valid amount in USD.';
        submitBtn.disabled = false;
        return;
      }

      try {
        const { clientSecret, error: backendError } = await createPaymentIntent(amountUsd);

        if (backendError) {
          message.textContent = backendError;
          submitBtn.disabled = false;
          return;
        }

        const result = await stripe.confirmCardPayment(clientSecret, {
          payment_method: {
            card: cardElement
          }
        });

        if (result.error) {
          message.textContent = result.error.message || 'Payment failed. Please try again.';
          submitBtn.disabled = false;
          return;
        }

        if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
             message.textContent = 'Payment successful. Your AutoVest wallet will be updated shortly.';
             
            // redirect
            window.location.href = 'topup-success2.php?amount=' + encodeURIComponent(amountUsd.toFixed(2));
        } else {
          message.textContent = 'Payment processing. Please wait.';
        }
      } catch (err) {
        console.error(err);
        message.textContent = 'Something went wrong. Please try again.';
        submitBtn.disabled = false;
      }
    });
  </script>
</body>
</html>
