<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'status')) Schema::table('users', fn(Blueprint $t) => $t->string('status', 20)->default('active')->index());
    }
    public function down(): void
    {
        if (Schema::hasColumn('users', 'status')) Schema::table('users', fn(Blueprint $t) => $t->dropColumn('status'));
    }
};
