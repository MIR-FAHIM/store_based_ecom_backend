<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'must_buy_package')) {
            Schema::table('users', function (Blueprint $table) {
                $table->tinyInteger('must_buy_package')->default(0)->after('banned');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'must_buy_package')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('must_buy_package');
            });
        }
    }
};
