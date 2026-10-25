<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitta jadval barcha ombor hujjatlari uchun: purchase (kirim), purchase_return (yetkazib beruvchiga qaytarish),
     * issue (chiqim), transfer (ko'chirish), inventory (inventarizatsiya). Hujjatlar o'chirilmaydi: draft -> approved -> cancelled.
     */
    public function up(): void
    {
        Schema::create('stock_documents', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->string('type', 20);
            $t->string('status', 12)->default('draft');
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('to_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('stock_documents')->restrictOnDelete();
            $t->date('doc_date');
            $t->decimal('discount', 15, 2)->default(0);
            $t->decimal('total', 15, 2)->default(0);
            $t->decimal('paid_amount', 15, 2)->default(0);
            $t->string('payment_method', 10)->nullable();
            $t->string('reason', 20)->nullable();
            $t->string('note', 500)->nullable();
            $t->string('cancel_reason', 500)->nullable();
            $t->string('client_token', 64)->nullable()->unique(); // qayta yuborishdan himoya (idempotency)
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['type', 'status', 'doc_date']);
            $t->index('parent_id');
        });

        Schema::create('stock_document_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_document_id')->constrained()->cascadeOnDelete(); // faqat draft tahrirlanganda qatorlar almashtiriladi
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 15, 3);                 // inventarizatsiyada: sanalgan (haqiqiy) miqdor
            $t->decimal('price', 15, 2)->nullable();        // xaridda kiritilgan narx; qaytarishda xarid narxi
            $t->decimal('unit_cost', 15, 4)->nullable();    // tasdiqlashda qayd qilingan tannarx (chegirma hisobga olingan)
            $t->decimal('system_qty', 15, 3)->nullable();   // inventarizatsiya: tasdiqlash paytidagi tizim qoldig'i
            $t->decimal('diff_qty', 15, 3)->nullable();     // inventarizatsiya: farq (+ ortiqcha, - kamomad)
            $t->timestamps();
            $t->unique(['stock_document_id', 'product_id']);
            $t->index('product_id');
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('stock_document_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type', 24);
            $t->decimal('quantity', 15, 3);                 // ishorali o'zgarish
            $t->decimal('balance_after', 15, 3);
            $t->decimal('unit_cost', 15, 4)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['warehouse_id', 'product_id']);
            $t->index(['product_id', 'created_at']);
            $t->index('stock_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_document_items');
        Schema::dropIfExists('stock_documents');
    }
};
