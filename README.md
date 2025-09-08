# GurutvaPay PHP SDK + Client-Side Checkout Launcher

Single-file PHP SDK + client-side checkout launcher integration for **GurutvaPay**.

---

## Repository Contents

- **`gurutvapay_client.php`** — tiny, single-file PHP SDK:
  - Logs in via OAuth password grant (`/uat_mode/login` or `/live/login`)
  - Initiates payments (`/uat_mode/initiate-payment` or `/live/initiate-payment`)
  - Returns the gateway `token` and `payment_url` to open checkout

- **Checkout launcher scripts**:
  - `gurutvapay-uat.js` → opens `https://uat-pay.gurutvapay.com/payment?token=...`
  - `gurutvapay-live.js` → opens `https://payment.gurutvapay.com/payment?token=...`

- **Example files**:
  - `checkout.html` — demo page for UAT & LIVE checkout
  - `README.md` — this file

---

## Quick Summary (How It Works)

1. **Server (PHP)** uses `gurutvapay_client.php` to:
   - Authenticate (login or API key)
   - Create a payment order
   - Receive a `token` (e.g. `pay_8fc13996...`) and `payment_url`

2. **Client (Browser)** includes launcher scripts:
   - `gurutvapay-uat.js` or `gurutvapay-live.js`
   - Calls:
     ```js
     GurutvapayUat.launch(token);
     // or
     GurutvapayLive.launch(token);
     ```
   - Opens GurutvaPay checkout in a popup (or redirect fallback)

---

## Requirements

- PHP **7.4+** (works on PHP 8.x)
- `curl` extension enabled
- HTTPS (production only)
- Store credentials in **environment variables** or a secrets manager

---

## Installation

1. Copy `gurutvapay_client.php` into your project (e.g. `lib/gurutvapay_client.php`)
2. Host `gurutvapay-uat.js` and `gurutvapay-live.js` under `/public/js/`  
   OR use CDN:

```html
<script src="https://api.gurutvapay.com/static/gurutvapay-uat.js"></script>
<script src="https://api.gurutvapay.com/static/gurutvapay-live.js"></script>
```


Configuration (Environment Variables)
```
# For UAT
GURUTVA_ENV=uat
GURUTVA_CLIENT_ID=CLIENT_12345
GURUTVA_CLIENT_SECRET=SECRET_67890
GURUTVA_USERNAME=john@example.com
GURUTVA_PASSWORD=your_password
GURUTVA_WEBHOOK_SECRET=supersecret

# For LIVE
GURUTVA_ENV=live
GURUTVA_CLIENT_ID=LIVE_CLIENT_...
GURUTVA_CLIENT_SECRET=LIVE_SECRET_...
GURUTVA_USERNAME=...
GURUTVA_PASSWORD=...
```

Usage Examples
1. Basic PHP Flow (UAT)


```
require_once __DIR__ . '/lib/gurutvapay_client.php';

$uatConfig = [
    'client_id' => getenv('GURUTVA_CLIENT_ID'),
    'client_secret' => getenv('GURUTVA_CLIENT_SECRET'),
    'username' => getenv('GURUTVA_USERNAME'),
    'password' => getenv('GURUTVA_PASSWORD'),
];

$sdk = new GurutvaPay('uat', $uatConfig);

try {
    $loginResp = $sdk->login();
    $accessToken = $loginResp['access_token'];

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

    header('Content-Type: application/json');
    echo json_encode([
        'token' => $initResp['token'] ?? null,
        'payment_url' => $initResp['payment_url'] ?? null,
        'raw' => $initResp
    ]);
} catch (GurutvaPayException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
```

2. Checkout Page with Token
```
<?php
// assume $token = created from initiatePayment()
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
    const tokenUat = "<?php echo htmlspecialchars($token); ?>";
    const tokenLive = ""; // optional

    document.getElementById('uatBtn').addEventListener('click', () => {
      GurutvapayUat.launch(tokenUat);
    });

    document.getElementById('liveBtn').addEventListener('click', () => {
      GurutvapayLive.launch(tokenLive);
    });
  </script>
</body>
</html>
```

3. Recommended Flow (Fetch Token on Click)

```
<button id="payBtn">Pay</button>
<script src="https://api.gurutvapay.com/static/gurutvapay-uat.js"></script>
<script>
document.getElementById('payBtn').addEventListener('click', async () => {
  const res = await fetch('/api/create-payment-session', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ amount: 100, merchantOrderId: 'ORD' + Date.now() })
  });
  const data = await res.json();
  if (data.token) {
    GurutvapayUat.launch(data.token);
  } else {
    alert('Failed to create session');
  }
});
</script>
```

Client-Side Launcher

UAT: https://api.gurutvapay.com/static/gurutvapay-uat.js

LIVE: https://api.gurutvapay.com/static/gurutvapay-live.js

Exposes global functions:

```
GurutvapayUat.launch('pay_abc123', { width: 1000, height: 800 });
GurutvapayLive.launch('pay_abc123', { mode: 'redirect' });
```

Security Notes

Never expose API keys/client secrets in JS

Always issue tokens from server

Use HTTPS

Store secrets in env vars or secret managers


Example Project Layout
```
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
```

