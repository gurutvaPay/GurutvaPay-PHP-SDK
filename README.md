# GuruTvapay PHP SDK — `gurutvapay_client.php`

A compact single-file PHP SDK for the GuruTvapay Payment Gateway. This README explains installation, configuration, usage examples, and integration tips for PHP applications (vanilla PHP, frameworks like Laravel, etc.).

---

## Contents

* `GuruTvapayClient` — main client class
* Static helper: `GuruTvapayClient::verifyWebhook($payloadBytes, $signatureHeader, $secret)`
* Custom exceptions: `GuruTvapayException`, `AuthException`, `NotFoundException`, `RateLimitException`

---

## Requirements

* PHP 7.4+ (works with PHP 8.x)
* `curl` extension enabled

Drop the file `gurutvapay_client.php` into your project and include it where you need it.

---

## Installation

Simply copy the file into your project and `require_once` it:

```php
require_once __DIR__ . '/gurutvapay_client.php';

use GuruTvapay\GuruTvapayClient;
```

(Optional) To turn this into a reusable package, create a `composer.json` and register it on Packagist. Example `composer.json` snippet:

```json
{
  "name": "yourorg/gurutvapay-php",
  "type": "library",
  "autoload": {
    "psr-4": { "GuruTvapay\\": "src/" }
  },
  "require": { "php": ">=7.4" }
}
```

---

## Quickstart

### API-key mode (recommended for server-to-server requests)

```php
require_once 'gurutvapay_client.php';
use GuruTvapay\GuruTvapayClient;

$client = new GuruTvapayClient([
  'env' => 'uat',
  'apiKey' => 'sk_test_...'
]);

$resp = $client->createPayment(
  100,
  'ORD123456',
  'web',
  'Online Payment',
  ['buyer_name' => 'John Doe', 'email' => 'john@example.com', 'phone' => '9876543210']
);

echo $resp['payment_url'];
```

### OAuth (password-grant) mode

```php
$client = new GuruTvapayClient([
  'env' => 'uat',
  'clientId' => 'CLIENT_12345',
  'clientSecret' => 'SECRET_67890'
]);
$token = $client->loginWithPassword('john@example.com', 'your_password');
// then call createPayment() as above
```

---

## Methods

* `loginWithPassword($username, $password, $grantType='password')` — obtains an OAuth access token (stores in the client)
* `createPayment($amount, $merchantOrderId, $channel, $purpose, array $customer, $expiresIn=null, $metadata=null)` — calls `/initiate-payment`
* `transactionStatus($merchantOrderId)` — calls `/<env_prefix>/transaction-status` with form-encoded body
* `transactionList($limit=50, $page=0)` — calls `/<env_prefix>/transaction-list?limit=..&page=..`
* `request($method, $pathOrUrl, $headers=[], $params=[], $data=null, $jsonBody=null)` — generic request for advanced use (attach custom headers like `Idempotency-Key`)
* `verifyWebhook($payloadBytes, $signatureHeader, $secret)` — static method to verify HMAC-SHA256 webhook signatures

---

## Error handling

The SDK throws typed exceptions:

* `AuthException` for 401/403
* `NotFoundException` for 404
* `RateLimitException` for 429
* `GuruTvapayException` for other errors

Wrap SDK calls in `try/catch` blocks to handle errors gracefully.

```php
try {
  $resp = $client->createPayment(...);
} catch (AuthException $e) {
  // re-auth or return 401 to caller
} catch (GuruTvapayException $e) {
  // log and respond with 500
}
```

---

## Idempotency

To avoid duplicate payments, attach an `Idempotency-Key` header when creating a payment. Example:

```php
$idempotencyKey = bin2hex(random_bytes(16));
$resp = $client->request('POST', '/initiate-payment', ['Idempotency-Key' => $idempotencyKey], [], null, $payload);
```

The `request()` method merges your headers with the auth header automatically.

---

## Webhook verification

Gateway webhooks should be verified before processing. Example (in a raw PHP endpoint):

```php
$payload = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_SIGNATURE'] ?? $_SERVER['HTTP_X_GURUTVAPAY_SIGNATURE'] ?? null;
$secret = getenv('GURUTVA_WEBHOOK_SECRET');
if (!GuruTvapay\GuruTvapayClient::verifyWebhook($payload, $sig, $secret)) {
  http_response_code(401);
  echo 'Invalid signature';
  exit;
}
// process payload
```

The SDK expects either `sha256=<hex>` or raw hex in the header and compares using timing-safe method.

---

## Integration examples

### Laravel controller example

```php
// app/Http/Controllers/PaymentsController.php
namespace App\Http\Controllers;

use GuruTvapay\GuruTvapayClient;
use Illuminate\Http\Request;

class PaymentsController extends Controller {
    protected $client;
    public function __construct() {
        $this->client = new GuruTvapayClient([
            'env' => env('GURUTVA_ENV','uat'),
            'apiKey' => env('GURUTVA_API_KEY'),
        ]);
    }

    public function create(Request $req) {
        $payload = $req->only(['amount','merchant_order_id','channel','purpose','customer']);
        try {
            $resp = $this->client->createPayment($payload['amount'], $payload['merchant_order_id'], $payload['channel'], $payload['purpose'], $payload['customer']);
            return response()->json($resp);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
```

### Simple webhook route (Laravel)

```php
// routes/web.php
Route::post('/webhook', function (\Illuminate\Http\Request $request) {
    $payload = $request->getContent();
    $sig = $request->header('X-Signature');
    if (!\GuruTvapay\GuruTvapayClient::verifyWebhook($payload, $sig, env('GURUTVA_WEBHOOK_SECRET'))) {
        return response('Invalid signature', 401);
    }
    // process
    return response('ok', 200);
});
```

---

## Testing

* Unit tests: mock `curl` responses or abstract HTTP calls for injection to unit-test logic.
* Integration tests: use the UAT endpoints with test credentials.

---

## Security & Best Practices

* Never commit API keys or client secrets to source control.
* Use environment variables or a secret manager in production.
* Log only non-sensitive information. Do not store or log full card or payment details.
* Rotate credentials periodically.

---

## Contributing

Open issues and PRs for enhancements. Suggestions:

* Convert to PSR-4 autoloaded package and publish on Packagist.
* Add PHPUnit tests and CI workflow (GitHub Actions).

---

## License

Add a `LICENSE` file (MIT recommended for SDKs) before publishing.

---

If you want, I can:

* convert this SDK into a composer package and generate `composer.json`,
* add a small PHPUnit test suite and GitHub Actions CI workflow, or
* create Node/Java SDKs using the same API surface. Which next?
