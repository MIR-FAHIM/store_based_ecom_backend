<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_resource_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('media_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('media_resource_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('preview_image_id')->nullable()->constrained('uploads')->nullOnDelete();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 10)->default('BDT');
            $table->string('resource_type', 60)->default('banner')->index();
            $table->string('status', 30)->default('active')->index();
            $table->integer('sort_order')->default(0);
            $table->text('instructions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('media_resource_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_resource_id')->constrained('media_resources')->cascadeOnDelete();
            $table->string('field_name');
            $table->string('field_type', 40);
            $table->string('label');
            $table->boolean('is_required')->default(false);
            $table->json('options')->nullable();
            $table->string('placeholder')->nullable();
            $table->text('help_text')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['media_resource_id', 'field_name']);
        });

        Schema::create('media_resource_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('store_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 40)->default('pending_payment')->index();
            $table->string('payment_status', 40)->default('unpaid')->index();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 10)->default('BDT');
            $table->text('customer_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'status']);
            $table->index(['seller_id', 'status']);
        });

        Schema::create('media_resource_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('media_resource_orders')->cascadeOnDelete();
            $table->foreignId('media_resource_id')->constrained('media_resources')->restrictOnDelete();
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('total', 12, 2)->default(0);
            $table->json('resource_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('media_resource_order_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('media_resource_order_items')->cascadeOnDelete();
            $table->foreignId('field_id')->nullable()->constrained('media_resource_fields')->nullOnDelete();
            $table->string('field_name')->nullable();
            $table->string('field_type', 40)->nullable();
            $table->text('value')->nullable();
            $table->json('json_value')->nullable();
            $table->timestamps();
        });

        Schema::create('media_resource_order_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('media_resource_order_items')->cascadeOnDelete();
            $table->foreignId('field_id')->nullable()->constrained('media_resource_fields')->nullOnDelete();
            $table->foreignId('upload_id')->nullable()->constrained('uploads')->nullOnDelete();
            $table->string('file_type', 60)->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('media_resource_order_deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('media_resource_order_items')->cascadeOnDelete();
            $table->foreignId('upload_id')->nullable()->constrained('uploads')->nullOnDelete();
            $table->string('file_type', 60)->default('draft');
            $table->string('file_path')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->text('note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('media_resource_order_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('media_resource_order_items')->cascadeOnDelete();
            $table->unsignedInteger('revision_number')->default(1);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('request_note');
            $table->string('status', 40)->default('requested')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('online_payments') && !Schema::hasColumn('online_payments', 'media_resource_order_id')) {
            Schema::table('online_payments', function (Blueprint $table) {
                $table->foreignId('media_resource_order_id')
                    ->nullable()
                    ->after('store_subscription_id')
                    ->constrained('media_resource_orders')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('online_payments') && Schema::hasColumn('online_payments', 'media_resource_order_id')) {
            Schema::table('online_payments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('media_resource_order_id');
            });
        }

        Schema::dropIfExists('media_resource_order_revisions');
        Schema::dropIfExists('media_resource_order_deliverables');
        Schema::dropIfExists('media_resource_order_files');
        Schema::dropIfExists('media_resource_order_field_values');
        Schema::dropIfExists('media_resource_order_items');
        Schema::dropIfExists('media_resource_orders');
        Schema::dropIfExists('media_resource_fields');
        Schema::dropIfExists('media_resources');
        Schema::dropIfExists('media_resource_categories');
    }
};
