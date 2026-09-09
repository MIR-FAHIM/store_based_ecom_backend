<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Service\AmarPayService;
use App\Models\OnlinePayment;
use Illuminate\Support\Carbon;
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
            'store_slug' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->attributes->get('api_user');

        return $this->aamarPayService->initiatePayment(
            isset($validated['order_id']) ? (int) $validated['order_id'] : null,
            $user,
            $validated['payment_group_id'] ?? null,
            $validated['store_slug'] ?? null
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
    public function verifyStoreOrder(Request $request): JsonResponse
    {
        return $this->aamarPayService->success($request->all());
    }

    public function failStoreOrder(Request $request): JsonResponse
    {
        return $this->aamarPayService->fail($request->all());
    }

    public function cancelStoreOrder(Request $request): JsonResponse
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

    private function responseSuccess($message, $data = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    private function responseFailed($message, $errors = null, int $code = 400): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    /**
     * GET /payments/online/list
     * Paginated list of online payments with filters
     */
    public function listOnlinePayments(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $query = OnlinePayment::with([
                'user:id,name,email,phone',
                'store:id,name,shop_name,slug',
                'order:id,order_number,total,status,payment_status',
                'storeSubscription.package',
                'mediaResourceOrder',
            ]);

            if ($request->filled('status')) {
                $status = strtolower((string) $request->query('status'));
                if (in_array($status, ['success', 'successful'], true)) {
                    $query->whereIn('status', ['success', 'successful', 'successful_verified']);
                } else {
                    $query->where('status', $status);
                }
            }

            if ($request->filled('payment_type')) {
                $query->where('payment_type', $request->query('payment_type'));
            }

            if ($request->filled('gateway')) {
                $query->where('gateway', $request->query('gateway'));
            }

            if ($request->filled('store_id')) {
                $query->where('store_id', (int) $request->query('store_id'));
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->query('user_id'));
            }

            if ($request->filled('start_date')) {
                $query->whereDate('created_at', '>=', $request->query('start_date'));
            }
            if ($request->filled('end_date')) {
                $query->whereDate('created_at', '<=', $request->query('end_date'));
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->query('search'));
                $like = '%' . $search . '%';

                $query->where(function ($q) use ($like) {
                    $q->where('merchant_transaction_id', 'like', $like)
                      ->orWhere('gateway_transaction_id', 'like', $like)
                      ->orWhere('payment_group_id', 'like', $like)
                      ->orWhereHas('user', function ($uq) use ($like) {
                          $uq->where('name', 'like', $like)
                             ->orWhere('email', 'like', $like)
                             ->orWhere('phone', 'like', $like);
                      });
                });
            }

            $payments = $query->latest('id')->paginate($perPage);

            return $this->responseSuccess('Online payments fetched successfully', $payments);
        } catch (\Throwable $e) {
            return $this->responseFailed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /payments/online/report
     * Summary metrics & aggregations for online payments
     */
    public function onlinePaymentReport(Request $request): JsonResponse
    {
        try {
            $query = OnlinePayment::query();

            if ($request->filled('store_id')) {
                $query->where('store_id', (int) $request->query('store_id'));
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->query('user_id'));
            }

            if ($request->filled('payment_type')) {
                $query->where('payment_type', $request->query('payment_type'));
            }

            if ($request->filled('start_date')) {
                $query->whereDate('created_at', '>=', $request->query('start_date'));
            }
            if ($request->filled('end_date')) {
                $query->whereDate('created_at', '<=', $request->query('end_date'));
            }

            $totalCount = (clone $query)->count();
            $totalAmount = (float) (clone $query)->sum('amount');

            $successQuery = (clone $query)->whereIn('status', ['success', 'successful', 'successful_verified']);
            $successCount = (clone $successQuery)->count();
            $successAmount = (float) (clone $successQuery)->sum('amount');

            $failedQuery = (clone $query)->where('status', 'failed');
            $failedCount = (clone $failedQuery)->count();
            $failedAmount = (float) (clone $failedQuery)->sum('amount');

            $pendingQuery = (clone $query)->whereIn('status', ['pending', 'initiated']);
            $pendingCount = (clone $pendingQuery)->count();
            $pendingAmount = (float) (clone $pendingQuery)->sum('amount');

            $cancelledQuery = (clone $query)->where('status', 'cancelled');
            $cancelledCount = (clone $cancelledQuery)->count();
            $cancelledAmount = (float) (clone $cancelledQuery)->sum('amount');

            $todaySuccessQuery = (clone $successQuery)->whereDate('created_at', Carbon::today());
            $todaySuccessCount = (clone $todaySuccessQuery)->count();
            $todaySuccessAmount = (float) (clone $todaySuccessQuery)->sum('amount');

            $monthSuccessQuery = (clone $successQuery)->whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year);
            $monthSuccessCount = (clone $monthSuccessQuery)->count();
            $monthSuccessAmount = (float) (clone $monthSuccessQuery)->sum('amount');

            $byType = (clone $query)
                ->selectRaw('payment_type, status, COUNT(*) as count, SUM(amount) as total_amount')
                ->groupBy('payment_type', 'status')
                ->get();

            return $this->responseSuccess('Online payment report generated successfully', [
                'summary' => [
                    'total_payments' => $totalCount,
                    'total_amount' => round($totalAmount, 2),
                    'successful' => [
                        'count' => $successCount,
                        'amount' => round($successAmount, 2),
                    ],
                    'failed' => [
                        'count' => $failedCount,
                        'amount' => round($failedAmount, 2),
                    ],
                    'pending' => [
                        'count' => $pendingCount,
                        'amount' => round($pendingAmount, 2),
                    ],
                    'cancelled' => [
                        'count' => $cancelledCount,
                        'amount' => round($cancelledAmount, 2),
                    ],
                ],
                'today' => [
                    'successful_count' => $todaySuccessCount,
                    'successful_amount' => round($todaySuccessAmount, 2),
                ],
                'this_month' => [
                    'successful_count' => $monthSuccessCount,
                    'successful_amount' => round($monthSuccessAmount, 2),
                ],
                'breakdown_by_type' => $byType,
            ]);
        } catch (\Throwable $e) {
            return $this->responseFailed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
