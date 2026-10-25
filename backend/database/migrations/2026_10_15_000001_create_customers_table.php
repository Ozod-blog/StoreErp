<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 6-bosqich uchun minimal tuzilma (savdoga mijoz biriktirish va qarzga sotish uchun). To'liq modul (qarz daftari, to'lovlar) — 7-bosqich. */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150)->index();
            $t->string('phone', 40)->nullable()->index();
            $t->string('address', 255)->nullable();
            $t->text('note')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
