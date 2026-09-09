<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'click_count')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('click_count')->default(0);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('products', 'click_count')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('click_count');
            });
        }
    }
};
