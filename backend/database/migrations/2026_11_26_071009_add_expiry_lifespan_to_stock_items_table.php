<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->integer('expiry_warning_days')->default(2)->after('expiry_date');
            $table->integer('shelf_life_days')->nullable()->after('expiry_warning_days');
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn(['expiry_warning_days', 'shelf_life_days']);
        });
    }
};