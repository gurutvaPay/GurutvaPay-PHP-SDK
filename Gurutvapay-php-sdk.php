<?php
/**
 * GuruTvapay PHP SDK - single-file client (gurutvapay_client.php)
 *
 * Usage:
 * 1. Save this file as `gurutvapay_client.php` and include it in your project:
 *
 *    require_once 'gurutvapay_client.php';
 *
 * 2. Instantiate in API-key mode:
 *
 *    $client = new GuruTvapay\GuruTvapayClient(['env' => 'uat', 'apiKey' => 'sk_test_...']);
 *    $resp = $client->createPayment(100, 'ORD123', 'web', 'Online Payment', [
 *      'buyer_name' => 'John', 'email' => 'john@example.com', 'phone' => '9876543210'
 *    ]);
 *    echo $resp['payment_url'];
 *
 * 3. OAuth (password grant) mode:
 *
 *    $client = new GuruTvapay\GuruTvapayClient(['env'=>'uat','clientId'=>'CLIENT_123','clientSecret'=>'SECRET_456']);
 *    $tokenInfo = $client->loginWithPassword('john@example.com','password');
 *
 * Notes:
 * - This SDK uses curl (no external deps) and provides simple retry/backoff for network/server errors.
 * - For idempotency headers, use the request() method to pass custom headers.
 */

namespace GuruTvapay;

class GuruTvapayException extends \Exception {}
class AuthException extends GuruTvapayException {}
class NotFoundException extends GuruTvapayException {}
class RateLimitException extends GuruTvapayException {}

class GuruTvapayClient {
    private $env;
    private $apiKey;
    private $clientId;
    private $clientSecret;
    private $timeout;
    private $maxRetries;
    private $backoffFactor;
    private $root;
    private $token; // ['access_token'=>..., 'expires_at'=>int]

    const DEFAULT_ROOT = 'https://api.gurutvapay.com';
    private static $envPrefixes = [
        'uat' => '/uat_mode',
        'live' => '/live',
    ];

    public function __construct(array $opts = []) {
        $this->env = isset($opts['env']) ? $opts['env'] : 'uat';
        if (!isset(self::$envPrefixes[$this->env])) {
            throw new \InvalidArgumentException("env must be 'uat' or 'live'");
        }
        $this->apiKey = $opts['apiKey'] ?? null;
        $this->clientId = $opts['clientId'] ?? null;
        $this->clientSecret = $opts['clientSecret'] ?? null;
        $this->timeout = $opts['timeout'] ?? 30;
        $this->maxRetries = $opts['maxRetries'] ?? 3;
        $this->backoffFactor = $opts['backoffFactor'] ?? 0.5;
        $this->root = $opts['customRoot'] ?? self::DEFAULT_ROOT;
        $this->token = null;
    }

    // -----------------------------
    // Low-level request helper with retries
    // -----------------------------
    private function httpRequest($method, $url, $headers = [], $params = [], $data = null, $jsonBody = null) {
        $attempt = 0;
        while (true) {
            $attempt += 1;
            $ch = curl_init();
            $finalUrl = $url;
            if (!empty($params)) {
                $finalUrl .= '?' . http_build_query($params);
            }
            curl_setopt($ch, CURLOPT_URL, $finalUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_FAILONERROR, false);

            $hdrs = [];
            foreach ($headers as $k => $v) {
                $hdrs[] = $k . ': ' . $v;
            }
            // add auth header if available
            $auth = $this->authHeader();
            foreach ($auth as $k => $v) { $hdrs[] = $k . ': ' . $v; }

            if (!empty($jsonBody)) {
                $body = json_encode($jsonBody);
                $hdrs[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            } elseif (is_array($data)) {
                // form-encoded
                $hdrs[] = 'Content-Type: application/x-www-form-urlencoded';
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }

            if (strtoupper($method) === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
            } else {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
            }

            if (!empty($hdrs)) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);

            $respBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                if ($attempt > $this->maxRetries) {
                    throw new GuruTvapayException("HTTP request failed: {$curlErr}");
                }
                $sleep = $this->backoffFactor * pow(2, $attempt - 1);
                usleep((int)($sleep * 1e6));
                continue;
            }

            $decoded = json_decode($respBody, true);

            if ($httpCode >= 200 && $httpCode < 300) {
                return $decoded ?? ['raw' => $respBody];
            }

            if ($httpCode == 401 || $httpCode == 403) {
                throw new AuthException("Authentication failed: {$respBody}");
            }
            if ($httpCode == 404) {
                throw new NotFoundException("Not found: {$url}");
            }
            if ($httpCode == 429) {
                if ($attempt <= $this->maxRetries) {
                    $retryAfter = null; // curl doesn't expose headers easily here; skip
                    $sleep = $this->backoffFactor * pow(2, $attempt - 1);
                    usleep((int)($sleep * 1e6));
                    continue;
                }
                throw new RateLimitException("Rate limited: {$respBody}");
            }

            if ($httpCode >= 500 && $attempt <= $this->maxRetries) {
                $sleep = $this->backoffFactor * pow(2, $attempt - 1);
                usleep((int)($sleep * 1e6));
                continue;
            }

            throw new GuruTvapayException("HTTP {$httpCode}: {$respBody}");
        }
    }

    // -----------------------------
    // Auth helpers
    // -----------------------------
    private function authHeader() {
        if ($this->apiKey) {
            return ['Authorization' => 'Bearer ' . $this->apiKey];
        }
        if ($this->token && isset($this->token['access_token']) && !$this->isTokenExpired()) {
            return ['Authorization' => 'Bearer ' . $this->token['access_token']];
        }
        return [];
    }

    private function isTokenExpired() {
        if (!$this->token || !isset($this->token['expires_at'])) return true;
        return time() >= ($this->token['expires_at'] - 10);
    }

    public function loginWithPassword($username, $password, $grantType = 'password') {
        if (!$this->clientId || !$this->clientSecret) {
            throw new \InvalidArgumentException('clientId and clientSecret are required for OAuth login');
        }
        $url = $this->root . self::$envPrefixes[$this->env] . '/login';
        $data = [
            'grant_type' => $grantType,
            'username' => $username,
            'password' => $password,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
        $resp = $this->httpRequest('POST', $url, [], [], $data, null);
        if (!isset($resp['access_token'])) {
            throw new AuthException('Login failed or missing access_token');
        }
        $expiresAt = isset($resp['expires_at']) ? intval($resp['expires_at']) : (time() + intval($resp['expires_in'] ?? 0));
        $this->token = ['access_token' => $resp['access_token'], 'expires_at' => $expiresAt];
        return $this->token;
    }

    // -----------------------------
    // High-level methods
    // -----------------------------
    public function createPayment($amount, $merchantOrderId, $channel, $purpose, array $customer, $expiresIn = null, $metadata = null) {
        $url = self::DEFAULT_ROOT . '/initiate-payment';
        $payload = [
            'amount' => $amount,
            'merchantOrderId' => $merchantOrderId,
            'channel' => $channel,
            'purpose' => $purpose,
            'customer' => $customer,
        ];
        if ($expiresIn !== null) $payload['expires_in'] = $expiresIn;
        if ($metadata !== null) $payload['metadata'] = $metadata;
        return $this->httpRequest('POST', $url, [], [], null, $payload);
    }

    public function transactionStatus($merchantOrderId) {
        $url = $this->root . self::$envPrefixes[$this->env] . '/transaction-status';
        $data = ['merchantOrderId' => $merchantOrderId];
        return $this->httpRequest('POST', $url, [], [], $data, null);
    }

    public function transactionList($limit = 50, $page = 0) {
        $url = $this->root . self::$envPrefixes[$this->env] . '/transaction-list';
        $params = ['limit' => $limit, 'page' => $page];
        return $this->httpRequest('GET', $url, [], $params, null, null);
    }

    // Generic request for advanced use (headers passed here will be merged with auth)
    public function request($method, $pathOrUrl, $headers = [], $params = [], $data = null, $jsonBody = null) {
        $url = $pathOrUrl;
        if (strpos($pathOrUrl, 'http://') !== 0 && strpos($pathOrUrl, 'https://') !== 0) {
            // join root
            if (strpos($pathOrUrl, '/') !== 0) $pathOrUrl = '/' . $pathOrUrl;
            $url = $this->root . $pathOrUrl;
        }
        return $this->httpRequest($method, $url, $headers, $params, $data, $jsonBody);
    }

    // -----------------------------
    // Webhook verification
    // -----------------------------
    public static function verifyWebhook($payloadBytes, $signatureHeader, $secret) {
        $sig = $signatureHeader;
        if (strpos($sig, 'sha256=') === 0) {
            $sig = substr($sig, 7);
        }
        $computed = hash_hmac('sha256', $payloadBytes, $secret);
        // timing-safe compare
        if (function_exists('hash_equals')) {
            return hash_equals($computed, $sig);
        }
        return $computed === $sig;
    }
}

// End of file
