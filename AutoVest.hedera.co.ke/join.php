<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1, viewport-fit=cover"
  />
  <title>Join Hedera Innovators Club</title>
  <meta name="format-detection" content="telephone=no,email=no,address=no" />
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; padding: 2rem; }
    .btn { display:inline-block; padding:.8rem 1.2rem; border:1px solid #111; border-radius:8px; text-decoration:none; }
    .muted { color:#555; font-size:.95rem; margin-top:1rem; }
  </style>
  <script>
    // 1) Configure your WhatsApp invite link here
    // Use the official format from WhatsApp
    // Example: https://chat.whatsapp.com/KBeFL6IhRb6EioOxmtJXtT
    const INVITE_LINK = "https://chat.whatsapp.com/KBeFL6IhRb6EioOxmtJXtT";

    // 2) Fallbacks
    const ANDROID_STORE = "https://play.google.com/store/apps/details?id=com.whatsapp";
    const IOS_STORE     = "https://apps.apple.com/app/whatsapp-messenger/id310633997";
    const DESKTOP_LP    = "https://whatsapp.hedera.co.ke/"; // optional landing page for desktop

    // Helper: UA checks
    function isiOS()      { return /iPad|iPhone|iPod/i.test(navigator.userAgent); }
    function isAndroid()  { return /Android/i.test(navigator.userAgent); }
    function isFacebook() { return /FBAN|FBAV|FB_IAB|FBAN\/Messenger|Instagram/i.test(navigator.userAgent); }
    function isDesktop()  { return !isiOS() && !isAndroid(); }

    // Detect if the page was backgrounded (user switched to WhatsApp)
    function createVisibilityWatcher(cancelFallback) {
      const handler = () => {
        if (document.visibilityState === "hidden") cancelFallback();
      };
      document.addEventListener("visibilitychange", handler, { once: true });
    }

    // Attempt to open WhatsApp with sensible fallbacks
    function openWhatsApp() {
      // If desktop, send to info page or show manual link
      if (isDesktop()) {
        // You can change to INVITE_LINK if you prefer web WhatsApp to prompt
        window.location.replace(DESKTOP_LP);
        return;
      }

      // Set a fallback timer that fires only if the app did not open
      const storeURL = isiOS() ? IOS_STORE : ANDROID_STORE;
      let didCancel = false;

      const cancelFallback = () => { didCancel = true; };
      createVisibilityWatcher(cancelFallback);

      // Facebook and Instagram in-app browsers sometimes need a user gesture.
      // We still try programmatic open first, and provide a manual button below.
      const attemptOpen = () => {
        // Preferred: use the official https invite link
        window.location.href = INVITE_LINK;

        // Extra nudge for some Android webviews that ignore first navigation
        if (isAndroid()) {
          setTimeout(() => {
            try {
              // Using a hidden iframe to poke the OS intent resolver
              const iframe = document.createElement("iframe");
              iframe.style.display = "none";
              // A harmless deep scheme that wakes WhatsApp if installed
              iframe.src = "whatsapp://send";
              document.body.appendChild(iframe);
              setTimeout(() => iframe.remove(), 2000);
            } catch (_) {}
          }, 250);
        }
      };

      // Start the attempt
      attemptOpen();

      // Fallback to store only if the page stayed visible
      const FALLBACK_MS = 2200;
      setTimeout(() => {
        if (!didCancel) {
          window.location.href = storeURL;
        }
      }, FALLBACK_MS);
    }

    // Provide a manual open function for the button
    function manualOpen() {
      // A user gesture often bypasses stricter in-app browser limits
      window.location.href = INVITE_LINK;
    }

    // Auto-run on load
    window.addEventListener("load", openWhatsApp);
  </script>
</head>
<body>
  <h1>Opening WhatsApp…</h1>
  <p>If nothing happens in about two seconds, tap the button below.</p>
  <p>
    <a class="btn" href="javascript:void(0)" onclick="manualOpen()">Open in WhatsApp</a>
  </p>
  <p class="muted">
    Direct join link:
    <a href="https://chat.whatsapp.com/KBeFL6IhRb6EioOxmtJXtT">
      https://chat.whatsapp.com/KBeFL6IhRb6EioOxmtJXtT
    </a>
  </p>
  <p class="muted">
    If WhatsApp is not installed, you will be sent to the app store automatically.
  </p>
</body>
</html>
