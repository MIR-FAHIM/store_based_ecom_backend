<?php

namespace App\Service;

use App\Exceptions\FirebaseNotificationException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class FirebaseNotificationService
{
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const JWT_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    public function sendToUser(User $user, string $title, string $body, array $data = [], ?string $image = null, array $options = []): array
    {
        if (!$user->device_token) {
            throw new FirebaseNotificationException('Device token not found for this user', [
                'user_id' => (int) $user->id,
            ], 422);
        }

        return $this->sendToToken($user->device_token, $title, $body, $data, $image, $options);
    }

    public function sendToToken(string $deviceToken, string $title, string $body, array $data = [], ?string $image = null, array $options = []): array
    {
        $credentials = $this->credentials();
        $projectId = $this->projectId($credentials);
        $accessToken = $this->accessToken($credentials);

        $notification = [
            'title' => $title,
            'body' => $body,
        ];

        if ($image) {
            $notification['image'] = $image;
        }

        $message = [
            'token' => $deviceToken,
            'notification' => $notification,
        ];

        $stringData = $this->stringData($data);
        if (!empty($stringData)) {
            $message['data'] = $stringData;
        }

        if (!empty($options['android'])) {
            $message['android'] = $options['android'];
        }

        if (!empty($options['apns'])) {
            $message['apns'] = $options['apns'];
        }

        try {
            $response = Http::timeout(20)
                ->withToken($accessToken)
                ->acceptJson()
                ->asJson()
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => $message,
                ]);
        } catch (ConnectionException $e) {
            throw new FirebaseNotificationException('Could not connect to Firebase Cloud Messaging', [
                'error' => $e->getMessage(),
            ], 502, $e);
        }

        $responseBody = $response->json();
        if (!is_array($responseBody)) {
            $responseBody = ['raw_response' => $response->body()];
        }

        if (!$response->successful()) {
            throw new FirebaseNotificationException('Firebase rejected the push notification request', [
                'firebase_status' => $response->status(),
                'firebase_response' => $responseBody,
            ], $response->status());
        }

        return [
            'message_id' => $responseBody['name'] ?? null,
            'firebase_response' => $responseBody,
        ];
    }

    private function credentials(): array
    {
        $credentials = null;
        $rawJson = trim((string) config('services.firebase.service_account_json'));

        if ($rawJson !== '') {
            $credentials = $this->decodeCredentialsJson($rawJson);
        }

        if (!$credentials) {
            $credentialsPath = trim((string) config('services.firebase.credentials'));

            if ($credentialsPath === '') {
                throw new FirebaseNotificationException('Firebase credentials are not configured', [
                    'required_env' => [
                        'FIREBASE_PROJECT_ID',
                        'FIREBASE_CREDENTIALS or FIREBASE_SERVICE_ACCOUNT_JSON',
                    ],
                ], 500);
            }

            $resolvedPath = $this->resolveCredentialsPath($credentialsPath);
            if (!is_readable($resolvedPath)) {
                throw new FirebaseNotificationException('Firebase credentials file is not readable', [
                    'path' => $resolvedPath,
                ], 500);
            }

            $credentials = $this->decodeCredentialsJson((string) file_get_contents($resolvedPath));
        }

        foreach (['client_email', 'private_key'] as $key) {
            if (empty($credentials[$key])) {
                throw new FirebaseNotificationException('Firebase service account JSON is missing required fields', [
                    'missing_field' => $key,
                ], 500);
            }
        }

        if (!empty($credentials['private_key']) && is_string($credentials['private_key'])) {
            $credentials['private_key'] = str_replace('\\n', "\n", $credentials['private_key']);
        }

        return $credentials;
    }

    private function decodeCredentialsJson(string $json): ?array
    {
        $credentials = json_decode($json, true);
        if (is_array($credentials)) {
            return $credentials;
        }

        $decoded = base64_decode($json, true);
        if ($decoded === false) {
            return null;
        }

        $credentials = json_decode($decoded, true);

        return is_array($credentials) ? $credentials : null;
    }

    private function resolveCredentialsPath(string $path): string
    {
        if (preg_match('/^([A-Za-z]:[\\\\\/]|\/)/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function projectId(array $credentials): string
    {
        $projectId = trim((string) (config('services.firebase.project_id') ?: ($credentials['project_id'] ?? '')));

        if ($projectId === '') {
            throw new FirebaseNotificationException('Firebase project id is not configured', [
                'required_env' => 'FIREBASE_PROJECT_ID',
            ], 500);
        }

        return rawurlencode($projectId);
    }

    private function accessToken(array $credentials): string
    {
        $projectId = $this->projectId($credentials);
        $cacheKey = 'firebase_fcm_access_token_' . sha1($projectId . '|' . $credentials['client_email']);

        try {
            $cachedToken = Cache::get($cacheKey);
            if (is_string($cachedToken) && $cachedToken !== '') {
                return $cachedToken;
            }
        } catch (Throwable) {
            $cachedToken = null;
        }

        $tokenResponse = $this->requestAccessToken($credentials);
        $accessToken = $tokenResponse['access_token'];
        $expiresIn = max(60, (int) ($tokenResponse['expires_in'] ?? 3600) - 300);

        try {
            Cache::put($cacheKey, $accessToken, now()->addSeconds($expiresIn));
        } catch (Throwable) {
            // Sending should still work if the cache store is unavailable.
        }

        return $accessToken;
    }

    private function requestAccessToken(array $credentials): array
    {
        $assertion = $this->signedJwt($credentials);

        try {
            $response = Http::timeout(20)
                ->asForm()
                ->post(config('services.firebase.token_uri'), [
                    'grant_type' => self::JWT_GRANT_TYPE,
                    'assertion' => $assertion,
                ]);
        } catch (ConnectionException $e) {
            throw new FirebaseNotificationException('Could not connect to Google OAuth token API', [
                'error' => $e->getMessage(),
            ], 502, $e);
        }

        $body = $response->json();
        if (!is_array($body)) {
            $body = ['raw_response' => $response->body()];
        }

        if (!$response->successful() || empty($body['access_token'])) {
            throw new FirebaseNotificationException('Could not create Firebase access token', [
                'google_status' => $response->status(),
                'google_response' => $body,
            ], $response->status() ?: 502);
        }

        return $body;
    }

    private function signedJwt(array $credentials): string
    {
        if (!extension_loaded('openssl')) {
            throw new FirebaseNotificationException('OpenSSL extension is required for Firebase authentication', null, 500);
        }

        $now = time();
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];
        $payload = [
            'iss' => $credentials['client_email'],
            'scope' => self::FCM_SCOPE,
            'aud' => config('services.firebase.token_uri'),
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $headerJson = json_encode($header);
        $payloadJson = json_encode($payload);

        if (!is_string($headerJson) || !is_string($payloadJson)) {
            throw new FirebaseNotificationException('Could not prepare Firebase authentication request', null, 500);
        }

        $unsignedToken = $this->base64UrlEncode($headerJson) . '.'
            . $this->base64UrlEncode($payloadJson);

        $signed = openssl_sign($unsignedToken, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new FirebaseNotificationException('Could not sign Firebase authentication request', null, 500);
        }

        return $unsignedToken . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function stringData(array $data): array
    {
        $stringData = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                $stringData[(string) $key] = '';
            } elseif (is_bool($value)) {
                $stringData[(string) $key] = $value ? 'true' : 'false';
            } elseif (is_scalar($value)) {
                $stringData[(string) $key] = (string) $value;
            } else {
                $encodedValue = json_encode($value);
                $stringData[(string) $key] = is_string($encodedValue) ? $encodedValue : '';
            }
        }

        return $stringData;
    }
}
