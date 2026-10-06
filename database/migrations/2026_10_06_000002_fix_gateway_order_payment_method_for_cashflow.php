<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'payment_method')) {
            DB::statement("ALTER TABLE `orders` MODIFY `payment_method` VARCHAR(50) NULL DEFAULT NULL");
        }

        if (!Schema::hasTable('orders') || !Schema::hasTable('online_payments')) {
            return;
        }

        $hasPaidAmount = Schema::hasColumn('orders', 'paid_amount');
        $hasDueAmount = Schema::hasColumn('orders', 'due_amount');
        $hasPaymentGroupId = Schema::hasColumn('orders', 'payment_group_id') && Schema::hasColumn('online_payments', 'payment_group_id');
        $hasOrderIds = Schema::hasColumn('online_payments', 'order_ids');

        DB::table('online_payments')
            ->select(['id', 'order_id', 'order_ids', 'payment_group_id'])
            ->where(function ($query) {
                $query->where('payment_type', 'order')
                    ->orWhereNull('payment_type');
            })
            ->whereIn('status', ['success', 'successful', 'successful_verified', 'completed'])
            ->chunkById(100, function ($payments) use ($hasPaidAmount, $hasDueAmount, $hasPaymentGroupId, $hasOrderIds) {
                foreach ($payments as $payment) {
                    $orderIds = [];

                    if (!empty($payment->order_id)) {
                        $orderIds[] = (int) $payment->order_id;
                    }

                    if ($hasOrderIds && !empty($payment->order_ids)) {
                        $decodedOrderIds = json_decode((string) $payment->order_ids, true);
                        if (is_array($decodedOrderIds)) {
                            foreach ($decodedOrderIds as $orderId) {
                                if (is_numeric($orderId)) {
                                    $orderIds[] = (int) $orderId;
                                }
                            }
                        }
                    }

                    if ($hasPaymentGroupId && !empty($payment->payment_group_id)) {
                        $groupOrderIds = DB::table('orders')
                            ->where('payment_group_id', $payment->payment_group_id)
                            ->pluck('id')
                            ->map(fn ($id) => (int) $id)
                            ->all();

                        $orderIds = array_merge($orderIds, $groupOrderIds);
                    }

                    $orderIds = array_values(array_unique(array_filter($orderIds)));
                    if (empty($orderIds)) {
                        continue;
                    }

                    $updates = [
                        'payment_status' => 'paid',
                        'payment_method' => 'aamarpay',
                    ];

                    if ($hasPaidAmount) {
                        $updates['paid_amount'] = DB::raw('COALESCE(`total`, 0)');
                    }

                    if ($hasDueAmount) {
                        $updates['due_amount'] = 0;
                    }

                    DB::table('orders')
                        ->whereIn('id', $orderIds)
                        ->update($updates);
                }
            }, 'id');
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'payment_method')) {
            DB::statement("ALTER TABLE `orders` MODIFY `payment_method` VARCHAR(50) NULL DEFAULT 'cash'");
        }
    }
};
