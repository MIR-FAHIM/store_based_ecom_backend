<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Shops;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Cart;
use App\Models\Transaction;
use App\Models\Review;
use Carbon\Carbon;

class ReportController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors
        ], $code);
    }

    private function countShopOrdersBetween($shopIds, Carbon $start, Carbon $end): int
    {
        return (int) OrderItem::whereIn('shop_id', $shopIds)
            ->whereHas('order', function ($query) use ($start, $end) {
                $query->whereBetween('created_at', [$start, $end]);
            })
            ->distinct('order_id')
            ->count('order_id');
    }

    /**
     * GET /reports/dashboard
     * Returns summary counts and sales metrics
     */
    public function dashboard(Request $request)
    {
        try {
            $productsCount = Product::count();
            $shopsCount = Shops::count();
            // Users use `user_type` (default 'customer') in the users table
            $customersCount = User::where('user_type', 'customer')->count();

            $ordersCount = Order::count();
            $activeCarts = Cart::where('status', 'active')->count();

            // Sales sums (only consider completed credit transactions)
            $totalSell = (float) Transaction::where('trx_type', 'credit')
                ->where('status', 'completed')
                ->sum('amount');

            $today = Carbon::today()->toDateString();
            $yesterday = Carbon::yesterday()->toDateString();

            $todaySell = (float) Transaction::where('trx_type', 'credit')
                ->where('status', 'completed')
                ->whereDate('created_at', $today)
                ->sum('amount');

            $yesterdaySell = (float) Transaction::where('trx_type', 'credit')
                ->where('status', 'completed')
                ->whereDate('created_at', $yesterday)
                ->sum('amount');

            $last7Start = Carbon::today()->subDays(6)->toDateString(); // include today = 7 days
            $last7Sell = (float) Transaction::where('trx_type', 'credit')
                ->where('status', 'completed')
                ->whereDate('created_at', '>=', $last7Start)
                ->sum('amount');

            // Daily breakdown for last 7 days (most recent first)
            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $d = Carbon::today()->subDays($i)->toDateString();
                $sum = (float) Transaction::where('trx_type', 'credit')
                    ->where('status', 'completed')
                    ->whereDate('created_at', $d)
                    ->sum('amount');
                $days[] = [
                    'date' => $d,
                    'total' => $sum,
                ];
            }

            $data = [
                'products_count' => $productsCount,
                'shops_count' => $shopsCount,
                'customers_count' => $customersCount,
                'orders_count' => $ordersCount,
                'active_carts' => $activeCarts,
                'total_sell' => $totalSell,
                'today_sell' => $todaySell,
                'yesterday_sell' => $yesterdaySell,
                'last_7_days_sell' => $last7Sell,
                'last_7_days_breakdown' => $days,
            ];

            return $this->success('Dashboard metrics fetched', $data);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /reports/shop/{userId}
     * Returns shop metrics for a vendor user
     */
    public function shopReportByUser($userId)
    {
        try {
            $shopIds = Shops::where('user_id', $userId)->pluck('id');

            if ($shopIds->isEmpty()) {
                $data = [
                    'shops_count' => 0,
                    'orders_count' => 0,
                    'orders_amount' => 0,
                    'products_count' => 0,
                    'today_total_orders' => 0,
                    'last_week_total_orders' => 0,
                    'last_month_total_orders' => 0,
                    'year_total_orders' => 0,
                    'orders_by_period' => [
                        'today' => 0,
                        'lastWeek' => 0,
                        'lastMonth' => 0,
                        'year' => 0,
                    ],
                ];

                return $this->success('Shop report fetched', $data);
            }

            $shopsCount = $shopIds->count();
            $ordersCount = OrderItem::whereIn('shop_id', $shopIds)
                ->distinct('order_id')
                ->count('order_id');

            $ordersAmount = (float) OrderItem::whereIn('shop_id', $shopIds)
                ->sum('line_total');

            $productsCount = Product::whereIn('shop_id', $shopIds)->count();
            $now = Carbon::now();
            $todayTotalOrders = $this->countShopOrdersBetween(
                $shopIds,
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay()
            );
            $lastWeekTotalOrders = $this->countShopOrdersBetween(
                $shopIds,
                $now->copy()->subDays(6)->startOfDay(),
                $now->copy()->endOfDay()
            );
            $lastMonthTotalOrders = $this->countShopOrdersBetween(
                $shopIds,
                $now->copy()->subDays(29)->startOfDay(),
                $now->copy()->endOfDay()
            );
            $yearTotalOrders = $this->countShopOrdersBetween(
                $shopIds,
                $now->copy()->startOfYear(),
                $now->copy()->endOfYear()
            );

            $data = [
                'shops_count' => $shopsCount,
                'orders_count' => $ordersCount,
                'orders_amount' => $ordersAmount,
                'products_count' => $productsCount,
                'today_total_orders' => $todayTotalOrders,
                'last_week_total_orders' => $lastWeekTotalOrders,
                'last_month_total_orders' => $lastMonthTotalOrders,
                'year_total_orders' => $yearTotalOrders,
                'orders_by_period' => [
                    'today' => $todayTotalOrders,
                    'lastWeek' => $lastWeekTotalOrders,
                    'lastMonth' => $lastMonthTotalOrders,
                    'year' => $yearTotalOrders,
                ],
            ];

            return $this->success('Shop report fetched', $data);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /reports/shop/{shopId}/sales
     * Returns last 12 months sales totals for a shop
     */
    public function shopSalesReport($shopId, Request $request)
    {
        try {
            $shop = Shops::find($shopId);
            if (!$shop) {
                return $this->failed('Shop not found', null, 404);
            }

            $end = Carbon::now()->endOfMonth();
            $start = $end->copy()->subMonths(11)->startOfMonth();

            $monthlyTotals = OrderItem::selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, SUM(line_total) as total")
                ->where('shop_id', $shopId)
                ->whereBetween('created_at', [$start, $end])
                ->groupBy('ym')
                ->orderBy('ym')
                ->pluck('total', 'ym');

            $months = [];
            $cursor = $start->copy();
            while ($cursor <= $end) {
                $key = $cursor->format('Y-m');
                $months[] = [
                    'month' => $key,
                    'amount' => (float) ($monthlyTotals[$key] ?? 0),
                ];
                $cursor->addMonth();
            }

            $data = [
                'shop_id' => (int) $shopId,
                'start_month' => $start->format('Y-m'),
                'end_month' => $end->format('Y-m'),
                'months' => $months,
            ];

            return $this->success('Shop sales report fetched', $data);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /reports/shop/{shopId}/summary?period=daily|monthly
     */
    public function shopSummary(Request $request, int $shopId)
    {
        try {
            $period = $request->input('period');
            if (!in_array($period, ['daily', 'monthly'], true)) {
                return $this->failed('Invalid period', [
                    'period' => ['The period must be either daily or monthly.'],
                ], 422);
            }

            $user = $request->attributes->get('api_user');
            if (!$user) {
                return $this->failed('Unauthorized', null, 401);
            }

            $shop = Shops::whereKey($shopId)
                ->where('user_id', $user->id)
                ->first();

            if (!$shop) {
                return $this->failed('You cannot access this shop', null, 403);
            }

            $now = Carbon::now('Asia/Dhaka');
            $from = $period === 'daily' ? $now->copy()->startOfDay() : $now->copy()->startOfMonth();
            $to = $period === 'daily' ? $now->copy()->endOfDay() : $now->copy()->endOfMonth();

            $items = OrderItem::where('shop_id', $shop->id)
                ->whereHas('order', function ($query) use ($from, $to) {
                    $query->whereBetween('created_at', [$from, $to]);
                });

            $totalSales = (float) (clone $items)->sum('line_total');
            $orderCount = (int) (clone $items)->distinct('order_id')->count('order_id');
            $paidAmount = (float) (clone $items)
                ->whereHas('order', fn ($query) => $query->where('payment_status', 'paid'))
                ->sum('line_total');

            $totalSales = round($totalSales, 2);
            $paidAmount = round($paidAmount, 2);

            return $this->success('Shop summary fetched successfully', [
                'period' => $period,
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'total_sales' => $totalSales,
                'order_count' => $orderCount,
                'paid_amount' => $paidAmount,
                'due_amount' => round($totalSales - $paidAmount, 2),
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /reports/orders/monthly
     * Params: start_month (YYYY-MM), end_month (YYYY-MM), status, payment_status, user_id
     */
    public function orderReportMonthly(Request $request)
    {
        try {
            $end = $request->filled('end_month')
                ? Carbon::createFromFormat('Y-m', $request->end_month)->endOfMonth()
                : Carbon::now()->endOfMonth();

            $start = $request->filled('start_month')
                ? Carbon::createFromFormat('Y-m', $request->start_month)->startOfMonth()
                : $end->copy()->subMonths(11)->startOfMonth();

            if ($start->gt($end)) {
                return $this->failed('Invalid date range', ['start_month' => 'start_month must be before end_month'], 422);
            }

            $query = Order::query();

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('payment_status')) {
                $query->where('payment_status', $request->payment_status);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            $monthlyTotals = $query->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as order_count, SUM(total) as total_amount")
                ->whereBetween('created_at', [$start, $end])
                ->groupBy('ym')
                ->orderBy('ym')
                ->get()
                ->keyBy('ym');

            $months = [];
            $cursor = $start->copy();
            while ($cursor <= $end) {
                $key = $cursor->format('Y-m');
                $row = $monthlyTotals->get($key);
                $months[] = [
                    'month' => $key,
                    'orders' => (int) ($row->order_count ?? 0),
                    'amount' => (float) ($row->total_amount ?? 0),
                ];
                $cursor->addMonth();
            }

            $data = [
                'start_month' => $start->format('Y-m'),
                'end_month' => $end->format('Y-m'),
                'months' => $months,
            ];

            return $this->success('Monthly order report fetched', $data);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /reports/today
     * Returns today's activity summary
     */
    public function todayReport(Request $request)
    {
        try {
            $today = Carbon::today()->toDateString();

            $totalOrders = Order::whereDate('created_at', $today)->count();
            $totalRegistered = User::whereDate('created_at', $today)->count();
            $totalReview = Review::whereDate('created_at', $today)->count();

            $totalEarn = (float) Transaction::where('trx_type', 'credit')
                ->where('status', 'completed')
                ->whereDate('created_at', $today)
                ->sum('amount');

            $totalDelivered = Order::where('status', 'delivered')
                ->whereDate('created_at', $today)
                ->count();

            $sellerOnboard = User::whereIn('user_type', ['seller', 'vendor'])
                ->whereDate('created_at', $today)
                ->count();
            $customerOnboard = User::whereIn('user_type', ['customer', 'user'])
                ->whereDate('created_at', $today)
                ->count();

            $data = [
                'date' => $today,
                'total_orders' => $totalOrders,
                'total_registered' => $totalRegistered,
                'website_visitors' => 0,
                'cart_clicked' => 0,
                'product_clicked' => 0,
                'total_review' => $totalReview,
                'total_earn' => $totalEarn,
                'total_delivered' => $totalDelivered,
                'seller_onboard' => $sellerOnboard,
                'customer_onboard' => $customerOnboard,
            ];

            return $this->success('Today report fetched', $data);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
