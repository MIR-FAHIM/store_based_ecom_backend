<?php

namespace App\Http\Controllers;

use App\Models\CustomerLedger;
use App\Models\CustomerPreferenceStore;
use App\Models\Shops;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerBakiController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
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

    private function resolveOrCreateCustomerPreference(int $customerId, Shops $store): CustomerPreferenceStore
    {
        $preference = CustomerPreferenceStore::where('customer_user_id', $customerId)
            ->where('seller_id', $store->user_id)
            ->first();

        if (!$preference) {
            $preference = CustomerPreferenceStore::create([
                'customer_user_id' => $customerId,
                'seller_id' => $store->user_id,
                'added_by' => $store->user_id,
                'added_by_type' => 'seller',
                'status' => 'active',
                'total_baki' => 0.00,
            ]);
        }

        return $preference;
    }

    /**
     * POST /api/seller/stores/{storeId}/baki/collect
     * Collect payment for customer Baki (reduces total debt)
     */
    public function collectPayment(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'customer_id' => ['required', 'integer', 'exists:users,id'],
                'collected_amount' => ['required', 'numeric', 'min:0.01'],
                'payment_method' => ['nullable', 'string', 'in:cash,bkash,nagad,bank,card'],
                'note' => ['nullable', 'string'],
                'due_date' => ['nullable', 'date'],
            ]);

            $store = Shops::find($storeId);
            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            $customer = User::find($validated['customer_id']);
            if (!$customer) {
                return $this->failed('Customer not found', null, 404);
            }

            DB::beginTransaction();

            $preference = $this->resolveOrCreateCustomerPreference($customer->id, $store);
            $currentBaki = (float) ($preference->total_baki ?? 0.0);
            $collectedAmount = (float) $validated['collected_amount'];
            $newBaki = round(max(0, $currentBaki - $collectedAmount), 2);

            $staffId = $request->attributes->get('api_user')?->id;

            $ledgerEntry = CustomerLedger::create([
                'shop_id' => $store->id,
                'seller_id' => $store->user_id,
                'customer_id' => $customer->id,
                'order_id' => null,
                'type' => 'PAYMENT',
                'amount' => -$collectedAmount, // Negative reduces debt
                'paid_amount' => $collectedAmount,
                'due_amount' => 0.00,
                'running_balance' => $newBaki,
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'due_date' => $validated['due_date'] ?? null,
                'note' => $validated['note'] ?? 'Baki payment collected',
                'created_by' => $staffId,
            ]);

            $preference->update([
                'total_baki' => $newBaki,
            ]);

            DB::commit();

            return $this->success('Baki payment collected successfully', [
                'ledger_entry' => $ledgerEntry,
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                ],
                'previous_baki' => $currentBaki,
                'collected_amount' => $collectedAmount,
                'current_total_baki' => $newBaki,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Could not collect Baki payment', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/seller/stores/{storeId}/baki/quick-add
     * Add quick Baki directly to customer ledger without POS checkout
     */
    public function quickAddBaki(Request $request, $storeId)
    {
        try {
            $validated = $request->validate([
                'customer_id' => ['required', 'integer', 'exists:users,id'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'due_date' => ['nullable', 'date'],
                'note' => ['nullable', 'string'],
            ]);

            $store = Shops::find($storeId);
            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            $customer = User::find($validated['customer_id']);
            if (!$customer) {
                return $this->failed('Customer not found', null, 404);
            }

            DB::beginTransaction();

            $preference = $this->resolveOrCreateCustomerPreference($customer->id, $store);
            $currentBaki = (float) ($preference->total_baki ?? 0.0);
            $addedAmount = (float) $validated['amount'];
            $newBaki = round($currentBaki + $addedAmount, 2);

            $staffId = $request->attributes->get('api_user')?->id;

            $ledgerEntry = CustomerLedger::create([
                'shop_id' => $store->id,
                'seller_id' => $store->user_id,
                'customer_id' => $customer->id,
                'order_id' => null,
                'type' => 'DUE',
                'amount' => $addedAmount, // Positive increases debt
                'paid_amount' => 0.00,
                'due_amount' => $addedAmount,
                'running_balance' => $newBaki,
                'payment_method' => null,
                'due_date' => $validated['due_date'] ?? null,
                'note' => $validated['note'] ?? 'Quick Baki entry',
                'created_by' => $staffId,
            ]);

            $preference->update([
                'total_baki' => $newBaki,
            ]);

            DB::commit();

            return $this->success('Quick Baki added successfully', [
                'ledger_entry' => $ledgerEntry,
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                ],
                'previous_baki' => $currentBaki,
                'added_baki' => $addedAmount,
                'current_total_baki' => $newBaki,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Could not add quick Baki', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/seller/stores/{storeId}/baki/customer/{customerId}
     * Get Baki timeline history and summary for a customer
     */
    public function getCustomerLedger(Request $request, $storeId, $customerId)
    {
        try {
            $store = Shops::find($storeId);
            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            $customer = User::find($customerId);
            if (!$customer) {
                return $this->failed('Customer not found', null, 404);
            }

            $preference = CustomerPreferenceStore::where('customer_user_id', $customerId)
                ->where('seller_id', $store->user_id)
                ->first();

            $totalBaki = (float) ($preference->total_baki ?? 0.0);

            $ledgerHistory = CustomerLedger::with(['order', 'creator'])
                ->where('shop_id', $storeId)
                ->where('customer_id', $customerId)
                ->latest()
                ->get();

            return $this->success('Customer Baki ledger retrieved', [
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'avatar' => $customer->avatar,
                ],
                'total_baki' => $totalBaki,
                'ledger_history' => $ledgerHistory,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Could not fetch customer ledger', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/seller/stores/{storeId}/baki/summary
     * Get store-wide Baki overview (total outstanding, top customer debts, recent activity)
     */
    public function getStoreBakiSummary(Request $request, $storeId)
    {
        try {
            $store = Shops::find($storeId);
            if (!$store) {
                return $this->failed('Store not found', null, 404);
            }

            $totalOutstandingBaki = (float) CustomerPreferenceStore::where('seller_id', $store->user_id)
                ->sum('total_baki');

            $customerCountWithBaki = CustomerPreferenceStore::where('seller_id', $store->user_id)
                ->where('total_baki', '>', 0)
                ->count();

            $customersWithBaki = CustomerPreferenceStore::with('customer')
                ->where('seller_id', $store->user_id)
                ->where('total_baki', '>', 0)
                ->orderByDesc('total_baki')
                ->get()
                ->map(function ($pref) {
                    return [
                        'customer_id' => $pref->customer_user_id,
                        'name' => $pref->customer?->name,
                        'phone' => $pref->customer?->phone,
                        'total_baki' => (float) $pref->total_baki,
                    ];
                });

            $recentLedgerEntries = CustomerLedger::with(['customer', 'order'])
                ->where('shop_id', $storeId)
                ->latest()
                ->take(20)
                ->get();

            return $this->success('Store Baki summary retrieved', [
                'total_outstanding_baki' => round($totalOutstandingBaki, 2),
                'customers_with_baki_count' => $customerCountWithBaki,
                'customers_list' => $customersWithBaki,
                'recent_ledger_entries' => $recentLedgerEntries,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Could not fetch store Baki summary', ['error' => $e->getMessage()], 500);
        }
    }
}

