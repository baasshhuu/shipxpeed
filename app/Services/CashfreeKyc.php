<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cashfree Secure ID (Verification Suite) client.
 * All settings come from config/cashfree_kyc.php (.env: CASHFREE_KYC_*).
 *
 * Every method returns: ['ok' => bool, 'status' => int, 'data' => array, 'error' => ?string]
 */
class CashfreeKyc
{
    protected function baseUrl(): string
    {
        $env = strtolower((string) config('cashfree_kyc.env', 'sandbox'));
        $env = in_array($env, ['production', 'prod', 'live'], true) ? 'production' : 'sandbox';

        return rtrim(config('cashfree_kyc.base_urls.' . $env), '/');
    }

    protected function headers(): array
    {
        $headers = [
            'x-client-id'     => (string) config('cashfree_kyc.client_id'),
            'x-client-secret' => (string) config('cashfree_kyc.client_secret'),
            'accept'          => 'application/json',
            'content-type'    => 'application/json',
        ];

        if ($version = config('cashfree_kyc.api_version')) {
            $headers['x-api-version'] = $version;
        }

        // Optional 2FA "Public Key" mode: x-cf-signature = RSA(clientId.timestamp)
        $keyPath = config('cashfree_kyc.public_key_path');
        if ($keyPath && is_readable($keyPath)) {
            $publicKey = openssl_pkey_get_public(file_get_contents($keyPath));
            $plain = config('cashfree_kyc.client_id') . '.' . time();
            if ($publicKey && openssl_public_encrypt($plain, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
                $headers['x-cf-signature'] = base64_encode($encrypted);
            }
        }

        return $headers;
    }

    public function isConfigured(): bool
    {
        return config('cashfree_kyc.client_id') && config('cashfree_kyc.client_secret');
    }

    protected function call(string $method, string $path, array $payload = []): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'status' => 500, 'data' => [], 'error' => 'KYC service is not configured (Cashfree keys missing in .env).'];
        }

        $url = $this->baseUrl() . $path;

        try {
            $request = Http::withHeaders($this->headers())->timeout((int) config('cashfree_kyc.timeout', 30));
            $res = $method === 'GET' ? $request->get($url, $payload) : $request->post($url, $payload);
        } catch (\Throwable $e) {
            Log::error('Cashfree KYC network error', ['path' => $path, 'error' => $e->getMessage()]);
            return ['ok' => false, 'status' => 503, 'data' => [], 'error' => 'Could not reach the verification service. Please try again.'];
        }

        $json = $res->json() ?? [];

        if ($res->successful()) {
            return ['ok' => true, 'status' => $res->status(), 'data' => $json, 'error' => null];
        }

        Log::warning('Cashfree KYC error', ['path' => $path, 'http' => $res->status(), 'code' => $json['code'] ?? null, 'message' => $json['message'] ?? null]);

        return ['ok' => false, 'status' => $res->status(), 'data' => $json, 'error' => $this->friendlyError($res->status(), $json)];
    }

    protected function friendlyError(int $http, array $json): string
    {
        $msg = $json['message'] ?? 'Verification failed';

        return match (true) {
            $http === 401 => 'Verification service rejected our credentials. Please contact support.',
            $http === 403 => 'Verification service blocked this server (IP not whitelisted). Please contact support.',
            $http === 422 && stripos($msg, 'balance') !== false => 'Verification is temporarily unavailable (insufficient balance). Please contact support.',
            $http === 429 => 'Too many attempts. Please wait a minute and try again.',
            $http >= 500 => 'The government source is temporarily unavailable. Please try again in a few minutes.',
            default => $msg,
        };
    }

    // ---------- DigiLocker (Aadhaar) ----------

    public function digilockerCreateUrl(string $verificationId, ?string $redirectUrl = null): array
    {
        $payload = [
            'verification_id'    => $verificationId,
            'document_requested' => ['AADHAAR'],
            'user_flow'          => 'signup',
        ];
        if ($redirectUrl && str_starts_with($redirectUrl, 'https://')) {
            $payload['redirect_url'] = $redirectUrl;
        }

        return $this->call('POST', '/digilocker', $payload);
    }

    public function digilockerStatus(string $verificationId): array
    {
        return $this->call('GET', '/digilocker', ['verification_id' => $verificationId]);
    }

    public function digilockerAadhaar(string $verificationId): array
    {
        return $this->call('GET', '/digilocker/document/AADHAAR', ['verification_id' => $verificationId]);
    }

    // ---------- PAN / GSTIN / Bank / CIN ----------

    public function verifyPan(string $pan, ?string $name = null): array
    {
        $payload = ['pan' => strtoupper($pan)];
        if ($name) {
            $payload['name'] = $name;
        }
        $r = $this->call('POST', '/pan', $payload);

        if ($r['ok']) {
            $valid = ($r['data']['valid'] ?? false) === true
                || strtoupper((string) ($r['data']['pan_status'] ?? '')) === 'VALID';
            if (!$valid) {
                $r['ok'] = false;
                $r['error'] = $r['data']['message'] ?? 'PAN is not valid.';
            }
        }

        return $r;
    }

    public function verifyGstin(string $gstin): array
    {
        $r = $this->call('POST', '/gstin', ['GSTIN' => strtoupper($gstin)]);

        if ($r['ok'] && ($r['data']['valid'] ?? false) !== true) {
            $r['ok'] = false;
            $r['error'] = $r['data']['message'] ?? 'GSTIN is not valid.';
        }

        return $r;
    }

    public function verifyBank(string $account, string $ifsc, ?string $name = null): array
    {
        $payload = ['bank_account' => $account, 'ifsc' => strtoupper($ifsc)];
        if ($name) {
            $payload['name'] = $name;
        }
        $r = $this->call('POST', '/bank-account/sync', $payload);

        if ($r['ok'] && strtoupper((string) ($r['data']['account_status'] ?? '')) !== 'VALID') {
            $r['ok'] = false;
            $code = $r['data']['account_status_code'] ?? '';
            $r['error'] = match ($code) {
                'INVALID_IFSC_FAIL' => 'Invalid IFSC code.',
                'ACCOUNT_BLOCKED'   => 'This bank account is blocked / frozen.',
                'NRE_ACCOUNT_FAIL'  => 'NRE accounts are not supported.',
                default             => 'Bank account could not be verified. Check the account number and IFSC.',
            };
        }

        return $r;
    }

    public function verifyCin(string $cin): array
    {
        return $this->call('POST', '/cin', ['verification_id' => 'cin_' . uniqid(), 'cin' => strtoupper($cin)]);
    }
}
