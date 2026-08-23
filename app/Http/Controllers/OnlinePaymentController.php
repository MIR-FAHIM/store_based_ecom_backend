<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Service\AmarPayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;

class OnlinePaymentController extends Controller
{
    public function __construct(
        protected AmarPayService $aamarPayService
    ) {}

    public function initiate(Request $request)
    {
        $validated = $request->validate([
            'order_id' => ['nullable', 'integer', 'exists:orders,id', 'required_without:payment_group_id'],
            'payment_group_id' => ['nullable', 'string', 'max:64', 'required_without:order_id'],
        ]);

        $user = $request->attributes->get('api_user');

        return $this->aamarPayService->initiatePayment(
            isset($validated['order_id']) ? (int) $validated['order_id'] : null,
            $user,
            $validated['payment_group_id'] ?? null
        );
    }

    public function success(Request $request): RedirectResponse
    {
        $response = $this->aamarPayService->success($request->all());
        $payload = $response->getData();

        if (($payload->status ?? null) !== 'success') {
            return redirect()->away($this->frontendPaymentUrl('payment_failed_path', [
                'status' => 'failed',
                'message' => $payload->message ?? 'Payment verification failed',
                'merchant_transaction_id' => $request->input('mer_txnid'),
            ]));
        }

        $payment = $payload->data->payment ?? null;

        return redirect()->away($this->frontendPaymentUrl('payment_success_path', [
            'status' => 'success',
            'payment_id' => $payment->id ?? null,
            'payment_type' => $payment->payment_type ?? null,
            'payment_group_id' => $payment->payment_group_id ?? null,
            'order_id' => $payment->order_id ?? null,
            'store_id' => $payment->store_id ?? null,
            'store_subscription_id' => $payment->store_subscription_id ?? null,
            'media_resource_order_id' => $payment->media_resource_order_id ?? null,
            'amount' => $payment->amount ?? null,
            'merchant_transaction_id' => $payment->merchant_transaction_id ?? $request->input('mer_txnid'),
            'gateway_transaction_id' => $payment->gateway_transaction_id ?? $request->input('pg_txnid'),
        ]));
    }

    public function fail(Request $request): RedirectResponse
    {
        $response = $this->aamarPayService->fail($request->all());
        $payload = $response->getData();

        return redirect()->away($this->frontendPaymentUrl('payment_failed_path', [
            'status' => 'failed',
            'message' => $payload->message ?? 'Payment failed',
            'merchant_transaction_id' => $request->input('mer_txnid'),
        ]));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $response = $this->aamarPayService->cancel($request->all());
        $payload = $response->getData();

        return redirect()->away($this->frontendPaymentUrl('payment_cancelled_path', [
            'status' => 'cancelled',
            'message' => $payload->message ?? 'Payment cancelled',
            'merchant_transaction_id' => $request->input('mer_txnid'),
        ]));
    }

    public function verifyMediaOrder(Request $request): JsonResponse
    {
        return $this->aamarPayService->success($request->all());
    }

    public function failMediaOrder(Request $request): JsonResponse
    {
        return $this->aamarPayService->fail($request->all());
    }

    public function cancelMediaOrder(Request $request): JsonResponse
    {
        return $this->aamarPayService->cancel($request->all());
    }
    private function frontendPaymentUrl(string $pathConfigKey, array $query = []): string
    {
        $frontendUrl = rtrim(config('services.frontend.url') ?: config('app.url'), '/');
        $path = '/' . ltrim(config("services.frontend.{$pathConfigKey}", '/payment-success'), '/');

        $query = array_filter($query, fn ($value) => $value !== null && $value !== '');
        $queryString = http_build_query($query);

        return $frontendUrl . $path . ($queryString ? '?' . $queryString : '');
    }
}
