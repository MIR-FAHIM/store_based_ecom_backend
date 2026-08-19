<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('banners', 'shop_id')) {
            return;
        }

        Schema::table('banners', function (Blueprint $table) {
            $table->foreignId('shop_id')
                ->nullable()
                ->after('related_category_id')
                ->constrained('shops')
                ->nullOnDelete();

            $table->index(['shop_id', 'is_active']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('banners', 'shop_id')) {
            return;
        }

        Schema::table('banners', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'is_active']);
            $table->dropConstrainedForeignId('shop_id');
        });
    }
};
