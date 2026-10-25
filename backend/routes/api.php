<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\ClientErrorController;
use App\Http\Controllers\Api\V1\LoyaltyController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\GlobalSearchController;
use App\Http\Controllers\Api\V1\ProductBatchController;
use App\Http\Controllers\Api\V1\PriceRuleController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\TelegramRecipientController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\TablePreferenceController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\TwoFactorRecoveryController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\CashboxController;
use App\Http\Controllers\Api\V1\CashTransactionController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\PartyPaymentController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseDocumentController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StockDocumentController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\WorkScheduleController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\StatsController;
use App\Http\Controllers\Api\V1\StatsSystemController;
use App\Http\Controllers\Api\V1\ReportCenterController;


Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        try {
            \Illuminate\Support\Facades\DB::select('select 1');
            $db = 'ok';
        } catch (\Throwable $e) {
            $db = 'error';
        }
        return response()->json(['success' => $db === 'ok', 'service' => 'store-erp-api', 'database' => $db, 'time' => now()->toIso8601String()], $db === 'ok' ? 200 : 503);
    });
    Route::get('/openapi.json', fn() => response()->json([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Store ERP API', 'version' => '1.1.0'],
        'servers' => [['url' => '/api/v1']],
        'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'Sanctum']]],
        'paths' => [
            '/health' => ['get' => ['summary' => 'Health check']],
            '/auth/login' => ['post' => ['summary' => 'Login']],
            '/notifications' => ['get' => ['summary' => 'List current user notifications', 'security' => [['bearerAuth' => []]]]],
            '/notifications/unread-count' => ['get' => ['summary' => 'Unread notification count', 'security' => [['bearerAuth' => []]]]],
            '/sales/quote' => ['post' => ['summary' => 'Authoritative POS quote (no sale is created)', 'security' => [['bearerAuth' => []]]]],
            '/auth/two-factor/verify' => ['post' => ['summary' => 'Verify TOTP or one-time recovery code']],
            '/product-batches' => ['get' => ['summary' => 'List inventory lots and expiry dates', 'security' => [['bearerAuth' => []]]]],
            '/price-rules' => ['get' => ['summary' => 'List promotions and customer discounts', 'security' => [['bearerAuth' => []]]]],
            '/client-errors' => ['post' => ['summary' => 'Rate-limited sanitized frontend error report']],
            '/search' => ['get' => ['summary' => 'Global search', 'parameters' => [['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']]]]],
        ]
    ]));
    Route::post('/client-errors', [ClientErrorController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
    Route::post('/auth/two-factor/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:10,1');
    Route::post('/auth/two-factor/recovery/email/start', [TwoFactorRecoveryController::class, 'startEmail'])->middleware('throttle:5,1');
    Route::post('/auth/two-factor/recovery/email/verify', [TwoFactorRecoveryController::class, 'verifyEmail'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/two-factor/setup', [AuthController::class, 'twoFactorSetup'])->middleware('throttle:10,1');
        Route::post('/auth/two-factor/confirm', [AuthController::class, 'twoFactorConfirm'])->middleware('throttle:10,1');
        Route::post('/auth/two-factor/disable', [AuthController::class, 'twoFactorDisable'])->middleware('throttle:10,1');
        Route::post('/auth/two-factor/recovery/admin-request', [TwoFactorRecoveryController::class, 'requestAdminRecovery'])->middleware('throttle:3,1');
        Route::post('/auth/two-factor/recovery/admin-requests', [TwoFactorRecoveryController::class, 'createAdminRequestForUser'])->middleware('throttle:10,1');
        Route::get('/auth/two-factor/recovery/admin-requests', [TwoFactorRecoveryController::class, 'adminRequests'])->middleware('throttle:30,1');
        Route::post('/auth/two-factor/recovery/admin-requests/{id}/review', [TwoFactorRecoveryController::class, 'reviewAdminRequest'])->whereNumber('id')->middleware('throttle:10,1');
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::get('/dashboard/me', [DashboardController::class, 'me']);
        Route::get('/stats/sales', [StatsController::class, 'sales'])->middleware('can:sales.view');
        Route::get('/stats/products', [StatsController::class, 'products'])->middleware('can:products.view');
        Route::get('/stats/stock-movements', [StatsController::class, 'stockMovements'])->middleware('can:warehouses.view');
        Route::get('/stats/documents', [StatsController::class, 'documents']);
        Route::get('/stats/parties', [StatsController::class, 'parties']);

        // 2-bosqich
        Route::get('/stats/expenses', [StatsController::class, 'expenses'])->middleware('can:expenses.view');
        Route::get('/stats/cashboxes', [StatsController::class, 'cashboxes'])->middleware('can:cashboxes.view');
        Route::get('/stats/warehouses', [StatsController::class, 'warehouses'])->middleware('can:warehouses.view');
        Route::get('/stats/categories', [StatsController::class, 'categories'])->middleware('can:products.view');
        Route::get('/stats/branches', [StatsController::class, 'branches'])->middleware('can:branches.view');

        // 3-bosqich (yangi controller)
        Route::get('/stats/pos', [StatsSystemController::class, 'pos'])->middleware('can:sales.create');
        Route::get('/stats/security', [StatsSystemController::class, 'security']);
        Route::get('/stats/sessions', [StatsSystemController::class, 'sessions']);
        Route::get('/stats/telegram', [StatsSystemController::class, 'telegram'])->middleware('can:users.view');
        Route::get('/auth/profile', [AuthController::class, 'profile']);
        Route::put('/auth/profile', [AuthController::class, 'updateProfile'])->middleware('throttle:10,1');
        Route::post('/auth/profile/email-code', [AuthController::class, 'sendEmailChangeCode'])->middleware('throttle:5,1');
        Route::post('/auth/profile/confirm-email', [AuthController::class, 'confirmEmailChange'])->middleware('throttle:10,1');
        Route::put('/auth/password', [AuthController::class, 'changePassword'])->middleware('throttle:5,1');
        Route::get('/table-preferences/{tableKey}', [TablePreferenceController::class, 'show']);
        Route::put('/table-preferences/{tableKey}', [TablePreferenceController::class, 'update']);
        Route::get('/sessions', [SessionController::class, 'index']);
        Route::delete('/sessions/{token}', [SessionController::class, 'destroy'])->whereNumber('token');
        Route::delete('/sessions', [SessionController::class, 'destroyOthers']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::get('/search', GlobalSearchController::class)->middleware('throttle:60,1');
        Route::get('/onboarding', [OnboardingController::class, 'index'])->middleware('can:users.view');
        Route::post('/onboarding/{step}/complete', [OnboardingController::class, 'complete'])->middleware('can:users.update');
        Route::get('/product-batches', [ProductBatchController::class, 'index'])->middleware('can:warehouses.view');
        Route::post('/product-batches', [ProductBatchController::class, 'store'])->middleware('can:warehouses.update');
        Route::get('/price-rules', [PriceRuleController::class, 'index'])->middleware('can:products.view');
        Route::get('/loyalty-accounts', [LoyaltyController::class, 'index'])->middleware('can:customers.view');
        Route::post('/customers/{customer}/loyalty-card', [LoyaltyController::class, 'issue'])->whereNumber('customer')->middleware('can:customers.update');
        Route::get('/telegram-recipients', [TelegramRecipientController::class, 'index'])->middleware('can:users.view');
        Route::post('/telegram-recipients', [TelegramRecipientController::class, 'store'])->middleware('can:users.update');
        Route::patch('/telegram-recipients/{telegramRecipient}', [TelegramRecipientController::class, 'update'])->whereNumber('telegramRecipient')->middleware('can:users.update');
        Route::delete('/telegram-recipients/{telegramRecipient}', [TelegramRecipientController::class, 'destroy'])->whereNumber('telegramRecipient')->middleware('can:users.update');
        Route::post('/price-rules', [PriceRuleController::class, 'store'])->middleware('can:products.update');
        Route::match(['put', 'patch'], '/price-rules/{priceRule}', [PriceRuleController::class, 'update'])->whereNumber('priceRule')->middleware('can:products.update');

        // Foydalanuvchilar
        Route::get('/users', [UserController::class, 'index'])->middleware('can:users.view');
        Route::get('/users/{user}', [UserController::class, 'show'])->middleware('can:users.view');
        Route::post('/users', [UserController::class, 'store'])->middleware('can:users.create');
        Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])->middleware('can:users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('can:users.delete');

        // Rollar va ruxsatlar (index ichida users.view yoki roles.view tekshiriladi)
        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/permissions', [RoleController::class, 'permissions'])->middleware('can:roles.view');
        Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->middleware('can:roles.update');

        // Filiallar: create/update/archive, filial permissions checked server-side.
        Route::get('/branches', [BranchController::class, 'index'])->middleware('can:branches.view');
        Route::post('/branches', [BranchController::class, 'store'])->middleware('can:branches.create');
        Route::match(['put', 'patch'], '/branches/{branch}', [BranchController::class, 'update'])->whereNumber('branch')->middleware('can:branches.update');
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])->whereNumber('branch')->middleware('can:branches.delete');

        // Xodimlar
        Route::get('/employees/available-users', [EmployeeController::class, 'availableUsers'])->middleware('can:employees.create');
        Route::get('/employees', [EmployeeController::class, 'index'])->middleware('can:employees.view');
        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->middleware('can:employees.view');
        Route::post('/employees', [EmployeeController::class, 'store'])->middleware('can:employees.create');
        Route::match(['put', 'patch'], '/employees/{employee}', [EmployeeController::class, 'update'])->middleware('can:employees.update');
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('can:employees.delete');

        // O'lchov birliklari (mahsulotlar ruxsatlari bilan boshqariladi)
        Route::get('/units', [UnitController::class, 'index'])->middleware('can:products.view');
        Route::post('/units', [UnitController::class, 'store'])->middleware('can:products.create');
        Route::match(['put', 'patch'], '/units/{unit}', [UnitController::class, 'update'])->middleware('can:products.update');
        Route::delete('/units/{unit}', [UnitController::class, 'destroy'])->middleware('can:products.delete');

        // Kategoriyalar
        Route::get('/categories', [CategoryController::class, 'index'])->middleware('can:products.view');
        Route::post('/categories', [CategoryController::class, 'store'])->middleware('can:products.create');
        Route::match(['put', 'patch'], '/categories/{category}', [CategoryController::class, 'update'])->middleware('can:products.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->middleware('can:products.delete');

        // Mahsulotlar
        Route::get('/products', [ProductController::class, 'index'])->middleware('can:products.view');
        Route::get('/products/{product}', [ProductController::class, 'show'])->withTrashed()->middleware('can:products.view');
        Route::post('/products', [ProductController::class, 'store'])->middleware('can:products.create');
        Route::match(['put', 'patch'], '/products/{product}', [ProductController::class, 'update'])->middleware('can:products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('can:products.delete');
        Route::post('/products/{product}/barcode', [ProductController::class, 'generateBarcode'])->whereNumber('product')->middleware('can:products.update');
        Route::post('/products/{id}/restore', [ProductController::class, 'restore'])->whereNumber('id')->middleware('can:products.delete');

        // Omborlar va ombor qoldiqlari (qoldiqni o'zgartirish faqat 4-bosqichdagi hujjatlar orqali)
        Route::get('/warehouses/branch-options', [WarehouseController::class, 'branchOptions'])->middleware('can:warehouses.view');
        Route::get('/warehouses', [WarehouseController::class, 'index'])->middleware('can:warehouses.view');
        Route::get('/warehouses/{warehouse}/stocks', [WarehouseController::class, 'stocks'])->middleware('can:warehouses.view');
        Route::post('/warehouses', [WarehouseController::class, 'store'])->middleware('can:warehouses.create');
        Route::match(['put', 'patch'], '/warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware('can:warehouses.update');
        Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->middleware('can:warehouses.delete');

        // Yetkazib beruvchilar (4-bosqichda minimal; to'liq modul — 7-bosqich)
        Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('can:suppliers.view');
        Route::get('/suppliers/summary', [SupplierController::class, 'summary'])->middleware('can:suppliers.view');
        Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('can:suppliers.create');
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->middleware('can:suppliers.view');
        Route::match(['put', 'patch'], '/suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->middleware('can:suppliers.update');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier')->middleware('can:suppliers.delete');
        Route::get('/suppliers/{supplier}/documents', [SupplierController::class, 'documents'])->whereNumber('supplier')->middleware('can:suppliers.view');
        Route::get('/suppliers/{supplier}/payments', [SupplierController::class, 'payments'])->whereNumber('supplier')->middleware('can:suppliers.view');
        Route::get('/suppliers/{supplier}/statement', [SupplierController::class, 'statement'])->whereNumber('supplier')->middleware('can:suppliers.view');
        // Yetkazib beruvchiga qarz to'lovi: suppliers.update; bekor qilish: purchases.cancel
        Route::post('/suppliers/{supplier}/payments', [PartyPaymentController::class, 'storeSupplier'])->whereNumber('supplier')->middleware('can:suppliers.update');
        Route::post('/supplier-payments/{id}/cancel', [PartyPaymentController::class, 'cancelSupplier'])->whereNumber('id')->middleware('can:purchases.cancel');

        // Qoldiq harakati tarixi
        Route::get('/stock-movements', [StockMovementController::class, 'index'])->middleware('can:warehouses.view');

        // Ombor hujjatlari. Qoldiq FAQAT approve orqali o'zgaradi (StockService).
        // purchases = xarid va qaytarish (purchases.*); stock-documents = chiqim, ko'chirish, inventarizatsiya (warehouses.*)
        Route::get('/purchases/{id}/returnable', [PurchaseDocumentController::class, 'returnable'])->whereNumber('id')->middleware('can:purchases.view');
        foreach (
            [
                ['purchases', 'purchases', PurchaseDocumentController::class],
                ['stock-documents', 'warehouses', StockDocumentController::class],
            ] as [$prefix, $module, $controller]
        ) {
            Route::get("/$prefix", [$controller, 'index'])->middleware("can:$module.view");
            Route::get("/$prefix/{id}", [$controller, 'show'])->whereNumber('id')->middleware("can:$module.view");
            Route::post("/$prefix", [$controller, 'store'])->middleware("can:$module.create");
            Route::match(['put', 'patch'], "/$prefix/{id}", [$controller, 'update'])->whereNumber('id')->middleware("can:$module.update");
            Route::post("/$prefix/{id}/approve", [$controller, 'approve'])->whereNumber('id')->middleware("can:$module.approve");
            Route::post("/$prefix/{id}/cancel", [$controller, 'cancel'])->whereNumber('id')->middleware("can:$module.cancel");
        }

        // Mijozlar (6-bosqichda minimal; to'liq modul — 7-bosqich)
        Route::get('/customers', [CustomerController::class, 'index'])->middleware('can:customers.view');
        Route::get('/customers/summary', [CustomerController::class, 'summary'])->middleware('can:customers.view');
        Route::post('/customers', [CustomerController::class, 'store'])->middleware('can:customers.create');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->middleware('can:customers.view');
        Route::match(['put', 'patch'], '/customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->middleware('can:customers.update');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->whereNumber('customer')->middleware('can:customers.delete');
        Route::get('/customers/{customer}/sales', [CustomerController::class, 'sales'])->whereNumber('customer')->middleware('can:customers.view');
        Route::get('/customers/{customer}/payments', [CustomerController::class, 'payments'])->whereNumber('customer')->middleware('can:customers.view');
        Route::get('/customers/{customer}/statement', [CustomerController::class, 'statement'])->whereNumber('customer')->middleware('can:customers.view');
        // Mijozdan qarz to'lovini qabul qilish: sales.create (kassir ham qila oladi); bekor qilish: sales.cancel
        Route::post('/customers/{customer}/payments', [PartyPaymentController::class, 'storeCustomer'])->whereNumber('customer')->middleware('can:sales.create');
        Route::post('/customer-payments/{id}/cancel', [PartyPaymentController::class, 'cancelCustomer'])->whereNumber('id')->middleware('can:sales.cancel');

        // Savdo / POS (6-bosqich). Sotuv, ombor, kassa va to'lovlar SaleService orqali yagona tranzaksiyada bajariladi.
        Route::get('/pos/context', [PosController::class, 'context'])->middleware('can:sales.create');
        Route::get('/pos/products', [PosController::class, 'products'])->middleware('can:sales.create');
        Route::get('/pos/scan', [PosController::class, 'scan'])->middleware('can:sales.create');
        Route::get('/sales', [SaleController::class, 'index'])->middleware('can:sales.view');
        Route::get('/sales/{id}', [SaleController::class, 'show'])->whereNumber('id')->middleware('can:sales.view');
        Route::get('/sales/{id}/returnable', [SaleController::class, 'returnable'])->whereNumber('id')->middleware('can:sales.view');
        Route::post('/sales/quote', [SaleController::class, 'quote'])->middleware(['can:sales.create', 'throttle:60,1']);
        Route::post('/sales', [SaleController::class, 'store'])->middleware('can:sales.create');
        Route::post('/sales/{id}/returns', [SaleController::class, 'storeReturn'])->whereNumber('id')->middleware('can:sales.update');
        Route::post('/sales/{id}/cancel', [SaleController::class, 'cancel'])->whereNumber('id')->middleware('can:sales.cancel');

        // Kassalar (5-bosqich). Balans faqat CashService orqali, kassa daftari (cash_transactions) asosida o'zgaradi.
        Route::get('/cashboxes/branch-options', [CashboxController::class, 'branchOptions'])->middleware('can:cashboxes.view');
        Route::get('/cashboxes', [CashboxController::class, 'index'])->middleware('can:cashboxes.view');
        Route::post('/cashboxes', [CashboxController::class, 'store'])->middleware('can:cashboxes.create');
        Route::match(['put', 'patch'], '/cashboxes/{cashbox}', [CashboxController::class, 'update'])->whereNumber('cashbox')->middleware('can:cashboxes.update');
        Route::delete('/cashboxes/{cashbox}', [CashboxController::class, 'destroy'])->whereNumber('cashbox')->middleware('can:cashboxes.delete');
        Route::get('/cash-transactions', [CashTransactionController::class, 'index'])->middleware('can:cashboxes.view');
        Route::post('/cash-transactions', [CashTransactionController::class, 'store'])->middleware('can:shifts.update');

        // Kassa smenalari (ish jadvalidan alohida). Yopilgan smenani tahrirlash endpointi yo'q.
        Route::get('/shifts', [ShiftController::class, 'index'])->middleware('can:shifts.view');
        Route::get('/shifts/current', [ShiftController::class, 'current'])->middleware('can:shifts.view');
        Route::get('/shifts/cashier-options', [ShiftController::class, 'cashierOptions'])->middleware('can:shifts.create');
        Route::get('/shifts/handover-candidates', [ShiftController::class, 'handoverCandidates'])->middleware('can:shifts.update');
        Route::post('/shifts/open', [ShiftController::class, 'open'])->middleware('can:shifts.create');
        Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->whereNumber('shift')->middleware('can:shifts.view');
        Route::post('/shifts/{shift}/close', [ShiftController::class, 'close'])->whereNumber('shift')->middleware('can:shifts.update');
        Route::post('/shifts/{shift}/handover', [ShiftController::class, 'handover'])->whereNumber('shift')->middleware('can:shifts.update');
        Route::post('/shifts/{shift}/review', [ShiftController::class, 'review'])->whereNumber('shift')->middleware('can:shifts.approve');

        // Xodimlar ish jadvali
        Route::get('/my-schedule', [WorkScheduleController::class, 'mine']);
        Route::get('/work-schedules', [WorkScheduleController::class, 'index'])->middleware('can:employees.view');
        Route::post('/work-schedules', [WorkScheduleController::class, 'store'])->middleware('can:employees.update');
        Route::delete('/work-schedules/{workSchedule}', [WorkScheduleController::class, 'destroy'])->whereNumber('workSchedule')->middleware('can:employees.update');

        // Audit jurnali
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view');
        Route::get('/audit-logs/export', [AuditLogController::class, 'export'])->middleware('can:audit.view');

        // 9-bosqich: boshqaruv paneli va hisobotlar
        Route::get('/reports/branch-options', [ReportController::class, 'branchOptions'])->middleware('can:reports.view');
        Route::get('/reports/dashboard', [ReportController::class, 'dashboard'])->middleware('can:reports.view');
        Route::get('/reports/sales', [ReportController::class, 'sales'])->middleware('can:reports.view');
        Route::get('/reports/inventory', [ReportController::class, 'inventory'])->middleware('can:reports.view');
        Route::get('/reports/finance', [ReportController::class, 'finance'])->middleware('can:reports.view');
        Route::get('/reports/center', [ReportCenterController::class, 'index'])->middleware('can:reports.view');
        Route::get('/reports/product-options', [ReportCenterController::class, 'productOptions'])->middleware('can:reports.view');
        Route::get('/reports/center/{report}', [ReportCenterController::class, 'show'])->middleware('can:reports.view');
        Route::get('/reports/center/{report}/export', [ReportCenterController::class, 'export'])->middleware('can:reports.view');

        Route::middleware('throttle:20,1')->group(function () {
            Route::get('/imports/{type}/template', [ImportController::class, 'template']);
            Route::post('/imports/{type}/preview', [ImportController::class, 'preview']);
            Route::post('/imports/{type}', [ImportController::class, 'commit']);
        });

        // 8-bosqich: bank va moliya
        Route::get('/bank-accounts', [FinanceController::class, 'accounts'])->middleware('can:bank.view');
        Route::post('/bank-accounts', [FinanceController::class, 'storeAccount'])->middleware('can:bank.create');
        Route::match(['put', 'patch'], '/bank-accounts/{bank_account}', [FinanceController::class, 'updateAccount'])->whereNumber('bank_account')->middleware('can:bank.update');
        Route::get('/bank-transactions', [FinanceController::class, 'transactions'])->middleware('can:bank.view');
        Route::post('/bank-transactions', [FinanceController::class, 'storeTransaction'])->middleware('can:bank.create');
        Route::post('/bank-transactions/{bank_transaction}/approve', [FinanceController::class, 'approveTransaction'])->whereNumber('bank_transaction')->middleware('can:bank.approve');
        Route::post('/bank-transactions/{bank_transaction}/cancel', [FinanceController::class, 'cancelTransaction'])->whereNumber('bank_transaction')->middleware('can:bank.cancel');
        Route::get('/expenses', [FinanceController::class, 'expenses'])->middleware('can:expenses.view');
        Route::post('/expenses', [FinanceController::class, 'storeExpense'])->middleware('can:expenses.create');
        Route::post('/expenses/{expense}/approve', [FinanceController::class, 'approveExpense'])->whereNumber('expense')->middleware('can:expenses.approve');
        Route::post('/expenses/{expense}/cancel', [FinanceController::class, 'cancelExpense'])->whereNumber('expense')->middleware('can:expenses.cancel');
    });
});
