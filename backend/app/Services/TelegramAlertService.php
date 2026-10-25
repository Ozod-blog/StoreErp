<?php
namespace App\Services;
use App\Models\TelegramRecipient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
class TelegramAlertService {
    public function send(string $eventType, string $message): int {
        $token = (string) config('telegram.bot_token');
        if ($token === '') { Log::warning('ERP Telegram alert skipped: bot token is not configured.'); return 0; }
        $sent = 0;
        TelegramRecipient::query()->where('is_active', true)->get()->each(function ($recipient) use ($token, $eventType, $message, &$sent) {
            $events = $recipient->event_types ?? [];
            if ($events && !in_array($eventType, $events, true)) return;
            try {
                $response = Http::timeout((int)config('telegram.timeout', 5))->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => $recipient->chat_id, 'text' => mb_substr($message, 0, 3900), 'disable_web_page_preview' => true,
                ]);
                if ($response->successful() && $response->json('ok') === true) $sent++;
                else Log::warning('ERP Telegram send failed', ['status'=>$response->status(),'recipient_id'=>$recipient->id]);
            } catch (\Throwable $e) { Log::warning('ERP Telegram send exception', ['recipient_id'=>$recipient->id,'exception'=>get_class($e)]); }
        });
        return $sent;
    }
}
