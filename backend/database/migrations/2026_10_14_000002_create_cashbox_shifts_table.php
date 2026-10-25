<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashbox_shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cashbox_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $t->string('status', 20)->default('open')->index(); // open | closed
            $t->timestamp('opened_at');
            $t->decimal('opening_cash', 14, 2)->default(0);
            $t->string('opening_note', 255)->nullable();
            $t->foreignId('handover_from_shift_id')->nullable()->constrained('cashbox_shifts')->restrictOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->decimal('expected_cash', 14, 2)->nullable();
            $t->decimal('actual_cash', 14, 2)->nullable();
            $t->decimal('difference', 14, 2)->nullable();
            $t->string('difference_reason', 255)->nullable();
            $t->foreignId('handed_over_to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 255)->nullable();
            $t->timestamps();
            $t->index(['cashbox_id', 'opened_at']);
        });

        // Bir kassada ham, bir xodimda ham bir vaqtda faqat bitta ochiq smena (poyga holatidan ham himoya).
        DB::statement("CREATE UNIQUE INDEX cashbox_shifts_one_open_per_cashbox ON cashbox_shifts (cashbox_id) WHERE status = 'open'");
        DB::statement("CREATE UNIQUE INDEX cashbox_shifts_one_open_per_user ON cashbox_shifts (user_id) WHERE status = 'open'");
    }

    public function down(): void
    {
        Schema::dropIfExists('cashbox_shifts');
    }
};
