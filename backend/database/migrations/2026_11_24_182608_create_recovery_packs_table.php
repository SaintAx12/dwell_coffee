<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_packs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('manual');
            $table->integer('size')->default(0);
            $table->string('status')->default('generated');
            $table->integer('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->boolean('photos_omitted')->default(false);
            $table->string('file_ref');
            $table->string('checksum');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_packs');
    }
};