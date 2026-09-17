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
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('division_id')->nullable()->after('address');
            $table->unsignedBigInteger('district_id')->nullable()->after('division_id');
            $table->unsignedBigInteger('upazila_id')->nullable()->after('district_id');
            $table->unsignedBigInteger('area_id')->nullable()->after('upazila_id');
            $table->unsignedBigInteger('zone_id')->nullable()->after('area_id');
            $table->decimal('lat', 10, 8)->nullable()->after('zone_id');
            $table->decimal('lon', 11, 8)->nullable()->after('lat');
            $table->text('note')->nullable()->after('lon');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'division_id',
                'district_id',
                'upazila_id',
                'area_id',
                'zone_id',
                'lat',
                'lon',
                'note',
            ]);
        });
    }
};
