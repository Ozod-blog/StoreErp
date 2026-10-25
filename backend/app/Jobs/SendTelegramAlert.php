<?php
namespace App\Jobs;
use App\Services\TelegramAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class SendTelegramAlert implements ShouldQueue {
 use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
 public int $tries=3;
 public array $backoff=[30,120,300];
 public function __construct(public string $eventType, public string $message) {}
 public function handle(TelegramAlertService $telegram): void { $telegram->send($this->eventType,$this->message); }
}
