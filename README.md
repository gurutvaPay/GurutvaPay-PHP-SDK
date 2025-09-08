# Gurutvapay PHP SDK — `gurutvapay_client.php`

This repository contains a single-file PHP SDK for **Gurutvapay**: `gurutvapay_client.php`.
It is a drop-in client you can `require_once` in any PHP project (plain PHP, Laravel, Symfony, etc.).

---

## Features

* Single-file, zero external dependencies (uses PHP `curl`).
* Supports **API Key** mode and **OAuth password-grant** mode.
* Methods: `createPayment`, `transactionStatus`, `transactionList`, `request` (generic).
* Built-in retry/backoff and basic timeout handling.
* Webhook HMAC-SHA256 verification helper: `verifyWebhook`.
* Typed exceptions: `AuthException`, `NotFoundException`, `RateLimitException`, and base `GuruTvapayException`.

---

## Requirements

* PHP 7.4+ (works with PHP 8.x)
* `curl` extension enabled

---

## Installation

Copy `gurutvapay_client.php` into your project (e.g. `lib/` or `vendor/gurutvapay/`) and include it:

```php
require_once __DIR__ . '/path/to/gurutvapay_client.php';
use GuruTvapay\GuruTvapayClient;
```

(Optional) Turn into a Composer package by adding a `composer.json` and using PSR-4 autoloading.

---

## Configuration (recommended via environment variables)

Keep secrets out of source control. Example env vars:

```
GURUTVA_ENV=uat                # or live
GURUTVA_API_KEY=sk_test_xxx
GURUTVA_CLIENT_ID=CLIENT_12345
GURUTVA_CLIENT_SECRET=SECRET_67890
GURUTVA_USERNAME=john@example.com
GURUTVA_PASSWORD=your_password
GURUTVA_WEBHOOK_SECRET=secret123
```

---

## Quick Examples

### 1) API Key mode (recommended)

```php
<?php
require_once 'gurutvapay_client.php';
use GuruTvapay\GuruTvapayClient;

$client = new GuruTvapayClient([
    'env' => 'uat',
    'apiKey' => getenv('GURUTVA_API_KEY'),
]);

try {
    $resp = $client->createPayment(
        100,
        'ORD' . time(),
        'web',
        'Online Payment',
        [
            'buyer_name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '9876543210',
        ]
    );
    echo "Open checkout: " . ($resp['payment_url'] ?? $resp['paymentUrl'] ?? 'no-url') . PHP_EOL;
} catch (GuruTvapay\AuthException $e) {
    // handle auth errors
    echo "Auth failed: " . $e->getMessage();
} catch (GuruTvapay\GuruTvapayException $e) {
    // handle other SDK errors
    echo "Error: " . $e->getMessage();
}
```

### 2) OAuth (password grant)

```php
$client = new GuruTvapayClient([
    'env' => 'uat',
    'clientId' => getenv('GURUTVA_CLIENT_ID'),
    'clientSecret' => getenv('GURUTVA_CLIENT_SECRET'),
]);

$token = $client->loginWithPassword(getenv('GURUTVA_USERNAME'), getenv('GURUTVA_PASSWORD'));
print_r($token);

$resp = $client->createPayment(100, 'ORD_OAUTH_1', 'web', 'OAuth Payment', [
    'buyer_name' => 'Alice', 'email' => 'alice@example.com', 'phone' => '9999999999'
]);
print_r($resp);
```

### 3) Use Idempotency-Key (prevent duplicates)

Use the generic `request()` to add custom headers like `Idempotency-Key`:

```php
$idemp = bin2hex(random_bytes(12));
$payload = [
  'amount' => 100,
  'merchantOrderId' => 'ORD_' . time(),
  'channel' => 'web',
  'purpose' => 'Online Payment',
  'customer' => ['buyer_name'=>'Joe','email'=>'joe@example.com','phone'=>'9999999999']
];
$resp = $client->request('POST', '/initiate-payment', ['Idempotency-Key' => $idemp], [], null, $payload);
print_r($resp);
```

### 4) Transaction status

```php
$status = $client->transactionStatus('ORDER_2024_001');
print_r($status);
```

### 5) Transaction list

```php
$list = $client->transactionList(50, 0);
print_r($list['data']['transactions'] ?? $list);
```

### 6) Webhook verification

Verify HMAC-SHA256 before processing webhooks:

```php
$payload = file_get_contents('php://input'); // raw bytes
$sig = $_SERVER['HTTP_X_SIGNATURE'] ?? $_SERVER['HTTP_X_GURUTVAPAY_SIGNATURE'] ?? null;
$secret = getenv('GURUTVA_WEBHOOK_SECRET');
if (!\GuruTvapay\GuruTvapayClient::verifyWebhook($payload, $sig, $secret)) {
    http_response_code(401);
    echo 'Invalid signature';
    exit;
}
// process payload
```

Header formats supported: `sha256=<hex>` or raw hex.

---

## Error handling

Wrap calls in try/catch and handle these exceptions:

* `GuruTvapay\AuthException` — authentication/authorization errors (401/403)
* `GuruTvapay\NotFoundException` — 404
* `GuruTvapay\RateLimitException` — 429
* `GuruTvapay\GuruTvapayException` — other errors

---

## Integrating into a Framework (Laravel example)

Place `gurutvapay_client.php` in `app/Libraries/` or convert into a Composer package. Example controller:

```php
<?php
namespace App\Http\Controllers;
use GuruTvapay\GuruTvapayClient;
use Illuminate\Http\Request;

class PaymentsController extends Controller {
    protected $client;
    public function __construct() {
        $this->client = new GuruTvapayClient(['env' => env('GURUTVA_ENV','uat'), 'apiKey' => env('GURUTVA_API_KEY')]);
    }

    public function create(Request $req) {
        $data = $req->only(['amount','merchant_order_id','channel','purpose','customer']);
        try {
            $resp = $this->client->createPayment(
                $data['amount'], $data['merchant_order_id'], $data['channel'] ?? 'web', $data['purpose'] ?? 'Online Payment', $data['customer']
            );
            return response()->json($resp);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
```

---

## Testing & local webhook simulation

Use UAT environment for integration tests. Simulate webhook signature with `openssl`:

```bash
payload='{"merchantOrderId":"ORD123","status":"success"}'
secret=$GURUTVA_WEBHOOK_SECRET
sig=$(echo -n $payload | openssl dgst -sha256 -hmac "$secret" -hex | sed 's/^.* //')

curl -X POST http://localhost:8080/webhook -H "X-Signature: sha256=$sig" -d "$payload"
```

---

## Packaging (Composer)

To make this reusable across projects, convert into a Composer package:

1. Move file into `src/` and namespace appropriately.
2. Add `composer.json` with PSR-4 autoloading.
3. Publish to Packagist.

Example `composer.json` fragment:

```json
{
  "name": "yourorg/gurutvapay-php",
  "type": "library",
  "autoload": { "psr-4": { "GuruTvapay\\": "src/" } },
  "require": { "php": ">=7.4" }
}
```

---

## Security & best practices

* Never commit API keys, client secrets, or webhook secrets.
* Use environment variables or a secrets manager in production.
* Log only non-sensitive information. Do not log full payloads containing sensitive data.
* Use HTTPS in production.

---

## License

Add a `LICENSE` file (MIT recommended) before publishing.

---

If you want, I can:

* create `composer.json` and package layout for immediate use,
* add PHPUnit tests and a GitHub Actions CI workflow,
* publish the package to Packagist for you (if you provide repo access),
* or generate similar README files for the Node/Python/Java SDKs. Which would you like next?
