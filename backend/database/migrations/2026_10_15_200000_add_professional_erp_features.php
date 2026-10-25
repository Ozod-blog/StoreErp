<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'two_factor_secret')) $table->text('two_factor_secret')->nullable();
            if (!Schema::hasColumn('users', 'two_factor_recovery_codes')) $table->text('two_factor_recovery_codes')->nullable();
            if (!Schema::hasColumn('users', 'two_factor_confirmed_at')) $table->timestamp('two_factor_confirmed_at')->nullable();
            if (!Schema::hasColumn('users', 'last_login_at')) $table->timestamp('last_login_at')->nullable();
            if (!Schema::hasColumn('users', 'last_login_ip')) $table->string('last_login_ip', 45)->nullable();
        });

        if (!Schema::hasTable('product_batches')) Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number', 100);
            $table->date('received_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->decimal('quantity_received', 14, 3)->default(0);
            $table->decimal('quantity_remaining', 14, 3)->default(0);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'warehouse_id', 'expires_at'], 'batch_product_wh_exp_idx');
            $table->unique(['product_id', 'warehouse_id', 'batch_number'], 'batch_product_wh_number_uq');
        });

        if (!Schema::hasTable('price_rules')) Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('kind', ['promotion', 'customer_discount', 'loyalty_redemption'])->default('promotion');
            $table->enum('discount_type', ['percent', 'fixed'])->default('percent');
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_card_code', 80)->nullable()->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'kind', 'starts_at', 'ends_at'], 'price_rules_active_window_idx');
        });

        if (!Schema::hasTable('loyalty_accounts')) Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('card_code', 80)->unique();
            $table->unsignedInteger('points_balance')->default(0);
            $table->unsignedInteger('lifetime_points')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        if (!Schema::hasTable('loyalty_transactions')) Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sale_id')->nullable()->index();
            $table->integer('points_delta');
            $table->string('reason', 180);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['loyalty_account_id', 'created_at']);
        });

        if (!Schema::hasTable('user_table_preferences')) Schema::create('user_table_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('table_key', 100);
            $table->json('hidden_columns')->nullable();
            $table->json('saved_filters')->nullable();
            $table->string('density', 20)->default('comfortable');
            $table->timestamps();
            $table->unique(['user_id', 'table_key']);
        });

        if (!Schema::hasTable('telegram_recipients')) Schema::create('telegram_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('chat_id', 100)->unique();
            $table->string('label')->nullable();
            $table->json('event_types')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        if (!Schema::hasTable('erp_onboarding')) Schema::create('erp_onboarding', function (Blueprint $table) {
            $table->id();
            $table->string('step_key', 80)->unique();
            $table->boolean('is_completed')->default(false);
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('cashbox_shifts') && !Schema::hasColumn('cashbox_shifts', 'difference_notified_at')) Schema::table('cashbox_shifts', function (Blueprint $table) { $table->timestamp('difference_notified_at')->nullable()->index(); });

        if (Schema::hasTable('sales') && !Schema::hasColumn('sales', 'due_at')) Schema::table('sales', function (Blueprint $table) { $table->timestamp('due_at')->nullable()->index(); });

        // Indexlar: katta katalog va vaqt bo'yicha filtrlashni tezlashtiradi.
        if (Schema::hasTable('products')) Schema::table('products', function (Blueprint $table) {
            $table->index(['is_active', 'name'], 'products_active_name_idx');
        });
        if (Schema::hasTable('customers')) Schema::table('customers', function (Blueprint $table) {
        });
    }

    public function down(): void
    {
        foreach (['product_batches','price_rules','loyalty_transactions','loyalty_accounts','user_table_preferences','telegram_recipients','erp_onboarding'] as $table) Schema::dropIfExists($table);
        // User fields and catalogue indexes are intentionally retained to avoid destructive rollback on live ERP data.
    }
};
