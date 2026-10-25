<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Kassa daftari: kassa balansi = kirimlar − chiqimlar. Yozuvlar o'zgartirilmaydi va o'chirilmaydi. */
    public function up(): void
    {
        Schema::create('cash_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cashbox_id')->constrained()->restrictOnDelete();
            $t->foreignId('shift_id')->nullable()->constrained('cashbox_shifts')->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('direction', 3); // in | out
            $t->string('category', 40)->index(); // manual_in, manual_out, opening_adjustment, shift_difference (keyin: sale, expense, bank_transfer ...)
            $t->decimal('amount', 14, 2);
            $t->decimal('balance_after', 14, 2);
            $t->string('note', 255)->nullable();
            $t->string('source_type', 60)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('client_token', 64)->nullable()->unique();
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->index(['cashbox_id', 'occurred_at']);
            $t->index('shift_id');
            $t->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
    }
};
