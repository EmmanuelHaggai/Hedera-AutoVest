<?php
// topup-success2.php

// Get ref from query params (e.g. /cardtopup/success/<ref> -> ?ref=...)
$refCode = isset($_GET['ref']) ? $_GET['ref'] : '';

// Defaults for UI if lookup fails
$linkValid        = false;
$waDisplay        = 'Unknown';
$tokenChoice      = 'HKSH';
$originalAmount   = null;
$amountDisplay    = '0.00';
$status           = 'pending';
$statusMessage    = '';
$whatsappLinkHref = 'https://wa.me/18167908575'; // always talking to AI endpoint

// Try to look up the link in DB if ref is present
if ($refCode !== '') {
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

    $dbHost = env('DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost');
    $dbUser = env('DB_USER') ?: ($env['DB_USER'] ?? 'root');
    $dbPass = env('DB_PASS') ?: ($env['DB_PASS'] ?? '');
    $dbName = env('DB_NAME') ?: ($env['DB_NAME'] ?? 'hedera_ai');

    $db = @mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);

    if ($db) {
        $refEsc = mysqli_real_escape_string($db, $refCode);
        $sql    = "SELECT * FROM autovest_card_topup_links WHERE link_code='{$refEsc}' LIMIT 1";
        $res    = mysqli_query($db, $sql);
        $row    = $res ? mysqli_fetch_assoc($res) : null;

        if ($row) {
            $linkValid      = true;
            $status         = $row['status'] ?? 'pending';
            $tokenChoice    = strtoupper($row['token_choice'] ?? 'HKSH');
            $originalAmount = isset($row['amount_usd']) ? (float)$row['amount_usd'] : null;

            if ($originalAmount !== null) {
                $amountDisplay = number_format($originalAmount, 2, '.', '');
            }

            // Prefer the stored whatsapp_phone, fall back to wa_id
            $phoneE164 = $row['whatsapp_phone'] ?: ltrim($row['wa_id'] ?? '', '+');
            if ($phoneE164) {
                $waDisplay = '+' . $phoneE164;

                // We are always talking to the AI endpoint
                $whatsappLinkHref = 'https://wa.me/18167908575';
            }

            // Build a human message based on status
            if ($status === 'paid') {
                $statusMessage = 'Your card payment has been confirmed and your wallet top-up should now be reflected.';
            } elseif ($status === 'failed') {
                $statusMessage = 'Your payment link shows a failed status. If you were charged, please contact support with your reference.';
            } elseif ($status === 'expired') {
                $statusMessage = 'This card top-up link is marked as expired. If you were charged, please contact support.';
            } elseif ($status === 'wallet_pending') {
                $statusMessage = 'Your card payment was received, but the wallet update is still pending. It will be completed shortly.';
            } else {
                // pending or unknown
                $statusMessage = 'We have received your card payment. Your wallet credit will be applied shortly.';
            }
        }

        mysqli_close($db);
    }
}

// Build extra detail line about amount
$detailAmountLine = '';
if ($linkValid && $originalAmount !== null) {
    $originalFmt = number_format($originalAmount, 2, '.', '');
    $detailAmountLine = "Requested card top-up amount: <strong>\${$originalFmt}</strong>.";
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful | Hedera AutoVest</title>
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
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      margin: 0;
      padding: 20px;
      text-align: center;
    }

    .box {
      background: #2E3A45;
      padding: 32px;
      border-radius: 16px;
      max-width: 420px;
      width: 100%;
      box-shadow: 0 12px 28px rgba(0,0,0,0.4);
      border: 1px solid #4FD1C5;
    }

    h1 {
      font-size: 24px;
      margin-bottom: 10px;
      color: #A8E6E0;
    }

    p {
      font-size: 16px;
      margin-bottom: 16px;
      color: #E0F7F5;
    }

    .summary {
      font-size: 14px;
      margin-bottom: 20px;
      color: #E0F7F5;
      line-height: 1.5;
    }

    .label {
      font-weight: 600;
      color: #A8E6E0;
    }

    .btn {
      display: inline-block;
      padding: 12px 20px;
      background: #00A896;
      color: #FFFFFF;
      text-decoration: none;
      border-radius: 10px;
      font-size: 16px;
      transition: background 0.2s ease;
    }

    .btn:hover {
      background: #00C8A8;
    }

    .note {
      margin-top: 16px;
      font-size: 12px;
      color: #B2DFDB;
    }
  </style>



</head>

<body>

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
                    <a href="https://wa.me/18167908575?text=Hi" target="_blank" rel="noopener noreferrer" class="btn primary">Start on WhatsApp</a>
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
                                    <a href="../../">Home</a>
                                </li>

                                <li class="menu-item">
                                    <a href="https://wa.me/18167908575?text=Hi" target="_blank" rel="noopener noreferrer"> Start on WhatsApp </a>

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
                                        <a href="../../" class="logo">
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



     <div class="box">
    <?php if ($linkValid): ?>
      <h1>Payment Successful 🎉</h1>

      <p>
        Your card payment of
        <strong>$<?= $amountDisplay ?></strong>
        has been received.
      </p>

      <div class="summary">
        <div><span class="label">WhatsApp wallet:</span> <?= htmlspecialchars($waDisplay, ENT_QUOTES, 'UTF-8') ?></div>
        <div><span class="label">Token to be credited:</span> <?= htmlspecialchars($tokenChoice, ENT_QUOTES, 'UTF-8') ?></div>
        <?php if ($detailAmountLine): ?>
          <div style="margin-top:8px;"><?= $detailAmountLine ?></div>
        <?php endif; ?>
        <div style="margin-top:8px;">
          <span class="label">Status:</span>
          <?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
      </div>

      <a class="btn" href="<?= htmlspecialchars($whatsappLinkHref, ENT_QUOTES, 'UTF-8') ?>">
        Return to WhatsApp
      </a>

      <div class="note">
        If your balance does not update within a few minutes, share this page or your reference
        <strong><?= htmlspecialchars($refCode, ENT_QUOTES, 'UTF-8') ?></strong>
        with support on WhatsApp.
      </div>
    <?php else: ?>
      <h1>Payment Received</h1>
      <p>
        We received a payment of <strong>$<?= $amountDisplay ?></strong>, but we could not
        match it to a valid card top-up link.
      </p>
      <p class="summary">
        This can happen if the link has expired or the reference is missing.<br>
        Please return to WhatsApp and contact support if you were charged.
      </p>
      <a class="btn" href="https://wa.me/18167908575">Return to WhatsApp</a>
    <?php endif; ?>
  </div>





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