# GurutvaPay Mini PHP SDK + Checkout Launcher

This repository contains:

- **Mini PHP SDK (`GurutvaPay` class)**  
  Provides login (OAuth password grant) and payment initiation.
- **Checkout Launcher (JavaScript)**  
  Small JS files to open the hosted checkout page in **UAT** or **LIVE** mode.

---

## Table of Contents
- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
  - [1. Server-side (PHP)](#1-server-side-php)
  - [2. Client-side (JavaScript)](#2-client-side-javascript)
  - [3. Safer Flow (AJAX)](#3-safer-flow-ajax)
- [Example API Response](#example-api-response)
- [Webhooks](#webhooks)
- [Error Handling](#error-handling)
- [Security](#security)
- [Contributing](#contributing)
- [License](#license)

---

## Overview

The **PHP SDK** communicates with the GurutvaPay API:

- **Login** → get an `access_token`  
- **Initiate Payment** → get a `token` + `payment_url`

The **Checkout Launcher** JS files open the hosted payment page:

- **UAT:** `https://api.gurutvapay.com/static/gurutvapay-uat.js`  
- **LIVE:** `https://api.gurutvapay.com/static/gurutvapay-live.js`

---

## Requirements

- PHP 7.4+ or PHP 8.x
- PHP `curl` extension enabled
- Modern browser for checkout page
- HTTPS in production

---

## Installation

### 1. SDK (Server-side PHP)
Copy the SDK file (`gurutvapay.php`) into your project, e.g.:

/lib/gurutvapay.php


Include in your code:

```php
require_once __DIR__ . '/lib/gurutvapay.php';

2. Checkout Launcher (Client-side JS)

Include the hosted scripts in your HTML:

<script src="https://api.gurutvapay.com/static/gurutvapay-uat.js"></script>
<script src="https://api.gurutvapay.com/static/gurutvapay-live.js"></script>

Configuration

Set credentials in environment variables:

GURUTVA_CLIENT_ID=CLIENT_12345
GURUTVA_CLIENT_SECRET=SECRET_67890
GURUTVA_USERNAME=john@example.com
GURUTVA_PASSWORD=secret_password

Usage
1. Server-side (PHP)
<?php
require_once __DIR__ . '/lib/gurutvapay.php';

// Config from env
$config = [
  'client_id'     => getenv('GURUTVA_CLIENT_ID'),
  'client_secret' => getenv('GURUTVA_CLIENT_SECRET'),
  'username'      => getenv('GURUTVA_USERNAME'),
  'password'      => getenv('GURUTVA_PASSWORD'),
];

// Create SDK instance (uat or live)
$sdk = new GurutvaPay('uat', $config);

// Login
$login = $sdk->login();
$accessToken = $login['access_token'];

// Create order
$order = [
  "amount" => 100,
  "merchantOrderId" => "ORD" . time(),
  "channel" => "web",
  "purpose" => "Online Payment",
  "customer" => [
    "buyer_name" => "John Doe",
    "email" => "john@example.com",
    "phone" => "9876543210"
  ]
];

$init = $sdk->initiatePayment($order, $accessToken);

// Extract token
$token = $init['token'] ?? null;

2. Client-side (JavaScript)

Render token into the page and use the checkout launcher:

<button id="uatBtn">Pay (UAT)</button>
<button id="liveBtn">Pay (LIVE)</button>

<script src="https://api.gurutvapay.com/static/gurutvapay-uat.js"></script>
<script src="https://api.gurutvapay.com/static/gurutvapay-live.js"></script>

<script>
  const tokenUat  = "<?= htmlspecialchars($tokenUat) ?>";
  const tokenLive = "<?= htmlspecialchars($tokenLive) ?>";

  document.getElementById('uatBtn').addEventListener('click', () => {
    GurutvapayUat.launch(tokenUat);
  });

  document.getElementById('liveBtn').addEventListener('click', () => {
    GurutvapayLive.launch(tokenLive);
  });
</script>

3. Safer Flow (AJAX)

Fetch token dynamically from your server endpoint:

document.getElementById('uatBtn').addEventListener('click', async () => {
  const res = await fetch('/create-session.php', { method: 'POST' });
  const data = await res.json();
  if (data.token) {
    GurutvapayUat.launch(data.token);
  }
});


Server (create-session.php):

<?php
require_once __DIR__ . '/lib/gurutvapay.php';
header('Content-Type: application/json');

$config = [ /* from env */ ];
$sdk = new GurutvaPay('uat', $config);

try {
  $login = $sdk->login();
  $token = $sdk->initiatePayment([/* order */], $login['access_token'])['token'] ?? null;
  echo json_encode(['ok' => true, 'token' => $token]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

Example API Response
{
  "status": "pending",
  "grd_id": "ORD123456",
  "amount": 100,
  "token": "pay_8fc13996-3f4f-4bdb-b3d1-df9e4cbeeaa7",
  "payment_url": "https://uat-pay.gurutvapay.com/payment?token=pay_8fc13996-...",
  "expires_in": 1200
}

Webhooks

After payment, GurutvaPay will send events to your webhook URL.

Always verify webhook signatures (HMAC-SHA256 with your secret).

Update your database (order status) only after webhook verification.

Error Handling

SDK may throw:

GurutvaPayException — generic error

cURL errors — network/TLS issues

Invalid JSON response — API returned invalid JSON

HTTP error messages with status code and body

Use try/catch:

try {
  $login = $sdk->login();
} catch (GurutvaPayException $e) {
  error_log("Payment error: " . $e->getMessage());
}

Security

Never expose client_id, client_secret, or password in client-side code.

Always generate token on the server.

Use HTTPS everywhere.

Tokens should be considered short-lived and single-use.

Contributing

Fork the repo

Create your feature branch (git checkout -b feature/my-feature)

Commit your changes (git commit -m 'Add my feature')

Push to branch (git push origin feature/my-feature)

Open a Pull Request

License

MIT License © 2025
You are free to use, modify, and distribute this SDK with attribution.


---

👉 Do you also want me to create a **demo project structure** (with `public/checkout.html`, `server/creat
