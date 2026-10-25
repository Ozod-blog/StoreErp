<?php
return [
    // Token faqat server ENV/secret manager'da saqlanadi. Hech qachon DB yoki API javobiga yozilmaydi.
    'bot_token' => env('ERP_TELEGRAM_BOT_TOKEN'),
    'timeout' => (int) env('ERP_TELEGRAM_TIMEOUT', 5),
];
