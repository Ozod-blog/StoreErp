<?php

namespace App\Services;

use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/**
 * Mijoz va yetkazib beruvchi qarzi — YAGONA hisoblash joyi. Qarz alohida jadvalda saqlanmaydi:
 *   mijoz qarzi        = Σ sotuv.debt_amount − Σ qaytarish.debt_amount − Σ mijoz to'lovlari (approved)
 *   yetkazib beruvchi  = Σ (xarid.total − xarid.paid_amount) − Σ qaytarish.total − Σ yetkazib beruvchiga to'lovlar (approved)
 * Faqat tasdiqlangan hujjatlar hisobga olinadi (bekor qilingan/qoralama qarzga ta'sir qilmaydi).
 * Musbat qiymat — qarz; manfiy — ortiqcha to'lov (kredit).
 */
class PartyLedgerService
{
    public const CUSTOMER_DEBT_SQL = "(
        COALESCE((SELECT SUM(s.debt_amount) FROM sales s WHERE s.customer_id = customers.id AND s.status = 'approved' AND s.type = 'sale'), 0)
      - COALESCE((SELECT SUM(s.debt_amount) FROM sales s WHERE s.customer_id = customers.id AND s.status = 'approved' AND s.type = 'return'), 0)
      - COALESCE((SELECT SUM(p.amount) FROM customer_payments p WHERE p.customer_id = customers.id AND p.status = 'approved'), 0))";

    public const SUPPLIER_DEBT_SQL = "(
        COALESCE((SELECT SUM(d.total - d.paid_amount) FROM stock_documents d WHERE d.supplier_id = suppliers.id AND d.type = 'purchase' AND d.status = 'approved'), 0)
      - COALESCE((SELECT SUM(d.total) FROM stock_documents d WHERE d.supplier_id = suppliers.id AND d.type = 'purchase_return' AND d.status = 'approved'), 0)
      - COALESCE((SELECT SUM(p.amount) FROM supplier_payments p WHERE p.supplier_id = suppliers.id AND p.status = 'approved'), 0))";

    public function customerDebtUnits(int $id): int
    {
        return Dec::units(DB::table('customers')->where('id', $id)->selectRaw(self::CUSTOMER_DEBT_SQL.' AS debt')->value('debt'), 2);
    }

    public function supplierDebtUnits(int $id): int
    {
        return Dec::units(DB::table('suppliers')->where('id', $id)->selectRaw(self::SUPPLIER_DEBT_SQL.' AS debt')->value('debt'), 2);
    }

    /** Barcha mijozlar bo'yicha jami: qarzdorlar soni, jami qarz, jami kredit. */
    public function totals(string $party): array
    {
        $table = $party === 'customer' ? 'customers' : 'suppliers';
        $expr = $party === 'customer' ? self::CUSTOMER_DEBT_SQL : self::SUPPLIER_DEBT_SQL;
        $sub = DB::table($table)->selectRaw("$expr AS debt");
        $row = DB::query()->fromSub($sub, 't')->selectRaw(
            'COALESCE(SUM(CASE WHEN debt > 0 THEN debt ELSE 0 END), 0) AS owed, '
            .'COALESCE(SUM(CASE WHEN debt < 0 THEN -debt ELSE 0 END), 0) AS credit, '
            .'COALESCE(SUM(CASE WHEN debt > 0 THEN 1 ELSE 0 END), 0) AS debtors'
        )->first();

        return [
            'total_debt' => Dec::str(Dec::units($row->owed, 2), 2),
            'total_credit' => Dec::str(Dec::units($row->credit, 2), 2),
            'debtors_count' => (int) $row->debtors,
        ];
    }

    public function customerSummary(int $id): array
    {
        $s = fn (string $type) => DB::table('sales')->where('customer_id', $id)->where('status', 'approved')->where('type', $type);
        $sales = $s('sale');
        $returns = $s('return');
        $payments = DB::table('customer_payments')->where('customer_id', $id)->where('status', 'approved');

        $salesTotal = Dec::units($sales->sum('total'), 2);
        $returnsTotal = Dec::units($returns->sum('total'), 2);
        $paidAtSale = Dec::units($sales->sum('paid_amount'), 2);
        $refunded = Dec::units($returns->sum('paid_amount'), 2);
        $debtPayments = Dec::units($payments->sum('amount'), 2);

        return [
            'sales_count' => (int) $sales->count(),
            'sales_total' => Dec::str($salesTotal, 2),
            'returns_total' => Dec::str($returnsTotal, 2),
            'net_total' => Dec::str($salesTotal - $returnsTotal, 2),             // umumiy xarid summasi (qaytarishsiz)
            'paid_total' => Dec::str($paidAtSale - $refunded + $debtPayments, 2), // jami to'langan (qarz to'lovlari bilan)
            'debt_payments_total' => Dec::str($debtPayments, 2),
            'debt' => Dec::str($this->customerDebtUnits($id), 2),
        ];
    }

    public function supplierSummary(int $id): array
    {
        $d = fn (string $type) => DB::table('stock_documents')->where('supplier_id', $id)->where('status', 'approved')->where('type', $type);
        $purchases = $d('purchase');
        $returns = $d('purchase_return');
        $payments = DB::table('supplier_payments')->where('supplier_id', $id)->where('status', 'approved');

        $purchasesTotal = Dec::units($purchases->sum('total'), 2);
        $paidAtPurchase = Dec::units($purchases->sum('paid_amount'), 2);
        $returnsTotal = Dec::units($returns->sum('total'), 2);
        $later = Dec::units($payments->sum('amount'), 2);

        return [
            'purchases_count' => (int) $purchases->count(),
            'purchases_total' => Dec::str($purchasesTotal, 2),
            'returns_total' => Dec::str($returnsTotal, 2),
            'net_total' => Dec::str($purchasesTotal - $returnsTotal, 2),
            'paid_at_purchase' => Dec::str($paidAtPurchase, 2),
            'payments_total' => Dec::str($later, 2),
            'paid_total' => Dec::str($paidAtPurchase + $later, 2),
            'debt' => Dec::str($this->supplierDebtUnits($id), 2),
        ];
    }

    /**
     * O'zaro hisob-kitob varaqasi: qarzga ta'sir qiluvchi barcha hodisalar (sana bo'yicha) va yurib boruvchi qoldiq.
     * `charge` — qarz oshdi, `credit` — qarz kamaydi. Davr filtri qoldiqni buzmaydi: boshlang'ich qoldiq alohida beriladi.
     */
    public function statement(string $party, int $id, ?string $from = null, ?string $to = null): array
    {
        $events = $party === 'customer' ? $this->customerEvents($id) : $this->supplierEvents($id);
        usort($events, fn ($a, $b) => [$a['at'], $a['seq']] <=> [$b['at'], $b['seq']]);

        $balance = 0;
        $opening = 0;
        $rows = [];
        foreach ($events as $e) {
            $day = substr($e['at'], 0, 10);
            $balance += $e['charge'] - $e['credit'];
            if ($from && $day < $from) {
                $opening = $balance;

                continue;
            }
            if ($to && $day > $to) {
                continue;
            }
            $rows[] = [
                'date' => $e['at'], 'kind' => $e['kind'], 'number' => $e['number'], 'description' => $e['description'],
                'charge' => Dec::str($e['charge'], 2), 'credit' => Dec::str($e['credit'], 2), 'balance' => Dec::str($balance, 2),
            ];
        }

        return ['opening_balance' => Dec::str($opening, 2), 'closing_balance' => Dec::str($balance, 2), 'rows' => $rows];
    }

    private function customerEvents(int $id): array
    {
        $events = [];
        foreach (DB::table('sales')->where('customer_id', $id)->where('status', 'approved')->where('debt_amount', '>', 0)->get() as $r) {
            $isSale = $r->type === 'sale';
            $debt = Dec::units($r->debt_amount, 2);
            $events[] = [
                'at' => (string) $r->sold_at, 'seq' => (int) $r->id, 'kind' => $isSale ? 'sale' : 'return', 'number' => $r->number,
                'description' => $isSale ? 'Savdo (qarzga: '.Dec::str($debt, 2).')' : 'Sotuvni qaytarish (qarz kamaydi)',
                'charge' => $isSale ? $debt : 0, 'credit' => $isSale ? 0 : $debt,
            ];
        }
        foreach (DB::table('customer_payments')->where('customer_id', $id)->where('status', 'approved')->get() as $r) {
            $events[] = [
                'at' => (string) $r->paid_at, 'seq' => 1000000000 + (int) $r->id, 'kind' => 'payment', 'number' => $r->number,
                'description' => 'Qarz to‘lovi ('.$r->method.')', 'charge' => 0, 'credit' => Dec::units($r->amount, 2),
            ];
        }

        return $events;
    }

    private function supplierEvents(int $id): array
    {
        $events = [];
        foreach (DB::table('stock_documents')->where('supplier_id', $id)->where('status', 'approved')->whereIn('type', ['purchase', 'purchase_return'])->get() as $r) {
            $isPurchase = $r->type === 'purchase';
            $total = Dec::units($r->total, 2);
            $owed = $isPurchase ? $total - Dec::units($r->paid_amount, 2) : $total;
            if ($owed === 0) {
                continue;
            }
            $events[] = [
                'at' => (string) ($r->approved_at ?? $r->created_at), 'seq' => (int) $r->id, 'kind' => $isPurchase ? 'purchase' : 'return', 'number' => $r->number,
                'description' => $isPurchase
                    ? 'Xarid (jami '.Dec::str($total, 2).', to‘langan '.Dec::str(Dec::units($r->paid_amount, 2), 2).')'
                    : 'Yetkazib beruvchiga qaytarish (qarz kamaydi)',
                'charge' => $isPurchase ? $owed : 0, 'credit' => $isPurchase ? 0 : $owed,
            ];
        }
        foreach (DB::table('supplier_payments')->where('supplier_id', $id)->where('status', 'approved')->get() as $r) {
            $events[] = [
                'at' => (string) $r->paid_at, 'seq' => 1000000000 + (int) $r->id, 'kind' => 'payment', 'number' => $r->number,
                'description' => 'Qarz to‘lovi ('.$r->method.')', 'charge' => 0, 'credit' => Dec::units($r->amount, 2),
            ];
        }

        return $events;
    }
}
