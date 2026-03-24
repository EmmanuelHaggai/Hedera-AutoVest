<?php
// card-topup.php

// // // During testing
error_reporting(E_ALL); // Report all types of errors
ini_set('display_errors', 1); // Display errors in the browser
ini_set('display_startup_errors', 1); // Display startup errors

require __DIR__ . '/stripe/vendor/autoload.php';

require_once '/var/www/AutoVest.hedera.co.ke/bootstrap_secrets.php';

try {
    $DEBUG = filter_var(getenv('DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);

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

/**
 * FX + fee config
 *
 * - USD/KES is taken from .env.
 * - HBAR/KES mid and buy quotes are obtained programmatically from your
 *   existing helper functions in v2-functions.php / hedera_functions.php.
 * - HKSH per KES defaults to 1:1 peg, but can be overridden via .env.
 */

// bring in your existing helper functions
require_once '/var/www/aws1/v2-functions.php';
require_once '/var/www/AutoVest.hedera.co.ke/api/callback/hedera_functions.php';

// USD -> KES for card charges (overrideable from .env)
$usdToKesRate     = isset($env['USD_KES_RATE']) ? (float)$env['USD_KES_RATE'] : 130.0;

// Stripe fee configuration
$stripeFeePercent = isset($env['STRIPE_FEE_PERCENT']) ? (float)$env['STRIPE_FEE_PERCENT'] : 2.9;  // %
$stripeFeeFixed   = isset($env['STRIPE_FEE_FIXED_USD']) ? (float)$env['STRIPE_FEE_FIXED_USD'] : 0.30;

// HKSH peg: 1 HKSH = 1 KES by default, but allow override from .env
$hkshPerKes       = isset($env['HKSH_PER_KES']) ? (float)$env['HKSH_PER_KES'] : 1.0;

// HBAR/KES mid and buy quotes from your helper functions
try {
    // use your cached rate with a safe fallback
    $midKesPerHbar = getHbarToKesRateCached(
        __DIR__ . '/hbar_rate_cache.json',  // cache file
        120,                                // cache duration in seconds
        10.0                                // fallback KES per HBAR if everything fails
    );

    // Buy quote with spread (user is buying HBAR from you)
    $buyKesPerHbar = getQuotedRateKesPerHbar(
        'buy',   // side
        150,     // profit_bps
        50,      // buffer_bps
        500      // cap_bps
    );
} catch (Throwable $e) {
    // last-resort hard fallbacks if helpers blow up for some reason
    $midKesPerHbar = 10.0;
    $buyKesPerHbar = 11.0;
}

// Get ref from query string
$ref = $_GET['ref'] ?? '';
$ref = trim($ref);

if ($ref === '') {
    http_response_code(400);
    echo "Missing reference.";
    exit;
}

// Load environment variables from .env
$envPath = '/var/www/AutoVest.hedera.co.ke/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), ';') === 0) continue;

        // Split key=value pairs
        list($key, $value) = array_map('trim', explode('=', $line, 2));
        if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// DB connection (same env as you use elsewhere)
$dbHost = env('DB_HOST') ?: 'localhost';
$dbUser = env('DB_USER') ?: 'root';
$dbPass = env('DB_PASS') ?: '';
$dbName = env('DB_NAME') ?: 'hedera_ai';

$db = mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);
if (mysqli_connect_errno()) {
    http_response_code(500);
    echo "DB error.";
    exit;
}

$refEsc = mysqli_real_escape_string($db, $ref);
$res = mysqli_query(
    $db,
    "SELECT * FROM autovest_card_topup_links WHERE link_code='{$refEsc}' LIMIT 1"
);
$row = $res ? mysqli_fetch_assoc($res) : null;

if (!$row) {
    http_response_code(404);
    echo "Invalid or expired link.";
    exit;
}

if ($row['status'] !== 'pending') {
    echo "This link has already been used or is no longer active.";
    exit;
}

$waPlus      = $row['wa_id'];          // stored as +254...
$phoneE164   = $row['whatsapp_phone']; // e.g. 254715586044
$amountUsd   = (float)$row['amount_usd'];
$tokenChoice = strtoupper($row['token_choice']);

// Sanity defaults
if ($amountUsd <= 0) $amountUsd = 10.00;
if (!in_array($tokenChoice, ['HBAR', 'HKSH'], true)) {
    $tokenChoice = 'HKSH';
}
?><!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Top Up via Card | Hedera AutoVest</title>
    <meta name="description" content="You will be charged in USD. After payment, your selected token will be credited to your AutoVest wallet linked to the WhatsApp number below.">

    <link rel="icon" href="../../inc/assets/images/favicon.png" sizes="32x32">

    <!-- stylesheets - start -->
    <link rel="stylesheet" href="../../inc/assets/dist/style.css">
    <!-- stylesheets - end -->

    <!-- Swiper-link -->
    <link rel="stylesheet" href="../../inc/assets/dist/swiper-bundle.min.css">

    <!-- Load Animate.css from CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">


     <style>


    body {
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .card {
      background: var(--primary-white);
      color: var(--secondary-color);
      border-radius: 16px;
      padding: 24px;
      padding-top: 160px;
      max-width: 480px;
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

    .phone-banner {
      background: #FFF8E1;
      border: 1px solid #FFB300;
      border-radius: 10px;
      padding: 10px 12px;
      margin-bottom: 16px;
      font-size: 14px;
    }

    .phone-big {
      font-size: 18px;
      font-weight: 600;
      margin-top: 4px;
      color: #D84315;
    }

    label {
      font-size: 14px;
      display: block;
      margin-bottom: 6px;
      color: var(--secondary-color);
    }

    input[type="number"], select {
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

    input[type="number"]:focus,
    select:focus {
      border-color: var(--primary-color);
      background: var(--primary-white);
      box-shadow: 0 0 0 1px rgba(0,168,150,0.3);
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

    .estimate-box {
      background: #F0FDF9;
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 13px;
      margin-bottom: 14px;
      border: 1px solid #CFFAF0;
    }

    .estimate-title {
      font-weight: 600;
      margin-bottom: 4px;
      color: #066F46;
    }

    .small-muted {
      font-size: 11px;
      color: #6B7280;
    }
  </style>

</head>

<body onload="particles()">

    <!-- Header-start -->
    <header class="header sticky-nav">
        <div class="container">
            <div class="header-wrapper">
                <div class="header-logo-wrapper">
                    <div class="hamburger direction-right">
                        <div class="hamburger-wrapper">
                            <div class="hamburger-icon">
                                <div class="bars">
                                    <div class="bar"></div>
                                    <div class="bar"></div>
                                    <div class="bar"></div>
                                </div>
                            </div>
                            <div class="hamburger-content">
                                <div class="hamburger-content-header">

                                    <div class="hamburger-close">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                                            <title>window-close</title>
                                            <path
                                                d="M13.46,12L19,17.54V19H17.54L12,13.46L6.46,19H5V17.54L10.54,12L5,6.46V5H6.46L12,10.54L17.54,5H19V6.46L13.46,12Z" />
                                        </svg>
                                    </div>
                                </div>
                                <div class="hamburger-content-inner">
                                    <div class="hamburger-image">
                                        <img src="../../inc/assets/images/publication-img-2.png" alt="Hamburger image"
                                            class="image">
                                    </div>

                                    <h2 class="heading-title">
                                        Your money. Your plan. Automated.
                                    </h2>
                                    <p>
                                        With Hedera AutoVest, anyone can invest in top companies using M-Pesa or crypto without complex apps or paperwork.
                                        Your transactions are fast, secure, and transparently verified on Hedera Hashgraph.
                                    </p>
                                        
                                </div>
                               
                            </div>
                            <div class="hamburger-overlay"></div>
                        </div>
                    </div>
                    <a href="" class="logo">
                        <img class="hide-sticky lightmode-logo" src="../../inc/assets/images/logo.png" alt="AutoVest logo">
                        <img class="hide-sticky darkmode-logo" src="../../inc/assets/images-dark/logo.png" alt="Logo">
                        <img class="show-sticky" src="../../inc/assets/images/logo.png" alt="AutoVest logo">
                    </a>
                </div>

                <div class="nav-btn-block">
                    <div>
                        <input type="checkbox" class="checkbox" id="checkbox">
                        <label for="checkbox" class="checkbox-label">
                            <svg class="moon-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512">
                                <path
                                    d="M223.5 32C100 32 0 132.3 0 256S100 480 223.5 480c60.6 0 115.5-24.2 155.8-63.4c5-4.9 6.3-12.5 3.1-18.7s-10.1-9.7-17-8.5c-9.8 1.7-19.8 2.6-30.1 2.6c-96.9 0-175.5-78.8-175.5-176c0-65.8 36-123.1 89.3-153.3c6.1-3.5 9.2-10.5 7.7-17.3s-7.3-11.9-14.3-12.5c-6.3-.5-12.6-.8-19-.8z" />
                            </svg>
                            <svg class="sun-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                                <path
                                    d="M361.5 1.2c5 2.1 8.6 6.6 9.6 11.9L391 121l107.9 19.8c5.3 1 9.8 4.6 11.9 9.6s1.5 10.7-1.6 15.2L446.9 256l62.3 90.3c3.1 4.5 3.7 10.2 1.6 15.2s-6.6 8.6-11.9 9.6L391 391 371.1 498.9c-1 5.3-4.6 9.8-9.6 11.9s-10.7 1.5-15.2-1.6L256 446.9l-90.3 62.3c-4.5 3.1-10.2 3.7-15.2 1.6s-8.6-6.6-9.6-11.9L121 391 13.1 371.1c-5.3-1-9.8-4.6-11.9-9.6s-1.5-10.7 1.6-15.2L65.1 256 2.8 165.7c-3.1-4.5-3.7-10.2-1.6-15.2s6.6-8.6 11.9-9.6L121 121 140.9 13.1c1-5.3 4.6-9.8 9.6-11.9s10.7-1.5 15.2 1.6L256 65.1 346.3 2.8c4.5-3.1 10.2-3.7 15.2-1.6zM160 256a96 96 0 1 1 192 0 96 96 0 1 1 -192 0zm224 0a128 128 0 1 0 -256 0 128 128 0 1 0 256 0z" />
                            </svg>
                            <span class="ball"></span>
                        </label>
                    </div>
                    <a href="https://wa.me/18167908575?text=Hi+I+want+to+start+investing" target="_blank" rel="noopener noreferrer" class="btn primary">Start on WhatsApp</a>
                </div>
            </div>
        </div>
        <div class="header-menu-wrapper">
            <div class="container">
                <div class="header-wrapper">
                    <div class="header-inner-wrapper">
                        <div class="navigation-menu-wrapper menu-b538310 desktop-wrapper">

                            <ul id="menu-main-menu" class="navigation-menu desktop">
                                <li class="menu-item">
                                    <a href="">Home</a>
                                </li>

                                <li class="menu-item">
                                    <a href="https://wa.me/18167908575?text=Hi+I+want+to+start+investing" target="_blank" rel="noopener noreferrer"> Start on WhatsApp </a>

                                </li>

                                <li class="menu-item">
                                    <a href="https://autovest.solyntra.org/demo/" target="_blank">Watch Demo</a>
                                </li>
                            </ul>
                        </div>

                        <div class="hamburger direction-left">
                            <div class="hamburger-wrapper">
                                <div class="hamburger-icon">
                                    <div class="bars">
                                        <div class="bar"></div>
                                        <div class="bar"></div>
                                        <div class="bar"></div>
                                    </div>
                                </div>
                                <div class="hamburger-content">
                                    <div class="hamburger-content-header">
                                        <a href="" class="logo">
                                            <img class="lightmode-logo" src="../../inc/assets/images/logo.png"
                                                alt="AutoVest logo">
                                            <img class="darkmode-logo" src="../../inc/assets/images-dark/logo.png"
                                                alt="Logo">
                                        </a>
                                        <div class="hamburger-close">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                                                <title>window-close</title>
                                                <path
                                                    d="M13.46,12L19,17.54V19H17.54L12,13.46L6.46,19H5V17.54L10.54,12L5,6.46V5H6.46L12,10.54L17.54,5H19V6.46L13.46,12Z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="navigation-menu-wrapper">
                                        <ul class="navigation-menu mobile">

                                            <li class="menu-item">
                                                <a href="">Home</a>
                                            </li>

                                            <li class="menu-item">
                                                <a href="https://wa.me/18167908575?text=Hi" target="_blank" rel="noopener noreferrer">Start on WhatsApp</a>
                                            </li>

                                            <li class="menu-item">
                                                <a href="https://autovest.solyntra.org/demo/" target="_blank">Watch Demo</a>
                                            </li>
                                        </ul>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
                    
                    
                </div>
            </div>
        </div>






    </header>
    <!-- Header-end -->








     <div class="card"
       data-ref="<?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?>"
       data-usd-to-kes="<?= htmlspecialchars($usdToKesRate, ENT_QUOTES, 'UTF-8') ?>"
       data-stripe-fee-pct="<?= htmlspecialchars($stripeFeePercent, ENT_QUOTES, 'UTF-8') ?>"
       data-stripe-fee-fixed="<?= htmlspecialchars($stripeFeeFixed, ENT_QUOTES, 'UTF-8') ?>"
       data-mid-kes-per-hbar="<?= htmlspecialchars($midKesPerHbar, ENT_QUOTES, 'UTF-8') ?>"
       data-buy-kes-per-hbar="<?= htmlspecialchars($buyKesPerHbar, ENT_QUOTES, 'UTF-8') ?>"
       data-hksh-per-kes="<?= htmlspecialchars($hkshPerKes, ENT_QUOTES, 'UTF-8') ?>"
  >
    <h1>Top Up via Card</h1>
    <p>
      You will be charged in USD. After payment, your selected token will be
      credited to your AutoVest wallet linked to the WhatsApp number below.
    </p>

    <div class="phone-banner">
      <div>Destination WhatsApp number (for HBAR / HKSH credit):</div>
      <div class="phone-big">
        +<?= htmlspecialchars($phoneE164, ENT_QUOTES, 'UTF-8') ?>
      </div>
      <div class="small-muted">
        Please confirm this matches your WhatsApp. If not, cancel and start again.
      </div>
    </div>

    <form id="payment-form">
      <label for="amount-usd">Amount (USD)</label>
      <input
        type="number"
        id="amount-usd"
        name="amount-usd"
        min="1"
        step="0.01"
        required
        value="<?= htmlspecialchars(number_format($amountUsd, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
      />

      <label for="token-choice">Token to receive</label>
      <select id="token-choice" name="token-choice">
        <option value="HKSH" <?= $tokenChoice === 'HKSH' ? 'selected' : '' ?>>HKSH</option>
        <option value="HBAR" <?= $tokenChoice === 'HBAR' ? 'selected' : '' ?>>HBAR</option>
      </select>

      <div class="estimate-box">
        <div class="estimate-title">Estimated tokens you will receive</div>
        <div id="estimate-output">Enter an amount to see the estimate.</div>
        <div class="small-muted">
          Final amount may differ slightly due to exchange rates and network fees.
        </div>
      </div>

      <div id="card-element"></div>

      <button id="submit">Pay with Card</button>
      <div id="message"></div>
    </form>
  </div>

  <script src="https://js.stripe.com/v3/"></script>
  <script>
    const stripe = Stripe("<?= htmlspecialchars($stripePublishableKey, ENT_QUOTES, 'UTF-8') ?>");
    const elements = stripe.elements();

    const cardElement = elements.create('card', {
      hidePostalCode: true,
      style: {
        base: {
          color: '#2E3A45',
          fontSize: '14px',
          '::placeholder': {
            color: '#8B939A'
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
    const tokenSelect = document.getElementById('token-choice');
    const estimateOutput = document.getElementById('estimate-output');

    const cardDiv = document.querySelector('.card');
    const refCode          = cardDiv.dataset.ref;
    const usdToKesRate     = parseFloat(cardDiv.dataset.usdToKes);
    const stripeFeePct     = parseFloat(cardDiv.dataset.stripeFeePct);
    const stripeFeeFixed   = parseFloat(cardDiv.dataset.stripeFeeFixed);
    const midKesPerHbar    = parseFloat(cardDiv.dataset.midKesPerHbar);
    const buyKesPerHbar    = parseFloat(cardDiv.dataset.buyKesPerHbar);
    const hkshPerKes       = parseFloat(cardDiv.dataset.hkshPerKes);

    function formatNumber(x, decimals) {
      return Number(x).toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
      });
    }

    function updateEstimate() {
      const amountUsd = parseFloat(amountInput.value);
      if (isNaN(amountUsd) || amountUsd <= 0) {
        estimateOutput.textContent = 'Enter an amount to see the estimate.';
        return;
      }

      const token = tokenSelect.value;

      const fee = (amountUsd * (stripeFeePct / 100.0)) + stripeFeeFixed;
      const netUsd = Math.max(amountUsd - fee, 0);

      const kesAmount = netUsd * usdToKesRate;

      let line1 = 'Gross: $' + formatNumber(amountUsd, 2)
                + ' | Stripe fees: ~$' + formatNumber(fee, 2)
                + ' | Net: $' + formatNumber(netUsd, 2);

      let line2 = '';
      if (token === 'HBAR') {
        const hbar = kesAmount / buyKesPerHbar;
        line2 = 'Approx. ' + formatNumber(hbar, 6) + ' HBAR will be credited.';
      } else {
        const hksh = kesAmount * hkshPerKes;
        line2 = 'Approx. ' + formatNumber(hksh, 2) + ' HKSH will be credited.';
      }

      estimateOutput.innerHTML = line1 + '<br>' + line2 + '<br>'
          + 'Using KES/HBAR mid: ' + formatNumber(midKesPerHbar, 4)
          + ', buy quote: ' + formatNumber(buyKesPerHbar, 4)
          + ', FX: ' + formatNumber(usdToKesRate, 2) + ' KES per USD.';
    }

    amountInput.addEventListener('input', updateEstimate);
    tokenSelect.addEventListener('change', updateEstimate);

    updateEstimate();

    async function createPaymentIntent(amountUsd, tokenChoice, refCode) {
      const amountCents = Math.round(amountUsd * 100);

      const res = await fetch('../../stripe/create-payment-intent2.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amount_cents: amountCents,
          ref_code: refCode,
          token_choice: tokenChoice
        })
      });

      if (!res.ok) {
        throw new Error('Failed to create PaymentIntent');
      }

      return res.json();
    }

    async function finalizeOnChain(refCode, paymentIntentId) {
      const res = await fetch('/card-topup-complete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          ref_code: refCode,
          payment_intent_id: paymentIntentId
        })
      });

      if (!res.ok) {
        throw new Error('Failed to finalize on-chain top-up');
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

      const tokenChoice = tokenSelect.value;

      try {
        const { clientSecret, error: backendError } =
          await createPaymentIntent(amountUsd, tokenChoice, refCode);

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
          message.textContent = 'Payment confirmed. Finalizing your on-chain top up...';

          try {
            const finalizeRes = await finalizeOnChain(refCode, result.paymentIntent.id);

            if (!finalizeRes.ok) {
              message.textContent =
                finalizeRes.error ||
                'Payment successful, but we could not complete the on-chain transfer. Please check WhatsApp for details.';
            } else {
              message.textContent = 'Top up successful. Redirecting...';
            }
          } catch (finalizeErr) {
            console.error(finalizeErr);
            message.textContent =
              'Payment successful, but an error occurred while finalizing your on-chain top up. Please check WhatsApp for details.';
          }

          window.location.href =
            '../../cardtopup/success/' + encodeURIComponent(refCode);
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


    <button class="scrollToTopBtn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
            <title>arrow-up</title>
            <path d="M13,20H11V8L5.5,13.5L4.08,12.08L12,4.16L19.92,12.08L18.5,13.5L13,8V20Z"></path>
        </svg>
    </button>
    <!-- Swiper JS -->
    <script src="../../inc/assets/js/swiper-bundle.min.js"></script>
    <!-- Chart js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.9.4/Chart.js"></script>

    <script src="../../inc/assets/js/ab-particles.min.js"></script>
    <!-- Load WOW.js from CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/wow/1.1.2/wow.min.js"></script>
    <!--  scripts - start -->
    <script src="../../inc/assets/dist/app.js" defer></script>
    <!--  scripts - end -->



</body>

</html>