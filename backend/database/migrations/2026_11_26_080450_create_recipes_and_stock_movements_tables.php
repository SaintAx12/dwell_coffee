<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Support fractional ingredient deductions (e.g., 0.5 pcs, 20.5 g, 150 ml)
        Schema::table('stock_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->default(0)->change();
            $table->decimal('min_level', 12, 2)->default(0)->change();
        });

        if (Schema::hasTable('stock_batches')) {
            Schema::table('stock_batches', function (Blueprint $table) {
                $table->decimal('quantity', 12, 2)->default(0)->change();
                $table->decimal('remaining_qty', 12, 2)->default(0)->change();
            });
        }

        // 1. Recipe mapping: Menu Item -> Required Stock Items & Quantities
        Schema::create('menu_item_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->decimal('quantity_used', 12, 2);
            $table->timestamps();

            $table->unique(['menu_item_id', 'stock_item_id']);
        });

        // 2. Stock Movement Audit Log (every deduction, restock, void, waste, and count adjustment)
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->string('item_name');
            $table->decimal('change_qty', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('reason'); // order_sale, order_void, stock_in, waste, closing_count, initial_stock
            $table->string('reference')->nullable();
            $table->string('performed_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('menu_item_recipes');
    }
};