<?php
$amount = isset($_GET['amount']) ? htmlspecialchars($_GET['amount']) : '0.00';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Payment Successful</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <style>
    body {
      background: #242A2E;
      color: #FFFFFF;
      font-family: system-ui, sans-serif;
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
      max-width: 360px;
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
      margin-bottom: 24px;
      color: #E0F7F5;
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
  </style>
</head>
<body>

  <div class="box">
    <h1>Payment Successful 🎉</h1>
    <p>Your wallet top-up of <strong>$<?= $amount ?></strong> has been received.</p>

    <a class="btn" href="https://wa.me/254715586044">Return to WhatsApp</a>
  </div>

</body>
</html>
