<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Cashbox;
use App\Models\CashboxShift;
use App\Models\CashTransaction;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\Dec;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Kassa va smenalar bo'yicha YAGONA joy: kassa balansi faqat shu servis orqali o'zgaradi.
 * Pul — butun sonlarda (tiyin, 2 xona), float ishlatilmaydi. Barcha amallar DB tranzaksiyasida,
 * kassa qatori lockForUpdate bilan qulflanadi (parallel so'rovlar balansni buzmasligi uchun).
 */
class CashService
{
    public const NON_WORK = ['off', 'vacation', 'sick', 'absent_excused', 'absent_unexcused'];

    public const TYPE_LABELS = [
        'work' => 'Ishlaydi', 'off' => 'Dam oladi', 'vacation' => 'Ta’til', 'sick' => 'Kasallik',
        'absent_excused' => 'Sababli kelmagan', 'absent_unexcused' => 'Sababsiz kelmagan',
    ];

    /** Kassaning daftar bo'yicha balansi (tiyin). */
    public function balanceUnits(int $cashboxId): int
    {
        $row = DB::table('cash_transactions')
            ->where('cashbox_id', $cashboxId)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS bal")
            ->first();

        return Dec::units($row->bal ?? 0, 2);
    }

    /** Smena davomidagi sof kassa harakati (boshlang'ich moslashtirish hisobga olinmaydi). */
    public function shiftNetUnits(int $shiftId): int
    {
        $row = DB::table('cash_transactions')
            ->where('shift_id', $shiftId)
            ->where('category', '<>', 'opening_adjustment')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS net")
            ->first();

        return Dec::units($row->net ?? 0, 2);
    }

    public function expectedUnits(CashboxShift $shift): int
    {
        return Dec::units($shift->opening_cash, 2) + $this->shiftNetUnits($shift->id);
    }

    /**
     * Kassa daftariga yozuv qo'shadi. Chaqiruvchi DB tranzaksiyasi ichida va kassani qulflagan bo'lishi shart.
     */
    public function record(
        Cashbox $cashbox,
        string $direction,
        int $amount,
        string $category,
        ?User $user,
        ?CashboxShift $shift = null,
        ?string $note = null,
        ?string $token = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): CashTransaction {
        if ($amount <= 0) {
            throw new BusinessRuleException('Summa noldan katta bo‘lishi kerak.');
        }

        $balance = $this->balanceUnits($cashbox->id);
        $after = $direction === 'in' ? $balance + $amount : $balance - $amount;
        if ($after < 0) {
            throw new BusinessRuleException('Kassada mablag‘ yetarli emas. Joriy balans: '.Dec::str($balance, 2).' so‘m.');
        }

        $tx = CashTransaction::create([
            'cashbox_id' => $cashbox->id,
            'shift_id' => $shift?->id,
            'user_id' => $user?->id,
            'direction' => $direction,
            'category' => $category,
            'amount' => Dec::str($amount, 2),
            'balance_after' => Dec::str($after, 2),
            'note' => $note,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'client_token' => $token,
            'occurred_at' => now(),
        ]);

        AuditLogger::log('cash.'.$category, $tx, null, [
            'cashbox_id' => $cashbox->id,
            'shift_id' => $shift?->id,
            'direction' => $direction,
            'amount' => Dec::str($amount, 2),
            'balance_before' => Dec::str($balance, 2),
            'balance_after' => Dec::str($after, 2),
            'note' => $note,
        ], 'success', $user?->id);

        return $tx;
    }

    /** Qo'lda kirim/chiqim. Ochiq smena bo'lsa — smenaga yoziladi. */
    public function manual(int $cashboxId, string $direction, string|int|float $amount, string $note, User $actor, ?string $token = null): CashTransaction
    {
        return DB::transaction(function () use ($cashboxId, $direction, $amount, $note, $actor, $token) {
            $cashbox = Cashbox::whereKey($cashboxId)->lockForUpdate()->firstOrFail();
            if (! $cashbox->is_active) {
                throw new BusinessRuleException('Kassa faol emas.');
            }

            $shift = CashboxShift::where('cashbox_id', $cashbox->id)->where('status', 'open')->first();
            if ($shift) {
                if ($shift->user_id !== $actor->id && ! $actor->can('shifts.approve')) {
                    throw new AuthorizationException('Bu kassada boshqa xodimning smenasi ochiq.');
                }
            } elseif (! $actor->can('cashboxes.update')) {
                throw new AuthorizationException('Smena ochiq bo‘lmagan kassada amal bajarish uchun ruxsatingiz yo‘q.');
            }

            return $this->record(
                $cashbox, $direction, Dec::units($amount, 2),
                $direction === 'in' ? 'manual_in' : 'manual_out', $actor, $shift, $note, $token
            );
        });
    }

    public function openShift(
        int $cashboxId,
        int $cashierId,
        string|int|float $opening,
        ?string $note,
        User $actor,
        ?CashboxShift $handoverFrom = null,
    ): CashboxShift {
        return DB::transaction(function () use ($cashboxId, $cashierId, $opening, $note, $actor, $handoverFrom) {
            $cashbox = Cashbox::whereKey($cashboxId)->lockForUpdate()->firstOrFail();
            if (! $cashbox->is_active) {
                throw new BusinessRuleException('Kassa faol emas.');
            }

            $cashier = User::find($cashierId);
            if (! $cashier || $cashier->status !== 'active') {
                throw new BusinessRuleException('Xodim topilmadi yoki faol emas.');
            }
            if (CashboxShift::where('cashbox_id', $cashbox->id)->where('status', 'open')->exists()) {
                throw new BusinessRuleException('Bu kassada allaqachon faol smena bor.');
            }
            if (CashboxShift::where('user_id', $cashier->id)->where('status', 'open')->exists()) {
                throw new BusinessRuleException('Bu xodimda boshqa kassada faol smena bor.');
            }

            $openingUnits = Dec::units($opening, 2);
            if ($openingUnits < 0) {
                throw new BusinessRuleException('Boshlang‘ich summa manfiy bo‘lmasligi kerak.');
            }

            $book = $this->balanceUnits($cashbox->id);
            $diff = $openingUnits - $book;
            $hasHistory = CashTransaction::where('cashbox_id', $cashbox->id)->exists();
            $note = $note !== null ? trim($note) : null;
            if ($diff !== 0 && $hasHistory && ($note === null || $note === '')) {
                throw new BusinessRuleException('Boshlang‘ich summa kassa balansidan ('.Dec::str($book, 2).' so‘m) farq qiladi — izoh kiriting.');
            }

            $shift = CashboxShift::create([
                'cashbox_id' => $cashbox->id,
                'user_id' => $cashier->id,
                'employee_id' => $cashier->employee?->id,
                'status' => 'open',
                'opened_at' => now(),
                'opening_cash' => Dec::str($openingUnits, 2),
                'opening_note' => $note ?: null,
                'handover_from_shift_id' => $handoverFrom?->id,
            ]);

            if ($diff !== 0) {
                $this->record($cashbox, $diff > 0 ? 'in' : 'out', abs($diff), 'opening_adjustment', $actor, $shift, $note ?: 'Boshlang‘ich qoldiq');
            }

            AuditLogger::log('shifts.open', $shift, null, [
                'cashbox_id' => $cashbox->id,
                'user_id' => $cashier->id,
                'opening_cash' => Dec::str($openingUnits, 2),
                'book_balance' => Dec::str($book, 2),
                'handover_from_shift_id' => $handoverFrom?->id,
            ], 'success', $actor->id);

            return $shift;
        });
    }

    public function closeShift(int $shiftId, string|int|float $actual, ?string $reason, User $actor, ?int $handedOverTo = null): CashboxShift
    {
        return DB::transaction(function () use ($shiftId, $actual, $reason, $actor, $handedOverTo) {
            $shift = CashboxShift::whereKey($shiftId)->lockForUpdate()->firstOrFail();
            if ($shift->status !== 'open') {
                throw new BusinessRuleException('Smena allaqachon yopilgan.');
            }
            if ($shift->user_id !== $actor->id && ! $actor->can('shifts.approve')) {
                throw new AuthorizationException('Faqat o‘z smenangizni yopa olasiz.');
            }

            $cashbox = Cashbox::whereKey($shift->cashbox_id)->lockForUpdate()->firstOrFail();
            $actualUnits = Dec::units($actual, 2);
            if ($actualUnits < 0) {
                throw new BusinessRuleException('Haqiqiy naqd pul manfiy bo‘lmasligi kerak.');
            }

            $expected = $this->expectedUnits($shift);
            $diff = $actualUnits - $expected;
            $reason = $reason !== null ? trim($reason) : null;
            if ($diff !== 0 && ($reason === null || $reason === '')) {
                throw new BusinessRuleException('Kutilgan ('.Dec::str($expected, 2).') va haqiqiy summa farq qiladi — farq sababini kiriting.');
            }

            if ($diff !== 0) {
                $this->record($cashbox, $diff > 0 ? 'in' : 'out', abs($diff), 'shift_difference', $actor, $shift, $reason);
            }

            $shift->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'expected_cash' => Dec::str($expected, 2),
                'actual_cash' => Dec::str($actualUnits, 2),
                'difference' => Dec::str($diff, 2),
                'difference_reason' => $diff !== 0 ? $reason : null,
                'handed_over_to_user_id' => $handedOverTo,
            ]);

            AuditLogger::log('shifts.close', $shift, ['status' => 'open'], [
                'status' => 'closed',
                'expected_cash' => Dec::str($expected, 2),
                'actual_cash' => Dec::str($actualUnits, 2),
                'difference' => Dec::str($diff, 2),
                'difference_reason' => $diff !== 0 ? $reason : null,
                'handed_over_to_user_id' => $handedOverTo,
            ], 'success', $actor->id);

            return $shift;
        });
    }

    /** Smenani topshirish: joriy smena yopiladi, sanalgan summa bilan keyingi xodimga yangi smena ochiladi. */
    public function handover(int $shiftId, string|int|float $actual, ?string $reason, int $toUserId, User $actor): array
    {
        return DB::transaction(function () use ($shiftId, $actual, $reason, $toUserId, $actor) {
            $current = CashboxShift::findOrFail($shiftId);
            if ($current->user_id === $toUserId) {
                throw new BusinessRuleException('Smenani o‘zingizga topshirib bo‘lmaydi.');
            }
            $to = User::find($toUserId);
            if (! $to || $to->status !== 'active' || ! $to->hasPermission('shifts.create')) {
                throw new BusinessRuleException('Qabul qiluvchi xodim faol emas yoki smena ochish ruxsatiga ega emas.');
            }

            $closed = $this->closeShift($shiftId, $actual, $reason, $actor, $toUserId);
            $new = $this->openShift($closed->cashbox_id, $toUserId, $closed->actual_cash, null, $actor, $closed);

            return [$closed, $new];
        });
    }

    public function review(int $shiftId, ?string $note, User $actor): CashboxShift
    {
        return DB::transaction(function () use ($shiftId, $note, $actor) {
            $shift = CashboxShift::whereKey($shiftId)->lockForUpdate()->firstOrFail();
            if ($shift->status !== 'closed') {
                throw new BusinessRuleException('Faqat yopilgan smenani ko‘rib chiqish mumkin.');
            }
            if ($shift->reviewed_at !== null) {
                throw new BusinessRuleException('Smena allaqachon ko‘rib chiqilgan.');
            }

            $shift->update(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note ? trim($note) : null]);
            AuditLogger::log('shifts.review', $shift, null, ['reviewed_by' => $actor->id, 'review_note' => $note], 'success', $actor->id);

            return $shift;
        });
    }

    /** Smenani ochishda ish jadvali bo'yicha ogohlantirish (bloklamaydi: jadval va smena alohida tushuncha). */
    public function scheduleWarning(User $cashier): ?string
    {
        $employee = $cashier->employee;
        if (! $employee) {
            return null;
        }
        $row = WorkSchedule::where('employee_id', $employee->id)
            ->whereDate('work_date', now('Asia/Tashkent')->toDateString())
            ->first();

        return $row && in_array($row->type, self::NON_WORK, true)
            ? 'Ish jadvaliga ko‘ra bugun bu xodim uchun “'.self::TYPE_LABELS[$row->type].'” belgilangan.'
            : null;
    }

    /** Smena bo'yicha yig'ma: kategoriya va yo'nalish bo'yicha jami (tiyin → matn). */
    public function summary(CashboxShift $shift): array
    {
        $rows = DB::table('cash_transactions')
            ->where('shift_id', $shift->id)
            ->selectRaw('direction, category, SUM(amount) AS total, COUNT(*) AS cnt')
            ->groupBy('direction', 'category')
            ->get();

        $in = 0;
        $out = 0;
        $byCategory = [];
        foreach ($rows as $r) {
            $units = Dec::units($r->total, 2);
            if ($r->category !== 'opening_adjustment') {
                if ($r->direction === 'in') {
                    $in += $units;
                } else {
                    $out += $units;
                }
            }
            $byCategory[] = ['direction' => $r->direction, 'category' => $r->category, 'total' => Dec::str($units, 2), 'count' => (int) $r->cnt];
        }

        $opening = Dec::units($shift->opening_cash, 2);

        return [
            'opening_cash' => Dec::str($opening, 2),
            'cash_in' => Dec::str($in, 2),
            'cash_out' => Dec::str($out, 2),
            'expected_cash' => Dec::str($opening + $in - $out, 2),
            'by_category' => $byCategory,
        ];
    }
}
