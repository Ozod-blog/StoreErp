<?php

namespace App\Support;

/**
 * Yagona ruxsatlar katalogi. Slug formati: "modul.amal" (masalan: sales.create).
 * Seeder shu katalogdan o'qiydi, shuning uchun yangi modul qo'shilganda faqat shu yerni yangilash kifoya.
 */
class PermissionCatalog
{
    public const ACTIONS = [
        'view' => 'Ko‘rish',
        'create' => 'Yaratish',
        'update' => 'Tahrirlash',
        'delete' => 'O‘chirish / arxivlash',
        'approve' => 'Tasdiqlash',
        'cancel' => 'Bekor qilish',
        'discount' => 'Chegirma berish',
        'debt' => 'Qarzdorlikka sotish',
    ];

    /** modul => [yorliq, amallar] */
    public const MODULES = [
        'users' => ['Foydalanuvchilar', ['view', 'create', 'update', 'delete']],
        'roles' => ['Rollar va ruxsatlar', ['view', 'update']],
        'employees' => ['Xodimlar', ['view', 'create', 'update', 'delete']],
        'products' => ['Mahsulotlar', ['view', 'create', 'update', 'delete']],
        'warehouses' => ['Omborlar va ombor hujjatlari', ['view', 'create', 'update', 'delete', 'approve', 'cancel']],
        'purchases' => ['Xaridlar', ['view', 'create', 'update', 'delete', 'approve', 'cancel']],
        'sales' => ['Savdo / POS', ['view', 'create', 'update', 'approve', 'cancel', 'discount', 'debt']],
        'customers' => ['Mijozlar', ['view', 'create', 'update', 'delete']],
        'suppliers' => ['Yetkazib beruvchilar', ['view', 'create', 'update', 'delete']],
        'cashboxes' => ['Kassalar', ['view', 'create', 'update', 'delete']],
        'shifts' => ['Kassa smenalari', ['view', 'create', 'update', 'approve', 'cancel']],
        'bank' => ['Bank', ['view', 'create', 'update', 'delete', 'approve', 'cancel']],
        'expenses' => ['Xarajatlar', ['view', 'create', 'update', 'delete', 'approve', 'cancel']],
        'branches' => ['Filiallar', ['view', 'create', 'update', 'delete']],
        'reports' => ['Hisobotlar', ['view']],
        'audit' => ['Audit jurnali', ['view']],
        'settings' => ['Sozlamalar', ['view', 'update']],
    ];

    /** @return array<int, array{slug:string,name:string}> */
    public static function all(): array
    {
        $result = [];
        foreach (self::MODULES as $module => [$label, $actions]) {
            foreach ($actions as $action) {
                $result[] = [
                    'slug' => "$module.$action",
                    'name' => "$label — ".self::ACTIONS[$action],
                ];
            }
        }

        return $result;
    }

    public static function slugs(): array
    {
        return array_column(self::all(), 'slug');
    }

    public static function moduleLabel(string $module): string
    {
        return self::MODULES[$module][0] ?? $module;
    }

    /**
     * Rol => ruxsatlar. '*' = barcha ruxsatlar.
     * Eslatma: bu faqat boshlang'ich holat; keyin Rollar sahifasidan o'zgartirish mumkin
     * (seeder qayta ishga tushganda admin/owner dan boshqa rollarning qo'lda o'zgartirilgan ruxsatlari saqlanadi).
     */
    public static function roleDefaults(): array
    {
        $viewAll = array_values(array_filter(
            self::slugs(),
            fn ($s) => str_ends_with($s, '.view') && ! in_array($s, ['users.view', 'roles.view', 'audit.view', 'settings.view'], true)
        ));

        $ops = ['products', 'warehouses', 'purchases', 'sales', 'customers', 'suppliers', 'cashboxes', 'shifts', 'expenses'];
        $manager = ['employees.view', 'employees.create', 'employees.update', 'branches.view', 'reports.view', 'sales.discount', 'sales.debt'];
        foreach ($ops as $m) {
            foreach (self::MODULES[$m][1] as $a) {
                if (! in_array($a, ['delete'], true)) {
                    $manager[] = "$m.$a";
                }
            }
        }

        return [
            'admin' => ['*'],
            'owner' => ['*'],
            'manager' => array_values(array_unique($manager)),
            'cashier' => ['sales.view', 'sales.create', 'customers.view', 'customers.create', 'products.view', 'cashboxes.view', 'shifts.view', 'shifts.create', 'shifts.update'],
            'warehouse' => ['products.view', 'products.create', 'products.update', 'warehouses.view', 'warehouses.create', 'warehouses.update', 'purchases.view', 'purchases.create', 'purchases.update', 'suppliers.view'],
            'accountant' => ['bank.view', 'bank.create', 'bank.update', 'bank.approve', 'expenses.view', 'expenses.create', 'expenses.update', 'expenses.approve', 'reports.view', 'purchases.view', 'sales.view', 'customers.view', 'suppliers.view', 'cashboxes.view', 'shifts.view'],
            'viewer' => $viewAll,
        ];
    }

    public static function roleLabels(): array
    {
        return [
            'admin' => ['Administrator', 'Barcha modullarni boshqarish (tizim administratori)'],
            'owner' => ['Do‘kon egasi', 'Barcha ruxsatlar'],
            'manager' => ['Menejer', 'Operatsion modullarni boshqarish'],
            'cashier' => ['Kassir', 'Savdo va o‘z smenasi bilan ishlash'],
            'warehouse' => ['Omborchi', 'Mahsulot, ombor va kirim hujjatlari'],
            'accountant' => ['Buxgalter', 'Bank, xarajatlar va moliyaviy hisobotlar'],
            'viewer' => ['Kuzatuvchi', 'Faqat ko‘rish (maxfiy bo‘limlarsiz)'],
        ];
    }
}
