<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitta `sales` jadvali: type=sale (sotuv) va type=return (mijozdan qaytarish, parent_id = asl sotuv).
     * Sotuv kassada yaratilishi bilan tasdiqlanadi (status=approved); keyin faqat bekor qilinishi mumkin (cancelled).
     * Hujjatlar o'chirilmaydi.
     *
     * Ustunlar ma'nosi:
     *  - total:        sotuv — mijoz to'laydigan jami; qaytarish — qaytariladigan qiymat.
     *  - paid_amount:  sotuv — qabul qilingan to'lovlar yig'indisi; qaytarish — mijozga pul qaytarilgan summa.
     *  - debt_amount:  sotuv — qarzga yozilgan summa; qaytarish — shu qaytarish hisobiga kamaygan qarz.
     *  - cogs:         sotilgan (qaytarilgan) tovarning tannarxi (vaznli o'rtacha, savdo paytidagi holat bo'yicha).
     */
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->string('type', 10)->default('sale');            // sale | return
            $t->string('status', 12)->default('approved');      // approved | cancelled
            $t->foreignId('parent_id')->nullable()->constrained('sales')->restrictOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('cashbox_id')->constrained()->restrictOnDelete();
            $t->foreignId('shift_id')->constrained('cashbox_shifts')->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->decimal('subtotal', 15, 2)->default(0);          // chegirmadan oldingi jami
            $t->decimal('discount', 15, 2)->default(0);          // qator + hujjat chegirmalari yig'indisi
            $t->decimal('total', 15, 2)->default(0);
            $t->decimal('paid_amount', 15, 2)->default(0);
            $t->decimal('debt_amount', 15, 2)->default(0);
            $t->decimal('cogs', 15, 2)->default(0);
            $t->string('refund_method', 10)->nullable();
            $t->string('note', 500)->nullable();
            $t->string('cancel_reason', 500)->nullable();
            $t->string('client_token', 64)->nullable()->unique(); // qayta yuborishdan himoya (idempotency)
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('sold_at');
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['type', 'status', 'sold_at']);
            $t->index('parent_id');
            $t->index('shift_id');
            $t->index('customer_id');
            $t->index('created_by');
        });

        Schema::create('sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 15, 3);
            $t->decimal('price', 15, 2);                         // sotuv paytidagi birlik narxi
            $t->decimal('discount', 15, 2)->default(0);          // qator chegirmasi (summa)
            $t->decimal('discount_share', 15, 2)->default(0);    // hujjat chegirmasidan shu qatorga tushgan ulush
            $t->decimal('net_total', 15, 2);                     // mijoz shu qator uchun to'laydigan sof summa (qaytarishda: qaytariladigan summa)
            $t->decimal('unit_cost', 15, 4)->default(0);         // sotuv paytidagi o'rtacha tannarx
            $t->timestamps();
            $t->unique(['sale_id', 'product_id']);
            $t->index('product_id');
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained()->restrictOnDelete();
            $t->foreignId('shift_id')->constrained('cashbox_shifts')->restrictOnDelete();
            $t->foreignId('cashbox_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('direction', 3);                          // in | out
            $t->string('kind', 10);                              // payment | refund | reversal
            $t->string('method', 10);                            // cash | card | click | payme | transfer
            $t->decimal('amount', 15, 2);
            $t->string('reference', 100)->nullable();            // chek / tranzaksiya raqami
            $t->foreignId('cash_transaction_id')->nullable()->constrained('cash_transactions')->restrictOnDelete();
            // Click/Payme/karta hozircha avtomatik tekshirilmaydi: kassir kiritgan. Rasmiy integratsiya qo'shilganda true bo'ladi.
            $t->boolean('is_online_verified')->default(false);
            $t->timestamps();
            $t->index('sale_id');
            $t->index(['shift_id', 'method']);
        });

        Schema::table('stock_movements', function (Blueprint $t) {
            $t->foreignId('sale_id')->nullable()->after('stock_document_id')->constrained('sales')->restrictOnDelete();
            $t->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('sale_id');
        });
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
