<?php

namespace App\Service;

use App\Models\OnlinePayment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SslCommerzService extends AmarPayService
{
    protected const GATEWAY = 'sslcommerz';

    private function baseUrl(): string
    {
        return config('services.sslcommerz.sandbox')
            ? 'https://sandbox.sslcommerz.com'
            : 'https://securepay.sslcommerz.com';
    }

    private function credentials(): array
    {
        return [
            'store_id' => config('services.sslcommerz.store_id'),
            'store_passwd' => config('services.sslcommerz.store_password'),
        ];
    }

    protected function validateConfig(): ?string
    {
        $callback = config('services.sslcommerz.callback_base_url') ?: config('app.url');
        if (!filter_var($callback, FILTER_VALIDATE_URL) || parse_url($callback, PHP_URL_SCHEME) !== 'https'
            || in_array(null, $this->credentials(), true) || in_array('', $this->credentials(), true)) {
            return 'SSLCOMMERZ requires store credentials and a public HTTPS callback base URL';
        }

        return null;
    }

    protected function transactionId(string $legacyId): string
    {
        return 'SSL-' . bin2hex(random_bytes(12));
    }

    protected function callbackUrl(string $type): string
    {
        $base = config('services.sslcommerz.callback_base_url') ?: config('app.url');

        return rtrim($base, '/') . '/api/payments/sslcommerz/' . $type;
    }

    protected function createGatewaySession(array $payload): Response
    {
        $customer = array_intersect_key($payload, array_flip([
            'cus_name', 'cus_email', 'cus_phone', 'cus_add1', 'cus_city', 'cus_state', 'cus_country',
        ]));

        return Http::asForm()->acceptJson()->connectTimeout(5)->timeout(20)
            ->post($this->baseUrl() . '/gwprocess/v4/api.php', $this->credentials() + $customer + [
                'tran_id' => $payload['tran_id'],
                'total_amount' => $payload['amount'],
                'currency' => $payload['currency'],
                'success_url' => $this->callbackUrl('success'),
                'fail_url' => $this->callbackUrl('fail'),
                'cancel_url' => $this->callbackUrl('cancel'),
                'ipn_url' => $this->callbackUrl('ipn'),
                'shipping_method' => 'NO',
                'num_of_item' => 1,
                'product_name' => substr($payload['desc'], 0, 120),
                'product_category' => 'MyZoo',
                'product_profile' => 'general',
                'cus_postcode' => '0000',
            ]);
    }

    protected function sessionResult(Response $response): array
    {
        $data = $response->json();
        $data = is_array($data) ? $data : [];
        $url = is_array($data) ? ($data['GatewayPageURL'] ?? null) : null;
        $accepted = is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_HOST) === parse_url($this->baseUrl(), PHP_URL_HOST)
            && ($data['status'] ?? null) === 'SUCCESS';

        return [
            'result' => $accepted,
            'payment_url' => $accepted ? $url : null,
            'sessionkey' => $data['sessionkey'] ?? null,
        ];
    }

    public function reconcile(OnlinePayment $payment): ?\Illuminate\Http\JsonResponse
    {
        if ($payment->gateway !== static::GATEWAY || $payment->status === 'success') {
            return null;
        }

        try {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(15)
                ->get($this->baseUrl() . '/validator/api/merchantTransIDvalidationAPI.php', $this->credentials() + [
                    'tran_id' => $payment->merchant_transaction_id,
                    'format' => 'json',
                ]);
        } catch (ConnectionException) {
            return null;
        }

        $attempts = $response->json('element');
        if (!$response->successful() || $response->json('APIConnect') !== 'DONE' || !is_array($attempts)) {
            return null;
        }

        $attempts = array_values(array_filter($attempts, fn ($attempt) =>
            is_array($attempt) && ($attempt['tran_id'] ?? null) === $payment->merchant_transaction_id));
        foreach ($attempts as $attempt) {
            if (in_array($attempt['status'] ?? null, ['VALID', 'VALIDATED'], true)) {
                return $this->success([
                    'tran_id' => $payment->merchant_transaction_id,
                    'val_id' => $attempt['val_id'] ?? null,
                ]);
            }
        }

        $statuses = array_unique(array_column($attempts, 'status'));
        if (count($statuses) === 1 && in_array($statuses[0], ['FAILED', 'CANCELLED', 'EXPIRED'], true)) {
            OnlinePayment::whereKey($payment->id)->where('status', 'initiated')
                ->update(['status' => $statuses[0] === 'CANCELLED' ? 'cancelled' : 'failed']);
        }

        return null;
    }

    protected function validateGatewayPayment(array $data, OnlinePayment $payment): array
    {
        $valId = $data['val_id'] ?? null;
        if (!is_string($valId) || $valId === '' || strlen($valId) > 50) {
            return ['valid' => false];
        }

        try {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(20)
                ->get($this->baseUrl() . '/validator/api/validationserverAPI.php', $this->credentials() + [
                    'val_id' => $valId,
                    'format' => 'json',
                ]);
        } catch (ConnectionException) {
            return ['valid' => false, 'unavailable' => true];
        }

        $result = $response->json();
        if (!$response->successful() || !is_array($result)) {
            return ['valid' => false, 'unavailable' => $response->serverError() || $response->status() === 429];
        }

        $amount = $result['currency_amount'] ?? $result['amount'] ?? null;
        $bankId = $result['bank_tran_id'] ?? null;
        $risk = (string) ($result['risk_level'] ?? '');
        $valid = in_array($result['status'] ?? null, ['VALID', 'VALIDATED'], true)
            && ($result['tran_id'] ?? null) === $payment->merchant_transaction_id
            && ($result['val_id'] ?? null) === $valId
            && ($result['currency_type'] ?? $result['currency'] ?? null) === $payment->currency
            && is_numeric($amount) && number_format((float) $amount, 2, '.', '') === (string) $payment->amount
            && is_string($bankId) && $bankId !== ''
            && in_array($risk, ['0', '1'], true);

        return [
            'valid' => $valid,
            'risk_level' => (int) $risk,
            'callback' => [
                'val_id' => $valId,
                'pg_txnid' => $bankId,
                'status' => $result['status'] ?? null,
            ],
        ];
    }
}
