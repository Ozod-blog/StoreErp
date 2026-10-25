<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->string('short_name', 20)->unique();
            // false => faqat butun miqdor (dona, quti); true => kasrli miqdor (kg, l, m)
            $t->boolean('allow_fraction')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
