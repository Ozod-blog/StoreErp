<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('work_date');
            $t->string('type', 20); // work | off | vacation | sick | absent_excused | absent_unexcused
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->string('note', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['employee_id', 'work_date']);
            $t->index('work_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
