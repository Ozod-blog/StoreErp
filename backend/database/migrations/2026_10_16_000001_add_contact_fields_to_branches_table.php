<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (! Schema::hasColumn('branches', 'code')) $table->string('code', 30)->nullable()->unique();
            if (! Schema::hasColumn('branches', 'phone')) $table->string('phone', 40)->nullable();
            if (! Schema::hasColumn('branches', 'manager_name')) $table->string('manager_name', 120)->nullable();
        });

        // Backfill old rows with stable unique codes; keep this migration safe for existing databases.
        foreach (DB::table('branches')->whereNull('code')->orderBy('id')->get(['id']) as $branch) {
            DB::table('branches')->where('id', $branch->id)->update(['code' => 'BR-'.str_pad((string) $branch->id, 4, '0', STR_PAD_LEFT)]);
        }
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'manager_name')) $table->dropColumn('manager_name');
            if (Schema::hasColumn('branches', 'phone')) $table->dropColumn('phone');
            if (Schema::hasColumn('branches', 'code')) $table->dropUnique(['code']);
        });
        if (Schema::hasColumn('branches', 'code')) Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('code'));
    }
};
