Single-file PHP SDK + client-side checkout launcher integration for Gurutvapay.
This repository contains:

gurutvapay_client.php — a tiny, single-file PHP SDK that:

logs in via OAuth password grant (/uat_mode/login or /live/login);

initiates payments (/uat_mode/initiate-payment or /live/initiate-payment);

returns the gateway token and payment_url you need to open the checkout.

Two thin checkout launcher scripts you can host (or use from the gateway):

gurutvapay-uat.js — opens https://uat-pay.gurutvapay.com/payment?token=...

gurutvapay-live.js — opens https://payment.gurutvapay.com/payment?token=...

Example HTML showing how to include the scripts and start checkout by passing the token.

Use this README to install, import, and wire the server (PHP SDK) → client (JS) flow end-to-end.

Contents

gurutvapay_client.php — PHP SDK (single file)

checkout.html — example page demonstrating UAT & LIVE checkout launch

gurutvapay-uat.js / gurutvapay-live.js — launcher scripts (host them in /static/ or use provided CDN path)

README.md — this file

Quick summary (how it works)

Server (PHP) uses gurutvapay_client.php to:

login (password grant) or use API key,

create a payment (order) via initiatePayment(...).

Gateway returns a token (e.g. pay_8fc13996...) and payment_url.

Server renders a page (or returns JSON) containing the token.

Client (browser) includes gurutvapay-uat.js or gurutvapay-live.js and calls GurutvapayUat.launch(token) (or GurutvapayLive.launch(token)).

The script opens the checkout page in a popup (fallback redirect).

Requirements

PHP 7.4+ (works on PHP 8.x)

curl extension enabled

HTTPS for production

Keep credentials (client_secret/api_key/webhook secret) in environment variables or a secret manager

Installation

Copy gurutvapay_client.php into your project, e.g. lib/gurutvapay_client.php.

Host gurutvapay-uat.js and gurutvapay-live.js under your public folder (e.g. /public/js/) or use your provided gateway static URLs:

<script src="https://api.gurutvapay.com/static/gurutvapay-uat.js"></script>
<script src="https://api.gurutvapay.com/static/gurutvapay-live.js"></script>

Configuration — environment variables (recommended)

Store secrets in environment variables:

# for UAT
GURUTVA_ENV=uat
GURUTVA_CLIENT_ID=CLIENT_12345
GURUTVA_CLIENT_SECRET=SECRET_67890
GURUTVA_USERNAME=john@example.com
GURUTVA_PASSWORD=your_password
GURUTVA_WEBHOOK_SECRET=supersecret

# for LIVE (use different values)
GURUTVA_ENV=live
GURUTVA_CLIENT_ID=LIVE_CLIENT_...
GURUTVA_CLIENT_SECRET=LIVE_SECRET_...
GURUTVA_USERNAME=...
GURUTVA_PASSWORD=...

Using the PHP SDK: examples
1) Basic flow (UAT) — login then initiate payment
<?php
require_once __DIR__ . '/lib/gurutvapay_client.php';

// Create SDK instance for UAT
$uatConfig = [
    'client_id' => getenv('GURUTVA_CLIENT_ID'),
    'client_secret' => getenv('GURUTVA_CLIENT_SECRET'),
    'username' => getenv('GURUTVA_USERNAME'),
    'password' => getenv('GURUTVA_PASSWORD'),
];

$sdk = new GurutvaPay('uat', $uatConfig);

try {
    // 1) Login to get access_token
    $loginResp = $sdk->login();
    $accessToken = $loginResp['access_token'];

    // 2) Create payment order
    $order = [
        "amount" => 100,
        "merchantOrderId" => "ORD" . time(),
        "channel" => "web",
        "purpose" => "Online Payment",
        "customer" => [
            "buyer_name" => "John Doe",
            "email" => "john.doe@example.com",
            "phone" => "9876543210"
        ]
    ];

    $initResp = $sdk->initiatePayment($order, $accessToken);

    // The response should include 'token' and/or 'payment_url'
    // Example:
    // $initResp = [
    //   "status" => "pending",
    //   "grd_id" => "ORD123456",
    //   "amount" => 100,
    //   "token" => "pay_8fc1...",
    //   "payment_url" => "https://uat-pay.gurutvapay.com/payment?token=pay_8fc1..."
    // ];
    $token = $initResp['token'] ?? null;
    $paymentUrl = $initResp['payment_url'] ?? null;

    // Render page or return JSON with token to client
    // Example: echo JSON (for AJAX)
    header('Content-Type: application/json');
    echo json_encode(['token' => $token, 'payment_url' => $paymentUrl, 'raw' => $initResp]);

} catch (GurutvaPayException $e) {
    // handle errors (log, return friendly message)
    error_log("GurutvaPay error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}


Note: For production you can skip login() and use an API key mode (if your gateway supports API key) rather than password grant.

2) Serve a checkout page that embeds token (server-rendered)

Example checkout.php:

<?php
require_once __DIR__ . '/lib/gurutvapay_client.php';

// create order & get token (same as above)...
// assume $token contains the token string

// Render an HTML page that includes the launcher scripts and token
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Checkout</title>
  <script src="https://api.gurutvapay.com/static/gurutvapay-uat.js"></script>
  <script src="https://api.gurutvapay.com/static/gurutvapay-live.js"></script>
</head>
<body>
  <button id="uatBtn">Pay (UAT)</button>
  <button id="liveBtn">Pay (LIVE)</button>

  <script>
    const tokenUat = "<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>";
    // If you have separate token for live, set tokenLive too
    const tokenLive = ""; // set if available

    document.getElementById('uatBtn').addEventListener('click', () => {
      GurutvapayUat.launch(tokenUat);
    });

    document.getElementById('liveBtn').addEventListener('click', () => {
      GurutvapayLive.launch(tokenLive);
    });
  </script>
</body>
</html>

3) Safer flow — fetch token immediately before launch (recommended)

Instead of embedding token in HTML, create a server endpoint that creates the session and returns { token: "pay_..." }. Call it from client with fetch() when user clicks Pay.

Client-side:

<button id="payBtn">Pay</button>
<script src="https://api.gurutvapay.com/static/gurutvapay-uat.js"></script>
<script>
document.getElementById('payBtn').addEventListener('click', async () => {
  // call your server to create payment and return token (server uses SDK)
  const res = await fetch('/api/create-payment-session', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ amount: 100, merchantOrderId: 'ORD' + Date.now() })
  });
  const data = await res.json();
  if (data.token) {
    GurutvapayUat.launch(data.token);
  } else {
    alert('Failed to create payment session');
  }
});
</script>


Server endpoint /api/create-payment-session should run the login() + initiatePayment() flow and return token in JSON.

Client-side Launcher scripts

Place these under your public static folder or use gateway-hosted versions:

UAT: https://api.gurutvapay.com/static/gurutvapay-uat.js

LIVE: https://api.gurutvapay.com/static/gurutvapay-live.js

What they do: expose a global function:

GurutvapayUat.launch(token, opts) — open UAT checkout

GurutvapayLive.launch(token, opts) — open LIVE checkout

opts can include width, height, and mode: 'popup' | 'redirect'. Example:

GurutvapayUat.launch('pay_abc123', { width: 1000, height: 800 });
// or redirect
GurutvapayLive.launch('pay_abc123', { mode: 'redirect' });

Example checkout.html (complete demo)

Save as checkout.html in your public directory and update script paths / token logic.

<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Gurutvapay Demo</title>
  <script src="https://api.gurutvapay.com/static/gurutvapay-uat.js"></script>
  <script src="https://api.gurutvapay.com/static/gurutvapay-live.js"></script>
</head>
<body>
  <button id="uatBtn">Pay (UAT)</button>
  <button id="liveBtn">Pay (LIVE)</button>

  <script>
    // Example: fetch token from server endpoint (recommended)
    document.getElementById('uatBtn').addEventListener('click', async () => {
      const resp = await fetch('/api/create-payment-session', { method: 'POST' });
      const json = await resp.json();
      if (json.token) GurutvapayUat.launch(json.token);
      else alert('Failed to create session');
    });

    document.getElementById('liveBtn').addEventListener('click', async () => {
      const resp = await fetch('/api/create-payment-session-live', { method: 'POST' });
      const json = await resp.json();
      if (json.token) GurutvapayLive.launch(json.token);
      else alert('Failed to create session');
    });
  </script>
</body>
</html>

Webhook verification (server)

Always verify gateway webhooks on server using HMAC-SHA256 and your webhook secret.

Example (PHP):

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_X_SIGNATURE'] ?? $_SERVER['HTTP_X_GURUTVAPAY_SIGNATURE'] ?? null;
$secret = getenv('GURUTVA_WEBHOOK_SECRET');

if (!GurutvaPay::verifyWebhook($payload, $sigHeader, $secret)) {
    http_response_code(401);
    echo 'Invalid signature';
    exit;
}
// process event JSON
$data = json_decode($payload, true);


If the verifyWebhook method is not present on your class, implement HMAC-SHA256 compare using hash_hmac('sha256', $payload, $secret) and hash_equals().

Error handling & retries

The included PHP SDK throws GurutvaPayException for any HTTP/cURL error. Catch it and return friendly messages to users.

For idempotent initiatePayment calls, set an Idempotency-Key header (if the gateway supports it) in the httpPost helper — this prevents duplicate orders on retries.

Security notes

Never expose API keys or client secrets in client-side JS.

Generate tokens on the server and keep them short-lived.

Use HTTPS for all endpoints and scripts.

Store secrets in environment variables or a secrets manager.

Limit access to the endpoint that issues tokens (e.g., require authenticated merchants).

Packaging & Composer (optional)

To convert this into a Composer package:

Move gurutvapay_client.php into src/ and add namespaces (PSR-4).

Add composer.json:

{
  "name": "yourorg/gurutvapay-php",
  "description": "Gurutvapay PHP SDK (single-file)",
  "type": "library",
  "autoload": {
    "psr-4": { "Gurutvapay\\": "src/" }
  },
  "require": {
    "php": ">=7.4"
  }
}


composer install, test, and publish to Packagist if desired.

Troubleshooting

cURL error — ensure php-curl is installed and enabled.

Invalid JSON response — gateway returned HTML or plain text; inspect raw response in logs.

Popup blocked — launcher falls back to redirect; prefer user-initiated clicks (not automatic popups).

Token not working — check that you used the correct env (uat vs live) and passed the token string returned by initiatePayment.

Example repository layout
/
├─ lib/
│  └─ gurutvapay_client.php
├─ public/
│  ├─ js/
│  │  ├─ gurutvapay-uat.js
│  │  └─ gurutvapay-live.js
│  └─ checkout.html
├─ api/
│  └─ create-payment-session.php
├─ README.md
