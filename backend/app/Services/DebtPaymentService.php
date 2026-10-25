<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Cashbox;
use App\Models\CashboxShift;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

/**
 * Qarz to'lovlari: mijozdan qabul qilish va yetkazib beruvchiga to'lash (qisman to'lov mumkin).
 * Hammasi bitta DB tranzaksiyasida: tarafni qulflash -> qarzni qayta hisoblash -> to'lov -> (naqd bo'lsa) kassa daftari -> audit.
 * Naqd pul faqat ochiq kassa smenasi orqali; naqd bo'lmagan to'lovlar kassaga yozilmaydi.
 */
class DebtPaymentService
{
    public const CUSTOMER_METHODS = ['cash', 'card', 'click', 'payme', 'transfer'];
    public const SUPPLIER_METHODS = ['cash', 'bank'];
    private const REFERENCE_REQUIRED = ['click', 'payme', 'transfer'];

    public function __construct(private CashService $cash, private PartyLedgerService $ledger)
    {
    }

    public function receiveFromCustomer(int $customerId, array $data, User $user): CustomerPayment
    {
        return DB::transaction(function () use ($customerId, $data, $user) {
            $customer = Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
            $method = $data['method'];
            $amount = $this->amount($data['amount']);
            if (in_array($method, self::REFERENCE_REQUIRED, true) && trim((string) ($data['reference'] ?? '')) === '') {
                throw new BusinessRuleException('Click/Payme/o‘tkazma uchun chek yoki tranzaksiya raqamini kiriting.');
            }

            $debt = $this->ledger->customerDebtUnits($customer->id);
            if ($amount > $debt) {
                throw new BusinessRuleException('To‘lov summasi qarzdan oshib ketdi. Joriy qarz: '.Dec::str(max($debt, 0), 2).' so‘m.');
            }

            [$shift, $cashbox] = $method === 'cash'
                ? $this->lockOpenShift($user, 'Naqd to‘lovni qabul qilish uchun avval kassa smenasini oching.')
                : $this->optionalShift($user);

            $payment = CustomerPayment::create([
                'number' => 'TMP-'.uniqid('', true), 'customer_id' => $customer->id, 'amount' => Dec::str($amount, 2),
                'method' => $method, 'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null,
                'status' => 'approved', 'shift_id' => $shift?->id, 'cashbox_id' => $cashbox?->id,
                'is_online_verified' => false, 'client_token' => $data['client_token'] ?? null,
                'paid_at' => now(), 'created_by' => $user->id,
            ]);
            $payment->forceFill(['number' => 'QT-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)]);

            if ($method === 'cash') {
                $tx = $this->cash->record($cashbox, 'in', $amount, 'debt_payment', $user, $shift, 'Mijoz qarzi to‘lovi '.$payment->number, null, 'customer_payment', $payment->id);
                $payment->cash_transaction_id = $tx->id;
            }
            $payment->save();

            AuditLogger::log('customer_payments.create', $payment, null, [
                'number' => $payment->number, 'customer_id' => $customer->id, 'amount' => $payment->amount, 'method' => $method,
                'debt_before' => Dec::str($debt, 2), 'debt_after' => Dec::str($debt - $amount, 2),
            ], 'success', $user->id);

            return $payment->fresh();
        });
    }

    public function payToSupplier(int $supplierId, array $data, User $user): SupplierPayment
    {
        return DB::transaction(function () use ($supplierId, $data, $user) {
            $supplier = Supplier::whereKey($supplierId)->lockForUpdate()->firstOrFail();
            $method = $data['method'];
            $amount = $this->amount($data['amount']);

            $debt = $this->ledger->supplierDebtUnits($supplier->id);
            if ($amount > $debt) {
                throw new BusinessRuleException('To‘lov summasi qarzdan oshib ketdi. Joriy qarz: '.Dec::str(max($debt, 0), 2).' so‘m.');
            }

            [$shift, $cashbox] = $method === 'cash'
                ? $this->lockOpenShift($user, 'Naqd to‘lov uchun avval kassa smenasini oching.')
                : [null, null];

            $payment = SupplierPayment::create([
                'number' => 'TMP-'.uniqid('', true), 'supplier_id' => $supplier->id, 'amount' => Dec::str($amount, 2),
                'method' => $method, 'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null,
                'status' => 'approved', 'shift_id' => $shift?->id, 'cashbox_id' => $cashbox?->id,
                'client_token' => $data['client_token'] ?? null, 'paid_at' => now(), 'created_by' => $user->id,
            ]);
            $payment->forceFill(['number' => 'YT-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)]);

            if ($method === 'cash') {
                $tx = $this->cash->record($cashbox, 'out', $amount, 'supplier_payment', $user, $shift, 'Yetkazib beruvchiga to‘lov '.$payment->number, null, 'supplier_payment', $payment->id);
                $payment->cash_transaction_id = $tx->id;
            }
            $payment->save();

            AuditLogger::log('supplier_payments.create', $payment, null, [
                'number' => $payment->number, 'supplier_id' => $supplier->id, 'amount' => $payment->amount, 'method' => $method,
                'debt_before' => Dec::str($debt, 2), 'debt_after' => Dec::str($debt - $amount, 2),
            ], 'success', $user->id);

            return $payment->fresh();
        });
    }

    public function cancelCustomerPayment(int $id, string $reason, User $user): CustomerPayment
    {
        return DB::transaction(function () use ($id, $reason, $user) {
            $payment = CustomerPayment::whereKey($id)->lockForUpdate()->firstOrFail();
            Customer::whereKey($payment->customer_id)->lockForUpdate()->first();

            return $this->cancel($payment, $reason, $user, 'out', 'debt_payment_cancel', 'customer_payment', 'customer_payments.cancel');
        });
    }

    public function cancelSupplierPayment(int $id, string $reason, User $user): SupplierPayment
    {
        return DB::transaction(function () use ($id, $reason, $user) {
            $payment = SupplierPayment::whereKey($id)->lockForUpdate()->firstOrFail();
            Supplier::whereKey($payment->supplier_id)->lockForUpdate()->first();

            return $this->cancel($payment, $reason, $user, 'in', 'supplier_payment_cancel', 'supplier_payment', 'supplier_payments.cancel');
        });
    }

    /** Bekor qilish: to'lov o'chirilmaydi; naqd bo'lsa kassa daftariga teskari yozuv (bekor qiluvchining ochiq smenasida). */
    private function cancel(Model $payment, string $reason, User $user, string $reverseDirection, string $category, string $sourceType, string $auditAction): Model
    {
        if ($payment->status !== 'approved') {
            throw new BusinessRuleException('To‘lov allaqachon bekor qilingan.');
        }

        if ($payment->method === 'cash') {
            [$shift, $cashbox] = $this->lockOpenShift($user, 'Naqd to‘lovni bekor qilish uchun avval o‘z kassa smenangizni oching.');
            $tx = $this->cash->record($cashbox, $reverseDirection, Dec::units($payment->amount, 2), $category, $user, $shift, 'Bekor qilindi: '.$payment->number, null, $sourceType, $payment->id);
            $payment->reversal_cash_transaction_id = $tx->id;
        }

        $payment->forceFill(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        AuditLogger::log($auditAction, $payment, ['status' => 'approved'], ['status' => 'cancelled', 'reason' => $reason, 'amount' => $payment->amount], 'success', $user->id);

        return $payment->fresh();
    }

    private function amount(string|int|float $value): int
    {
        $units = Dec::units($value, 2);
        if ($units <= 0) {
            throw new BusinessRuleException('To‘lov summasi noldan katta bo‘lishi kerak.');
        }

        return $units;
    }

    /** Smena -> kassa tartibida qulflaydi (SaleService/CashService bilan bir xil tartib). */
    private function lockOpenShift(User $user, string $missing): array
    {
        $shift = CashboxShift::where('user_id', $user->id)->where('status', 'open')->lockForUpdate()->first();
        if (! $shift) {
            throw new BusinessRuleException($missing);
        }
        $cashbox = Cashbox::whereKey($shift->cashbox_id)->lockForUpdate()->firstOrFail();
        if (! $cashbox->is_active) {
            throw new BusinessRuleException('Kassa faol emas.');
        }

        return [$shift, $cashbox];
    }

    /** Naqd bo'lmagan to'lov: ochiq smena bo'lsa shunga bog'lanadi, bo'lmasa smenasiz saqlanadi. */
    private function optionalShift(User $user): array
    {
        $shift = CashboxShift::where('user_id', $user->id)->where('status', 'open')->first();

        return [$shift, $shift ? Cashbox::find($shift->cashbox_id) : null];
    }
}
