<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->unsignedInteger('sender_id');
            $table->string('sender_type', 30);
            $table->string('message_type', 30);
            $table->text('message')->nullable();
            $this->nullableForeignIdColumn($table, 'product_id', 'products');
            $this->nullableForeignIdColumn($table, 'order_id', 'orders');
            $table->foreignId('reply_to_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('sender_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->index('conversation_id');
            $table->index('sender_id');
            $table->index('created_at');
            $table->index(['conversation_id', 'created_at']);
            $table->index(['conversation_id', 'is_read']);
            $table->index('message_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }

    private function nullableForeignIdColumn(Blueprint $table, string $column, string $referencedTable): void
    {
        $definition = $this->referencedIdDefinition($referencedTable);

        if ($definition['bigint'] && $definition['unsigned']) {
            $table->unsignedBigInteger($column)->nullable();
            return;
        }

        if ($definition['bigint']) {
            $table->bigInteger($column)->nullable();
            return;
        }

        if ($definition['unsigned']) {
            $table->unsignedInteger($column)->nullable();
            return;
        }

        $table->integer($column)->nullable();
    }

    private function referencedIdDefinition(string $table): array
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return ['bigint' => true, 'unsigned' => true];
        }

        $column = Schema::getConnection()->selectOne("SHOW COLUMNS FROM `{$table}` WHERE Field = 'id'");
        $type = strtolower((string) ($column->Type ?? 'bigint unsigned'));

        return [
            'bigint' => str_contains($type, 'bigint'),
            'unsigned' => str_contains($type, 'unsigned'),
        ];
    }
};
