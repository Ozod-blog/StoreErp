<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /** Bu kalitlarning qiymati audit jurnaliga hech qachon yozilmaydi. */
    private const SENSITIVE = ['password', 'password_confirmation', 'current_password', 'token', 'remember_token', 'plain_text_token'];

    public static function log(
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        string $result = 'success',
        ?int $userId = null,
    ): AuditLog {
        $request = request();

        return AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old === null ? null : self::redact($old),
            'new_values' => $new === null ? null : self::redact($new),
            'result' => $result,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    public static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE, true)) {
                $values[$key] = '[yashirilgan]';
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }
}
