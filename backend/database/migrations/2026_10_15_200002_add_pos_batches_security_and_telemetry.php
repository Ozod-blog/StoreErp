<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('sales')) Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'pricing_type')) $table->string('pricing_type', 12)->default('retail');
            if (!Schema::hasColumn('sales', 'loyalty_points_redeemed')) $table->unsignedInteger('loyalty_points_redeemed')->default(0);
            if (!Schema::hasColumn('sales', 'loyalty_points_earned')) $table->unsignedInteger('loyalty_points_earned')->default(0);
        });
        if (Schema::hasTable('stock_document_items')) Schema::table('stock_document_items', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_document_items', 'batch_number')) $table->string('batch_number', 100)->nullable();
            if (!Schema::hasColumn('stock_document_items', 'received_at')) $table->date('received_at')->nullable();
            if (!Schema::hasColumn('stock_document_items', 'expires_at')) $table->date('expires_at')->nullable()->index();
        });
        if (!Schema::hasTable('product_batch_movements')) Schema::create('product_batch_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_batch_id')->constrained('product_batches')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_document_id')->nullable()->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->restrictOnDelete();
            $table->string('movement_type', 40);
            $table->decimal('quantity_delta', 15, 3);
            $table->timestamps();
            $table->index(['sale_id', 'product_id', 'movement_type'], 'batch_mov_sale_product_type_idx');
            $table->index(['stock_document_id', 'product_id'], 'batch_mov_doc_product_idx');
        });
        // Backfill existing warehouse stock as a non-expiring legacy lot so FEFO tracking can be enforced immediately.
        if (Schema::hasTable('warehouse_stocks') && Schema::hasTable('products')) {
            $stocks = DB::table('warehouse_stocks')->join('products', 'products.id', '=', 'warehouse_stocks.product_id')
                ->where('warehouse_stocks.quantity', '>', 0)
                ->select('warehouse_stocks.warehouse_id', 'warehouse_stocks.product_id', 'warehouse_stocks.quantity', 'products.avg_cost')->get();
            foreach ($stocks as $stock) {
                $stockMilli = (int) round((float) $stock->quantity * 1000);
                $trackedMilli = (int) round((float) DB::table('product_batches')->where('warehouse_id', $stock->warehouse_id)->where('product_id', $stock->product_id)->sum('quantity_remaining') * 1000);
                $difference = $stockMilli - $trackedMilli;
                if ($difference <= 0) continue; // never silently overwrite lots whose total is already equal or above ledger stock
                $number = 'LEGACY-'.$stock->warehouse_id.'-'.$stock->product_id;
                $batch = DB::table('product_batches')->where('warehouse_id', $stock->warehouse_id)->where('product_id', $stock->product_id)->where('batch_number', $number)->first();
                if ($batch) {
                    DB::table('product_batches')->where('id', $batch->id)->update(['quantity_received' => (float)$batch->quantity_received + $difference / 1000, 'quantity_remaining' => (float)$batch->quantity_remaining + $difference / 1000, 'updated_at' => now()]);
                    $id = $batch->id;
                } else {
                    $id = DB::table('product_batches')->insertGetId(['product_id' => $stock->product_id, 'warehouse_id' => $stock->warehouse_id, 'batch_number' => $number, 'received_at' => now()->toDateString(), 'expires_at' => null, 'quantity_received' => $difference / 1000, 'quantity_remaining' => $difference / 1000, 'unit_cost' => $stock->avg_cost ?? 0, 'source_type' => 'legacy_opening', 'source_id' => null, 'created_at' => now(), 'updated_at' => now()]);
                }
                if (Schema::hasTable('product_batch_movements')) DB::table('product_batch_movements')->insert(['product_batch_id' => $id, 'product_id' => $stock->product_id, 'warehouse_id' => $stock->warehouse_id, 'stock_document_id' => null, 'sale_id' => null, 'movement_type' => 'legacy_opening', 'quantity_delta' => $difference / 1000, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if (Schema::hasTable('users')) Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'two_factor_secret')) $table->text('two_factor_secret')->nullable();
            if (!Schema::hasColumn('users', 'two_factor_recovery_codes')) $table->text('two_factor_recovery_codes')->nullable();
            if (!Schema::hasColumn('users', 'two_factor_confirmed_at')) $table->timestamp('two_factor_confirmed_at')->nullable();
            if (!Schema::hasColumn('users', 'two_factor_pending_secret')) $table->text('two_factor_pending_secret')->nullable();
        });
        if (!Schema::hasTable('user_security_events')) Schema::create('user_security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 60);
            $table->string('ip_address', 45)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['user_id', 'event_type', 'created_at'], 'user_security_event_lookup_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('user_security_events');
        Schema::dropIfExists('product_batch_movements');
        // Production-safe rollback: retain added columns to avoid data loss.
    }
};
