<?php

namespace App\Http\Controllers;

use App\Models\Shops;
use App\Models\StoreCashLog;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
