<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('on_hand')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('reorder_point')->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id']);
            $table->index('product_id');
        });

        // Defence in depth: the application never reserves more than is on hand,
        // and the database refuses it as well.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_reserved_within_on_hand CHECK (reserved <= on_hand)');
        }

        // Append-only ledger: every change to a stock level is recorded here.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->integer('on_hand_delta');
            $table->integer('reserved_delta');
            $table->unsignedInteger('on_hand_after');
            $table->unsignedInteger('reserved_after');
            $table->string('reason')->nullable();
            $table->nullableMorphs('reference');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['warehouse_id', 'product_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_levels');
    }
};
