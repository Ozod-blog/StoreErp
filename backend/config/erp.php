<?php

return [
    'backup_encryption_key' => env('ERP_BACKUP_ENCRYPTION_KEY', ''),
    'expiry_alert_days' => (int) env('ERP_EXPIRY_ALERT_DAYS', 30),
    'loyalty_point_value_minor' => (int) env('ERP_LOYALTY_POINT_VALUE_MINOR', 1),
    'loyalty_earn_minor_per_point' => (int) env('ERP_LOYALTY_EARN_MINOR_PER_POINT', 1000),
    // Birinchi administratorni yaratish uchun (faqat php artisan db:seed paytida o'qiladi).
    'admin_name' => env('ADMIN_NAME', 'Administrator'),
    'admin_email' => env('ADMIN_EMAIL', 'admin@store.local'),
    'admin_password' => env('ADMIN_PASSWORD'),

    // Chek rekvizitlari (6-bosqich). Sozlamalar moduli qo'shilganda shu qiymatlar bazaga ko'chiriladi.
    'receipt' => [
        'store_name' => env('ERP_STORE_NAME', 'Do‘kon'),
        'address' => env('ERP_STORE_ADDRESS'),
        'phone' => env('ERP_STORE_PHONE'),
        'tin' => env('ERP_STORE_TIN'),
        'footer' => env('ERP_RECEIPT_FOOTER', 'Xaridingiz uchun rahmat!'),
    ],
];
