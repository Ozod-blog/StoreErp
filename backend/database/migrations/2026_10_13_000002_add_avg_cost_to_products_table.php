<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** O'rtacha tannarx (vaznli o'rtacha). Faqat tasdiqlangan xarid hujjati o'zgartiradi; mahsulot formasidan o'zgarmaydi. */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('avg_cost', 15, 4)->default(0)->after('wholesale_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn('avg_cost');
        });
    }
};
