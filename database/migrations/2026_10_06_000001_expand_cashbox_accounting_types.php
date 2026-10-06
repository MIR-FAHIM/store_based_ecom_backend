<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('store_cash_logs')) {
            return;
        }

        DB::statement("
            ALTER TABLE `store_cash_logs`
            MODIFY `type` ENUM(
                'OPENING_CASH',
                'QUICK_CASH',
                'EXPENSE',
                'DRAWER_ADJUSTMENT',
                'REFUND',
                'OWNER_WITHDRAWAL',
                'OWNER_DEPOSIT',
                'CLOSING_CASH',
                'CARRY_FORWARD'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('store_cash_logs')) {
            return;
        }

        DB::table('store_cash_logs')
            ->whereIn('type', ['OWNER_WITHDRAWAL', 'OWNER_DEPOSIT', 'CLOSING_CASH', 'CARRY_FORWARD'])
            ->update(['type' => 'DRAWER_ADJUSTMENT']);

        DB::statement("
            ALTER TABLE `store_cash_logs`
            MODIFY `type` ENUM(
                'OPENING_CASH',
                'QUICK_CASH',
                'EXPENSE',
                'DRAWER_ADJUSTMENT',
                'REFUND'
            ) NOT NULL
        ");
    }
};
