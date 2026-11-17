<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_settings', function (Blueprint $table) {
            $table->string('company_name')->nullable();
            $table->text('management_details')->nullable();
            $table->text('registered_address')->nullable();
            $table->string('trade_license_number', 100)->nullable();
            $table->string('company_phone', 50)->nullable();
            $table->string('company_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('website_settings', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'management_details', 'registered_address', 'trade_license_number', 'company_phone', 'company_email']);
        });
    }
};
