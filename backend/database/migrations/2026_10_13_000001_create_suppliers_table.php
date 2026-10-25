<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 4-bosqich uchun minimal tuzilma (xarid hujjati yetkazib beruvchini tanlashi uchun). To'liq modul — 7-bosqich. */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150)->index();
            $t->string('phone', 40)->nullable();
            $t->string('address', 255)->nullable();
            $t->text('note')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
