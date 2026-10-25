<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\ProductBatch;
use App\Models\User;
use App\Services\TelegramAlertService;
use App\Jobs\SendTelegramAlert;
use App\Models\Sale;
use App\Models\Product;
use App\Models\CashboxShift;

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('erp:backup', function () {
    $connection = config('database.default');
    $config = config("database.connections.{$connection}", []);
    $dir = storage_path('app/private/backups');
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        $this->error('Backup directory could not be created');
        return self::FAILURE;
    }
    @chmod($dir, 0700);
    $stamp = now()->format('Ymd_His');
    try {
        if ($connection === 'sqlite') {
            $source = $config['database'] ?? null;
            if (!$source || $source === ':memory:' || !is_file($source)) {
                $this->error('SQLite database file not found or in-memory database cannot be backed up.');
                return self::FAILURE;
            }
            $destination = $dir . '/store-erp-' . $stamp . '.sqlite';
            $pdo = DB::connection('sqlite')->getPdo();
            $pdo->exec('VACUUM INTO ' . $pdo->quote($destination));
            if (!is_file($destination) || filesize($destination) < 100) {
                @unlink($destination);
                $this->error('SQLite snapshot backup failed');
                return self::FAILURE;
            }
            @chmod($destination, 0600);
        } elseif ($connection === 'pgsql') {
            $destination = $dir . '/store-erp-' . $stamp . '.dump';
            $env = ['PGPASSWORD' => (string) ($config['password'] ?? '')];
            $args = ['pg_dump', '--host=' . (string)($config['host'] ?? '127.0.0.1'), '--port=' . (string)($config['port'] ?? 5432), '--username=' . (string)($config['username'] ?? ''), '--format=custom', '--no-owner', '--file=' . $destination, (string)($config['database'] ?? '')];
            $process = new \Symfony\Component\Process\Process($args, base_path(), $env, null, 3600);
            $process->run();
            if (!$process->isSuccessful()) throw new RuntimeException('pg_dump failed: ' . trim($process->getErrorOutput()));
            @chmod($destination, 0600);
        } elseif (in_array($connection, ['mysql', 'mariadb'], true)) {
            $destination = $dir . '/store-erp-' . $stamp . '.sql';
            $env = ['MYSQL_PWD' => (string) ($config['password'] ?? '')];
            // mysqldump writes directly to the target file so large DBs are not buffered in PHP memory.
            $args = ['mysqldump', '--host=' . (string)($config['host'] ?? '127.0.0.1'), '--port=' . (string)($config['port'] ?? 3306), '--user=' . (string)($config['username'] ?? ''), '--single-transaction', '--quick', '--routines', '--triggers', '--default-character-set=utf8mb4', '--result-file=' . $destination, (string)($config['database'] ?? '')];
            $process = new \Symfony\Component\Process\Process($args, base_path(), $env, null, 3600);
            $process->run();
            if (!$process->isSuccessful()) {
                @unlink($destination);
                throw new RuntimeException('mysqldump failed: ' . trim($process->getErrorOutput()));
            }
            @chmod($destination, 0600);
        } else {
            $this->error('Unsupported DB driver for automatic backup: ' . $connection);
            return self::FAILURE;
        }
        // Optional AES-256 encryption at rest; password is passed through process environment, never command arguments.
        $backupKey = (string) config('erp.backup_encryption_key', '');
        if ($backupKey !== '') {
            $encrypted = $destination . '.enc';
            $encrypt = new \Symfony\Component\Process\Process(['openssl', 'enc', '-aes-256-cbc', '-pbkdf2', '-salt', '-in', $destination, '-out', $encrypted, '-pass', 'env:ERP_BACKUP_ENCRYPTION_KEY'], base_path(), ['ERP_BACKUP_ENCRYPTION_KEY' => $backupKey], null, 3600);
            $encrypt->run();
            if (!$encrypt->isSuccessful()) {
                @unlink($encrypted);
                @unlink($destination);
                throw new RuntimeException('Backup encryption failed; plaintext temporary backup removed.');
            }
            @chmod($encrypted, 0600);
            @unlink($destination);
            $destination = $encrypted;
        }
        // Keep 14 days of local backups; older files are deleted only from the dedicated private backup directory.
        foreach (glob($dir . '/store-erp-*') ?: [] as $file) if (is_file($file) && filemtime($file) < now()->subDays(14)->timestamp) @unlink($file);
        $this->info('Backup created successfully: ' . $destination);
        Log::info('ERP database backup created', ['driver' => $connection, 'file' => basename($destination), 'encrypted' => str_ends_with($destination, '.enc')]);
        return self::SUCCESS;
    } catch (\Throwable $e) {
        Log::error('ERP database backup failed', ['driver' => $connection, 'error' => $e->getMessage()]);
        $this->error('Backup failed. Check server logs and ensure ' . ($connection === 'pgsql' ? 'pg_dump' : ($connection === 'mysql' || $connection === 'mariadb' ? 'mysqldump' : 'database access')) . ' is configured.');
        return self::FAILURE;
    }
})->purpose('Create a private SQLite, PostgreSQL or MySQL database backup');

Artisan::command('erp:expiry-alerts', function () {
    $days = max(1, (int) config('erp.expiry_alert_days', 30));
    $batches = ProductBatch::with(['product:id,name,sku', 'warehouse:id,name'])
        ->where('quantity_remaining', '>', 0)->whereNotNull('expires_at')
        ->whereDate('expires_at', '<=', now()->addDays($days)->toDateString())
        ->orderBy('expires_at')->limit(500)->get();
    if ($batches->isEmpty()) {
        $this->info('No expiring batches found');
        return self::SUCCESS;
    }
    $message = "Store ERP: yaroqlilik muddati yaqin partiyalar (" . $batches->count() . ")\n";
    foreach ($batches->take(15) as $batch) $message .= sprintf("• %s [%s], ombor: %s, qoldiq: %s, muddat: %s\n", $batch->product?->name ?? 'Mahsulot', $batch->product?->sku ?? '-', $batch->warehouse?->name ?? '-', $batch->quantity_remaining, optional($batch->expires_at)->format('Y-m-d'));
    User::query()->where('status', 'active')->limit(500)->get()->each(function ($user) use ($message) {
        $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\ErpAlert', 'data' => ['title' => 'Yaroqlilik muddati ogohlantirishi', 'message' => $message, 'event' => 'expiry']]);
    });
    SendTelegramAlert::dispatch('expiry', $message);
    $this->info('Expiry alerts sent to in-app notifications and configured Telegram recipients.');
})->purpose('Notify about batches nearing expiration');

Schedule::command('erp:backup')->dailyAt('21:25')->withoutOverlapping();
Schedule::command('erp:expiry-alerts')->dailyAt('08:00')->withoutOverlapping();


Artisan::command('erp:low-stock-alerts', function () {
    $products = Product::query()->where('is_active', true)->where('min_stock', '>', 0)
        ->whereRaw('(SELECT COALESCE(SUM(quantity), 0) FROM warehouse_stocks WHERE warehouse_stocks.product_id = products.id) <= products.min_stock')
        ->withSum('stocks as total_stock', 'quantity')->limit(200)->get(['id', 'name', 'sku', 'min_stock']);
    if ($products->isEmpty()) {
        $this->info('No low-stock products found');
        return self::SUCCESS;
    }
    $message = "Store ERP: kam qoldiqdagi mahsulotlar (" . $products->count() . ")\n";
    foreach ($products->take(20) as $product) $message .= sprintf("• %s [%s] — qoldiq: %s, minimum: %s\n", $product->name, $product->sku ?? '-', $product->total_stock ?? 0, $product->min_stock);
    User::query()->where('status', 'active')->limit(500)->get()->each(fn($user) => $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\ErpAlert', 'data' => ['title' => 'Kam qoldiq ogohlantirishi', 'message' => $message, 'event' => 'low_stock']]));
    SendTelegramAlert::dispatch('low_stock', $message);
    $this->info('Low-stock alerts queued.');
})->purpose('Notify about products below minimum stock');

Artisan::command('erp:debt-reminders', function () {
    $sales = Sale::with('customer:id,name,phone')->where('type', 'sale')->where('status', 'approved')->where('debt_amount', '>', 0)->whereNotNull('due_at')->where('due_at', '<=', now())->orderBy('due_at')->limit(300)->get();
    if ($sales->isEmpty()) {
        $this->info('No overdue customer debts found');
        return self::SUCCESS;
    }
    $message = "Store ERP: muddati o‘tgan mijoz qarzlari (" . $sales->count() . ")\n";
    foreach ($sales->take(20) as $sale) $message .= sprintf("• %s — hujjat %s, qarz %s so‘m, muddat %s\n", $sale->customer?->name ?? 'Mijoz belgilanmagan', $sale->number, $sale->debt_amount, optional($sale->due_at)->format('Y-m-d'));
    User::query()->where('status', 'active')->limit(500)->get()->each(fn($user) => $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\ErpAlert', 'data' => ['title' => 'Muddati o‘tgan qarzlar', 'message' => $message, 'event' => 'debt_due']]));
    SendTelegramAlert::dispatch('debt_due', $message);
    $this->info('Debt reminders queued.');
})->purpose('Notify about overdue customer debts');

Artisan::command('erp:daily-report', function () {
    $from = now()->startOfDay();
    $to = now()->endOfDay();
    $stats = Sale::query()->where('type', 'sale')->where('status', 'approved')->whereBetween('sold_at', [$from, $to])->selectRaw('COUNT(*) as count, COALESCE(SUM(total),0) as total, COALESCE(SUM(debt_amount),0) as debt')->first();
    $message = sprintf("Store ERP — kunlik hisobot (%s)\nSotuvlar: %d\nSavdo summasi: %s so‘m\nQarzga yozilgan: %s so‘m", now()->format('Y-m-d'), (int)$stats->count, number_format((float)$stats->total, 2, '.', ' '), number_format((float)$stats->debt, 2, '.', ' '));
    User::query()->where('status', 'active')->limit(500)->get()->each(fn($user) => $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\ErpAlert', 'data' => ['title' => 'Kunlik hisobot', 'message' => $message, 'event' => 'daily_report']]));
    SendTelegramAlert::dispatch('daily_report', $message);
    $this->info('Daily report queued.');
})->purpose('Send a daily sales summary');

Schedule::command('erp:low-stock-alerts')->dailyAt('08:10')->withoutOverlapping();
Schedule::command('erp:debt-reminders')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('erp:daily-report')->dailyAt('21:20')->withoutOverlapping();


Artisan::command('erp:shift-difference-alerts', function () {
    $shifts = CashboxShift::with(['user:id,name', 'cashbox:id,name'])->where('status', 'closed')->whereNotNull('difference')->where('difference', '!=', 0)->whereNull('difference_notified_at')->orderBy('closed_at')->limit(300)->get();
    if ($shifts->isEmpty()) {
        $this->info('No unnotified shift differences found');
        return self::SUCCESS;
    }
    foreach ($shifts as $shift) {
        $message = sprintf("Store ERP: smena farqi\nKassa: %s\nXodim: %s\nKutilgan: %s so‘m\nHaqiqiy: %s so‘m\nFarq: %s so‘m", $shift->cashbox?->name ?? '-', $shift->user?->name ?? '-', $shift->expected_cash ?? 0, $shift->actual_cash ?? 0, $shift->difference);
        User::query()->where('status', 'active')->limit(500)->get()->each(fn($user) => $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\ErpAlert', 'data' => ['title' => 'Smena farqi', 'message' => $message, 'event' => 'shift_difference', 'shift_id' => $shift->id]]));
        SendTelegramAlert::dispatch('shift_difference', $message);
        $shift->forceFill(['difference_notified_at' => now()])->save();
    }
    $this->info('Shift difference alerts queued.');
})->purpose('Notify about unreviewed cash shift differences');
Schedule::command('erp:shift-difference-alerts')->everyTenMinutes()->withoutOverlapping();
