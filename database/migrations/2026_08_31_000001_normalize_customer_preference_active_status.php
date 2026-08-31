<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('customer_preferences_store')
            ->select('customer_user_id')
            ->where('status', 'active')
            ->groupBy('customer_user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('customer_user_id')
            ->each(function ($customerUserId) {
                $activePreferences = DB::table('customer_preferences_store')
                    ->where('customer_user_id', $customerUserId)
                    ->where('status', 'active')
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->get();

                foreach ($activePreferences->skip(1) as $preference) {
                    DB::table('customer_preferences_store')
                        ->where('id', $preference->id)
                        ->update(['status' => 'inactive', 'updated_at' => now()]);
                }
            });
    }

    public function down(): void
    {
        // Status normalization is intentionally not reversed.
    }
};
