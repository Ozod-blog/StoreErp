<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 60)->unique();
            $t->string('barcode', 64)->nullable()->unique();
            $t->string('name', 200)->index();
            $t->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('unit_id')->constrained()->restrictOnDelete();
            $t->decimal('purchase_price', 15, 2)->default(0);   // oxirgi xarid narxi (ma'lumot uchun; tannarx emas)
            $t->decimal('sale_price', 15, 2)->default(0);
            $t->decimal('wholesale_price', 15, 2)->nullable();  // ulgurji narx (kengaytirish uchun)
            $t->decimal('min_stock', 15, 3)->default(0);        // barcha omborlardagi JAMI qoldiq uchun chegara
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->softDeletes();                                    // arxiv: tarixiy hujjatlar buzilmaydi
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
