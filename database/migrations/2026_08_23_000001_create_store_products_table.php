<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('shops')->cascadeOnDelete();
            $table->unsignedInteger('product_id');
            $table->decimal('price', 20, 2)->nullable();
            $table->decimal('discount', 20, 2)->nullable();
            $table->string('discount_type', 20)->nullable();
            $table->integer('stock')->nullable();
            $table->string('sku')->nullable();
            $table->string('title_override')->nullable();
            $table->longText('description_override')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('todays_deal')->default(false);
            $table->timestamps();

            $table->unique(['store_id', 'product_id']);
            $table->index(['store_id', 'is_active']);
            $table->index(['product_id', 'is_active']);
            $table->index(['store_id', 'is_featured']);
            $table->index(['store_id', 'todays_deal']);

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
        });

        if (Schema::hasColumn('products', 'shop_id')) {
            $now = now();

            DB::table('products')
                ->whereNotNull('shop_id')
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('shops')
                        ->whereColumn('shops.id', 'products.shop_id');
                })
                ->orderBy('id')
                ->select([
                    'id',
                    'shop_id',
                    'unit_price',
                    'discount',
                    'discount_type',
                    'current_stock',
                    'featured',
                    'todays_deal',
                ])
                ->chunk(500, function ($products) use ($now) {
                    $rows = [];

                    foreach ($products as $product) {
                        $rows[] = [
                            'store_id' => $product->shop_id,
                            'product_id' => $product->id,
                            'price' => $product->unit_price,
                            'discount' => $product->discount,
                            'discount_type' => $product->discount_type,
                            'stock' => $product->current_stock,
                            'sku' => null,
                            'is_active' => true,
                            'is_featured' => (bool) $product->featured,
                            'todays_deal' => (bool) $product->todays_deal,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if (!empty($rows)) {
                        DB::table('store_products')->upsert(
                            $rows,
                            ['store_id', 'product_id'],
                            [
                                'price',
                                'discount',
                                'discount_type',
                                'stock',
                                'sku',
                                'is_active',
                                'is_featured',
                                'todays_deal',
                                'updated_at',
                            ]
                        );
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_products');
    }
};
