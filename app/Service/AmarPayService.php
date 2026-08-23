<?php

namespace App\Service;

use App\Models\OnlinePayment;
use App\Models\MediaResourceOrder;
use App\Models\Order;
use App\Models\Shops;
use App\Models\StoreSubscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;

class AmarPayService
{
    private const PAYMENT_TYPE_ORDER = 'order';
    private const PAYMENT_TYPE_STORE_SUBSCRIPTION = 'store_subscription';
    private const PAYMENT_TYPE_MEDIA_RESOURCE_ORDER = 'media_resource_order';

    public function initiatePayment(?int $orderId, ?User $authenticatedUser = null, ?string $paymentGroupId = null): JsonResponse
    {
        $configError = $this->validateConfig();
        if ($configError) {
            return $this->jsonFailed($configError, null, 500);
        }

        if (!$authenticatedUser) {
            return $this->jsonFailed('Authentication required', null, 401);
        }

        $orders = $this->resolvePaymentOrders($orderId, $paymentGroupId);
        if ($orders->isEmpty()) {
            return $this->jsonFailed('No payable orders found', null, 404);
        }

        if (!$this->canInitiatePaymentForOrders($orders, $authenticatedUser)) {
            return $this->jsonFailed('You cannot pay for this order', [
                'order_user_ids' => $orders->pluck('user_id')->unique()->values(),
                'authenticated_user_id' => (int) $authenticatedUser->id,
            ], 403);
        }

        $unpaidOrders = $orders
            ->filter(fn (Order $order) => $order->payment_status !== 'paid')
            ->values();

        if ($unpaidOrders->isEmpty()) {
            return $this->jsonFailed('All orders in this payment are already paid', null, 409);
        }

        $amount = round($unpaidOrders->sum(fn (Order $order) => (float) $order->total), 2);
        if ($amount <= 0) {
            return $this->jsonFailed('Payment total must be greater than zero', null, 422);
        }

        $primaryOrder = $unpaidOrders->first();
        $paymentGroupId = $paymentGroupId ?: $primaryOrder->payment_group_id;
        $orderIds = $unpaidOrders->pluck('id')->values()->all();
        $merchantTransactionId = 'PAY-' . $primaryOrder->id . '-' . now()->format('ymdHis') . '-' . random_int(1000, 9999);

        $payment = OnlinePayment::create([
            'payment_type' => self::PAYMENT_TYPE_ORDER,
            'order_id' => $primaryOrder->id,
            'payment_group_id' => $paymentGroupId,
            'order_ids' => $orderIds,
            'user_id' => $primaryOrder->user_id,
            'gateway' => 'aamarpay',
            'merchant_transaction_id' => $merchantTransactionId,
            'amount' => $amount,
            'currency' => 'BDT',
            'status' => 'initiated',
            'initiated_at' => now(),
        ]);

        $payload = [
            'store_id' => config('services.aamarpay.store_id'),
            'tran_id' => $merchantTransactionId,
            'success_url' => $this->callbackUrl('success'),
            'fail_url' => $this->callbackUrl('fail'),
            'cancel_url' => $this->callbackUrl('cancel'),
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'BDT',
            'signature_key' => config('services.aamarpay.signature_key'),
            'desc' => $unpaidOrders->count() > 1
                ? 'Checkout payment ' . ($paymentGroupId ?? $merchantTransactionId)
                : 'Order #' . ($primaryOrder->order_number ?? $primaryOrder->id),
            'cus_name' => $primaryOrder->customer_name ?: 'Customer',
            'cus_email' => optional($primaryOrder->user)->email ?: 'customer@example.com',
            'cus_phone' => $primaryOrder->customer_phone ?: optional($primaryOrder->user)->phone ?: '01000000000',
            'cus_add1' => $primaryOrder->shipping_address ?: 'Not provided',
            'cus_city' => $primaryOrder->district ?: $primaryOrder->area ?: 'Dhaka',
            'cus_state' => $primaryOrder->zone ?: $primaryOrder->district ?: 'Dhaka',
            'cus_country' => 'Bangladesh',
            'opt_a' => $paymentGroupId,
            'opt_b' => implode(',', $orderIds),
            'opt_c' => (string) $unpaidOrders->count(),
            'type' => 'json',
        ];
        $payload = array_filter($payload, fn ($value) => $value !== null && $value !== '');

        try {
            $response = Http::timeout(20)
                ->asJson()
                ->post($this->paymentUrl(), $payload);
        } catch (ConnectionException $e) {
            $payment->update([
                'status' => 'failed',
                'gateway_response' => ['error' => $e->getMessage()],
            ]);

            return $this->jsonFailed('Could not connect to AamarPay', null, 502);
        }

        $result = $response->json();
        if (!is_array($result)) {
            $result = ['raw_response' => $response->body()];
        }

        $payment->update([
            'gateway_response' => [
                'request' => $this->safePayloadForLogs($payload),
                'response' => $result,
            ],
        ]);

        if (!$response->successful() || !$this->gatewayAccepted($result) || empty($result['payment_url'])) {
            $payment->update(['status' => 'failed']);

            return $this->jsonFailed('AamarPay rejected the payment request', $result, 502);
        }

        $payment->update(['status' => 'pending']);

        return $this->jsonSuccess('Payment initiated successfully', [
            'payment_id' => $payment->id,
            'payment_group_id' => $payment->payment_group_id,
            'order_ids' => $payment->order_ids,
            'amount' => $payment->amount,
            'merchant_transaction_id' => $payment->merchant_transaction_id,
            'payment_url' => $result['payment_url'],
        ]);
    }

    public function initiateStoreSubscriptionPayment(StoreSubscription $subscription, ?User $authenticatedUser = null): JsonResponse
    {
        $configError = $this->validateConfig();
        if ($configError) {
            return $this->jsonFailed($configError, null, 500);
        }

        if (!$authenticatedUser) {
            return $this->jsonFailed('Authentication required', null, 401);
        }

        $subscription->loadMissing(['store.user', 'package']);
        $store = $subscription->store;

        if (!$store) {
            return $this->jsonFailed('Store not found for subscription', null, 404);
        }

        if (!$this->canInitiatePaymentForStore($store, $authenticatedUser)) {
            return $this->jsonFailed('You cannot pay for this store subscription', [
                'store_owner_id' => $store->user_id,
                'authenticated_user_id' => (int) $authenticatedUser->id,
            ], 403);
        }

        if ($subscription->payment_status === 'paid' || $subscription->status === 'active') {
            return $this->jsonFailed('This subscription is already paid or active', null, 409);
        }

        $amount = round((float) $subscription->price, 2);
        if ($amount <= 0) {
            $subscription->update([
                'status' => 'active',
                'payment_status' => 'paid',
                'starts_at' => $subscription->starts_at ?: now(),
            ]);

            return $this->jsonSuccess('Subscription activated successfully', [
                'subscription' => $subscription->fresh(['package', 'store']),
                'payment_required' => false,
                'payment_url' => null,
            ]);
        }

        $merchantTransactionId = 'SUB-' . $subscription->id . '-' . now()->format('ymdHis') . '-' . random_int(1000, 9999);

        $payment = OnlinePayment::create([
            'payment_type' => self::PAYMENT_TYPE_STORE_SUBSCRIPTION,
            'order_id' => null,
            'payment_group_id' => null,
            'order_ids' => null,
            'store_subscription_id' => $subscription->id,
            'store_id' => $store->id,
            'user_id' => $authenticatedUser->id,
            'gateway' => 'aamarpay',
            'merchant_transaction_id' => $merchantTransactionId,
            'amount' => $amount,
            'currency' => $subscription->currency ?: 'BDT',
            'status' => 'initiated',
            'initiated_at' => now(),
        ]);

        $customerName = $store->shop_name ?: $store->name ?: $authenticatedUser->name ?: 'Store Owner';
        $customerEmail = $store->email ?: $authenticatedUser->email ?: 'merchant@example.com';
        $customerPhone = $store->phone ?: $authenticatedUser->phone ?: '01000000000';
        $address = $store->address ?: 'Not provided';
        $city = $store->district ?: $store->area ?: 'Dhaka';
        $state = $store->zone ?: $store->district ?: 'Dhaka';

        $payload = [
            'store_id' => config('services.aamarpay.store_id'),
            'tran_id' => $merchantTransactionId,
            'success_url' => $this->callbackUrl('success'),
            'fail_url' => $this->callbackUrl('fail'),
            'cancel_url' => $this->callbackUrl('cancel'),
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => $subscription->currency ?: 'BDT',
            'signature_key' => config('services.aamarpay.signature_key'),
            'desc' => 'Store subscription #' . $subscription->id . ' - ' . optional($subscription->package)->name,
            'cus_name' => $customerName,
            'cus_email' => $customerEmail,
            'cus_phone' => $customerPhone,
            'cus_add1' => $address,
            'cus_city' => $city,
            'cus_state' => $state,
            'cus_country' => 'Bangladesh',
            'opt_a' => 'store_subscription',
            'opt_b' => (string) $subscription->id,
            'opt_c' => (string) $store->id,
            'type' => 'json',
        ];
        $payload = array_filter($payload, fn ($value) => $value !== null && $value !== '');

        try {
            $response = Http::timeout(20)
                ->asJson()
                ->post($this->paymentUrl(), $payload);
        } catch (ConnectionException $e) {
            $payment->update([
                'status' => 'failed',
                'gateway_response' => ['error' => $e->getMessage()],
            ]);

            return $this->jsonFailed('Could not connect to AamarPay', null, 502);
        }

        $result = $response->json();
        if (!is_array($result)) {
            $result = ['raw_response' => $response->body()];
        }

        $payment->update([
            'gateway_response' => [
                'request' => $this->safePayloadForLogs($payload),
                'response' => $result,
            ],
        ]);

        if (!$response->successful() || !$this->gatewayAccepted($result) || empty($result['payment_url'])) {
            $payment->update(['status' => 'failed']);

            return $this->jsonFailed('AamarPay rejected the subscription payment request', $result, 502);
        }

        $payment->update(['status' => 'pending']);

        return $this->jsonSuccess('Subscription payment initiated successfully', [
            'subscription' => $subscription->fresh(['package', 'store']),
            'payment_required' => true,
            'payment_id' => $payment->id,
            'merchant_transaction_id' => $payment->merchant_transaction_id,
            'amount' => $payment->amount,
            'payment_url' => $result['payment_url'],
        ], 201);
    }

    public function initiateMediaResourceOrderPayment(MediaResourceOrder $mediaOrder, ?User $authenticatedUser = null): JsonResponse
    {
        $configError = $this->validateConfig();
        if ($configError) {
            return $this->jsonFailed($configError, null, 500);
        }

        if (!$authenticatedUser) {
            return $this->jsonFailed('Authentication required', null, 401);
        }

        $mediaOrder->loadMissing(['store', 'seller', 'items.resource']);
        $store = $mediaOrder->store;

        if (!$store) {
            return $this->jsonFailed('Store not found for media order', null, 404);
        }

        if (!$this->canInitiatePaymentForStore($store, $authenticatedUser)) {
            return $this->jsonFailed('You cannot pay for this media order', [
                'store_owner_id' => $store->user_id,
                'authenticated_user_id' => (int) $authenticatedUser->id,
            ], 403);
        }

        if ($mediaOrder->payment_status === 'paid' || $mediaOrder->status === 'completed') {
            return $this->jsonFailed('This media order is already paid', null, 409);
        }

        $amount = round((float) $mediaOrder->total, 2);
        if ($amount <= 0) {
            $mediaOrder->update([
                'status' => 'paid',
                'payment_status' => 'paid',
                'paid_at' => $mediaOrder->paid_at ?: now(),
            ]);

            return $this->jsonSuccess('Media order activated successfully', [
                'media_order' => $mediaOrder->fresh(['store', 'items.resource']),
                'payment_required' => false,
                'payment_url' => null,
            ]);
        }

        $merchantTransactionId = 'MEDIA-' . $mediaOrder->id . '-' . now()->format('ymdHis') . '-' . random_int(1000, 9999);

        $payment = OnlinePayment::create([
            'payment_type' => self::PAYMENT_TYPE_MEDIA_RESOURCE_ORDER,
            'order_id' => null,
            'payment_group_id' => null,
            'order_ids' => null,
            'store_subscription_id' => null,
            'media_resource_order_id' => $mediaOrder->id,
            'store_id' => $mediaOrder->store_id,
            'user_id' => $authenticatedUser->id,
            'gateway' => 'aamarpay',
            'merchant_transaction_id' => $merchantTransactionId,
            'amount' => $amount,
            'currency' => $mediaOrder->currency ?: 'BDT',
            'status' => 'initiated',
            'initiated_at' => now(),
        ]);

        $customerName = $store->shop_name ?: $store->name ?: $authenticatedUser->name ?: 'Store Owner';
        $customerEmail = $store->email ?: $authenticatedUser->email ?: 'merchant@example.com';
        $customerPhone = $store->phone ?: $authenticatedUser->phone ?: '01000000000';
        $address = $store->address ?: 'Not provided';
        $city = $store->district ?: $store->area ?: 'Dhaka';
        $state = $store->zone ?: $store->district ?: 'Dhaka';

        $payload = [
            'store_id' => config('services.aamarpay.store_id'),
            'tran_id' => $merchantTransactionId,
            'success_url' => $this->callbackUrl('success'),
            'fail_url' => $this->callbackUrl('fail'),
            'cancel_url' => $this->callbackUrl('cancel'),
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => $mediaOrder->currency ?: 'BDT',
            'signature_key' => config('services.aamarpay.signature_key'),
            'desc' => 'Media order #' . $mediaOrder->order_number,
            'cus_name' => $customerName,
            'cus_email' => $customerEmail,
            'cus_phone' => $customerPhone,
            'cus_add1' => $address,
            'cus_city' => $city,
            'cus_state' => $state,
            'cus_country' => 'Bangladesh',
            'opt_a' => 'media_resource_order',
            'opt_b' => (string) $mediaOrder->id,
            'opt_c' => (string) $mediaOrder->store_id,
            'type' => 'json',
        ];
        $payload = array_filter($payload, fn ($value) => $value !== null && $value !== '');

        try {
            $response = Http::timeout(20)
                ->asJson()
                ->post($this->paymentUrl(), $payload);
        } catch (ConnectionException $e) {
            $payment->update([
                'status' => 'failed',
                'gateway_response' => ['error' => $e->getMessage()],
            ]);

            return $this->jsonFailed('Could not connect to AamarPay', null, 502);
        }

        $result = $response->json();
        if (!is_array($result)) {
            $result = ['raw_response' => $response->body()];
        }

        $payment->update([
            'gateway_response' => [
                'request' => $this->safePayloadForLogs($payload),
                'response' => $result,
            ],
        ]);

        if (!$response->successful() || !$this->gatewayAccepted($result) || empty($result['payment_url'])) {
            $payment->update(['status' => 'failed']);

            return $this->jsonFailed('AamarPay rejected the media order payment request', $result, 502);
        }

        $payment->update(['status' => 'pending']);

        return $this->jsonSuccess('Media order payment initiated successfully', [
            'media_order' => $mediaOrder->fresh(['store', 'items.resource']),
            'payment_required' => true,
            'payment_id' => $payment->id,
            'merchant_transaction_id' => $payment->merchant_transaction_id,
            'amount' => $payment->amount,
            'payment_url' => $result['payment_url'],
        ], 201);
    }

    public function success(array $data): JsonResponse
    {
        $payment = $this->findPaymentFromCallback($data);
        if (!$payment) {
            return $this->jsonFailed('Payment not found for callback', null, 404);
        }

        $validation = $this->validateGatewayPayment($data, $payment);
        if (!$validation['valid']) {
            $payment->update([
                'gateway_response' => $this->appendCallbackData($payment, $data, $validation),
            ]);

            return $this->jsonFailed('Payment could not be verified with AamarPay', $validation, 422);
        }

        $payment = DB::transaction(function () use ($payment, $data, $validation) {
            $lockedPayment = OnlinePayment::whereKey($payment->id)->lockForUpdate()->first();

            if ($lockedPayment->status !== 'success' && $lockedPayment->payment_type === self::PAYMENT_TYPE_ORDER) {
                $orderIds = $this->orderIdsForPayment($lockedPayment);
                $orders = Order::whereIn('id', $orderIds)->lockForUpdate()->get();
                $gatewayTransactionId = $data['pg_txnid'] ?? $lockedPayment->gateway_transaction_id;

                $lockedPayment->update([
                    'status' => 'success',
                    'gateway_transaction_id' => $gatewayTransactionId,
                    'gateway_fee' => $data['gateway_fee'] ?? $lockedPayment->gateway_fee ?? 0,
                    'gateway_response' => $this->appendCallbackData($lockedPayment, $data, $validation),
                    'paid_at' => $lockedPayment->paid_at ?: now(),
                ]);

                Order::whereIn('id', $orderIds)->update(['payment_status' => 'paid']);

                foreach ($orders as $order) {
                    Transaction::firstOrCreate(
                        [
                            'order_id' => $order->id,
                            'source' => 'online_payment',
                            'type' => 'order_payment',
                        ],
                        [
                            'amount' => $order->total,
                            'ref_id' => $lockedPayment->merchant_transaction_id,
                            'trx_id' => $gatewayTransactionId,
                            'trx_type' => 'credit',
                            'status' => 'completed',
                            'note' => 'Online payment received for order #' . $order->order_number,
                        ]
                    );
                }
            } elseif ($lockedPayment->status !== 'success' && $lockedPayment->payment_type === self::PAYMENT_TYPE_STORE_SUBSCRIPTION) {
                $gatewayTransactionId = $data['pg_txnid'] ?? $lockedPayment->gateway_transaction_id;

                $subscription = StoreSubscription::whereKey($lockedPayment->store_subscription_id)
                    ->lockForUpdate()
                    ->first();

                $lockedPayment->update([
                    'status' => 'success',
                    'gateway_transaction_id' => $gatewayTransactionId,
                    'gateway_fee' => $data['gateway_fee'] ?? $lockedPayment->gateway_fee ?? 0,
                    'gateway_response' => $this->appendCallbackData($lockedPayment, $data, $validation),
                    'paid_at' => $lockedPayment->paid_at ?: now(),
                ]);

                if ($subscription) {
                    StoreSubscription::where('store_id', $subscription->store_id)
                        ->where('id', '!=', $subscription->id)
                        ->whereIn('status', ['pending', 'active'])
                        ->update(['status' => 'cancelled']);

                    $subscription->update([
                        'status' => 'active',
                        'payment_status' => 'paid',
                        'payment_reference' => $gatewayTransactionId ?: $lockedPayment->merchant_transaction_id,
                        'starts_at' => $subscription->starts_at ?: now(),
                    ]);
                }
            } elseif ($lockedPayment->status !== 'success' && $lockedPayment->payment_type === self::PAYMENT_TYPE_MEDIA_RESOURCE_ORDER) {
                $gatewayTransactionId = $data['pg_txnid'] ?? $lockedPayment->gateway_transaction_id;

                $mediaOrder = MediaResourceOrder::whereKey($lockedPayment->media_resource_order_id)
                    ->lockForUpdate()
                    ->first();

                $lockedPayment->update([
                    'status' => 'success',
                    'gateway_transaction_id' => $gatewayTransactionId,
                    'gateway_fee' => $data['gateway_fee'] ?? $lockedPayment->gateway_fee ?? 0,
                    'gateway_response' => $this->appendCallbackData($lockedPayment, $data, $validation),
                    'paid_at' => $lockedPayment->paid_at ?: now(),
                ]);

                if ($mediaOrder) {
                    $mediaOrder->update([
                        'status' => 'pending_design',
                        'payment_status' => 'paid',
                        'paid_at' => $mediaOrder->paid_at ?: now(),
                    ]);
                }
            }

            $freshPayment = $lockedPayment->fresh(['order', 'storeSubscription.package', 'mediaResourceOrder.items.resource', 'store']);
            if ($lockedPayment->payment_type === self::PAYMENT_TYPE_ORDER) {
                $freshPayment->paid_orders = Order::whereIn('id', $this->orderIdsForPayment($lockedPayment))->get();
            }

            return $freshPayment;
        });

        return $this->jsonSuccess('Payment verified successfully', [
            'payment' => $payment,
        ]);
    }

    public function fail(array $data): JsonResponse
    {
        return $this->markCallbackAs($data, 'failed', 'Payment marked as failed');
    }

    public function cancel(array $data): JsonResponse
    {
        return $this->markCallbackAs($data, 'cancelled', 'Payment marked as cancelled');
    }

    private function markCallbackAs(array $data, string $status, string $message): JsonResponse
    {
        $payment = $this->findPaymentFromCallback($data);
        if (!$payment) {
            return $this->jsonFailed('Payment not found for callback', null, 404);
        }

        if ($payment->status === 'success') {
            return $this->jsonSuccess('Payment is already verified successfully', ['payment_id' => $payment->id]);
        }

        $payment->update([
            'status' => $status,
            'gateway_response' => $this->appendCallbackData($payment, $data),
        ]);

        if ($payment->payment_type === self::PAYMENT_TYPE_ORDER && $status === 'failed') {
            Order::whereIn('id', $this->orderIdsForPayment($payment))
                ->where('payment_status', '!=', 'paid')
                ->update(['payment_status' => 'failed']);
        }

        if ($payment->payment_type === self::PAYMENT_TYPE_STORE_SUBSCRIPTION) {
            $subscriptionStatus = $status === 'failed' ? 'pending' : 'cancelled';
            $paymentStatus = $status === 'failed' ? 'failed' : 'unpaid';

            StoreSubscription::whereKey($payment->store_subscription_id)
                ->where('payment_status', '!=', 'paid')
                ->update([
                    'status' => $subscriptionStatus,
                    'payment_status' => $paymentStatus,
                ]);
        }

        if ($payment->payment_type === self::PAYMENT_TYPE_MEDIA_RESOURCE_ORDER) {
            $orderStatus = $status === 'failed' ? 'pending_payment' : 'cancelled';
            $paymentStatus = $status === 'failed' ? 'failed' : 'unpaid';

            MediaResourceOrder::whereKey($payment->media_resource_order_id)
                ->where('payment_status', '!=', 'paid')
                ->update([
                    'status' => $orderStatus,
                    'payment_status' => $paymentStatus,
                ]);
        }

        return $this->jsonSuccess($message, ['payment_id' => $payment->id]);
    }

    private function resolvePaymentOrders(?int $orderId, ?string $paymentGroupId): Collection
    {
        if ($paymentGroupId) {
            return Order::where('payment_group_id', $paymentGroupId)
                ->orderBy('id')
                ->get();
        }

        if (!$orderId) {
            return collect();
        }

        $order = Order::find($orderId);
        if (!$order) {
            return collect();
        }

        if ($order->payment_group_id) {
            return Order::where('payment_group_id', $order->payment_group_id)
                ->orderBy('id')
                ->get();
        }

        return collect([$order]);
    }

    private function canInitiatePaymentForOrders(Collection $orders, User $authenticatedUser): bool
    {
        if ($this->isAdmin($authenticatedUser)) {
            return true;
        }

        return $orders->every(
            fn (Order $order) => (int) $order->user_id === (int) $authenticatedUser->id
        );
    }

    private function canInitiatePaymentForStore(Shops $store, User $authenticatedUser): bool
    {
        if ($this->isAdmin($authenticatedUser)) {
            return true;
        }

        return (int) $store->user_id === (int) $authenticatedUser->id;
    }

    private function isAdmin(User $authenticatedUser): bool
    {
        $role = strtolower((string) ($authenticatedUser->role ?? ''));
        $userType = strtolower((string) ($authenticatedUser->user_type ?? ''));

        return in_array('admin', [$role, $userType], true);
    }

    private function orderIdsForPayment(OnlinePayment $payment): array
    {
        if (is_array($payment->order_ids) && count($payment->order_ids) > 0) {
            return array_map('intval', $payment->order_ids);
        }

        if ($payment->payment_group_id) {
            return Order::where('payment_group_id', $payment->payment_group_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return [(int) $payment->order_id];
    }

    private function findPaymentFromCallback(array $data): ?OnlinePayment
    {
        $merchantTransactionId = $data['mer_txnid'] ?? $data['tran_id'] ?? null;
        if (!$merchantTransactionId) {
            return null;
        }

        return OnlinePayment::with('order')
            ->where('merchant_transaction_id', $merchantTransactionId)
            ->first();
    }

    private function validateGatewayPayment(array $data, OnlinePayment $payment): array
    {
        // AamarPay Search Transaction API expects the merchant transaction id.
        $requestId = $payment->merchant_transaction_id;
        if (!$requestId) {
            return ['valid' => false, 'reason' => 'Missing merchant transaction id'];
        }

        try {
            $response = Http::timeout(20)->get($this->validationUrl(), [
                'request_id' => $requestId,
                'store_id' => config('services.aamarpay.store_id'),
                'signature_key' => config('services.aamarpay.signature_key'),
                'type' => 'json',
            ]);
        } catch (ConnectionException $e) {
            return ['valid' => false, 'reason' => 'Could not connect to AamarPay validation API'];
        }

        $body = $response->json();
        if (!is_array($body)) {
            $body = ['raw_response' => $response->body()];
        }

        $amount = (float) ($body['amount'] ?? $body['amount_bdt'] ?? $data['amount'] ?? 0);
        $status = strtolower((string) ($body['pay_status'] ?? $body['status'] ?? $data['pay_status'] ?? ''));
        $statusCode = (string) ($body['status_code'] ?? $data['status_code'] ?? '');
        $merchantTransactionId = $body['mer_txnid'] ?? $body['tran_id'] ?? $data['mer_txnid'] ?? null;

        $valid = $response->successful()
            && ($statusCode === '2' || in_array($status, ['successful', 'success', 'paid', 'complete', 'completed'], true))
            && $merchantTransactionId === $payment->merchant_transaction_id
            && abs($amount - (float) $payment->amount) < 0.01;

        return [
            'valid' => $valid,
            'request_id' => $requestId,
            'status' => $status,
            'status_code' => $statusCode,
            'amount' => $amount,
            'gateway_response' => $body,
        ];
    }

    private function appendCallbackData(OnlinePayment $payment, array $callback, array $validation = []): array
    {
        $existing = is_array($payment->gateway_response) ? $payment->gateway_response : [];

        return array_merge($existing, [
            'callback' => $callback,
            'validation' => $validation,
        ]);
    }

    private function safePayloadForLogs(array $payload): array
    {
        $safePayload = $payload;
        $safePayload['signature_key'] = '***';

        return $safePayload;
    }

    private function gatewayAccepted(array $result): bool
    {
        return in_array($result['result'] ?? null, [true, 'true', 'TRUE', 1, '1'], true);
    }

    private function validateConfig(): ?string
    {
        if (!config('services.aamarpay.base_url')) {
            return 'AamarPay base URL is not configured';
        }

        if (!config('services.aamarpay.store_id') || !config('services.aamarpay.signature_key')) {
            return 'AamarPay credentials are not configured';
        }

        return null;
    }

    private function paymentUrl(): string
    {
        return rtrim(config('services.aamarpay.base_url'), '/') . '/jsonpost.php';
    }

    private function validationUrl(): string
    {
        return config('services.aamarpay.validation_url')
            ?: rtrim(config('services.aamarpay.base_url'), '/') . '/api/v1/trxcheck/request.php';
    }

    private function callbackUrl(string $type): string
    {
        return config("services.aamarpay.{$type}_url")
            ?: url("/api/payments/aamarpay/{$type}");
    }

    private function jsonSuccess(string $message, mixed $data = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    private function jsonFailed(string $message, mixed $errors = null, int $code = 400): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }
}
