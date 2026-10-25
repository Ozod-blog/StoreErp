<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Qoldiq faqat ombor hujjatlari (4-bosqich) orqali o'zgaradi; mahsulot formasida qoldiq maydoni yo'q. */
    public function up(): void
    {
        Schema::create('warehouse_stocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 15, 3)->default(0);
            $t->timestamps();
            $t->unique(['warehouse_id', 'product_id']);
            $t->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stocks');
    }
};
