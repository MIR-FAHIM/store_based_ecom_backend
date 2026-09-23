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
use App\Models\CustomerLedger;
use App\Models\CustomerPreferenceStore;
use App\Models\StoreCashLog;
use App\Models\OnlinePayment;
use App\Models\StoreSubscription;
use App\Models\SubscriptionPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
     * GET /reports/shop/{shopId}/summary
     * Params: period=today|yesterday|this_week|this_month|daily|monthly|custom, start_date (YYYY-MM-DD), end_date (YYYY-MM-DD)
     */
    public function shopSummary(Request $request, int $shopId)
    {
        try {
            $period = $request->input('period', 'today');

            $shop = Shops::find($shopId);
            if (!$shop) {
                return $this->failed('Shop not found', null, 404);
            }

            $user = $request->attributes->get('api_user');
            if ($user && $user->id !== $shop->user_id) {
                // If api_user set and not matching shop owner, permit if user is seller staff/admin
            }

            $now = Carbon::now('Asia/Dhaka');
            $fromDate = null;
            $toDate = null;

            if ($period === 'yesterday') {
                $fromDate = $now->copy()->subDay()->startOfDay();
                $toDate = $now->copy()->subDay()->endOfDay();
            } elseif ($period === 'this_week') {
                $fromDate = $now->copy()->startOfWeek();
                $toDate = $now->copy()->endOfWeek();
            } elseif ($period === 'this_month' || $period === 'monthly') {
                $fromDate = $now->copy()->startOfMonth();
                $toDate = $now->copy()->endOfMonth();
            } elseif ($period === 'custom' || $request->filled('start_date')) {
                $fromDate = Carbon::parse($request->input('start_date', $now->toDateString()))->startOfDay();
                $toDate = Carbon::parse($request->input('end_date', $now->toDateString()))->endOfDay();
            } else {
                // Default 'today' / 'daily'
                $fromDate = $now->copy()->startOfDay();
                $toDate = $now->copy()->endOfDay();
            }

            $summaryData = $this->calculateShopFinancialSummary($shop->id, $fromDate, $toDate);
            $summaryData['period'] = $period;

            return $this->success('Shop summary fetched successfully', $summaryData);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Calculate financial summary matrix (Debit, Credit, Balances, KPI metrics)
     */
    public function calculateShopFinancialSummary(int $shopId, $fromDate, $toDate): array
    {
        $start = Carbon::parse($fromDate)->startOfDay();
        $end = Carbon::parse($toDate)->endOfDay();
        $startDateStr = $start->toDateString();
        $endDateStr = $end->toDateString();

        $shop = Shops::find($shopId);
        $hasPaymentMethod = Schema::hasColumn('orders', 'payment_method');
        $hasOrderType = Schema::hasColumn('orders', 'order_type');
        $hasShopIdInOrders = Schema::hasColumn('orders', 'shop_id');

        // 1. OPENING CASH
        $openingCashLog = StoreCashLog::where('shop_id', $shopId)
            ->where('type', 'OPENING_CASH')
            ->whereBetween('entry_date', [$startDateStr, $endDateStr])
            ->orderBy('entry_date', 'desc')
            ->first();
        $openingCash = $openingCashLog ? (float) $openingCashLog->amount : 0.0;

        // Base Orders Query for Shop
        $baseOrderQuery = Order::whereBetween('created_at', [$start, $end])
            ->where(function ($q) use ($shopId, $hasShopIdInOrders) {
                if ($hasShopIdInOrders) {
                    $q->where('shop_id', $shopId)
                      ->orWhereHas('items', fn ($iq) => $iq->where('shop_id', $shopId));
                } else {
                    $q->whereHas('items', fn ($iq) => $iq->where('shop_id', $shopId));
                }
            });

        // 2. POS CASH SALES (Orders placed in store with cash or paid_amount)
        $posCashSalesQuery = (clone $baseOrderQuery)
            ->where(function ($q) use ($hasOrderType) {
                if ($hasOrderType) {
                    $q->where('order_type', 'pos')
                      ->orWhere('platform', 'pos');
                } else {
                    $q->where('platform', 'pos');
                }
            })
            ->where(function ($q) use ($hasPaymentMethod) {
                if ($hasPaymentMethod) {
                    $q->where('payment_method', 'cash')
                      ->orWhere('paid_amount', '>', 0);
                } else {
                    $q->where('payment_status', 'paid')
                      ->orWhere('paid_amount', '>', 0);
                }
            });

        $posCashSalesSumExpression = $hasPaymentMethod 
            ? "CASE WHEN payment_method = 'cash' THEN total ELSE paid_amount END" 
            : "CASE WHEN payment_status = 'paid' THEN total ELSE paid_amount END";

        $posCashSales = (float) $posCashSalesQuery->sum(DB::raw($posCashSalesSumExpression));

        // 3. ONLINE APP COD CASH COLLECTED
        $onlineCodCashQuery = (clone $baseOrderQuery)
            ->where(function ($q) use ($hasOrderType) {
                if ($hasOrderType) {
                    $q->where('order_type', 'online')
                      ->orWhereNull('order_type');
                } else {
                    $q->where('platform', '!=', 'pos')
                      ->orWhereNull('platform');
                }
            })
            ->where('payment_status', 'paid');

        if ($hasPaymentMethod) {
            $onlineCodCashQuery->where('payment_method', 'cash');
        }

        $onlineCodCash = (float) $onlineCodCashQuery->sum('total');

        // 4. BAKI RECOVERED IN CASH
        $bakiRecoveredCash = (float) CustomerLedger::where('shop_id', $shopId)
            ->where('type', 'PAYMENT')
            ->where(function ($q) {
                $q->where('payment_method', 'cash')
                  ->orWhereNull('payment_method');
            })
            ->whereBetween('created_at', [$start, $end])
            ->sum('paid_amount');

        // 5. QUICK MANUAL CASH SALES
        $quickCashSales = (float) StoreCashLog::where('shop_id', $shopId)
            ->where('type', 'QUICK_CASH')
            ->whereBetween('entry_date', [$startDateStr, $endDateStr])
            ->sum('amount');

        // 6. DIGITAL PAYMENTS (bKash, Nagad, Card, Bank)
        $digitalPaymentsQuery = (clone $baseOrderQuery);
        if ($hasPaymentMethod) {
            $digitalPaymentsQuery->whereIn('payment_method', ['bkash', 'nagad', 'rocket', 'card', 'bank', 'bank_transfer']);
        } else {
            $digitalPaymentsQuery->where('payment_status', 'paid')->where('platform', 'online');
        }
        $digitalPaymentsOrders = (float) $digitalPaymentsQuery->sum('total');

        $digitalPaymentsLedger = (float) CustomerLedger::where('shop_id', $shopId)
            ->where('type', 'PAYMENT')
            ->whereIn('payment_method', ['bkash', 'nagad', 'rocket', 'card', 'bank', 'bank_transfer'])
            ->whereBetween('created_at', [$start, $end])
            ->sum('paid_amount');

        $totalDigitalPayments = round($digitalPaymentsOrders + $digitalPaymentsLedger, 2);

        // 7. SHOP EXPENSES (OUT)
        $shopExpenses = (float) StoreCashLog::where('shop_id', $shopId)
            ->where('type', 'EXPENSE')
            ->whereBetween('entry_date', [$startDateStr, $endDateStr])
            ->sum('amount');

        // 8. REFUNDS (OUT)
        $customerRefunds = (float) StoreCashLog::where('shop_id', $shopId)
            ->where('type', 'REFUND')
            ->whereBetween('entry_date', [$startDateStr, $endDateStr])
            ->sum('amount');

        // 9. DRAWER ADJUSTMENTS
        $drawerAdjIn = (float) StoreCashLog::where('shop_id', $shopId)
            ->where('type', 'DRAWER_ADJUSTMENT')
            ->where('flow', 'IN')
            ->whereBetween('entry_date', [$startDateStr, $endDateStr])
            ->sum('amount');

        $drawerAdjOut = (float) StoreCashLog::where('shop_id', $shopId)
            ->where('type', 'DRAWER_ADJUSTMENT')
            ->where('flow', 'OUT')
            ->whereBetween('entry_date', [$startDateStr, $endDateStr])
            ->sum('amount');

        $netDrawerAdj = round($drawerAdjIn - $drawerAdjOut, 2);

        // 10. BAKI DEBT METRICS
        $newBakiGiven = (float) CustomerLedger::where('shop_id', $shopId)
            ->where('type', 'DUE')
            ->whereBetween('created_at', [$start, $end])
            ->sum('due_amount');

        $totalBakiRecovered = (float) CustomerLedger::where('shop_id', $shopId)
            ->where('type', 'PAYMENT')
            ->whereBetween('created_at', [$start, $end])
            ->sum('paid_amount');

        // OVERALL STORE MARKET DUE
        $sellerUserId = $shop ? $shop->user_id : null;
        $totalStoreOutstandingBaki = (float) CustomerPreferenceStore::where('seller_id', $sellerUserId)
            ->sum('total_baki');

        // CALCULATE EXPECTED CASH IN DRAWER
        $totalCashIn = round($openingCash + $posCashSales + $onlineCodCash + $bakiRecoveredCash + $quickCashSales + $drawerAdjIn, 2);
        $totalCashOut = round($shopExpenses + $customerRefunds + $drawerAdjOut, 2);
        $expectedCashInDrawer = round($totalCashIn - $totalCashOut, 2);

        return [
            'from' => $start->toIso8601String(),
            'to' => $end->toIso8601String(),
            'kpi_cards' => [
                'expected_cash_in_drawer' => $expectedCashInDrawer,
                'total_digital_payments' => $totalDigitalPayments,
                'today_new_baki' => round($newBakiGiven, 2),
                'total_store_outstanding_baki' => round($totalStoreOutstandingBaki, 2),
            ],
            'ledger_rows' => [
                [
                    'section' => 'OPENING_BALANCE',
                    'title' => 'Opening Cash Drawer (Morning Till)',
                    'flow_type' => 'Starting Cash',
                    'debit' => 0.00,
                    'credit' => $openingCash,
                    'net_impact' => $openingCash,
                ],
                [
                    'section' => 'REVENUE_INFLOW',
                    'title' => 'POS In-Store Cash Sales',
                    'flow_type' => 'Cash In (+)',
                    'debit' => 0.00,
                    'credit' => round($posCashSales, 2),
                    'net_impact' => round($posCashSales, 2),
                ],
                [
                    'section' => 'REVENUE_INFLOW',
                    'title' => 'Online App COD Cash Collected',
                    'flow_type' => 'Cash In (+)',
                    'debit' => 0.00,
                    'credit' => round($onlineCodCash, 2),
                    'net_impact' => round($onlineCodCash, 2),
                ],
                [
                    'section' => 'REVENUE_INFLOW',
                    'title' => 'Baki Recovered (Customer Cash Payment)',
                    'flow_type' => 'Debt Recovery (+)',
                    'debit' => 0.00,
                    'credit' => round($bakiRecoveredCash, 2),
                    'net_impact' => round($bakiRecoveredCash, 2),
                ],
                [
                    'section' => 'REVENUE_INFLOW',
                    'title' => 'Quick Manual Cash Sales (No Cart)',
                    'flow_type' => 'Manual Cash In (+)',
                    'debit' => 0.00,
                    'credit' => round($quickCashSales, 2),
                    'net_impact' => round($quickCashSales, 2),
                ],
                [
                    'section' => 'REVENUE_INFLOW',
                    'title' => 'Digital Payments (bKash / Nagad / Card)',
                    'flow_type' => 'Non-Cash Digital',
                    'debit' => 0.00,
                    'credit' => $totalDigitalPayments,
                    'net_impact' => $totalDigitalPayments,
                ],
                [
                    'section' => 'CASH_OUTFLOW',
                    'title' => 'Shop Daily Expenses',
                    'flow_type' => 'Cash Out (-)',
                    'debit' => round($shopExpenses, 2),
                    'credit' => 0.00,
                    'net_impact' => -round($shopExpenses, 2),
                ],
                [
                    'section' => 'CASH_OUTFLOW',
                    'title' => 'Customer Cash Refunds',
                    'flow_type' => 'Cash Out (-)',
                    'debit' => round($customerRefunds, 2),
                    'credit' => 0.00,
                    'net_impact' => -round($customerRefunds, 2),
                ],
                [
                    'section' => 'DRAWER_ADJUSTMENT',
                    'title' => 'Rush Hour & Till Adjustments',
                    'flow_type' => 'Drawer Correction',
                    'debit' => round($drawerAdjOut, 2),
                    'credit' => round($drawerAdjIn, 2),
                    'net_impact' => $netDrawerAdj,
                ],
                [
                    'section' => 'BAKI_FLOW',
                    'title' => 'New Baki Given (Unpaid Sales)',
                    'flow_type' => 'Customer Debt (+)',
                    'debit' => round($newBakiGiven, 2),
                    'credit' => 0.00,
                    'net_impact' => round($newBakiGiven, 2),
                ],
                [
                    'section' => 'BAKI_FLOW',
                    'title' => 'Total Baki Recovered Today',
                    'flow_type' => 'Debt Cleared (-)',
                    'debit' => 0.00,
                    'credit' => round($totalBakiRecovered, 2),
                    'net_impact' => -round($totalBakiRecovered, 2),
                ],
            ],
            'totals' => [
                'total_debit' => round($totalCashOut + $newBakiGiven, 2),
                'total_credit' => round($totalCashIn + $totalDigitalPayments, 2),
                'expected_cash_drawer' => $expectedCashInDrawer,
                'total_digital' => $totalDigitalPayments,
                'overall_store_baki' => round($totalStoreOutstandingBaki, 2),
            ],
        ];
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

            $productClicked = (int) Product::sum('click_count');

            $data = [
                'date' => $today,
                'total_orders' => $totalOrders,
                'total_registered' => $totalRegistered,
                'website_visitors' => 0,
                'cart_clicked' => 0,
                'product_clicked' => $productClicked,
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

    /**
     * GET /reports/subscriptions
     * Admin Store Subscription Purchases & Pending Payments Panel Report
     */
    public function subscriptionReport(Request $request)
    {
        try {
            $now = Carbon::now('Asia/Dhaka');
            $period = $request->input('period', 'all');
            $paymentStatus = $request->input('payment_status');
            $subscriptionStatus = $request->input('subscription_status');
            $search = trim((string) $request->input('search', ''));
            $perPage = (int) $request->input('per_page', 20);

            $fromDate = null;
            $toDate = null;

            if ($period === 'today') {
                $fromDate = $now->copy()->startOfDay();
                $toDate = $now->copy()->endOfDay();
            } elseif ($period === 'this_week') {
                $fromDate = $now->copy()->startOfWeek();
                $toDate = $now->copy()->endOfWeek();
            } elseif ($period === 'this_month') {
                $fromDate = $now->copy()->startOfMonth();
                $toDate = $now->copy()->endOfMonth();
            } elseif ($period === 'last_30_days') {
                $fromDate = $now->copy()->subDays(29)->startOfDay();
                $toDate = $now->copy()->endOfDay();
            } elseif ($period === 'custom' || $request->filled('start_date')) {
                $fromDate = Carbon::parse($request->input('start_date', $now->toDateString()))->startOfDay();
                $toDate = Carbon::parse($request->input('end_date', $now->toDateString()))->endOfDay();
            }

            // Summary Totals
            $totalRevenue = (float) OnlinePayment::where('payment_type', 'store_subscription')
                ->where('status', 'success')
                ->sum('amount');

            $totalBought = (int) OnlinePayment::where('payment_type', 'store_subscription')->count();

            $pendingPaymentsCount = (int) OnlinePayment::where('payment_type', 'store_subscription')
                ->where(function ($q) {
                    $q->where('status', 'pending')
                      ->orWhereNull('status');
                })
                ->count();

            $completedPaymentsCount = (int) OnlinePayment::where('payment_type', 'store_subscription')
                ->where('status', 'success')
                ->count();

            $failedPaymentsCount = (int) OnlinePayment::where('payment_type', 'store_subscription')
                ->whereIn('status', ['failed', 'cancelled'])
                ->count();

            $activeSubscriptionsCount = (int) StoreSubscription::where('status', 'active')->count();
            $expiredSubscriptionsCount = (int) StoreSubscription::where('status', 'expired')->count();

            // Main Query
            $query = OnlinePayment::query()
                ->where(function ($q) {
                    $q->where('payment_type', 'store_subscription')
                      ->orWhereNotNull('store_subscription_id');
                })
                ->with([
                    'store.user',
                    'user',
                    'storeSubscription.package',
                ]);

            if ($fromDate && $toDate) {
                $query->whereBetween('created_at', [$fromDate, $toDate]);
            }

            if ($paymentStatus && $paymentStatus !== 'all') {
                if ($paymentStatus === 'pending') {
                    $query->where(function ($q) {
                        $q->where('status', 'pending')->orWhereNull('status');
                    });
                } else {
                    $query->where('status', $paymentStatus);
                }
            }

            if ($subscriptionStatus && $subscriptionStatus !== 'all') {
                $query->whereHas('storeSubscription', fn ($sq) => $sq->where('status', $subscriptionStatus));
            }

            if (!empty($search)) {
                $query->where(function ($sq) use ($search) {
                    $sq->where('merchant_transaction_id', 'like', "%{$search}%")
                      ->orWhere('gateway_transaction_id', 'like', "%{$search}%")
                      ->orWhereHas('store', function ($qshop) use ($search) {
                          $qshop->where('shop_name', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                      })
                      ->orWhereHas('user', function ($quser) use ($search) {
                          $quser->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            $query->latest();

            $paginated = $query->paginate($perPage);

            // Format items into clean DTOs
            $items = collect($paginated->items())->map(function (OnlinePayment $payment) {
                $sub = $payment->storeSubscription;
                $shop = $payment->store ?: $sub?->store;
                $package = $sub?->package;
                $user = $payment->user ?: $shop?->user;

                return [
                    'online_payment_id' => $payment->id,
                    'payment_type' => $payment->payment_type,
                    'merchant_transaction_id' => $payment->merchant_transaction_id,
                    'gateway' => $payment->gateway,
                    'gateway_transaction_id' => $payment->gateway_transaction_id,
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency ?: 'BDT',
                    'payment_status' => $payment->status ?: 'pending',
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'initiated_at' => $payment->initiated_at?->toIso8601String() ?: $payment->created_at?->toIso8601String(),
                    'shop' => $shop ? [
                        'id' => (int) $shop->id,
                        'shop_name' => $shop->shop_name ?: $shop->name,
                        'code' => $shop->code,
                        'phone' => $shop->phone,
                        'email' => $shop->email,
                        'owner' => $user ? [
                            'id' => (int) $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'phone' => $user->phone,
                        ] : null,
                    ] : null,
                    'package' => $package ? [
                        'id' => (int) $package->id,
                        'name' => $package->name,
                        'slug' => $package->slug,
                        'price' => (float) $package->price,
                        'billing_cycle' => $package->billing_cycle,
                        'max_products' => $package->max_products !== null ? (int) $package->max_products : null,
                    ] : null,
                    'subscription' => $sub ? [
                        'id' => (int) $sub->id,
                        'status' => $sub->status,
                        'payment_status' => $sub->payment_status,
                        'starts_at' => $sub->starts_at?->toIso8601String(),
                        'ends_at' => $sub->ends_at?->toIso8601String(),
                    ] : null,
                ];
            });

            $responsePayload = [
                'summary' => [
                    'total_revenue' => round($totalRevenue, 2),
                    'total_subscriptions_bought' => $totalBought,
                    'total_completed_payments' => $completedPaymentsCount,
                    'total_pending_payments' => $pendingPaymentsCount,
                    'total_failed_payments' => $failedPaymentsCount,
                    'total_active_subscriptions' => $activeSubscriptionsCount,
                    'total_expired_subscriptions' => $expiredSubscriptionsCount,
                ],
                'subscriptions' => [
                    'current_page' => $paginated->currentPage(),
                    'per_page' => $paginated->perPage(),
                    'last_page' => $paginated->lastPage(),
                    'total' => $paginated->total(),
                    'data' => $items,
                ],
            ];

            return $this->success('Subscription report fetched successfully', $responsePayload);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /reports/subscriptions/{paymentId}/verify-payment
     * Admin manual approval/verification of a pending subscription payment
     */
    public function verifySubscriptionPayment(Request $request, $paymentId)
    {
        try {
            $payment = OnlinePayment::find($paymentId);
            if (!$payment) {
                return $this->failed('Online payment record not found', null, 404);
            }

            DB::beginTransaction();

            $payment->update([
                'status' => 'completed',
                'paid_at' => Carbon::now(),
            ]);

            if ($payment->store_subscription_id) {
                $subscription = StoreSubscription::find($payment->store_subscription_id);
                if ($subscription) {
                    $subscription->update([
                        'status' => 'active',
                        'payment_status' => 'paid',
                        'payment_reference' => $payment->merchant_transaction_id ?: $payment->gateway_transaction_id,
                    ]);

                    if ($subscription->store_id) {
                        $package = SubscriptionPackage::find($subscription->subscription_package_id);
                        if ($package && $package->max_products !== null) {
                            Shops::where('id', $subscription->store_id)->update([
                                'product_limit' => $package->max_products,
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            return $this->success('Subscription payment verified and activated successfully.', [
                'payment' => $payment->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Could not verify subscription payment', ['error' => $e->getMessage()], 500);
        }
    }
}
