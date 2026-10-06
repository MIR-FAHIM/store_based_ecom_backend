<?php

namespace App\Http\Controllers;

use App\Models\Shops;
use App\Models\StoreCashLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreCashLogController extends Controller
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
            'errors' => $errors,
        ], $code);
    }

    private function entryDate(array $validated, string $key = 'entry_date'): string
    {
        return isset($validated[$key])
            ? Carbon::parse($validated[$key])->toDateString()
            : Carbon::today('Asia/Dhaka')->toDateString();
    }

    private function sellerId(Request $request, Shops $store): int
    {
        return (int) ($request->attributes->get('api_user')?->id ?? $store->user_id);
    }

    /**
     * POST /api/seller/stores/{storeId}/cash-logs/opening
     * Set or update opening cash for a specific date (defaults to today)
     */
    public function setOpeningCash(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'amount' => ['required', 'numeric', 'min:0'],
                'entry_date' => ['nullable', 'date'],
                'note' => ['nullable', 'string'],
            ]);

            $store = Shops::find($storeId);
            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            $sellerId = $request->attributes->get('api_user')?->id ?? $store->user_id;
            $entryDate = isset($validated['entry_date']) 
                ? Carbon::parse($validated['entry_date'])->toDateString() 
                : Carbon::today('Asia/Dhaka')->toDateString();

            $cashLog = StoreCashLog::updateOrCreate(
                [
                    'shop_id' => $store->id,
                    'type' => 'OPENING_CASH',
                    'entry_date' => $entryDate,
                ],
                [
                    'seller_id' => $sellerId,
                    'flow' => 'IN',
                    'amount' => (float) $validated['amount'],
                    'category' => 'Opening Cash Drawer',
                    'note' => $validated['note'] ?? 'Starting cash in drawer',
                ]
            );

            return $this->success('Opening cash set successfully.', [
                'cash_log' => $cashLog,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Could not set opening cash', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/seller/stores/{storeId}/cash-logs/quick-cash
     * Add quick unlogged manual cash sale
     */
    public function addQuickCash(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'amount' => ['required', 'numeric', 'min:0.01'],
                'entry_date' => ['nullable', 'date'],
                'note' => ['nullable', 'string'],
            ]);

            $store = Shops::find($storeId);
            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            $sellerId = $request->attributes->get('api_user')?->id ?? $store->user_id;
            $entryDate = isset($validated['entry_date']) 
                ? Carbon::parse($validated['entry_date'])->toDateString() 
                : Carbon::today('Asia/Dhaka')->toDateString();

            $cashLog = StoreCashLog::create([
                'shop_id' => $store->id,
                'seller_id' => $sellerId,
                'type' => 'QUICK_CASH',
                'flow' => 'IN',
                'amount' => (float) $validated['amount'],
                'category' => 'Quick Manual Cash Sale',
                'note' => $validated['note'] ?? 'Manual unlogged cash sale',
                'entry_date' => $entryDate,
            ]);

            return $this->success('Quick cash sale recorded successfully.', [
                'cash_log' => $cashLog,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Could not record quick cash sale', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/seller/stores/{storeId}/cash-logs/expense
     * Add daily store cash expense
     */
    public function addExpense(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'amount' => ['required', 'numeric', 'min:0.01'],
                'category' => ['nullable', 'string'],
                'note' => ['nullable', 'string'],
                'entry_date' => ['nullable', 'date'],
            ]);

            $store = Shops::find($storeId);
            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            $sellerId = $request->attributes->get('api_user')?->id ?? $store->user_id;
            $entryDate = isset($validated['entry_date']) 
                ? Carbon::parse($validated['entry_date'])->toDateString() 
                : Carbon::today('Asia/Dhaka')->toDateString();

            $cashLog = StoreCashLog::create([
                'shop_id' => $store->id,
                'seller_id' => $sellerId,
                'type' => 'EXPENSE',
                'flow' => 'OUT',
                'amount' => (float) $validated['amount'],
                'category' => $validated['category'] ?? 'General Expense',
                'note' => $validated['note'] ?? null,
                'entry_date' => $entryDate,
            ]);

            return $this->success('Expense recorded successfully.', [
                'cash_log' => $cashLog,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Could not record expense', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/seller/stores/{storeId}/cash-logs/withdrawal
     * Owner withdrawal reduces physical drawer cash but is not a business expense.
     */
    public function addOwnerWithdrawal(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'amount' => ['required', 'numeric', 'min:0.01'],
                'category' => ['nullable', 'string'],
                'note' => ['nullable', 'string'],
                'entry_date' => ['nullable', 'date'],
            ]);

            $store = Shops::find($storeId);
            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            $cashLog = StoreCashLog::create([
                'shop_id' => $store->id,
                'seller_id' => $this->sellerId($request, $store),
                'type' => 'OWNER_WITHDRAWAL',
                'flow' => 'OUT',
                'amount' => (float) $validated['amount'],
                'category' => $validated['category'] ?? 'Owner Withdrawal',
                'note' => $validated['note'] ?? 'Cash withdrawn by owner from drawer',
                'entry_date' => $this->entryDate($validated),
            ]);

            return $this->success('Owner withdrawal recorded successfully.', [
                'cash_log' => $cashLog,
            ], 201);
        } catch (\Throwable $e) {
            return $this->failed('Could not record owner withdrawal', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/seller/stores/{storeId}/cash-logs/deposit
     * Owner deposit increases physical drawer cash but is not business revenue.
     */
    public function addOwnerDeposit(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'amount' => ['required', 'numeric', 'min:0.01'],
                'category' => ['nullable', 'string'],
                'note' => ['nullable', 'string'],
                'entry_date' => ['nullable', 'date'],
            ]);

            $store = Shops::find($storeId);
            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            $cashLog = StoreCashLog::create([
                'shop_id' => $store->id,
                'seller_id' => $this->sellerId($request, $store),
                'type' => 'OWNER_DEPOSIT',
                'flow' => 'IN',
                'amount' => (float) $validated['amount'],
                'category' => $validated['category'] ?? 'Owner Cash Deposit',
                'note' => $validated['note'] ?? 'Cash added by owner to drawer',
                'entry_date' => $this->entryDate($validated),
            ]);

            return $this->success('Owner cash deposit recorded successfully.', [
                'cash_log' => $cashLog,
            ], 201);
        } catch (\Throwable $e) {
            return $this->failed('Could not record owner cash deposit', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/seller/stores/{storeId}/cash-logs/closing
     * Records counted closing cash for audit. It does not change expected cash.
     */
    public function setClosingCash(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'actual_cash' => ['required', 'numeric', 'min:0'],
                'entry_date' => ['nullable', 'date'],
                'note' => ['nullable', 'string'],
                'carry_forward_amount' => ['nullable', 'numeric', 'min:0'],
                'set_next_opening' => ['nullable', 'boolean'],
                'next_opening_date' => ['nullable', 'date'],
            ]);

            $store = Shops::find($storeId);
            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            $entryDate = $this->entryDate($validated);
            $sellerId = $this->sellerId($request, $store);
            $actualCash = round((float) $validated['actual_cash'], 2);

            $summaryData = (new ReportController())->calculateShopFinancialSummary($store->id, $entryDate, $entryDate);
            $expectedCash = round((float) ($summaryData['totals']['expected_cash_drawer'] ?? 0.0), 2);
            $difference = round($actualCash - $expectedCash, 2);

            $result = DB::transaction(function () use ($store, $sellerId, $entryDate, $actualCash, $expectedCash, $difference, $validated) {
                $closingLog = StoreCashLog::updateOrCreate(
                    [
                        'shop_id' => $store->id,
                        'type' => 'CLOSING_CASH',
                        'entry_date' => $entryDate,
                    ],
                    [
                        'seller_id' => $sellerId,
                        'flow' => 'IN',
                        'amount' => $actualCash,
                        'category' => 'Closing Cash Count',
                        'note' => $validated['note'] ?? ('Expected cash ' . $expectedCash . ', actual cash ' . $actualCash . ', difference ' . $difference),
                    ]
                );

                $carryForwardLog = null;
                $nextOpeningLog = null;

                if (array_key_exists('carry_forward_amount', $validated)) {
                    $carryForwardAmount = round((float) $validated['carry_forward_amount'], 2);
                    $carryForwardLog = StoreCashLog::updateOrCreate(
                        [
                            'shop_id' => $store->id,
                            'type' => 'CARRY_FORWARD',
                            'entry_date' => $entryDate,
                        ],
                        [
                            'seller_id' => $sellerId,
                            'flow' => 'IN',
                            'amount' => $carryForwardAmount,
                            'category' => 'Carry Forward To Next Day',
                            'note' => 'Cash kept in drawer for next opening',
                        ]
                    );

                    if (filter_var($validated['set_next_opening'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        $nextOpeningDate = isset($validated['next_opening_date'])
                            ? Carbon::parse($validated['next_opening_date'])->toDateString()
                            : Carbon::parse($entryDate)->addDay()->toDateString();

                        $nextOpeningLog = StoreCashLog::updateOrCreate(
                            [
                                'shop_id' => $store->id,
                                'type' => 'OPENING_CASH',
                                'entry_date' => $nextOpeningDate,
                            ],
                            [
                                'seller_id' => $sellerId,
                                'flow' => 'IN',
                                'amount' => $carryForwardAmount,
                                'category' => 'Opening Cash Drawer',
                                'note' => 'Auto-created from previous day carry forward',
                            ]
                        );
                    }
                }

                return [$closingLog, $carryForwardLog, $nextOpeningLog];
            });

            return $this->success('Closing cash recorded successfully.', [
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash,
                'difference' => $difference,
                'closing_cash_log' => $result[0],
                'carry_forward_log' => $result[1],
                'next_opening_cash_log' => $result[2],
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Could not record closing cash', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/seller/stores/{storeId}/cash-logs/carry-forward
     * Carries physical closing cash into a future opening cash record.
     */
    public function carryForwardCash(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'amount' => ['required', 'numeric', 'min:0'],
                'from_date' => ['nullable', 'date'],
                'to_date' => ['nullable', 'date'],
                'note' => ['nullable', 'string'],
            ]);

            $store = Shops::find($storeId);
            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            $sellerId = $this->sellerId($request, $store);
            $fromDate = $this->entryDate($validated, 'from_date');
            $toDate = isset($validated['to_date'])
                ? Carbon::parse($validated['to_date'])->toDateString()
                : Carbon::parse($fromDate)->addDay()->toDateString();
            $amount = round((float) $validated['amount'], 2);

            $result = DB::transaction(function () use ($store, $sellerId, $fromDate, $toDate, $amount, $validated) {
                $carryForwardLog = StoreCashLog::updateOrCreate(
                    [
                        'shop_id' => $store->id,
                        'type' => 'CARRY_FORWARD',
                        'entry_date' => $fromDate,
                    ],
                    [
                        'seller_id' => $sellerId,
                        'flow' => 'IN',
                        'amount' => $amount,
                        'category' => 'Carry Forward To Next Day',
                        'note' => $validated['note'] ?? 'Cash kept in drawer for next opening',
                    ]
                );

                $openingLog = StoreCashLog::updateOrCreate(
                    [
                        'shop_id' => $store->id,
                        'type' => 'OPENING_CASH',
                        'entry_date' => $toDate,
                    ],
                    [
                        'seller_id' => $sellerId,
                        'flow' => 'IN',
                        'amount' => $amount,
                        'category' => 'Opening Cash Drawer',
                        'note' => 'Auto-created from carry forward',
                    ]
                );

                return [$carryForwardLog, $openingLog];
            });

            return $this->success('Cash carried forward successfully.', [
                'carry_forward_log' => $result[0],
                'opening_cash_log' => $result[1],
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Could not carry forward cash', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/seller/stores/{storeId}/cash-logs/adjust-drawer
     * Adjust cash drawer balance after peak rush hour
     */
    public function adjustDrawer(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'actual_cash' => ['required', 'numeric', 'min:0'],
                'reason' => ['nullable', 'string'],
                'note' => ['nullable', 'string'],
                'entry_date' => ['nullable', 'date'],
            ]);

            $store = Shops::find($storeId);
            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            $sellerId = $request->attributes->get('api_user')?->id ?? $store->user_id;
            $entryDate = isset($validated['entry_date']) 
                ? Carbon::parse($validated['entry_date'])->toDateString() 
                : Carbon::today('Asia/Dhaka')->toDateString();

            // Calculate current expected cash in drawer for that date
            $reportController = new ReportController();
            $summaryData = $reportController->calculateShopFinancialSummary($store->id, $entryDate, $entryDate);
            $expectedCash = (float) ($summaryData['totals']['expected_cash_drawer'] ?? 0.0);

            $actualCash = (float) $validated['actual_cash'];
            $diff = round($actualCash - $expectedCash, 2);

            if (abs($diff) < 0.01) {
                return $this->success('Drawer cash already matches perfectly. No adjustment needed.', [
                    'expected_cash' => $expectedCash,
                    'actual_cash' => $actualCash,
                    'difference' => 0,
                ]);
            }

            $flow = $diff > 0 ? 'IN' : 'OUT';
            $cashLog = StoreCashLog::create([
                'shop_id' => $store->id,
                'seller_id' => $sellerId,
                'type' => 'DRAWER_ADJUSTMENT',
                'flow' => $flow,
                'amount' => abs($diff),
                'category' => $validated['reason'] ?? 'Rush Hour Adjustment',
                'note' => $validated['note'] ?? ('System expected ৳' . $expectedCash . ', Actual ৳' . $actualCash),
                'entry_date' => $entryDate,
            ]);

            return $this->success('Drawer cash adjusted successfully.', [
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash,
                'adjustment_amount' => $diff,
                'cash_log' => $cashLog,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Could not adjust drawer cash', ['error' => $e->getMessage()], 500);
        }
    }
}
