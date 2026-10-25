<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('employees',function(Blueprint $t){$t->id();$t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();$t->string('employee_code',40)->unique();$t->string('phone',40)->nullable();$t->string('position',100);$t->date('hire_date')->nullable();$t->decimal('salary',14,2)->nullable();$t->string('status',20)->default('active')->index();$t->text('notes')->nullable();$t->timestamps();}); }
 public function down(): void { Schema::dropIfExists('employees'); }
};
