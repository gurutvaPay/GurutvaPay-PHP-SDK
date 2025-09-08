<?php
class GurutvaPayException extends Exception {}

class GurutvaPay {
    private $mode; // 'uat' or 'live'
    private $baseUrl;
    private $config;
    private $cacheFile; // path for token cache
    private $expiryBuffer = 300; // seconds before expiry when we proactively refresh (5 minutes)

    /**
     * $mode: 'uat' or 'live'
     * $config: [
     *   'client_id' => '',
     *   'client_secret' => '',
     *   'username' => '',
     *   'password' => '',
     *   // optional:
     *   'token_cache_file' => '/path/to/cache.json'
     * ]
     */
    public function __construct(string $mode, array $config = []) {
        $mode = strtolower($mode);
        if (!in_array($mode, ['uat', 'live'])) {
            throw new GurutvaPayException("Mode must be 'uat' or 'live'");
        }
        $this->mode = $mode;
        $this->config = $config;
        $this->baseUrl = 'https://api.gurutvapay.com/' . ($mode === 'uat' ? 'uat_mode' : 'live');

        // cache file default (make sure web server user can write)
        $this->cacheFile = $config['token_cache_file'] ?? sys_get_temp_dir() . "/gurutvapay_{$mode}_token.json";
    }

    /**
     * Returns a valid access token (string).
     * Will use cached token if present & not expiring within expiryBuffer seconds.
     * Otherwise logs in and caches the token.
     */
    public function getAccessToken(): string {
        // Try to load from cache
        $cached = $this->loadTokenFromFile();

        if ($cached && isset($cached['access_token']) && isset($cached['expires_at'])) {
            $now = time();
            $expiresAt = (int)$cached['expires_at'];

            // if it's still valid with buffer, return it
            if ($expiresAt - $now > $this->expiryBuffer) {
                return $cached['access_token'];
            }
            // otherwise fall through to refresh
        }

        // perform login and cache the result
        $loginResp = $this->login();
        if (!isset($loginResp['access_token'])) {
            throw new GurutvaPayException("Login didn't return access_token");
        }

        // compute expires_at (prefer returned expires_at or expires_at_iso)
        $expiresAt = null;
        if (isset($loginResp['expires_at']) && is_numeric($loginResp['expires_at'])) {
            $expiresAt = (int)$loginResp['expires_at'];
        } elseif (isset($loginResp['expires_at_iso'])) {
            $ts = strtotime($loginResp['expires_at_iso']);
            if ($ts !== false) $expiresAt = $ts;
        } elseif (isset($loginResp['expires_in'])) {
            $expiresAt = time() + (int)$loginResp['expires_in'];
        } else {
            // fallback: short expiry so we force refresh next time
            $expiresAt = time() + 300;
        }

        $this->saveTokenToFile([
            'access_token' => $loginResp['access_token'],
            'expires_at' => $expiresAt
        ]);

        return $loginResp['access_token'];
    }

    /**
     * Login (password grant) — returns API JSON array (decoded).
     */
    public function login(): array {
        $url = $this->baseUrl . '/login';
        $body = [
            'grant_type' => 'password',
            'username' => $this->config['username'] ?? '',
            'password' => $this->config['password'] ?? '',
            'client_id' => $this->config['client_id'] ?? '',
            'client_secret' => $this->config['client_secret'] ?? ''
        ];
        foreach (['username','password','client_id','client_secret'] as $k) {
            if (empty($body[$k])) {
                throw new GurutvaPayException("Missing required login config: {$k}");
            }
        }
        $headers = ["Content-Type: application/x-www-form-urlencoded"];
        $resp = $this->httpPost($url, http_build_query($body), $headers);
        if (!is_array($resp)) throw new GurutvaPayException("Invalid JSON response from login");
        return $resp;
    }

    /**
     * Initiate payment: $payload and token obtained through getAccessToken()
     */
    public function initiatePayment(array $payload, ?string $accessToken = null): array {
        $accessToken = $accessToken ?? $this->getAccessToken();
        $url = $this->baseUrl . '/initiate-payment';
        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer " . $accessToken
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new GurutvaPayException("Failed to encode payload to JSON");
        $resp = $this->httpPost($url, $json, $headers);
        if (!is_array($resp)) throw new GurutvaPayException("Invalid JSON response from initiate-payment");
        return $resp;
    }

    /*****************************
     * Token file cache helpers
     *****************************/
    private function loadTokenFromFile(): ?array {
        $file = $this->cacheFile;
        if (!file_exists($file)) return null;

        $fp = fopen($file, 'r');
        if (!$fp) return null;
        // shared lock
        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return null;
        }
        $contents = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (empty($contents)) return null;
        $data = json_decode($contents, true);
        if (!is_array($data)) return null;
        return $data;
    }

    private function saveTokenToFile(array $data): void {
        $file = $this->cacheFile;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $file . '.tmp';
        $fp = fopen($tmp, 'c');
        if (!$fp) {
            throw new GurutvaPayException("Unable to open temp file for token cache");
        }
        // exclusive lock
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new GurutvaPayException("Unable to lock token cache file");
        }
        // write
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        // atomic replace
        rename($tmp, $file);
    }

    /*****************************
     * HTTP helper
     *****************************/
    private function httpPost(string $url, string $body, array $headers = []): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new GurutvaPayException("cURL error: {$err}");
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode($raw, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new GurutvaPayException("Received non-JSON 2xx response: {$raw}");
            }
            return (array)$decoded;
        }
        $message = "HTTP {$httpCode}";
        if ($decoded !== null) {
            $message .= " - " . json_encode($decoded);
        } else {
            $message .= " - " . substr($raw, 0, 500);
        }
        throw new GurutvaPayException($message);
    }
}
