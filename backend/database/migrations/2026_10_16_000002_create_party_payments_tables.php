<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Qarz to'lovlari. Qarz alohida saqlanmaydi — hujjatlar (sotuv, xarid, qaytarish) va shu to'lovlardan hisoblanadi
     * (bitta operatsiya ikki marta hisobga olinmasligi uchun). To'lov o'chirilmaydi: approved -> cancelled.
     * Naqd to'lov kassa daftariga (cash_transactions) yoziladi; bekor qilinsa teskari yozuv bilan.
     */
    public function up(): void
    {
        Schema::create('customer_payments', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 15, 2);
            $t->string('method', 10);                       // cash | card | click | payme | transfer
            $t->string('reference', 100)->nullable();
            $t->string('note', 500)->nullable();
            $t->string('status', 12)->default('approved');  // approved | cancelled
            $t->foreignId('shift_id')->nullable()->constrained('cashbox_shifts')->restrictOnDelete();
            $t->foreignId('cashbox_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('cash_transaction_id')->nullable()->constrained('cash_transactions')->restrictOnDelete();
            $t->foreignId('reversal_cash_transaction_id')->nullable()->constrained('cash_transactions')->restrictOnDelete();
            $t->boolean('is_online_verified')->default(false);
            $t->string('client_token', 64)->nullable()->unique();
            $t->timestamp('paid_at');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 500)->nullable();
            $t->timestamps();
            $t->index(['customer_id', 'status']);
        });

        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 15, 2);
            $t->string('method', 10);                       // cash | bank  (bank balansiga yozish — 8-bosqich)
            $t->string('reference', 100)->nullable();
            $t->string('note', 500)->nullable();
            $t->string('status', 12)->default('approved');
            $t->foreignId('shift_id')->nullable()->constrained('cashbox_shifts')->restrictOnDelete();
            $t->foreignId('cashbox_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('cash_transaction_id')->nullable()->constrained('cash_transactions')->restrictOnDelete();
            $t->foreignId('reversal_cash_transaction_id')->nullable()->constrained('cash_transactions')->restrictOnDelete();
            $t->string('client_token', 64)->nullable()->unique();
            $t->timestamp('paid_at');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 500)->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('customer_payments');
    }
};
