<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Cashbox;
use App\Models\CashboxShift;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\PriceRule;
use App\Models\ProductBatch;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Dec;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Savdo/POS jarayonining YAGONA kirish nuqtasi (6-bosqich).
 *
 * Bir savdo bitta DB tranzaksiyasida: hujjat + qatorlar + ombor harakati (StockService) + to'lovlar +
 * kassa daftari (CashService) + audit. Birortasi xato bersa — hammasi qaytariladi.
 *
 * Tannarx: 4-bosqichdagi vaznli o'rtacha (products.avg_cost). Savdo paytidagi qiymat sale_items.unit_cost ga
 * "muzlatiladi"; foyda = sof tushum (chegirmadan keyin) − tannarx. Qaytarishda asl savdoning tannarxi ishlatiladi
 * va avg_cost o'zgarmaydi (xarid qaytarishi, chiqim bilan bir xil qoida).
 *
 * Pul — butun sonlarda (tiyin), miqdor — 3 xonali butun son (Support/Dec); float ishlatilmaydi.
 */
class SaleService
{
    public const METHODS = ['cash', 'card', 'click', 'payme', 'transfer'];

    /** Bu usullarda chek / tranzaksiya raqami majburiy (kassir qo'lda kiritadi; avtomatik tekshirilmaydi). */
    public const REFERENCE_REQUIRED = ['click', 'payme', 'transfer'];

    public const METHOD_LABELS = [
        'cash' => 'Naqd pul', 'card' => 'Bank kartasi', 'click' => 'Click', 'payme' => 'Payme', 'transfer' => 'Bank o‘tkazmasi',
    ];

    public function __construct(private StockService $stock, private CashService $cash)
    {
    }

    // ---------------------------------------------------------------- sotuv

    /** Read-only authoritative quote used by POS before collecting payment. Amounts are decimal currency strings. */
    public function quote(array $data, User $user): array
    {
        $customer = !empty($data['customer_id']) ? Customer::find((int)$data['customer_id']) : null;
        if (!empty($data['customer_id']) && (!$customer || !$customer->is_active)) throw new BusinessRuleException('Mijoz topilmadi yoki faol emas.');
        $cardCode = trim((string)($data['loyalty_card_code'] ?? ''));
        $account = $customer ? LoyaltyAccount::where('customer_id', $customer->id)->first() : null;
        if ($cardCode !== '' && (!$account || !hash_equals((string)$account->card_code, $cardCode))) throw new BusinessRuleException('Loyallik kartasi kodi mijozga mos emas.');
        if ($cardCode !== '' && !$customer) throw new BusinessRuleException('Loyallik kartasi uchun mijozni tanlang.');
        $pricingType = ($data['pricing_type'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        $products = Product::with('unit')->whereIn('id', collect($data['items'] ?? [])->pluck('product_id'))->get()->keyBy('id');
        $subtotal = 0; $lineDiscountTotal = 0; $lines = [];
        foreach (($data['items'] ?? []) as $row) {
            $product = $products[(int)$row['product_id']] ?? null;
            if (!$product || !$product->is_active) throw new BusinessRuleException('Mahsulot topilmadi yoki faol emas.');
            $qty = Dec::units($row['quantity'], 3);
            if ($qty <= 0) throw new BusinessRuleException('Miqdor 0 dan katta bo‘lishi kerak.');
            if ($warehouseId > 0) {
                $eligibleMilli = Dec::units((string) ProductBatch::where('warehouse_id', $warehouseId)->where('product_id', $product->id)->where('quantity_remaining', '>', 0)->where(fn($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString()))->sum('quantity_remaining'), 3);
                if ($qty > $eligibleMilli) throw new BusinessRuleException('«'.$product->name.'»: yaroqlilik muddati o‘tmagan partiya qoldig‘i yetarli emas.');
            }
            $basePrice = Dec::units($pricingType === 'wholesale' && $product->wholesale_price !== null ? $product->wholesale_price : $product->sale_price, 2);
            $price = isset($row['price']) && $row['price'] !== '' ? Dec::units($row['price'], 2) : $basePrice;
            if ($price !== $basePrice && !$user->can('sales.discount')) throw new AuthorizationException('Narxni o‘zgartirish uchun ruxsat kerak.');
            $gross = Dec::lineTotal($qty, $price);
            $auto = $this->automaticDiscountFor($product, $customer, $account, $cardCode, $gross);
            $manual = Dec::units($row['discount'] ?? 0, 2);
            if ($manual > 0 && !$user->can('sales.discount')) throw new AuthorizationException('Qo‘lda chegirma berish uchun ruxsat kerak.');
            if ($manual < 0 || $auto + $manual > $gross) throw new BusinessRuleException('Chegirma qator summasidan oshib ketdi.');
            $discount = $auto + $manual;
            $lines[] = ['product_id' => $product->id, 'gross' => Dec::str($gross, 2), 'discount' => Dec::str($discount, 2), 'net' => Dec::str($gross - $discount, 2)];
            $subtotal += $gross; $lineDiscountTotal += $discount;
        }
        $net = array_sum(array_map(fn($line) => Dec::units($line['net'], 2), $lines));
        $docDiscount = Dec::units($data['discount'] ?? 0, 2);
        if ($docDiscount > 0 && !$user->can('sales.discount')) throw new AuthorizationException('Umumiy chegirma uchun ruxsat kerak.');
        if ($docDiscount < 0 || $docDiscount > $net) throw new BusinessRuleException('Umumiy chegirma noto‘g‘ri.');
        $redeem = max(0, (int)($data['loyalty_points_redeem'] ?? 0));
        $loyaltyDiscount = 0;
        if ($redeem > 0) {
            if (!$account || !$account->is_active || $redeem > (int)$account->points_balance) throw new BusinessRuleException('Loyallik ballari yetarli emas yoki karta faol emas.');
            $loyaltyDiscount = $redeem * max(1, (int)config('erp.loyalty_point_value_minor', 1));
            if ($loyaltyDiscount >= $net - $docDiscount) throw new BusinessRuleException('Loyallik ballari chegirmasi savdo summasidan kichik bo‘lishi kerak.');
        }
        $discount = $lineDiscountTotal + $docDiscount + $loyaltyDiscount;
        if ($subtotal - $discount <= 0) throw new BusinessRuleException('Savdo summasi noldan katta bo‘lishi kerak.');
        return ['subtotal' => Dec::str($subtotal, 2), 'discount' => Dec::str($discount, 2), 'total' => Dec::str($subtotal - $discount, 2), 'lines' => $lines, 'loyalty_discount' => Dec::str($loyaltyDiscount, 2)];
    }

    public function checkout(array $data, User $user): Sale
    {
        return DB::transaction(function () use ($data, $user) {
            [$shift, $cashbox] = $this->lockOpenShift($user, 'Savdo qilish uchun avval kassa smenasini oching.');
            $warehouse = $this->resolveWarehouse($cashbox, isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null);

            $customer = null;
            if (! empty($data['customer_id'])) {
                $customer = Customer::find((int) $data['customer_id']);
                if (! $customer || ! $customer->is_active) {
                    throw new BusinessRuleException('Mijoz topilmadi yoki faol emas.');
                }
            }

            $canDiscount = $user->can('sales.discount');
            $pricingType = ($data['pricing_type'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
            $cardCode = trim((string) ($data['loyalty_card_code'] ?? ''));
            $loyaltyAccount = null;
            if ($customer) {
                $loyaltyAccount = LoyaltyAccount::where('customer_id', $customer->id)->lockForUpdate()->first();
                if ($cardCode !== '' && !$loyaltyAccount) {
                    throw new BusinessRuleException('Bu mijoz uchun loyallik kartasi mavjud emas.');
                }
                if ($cardCode !== '' && $loyaltyAccount && ! hash_equals((string) $loyaltyAccount->card_code, $cardCode)) {
                    throw new BusinessRuleException('Mijozning loyallik kartasi kodi mos kelmadi.');
                }
                if (!$loyaltyAccount && $cardCode === '') {
                    $loyaltyAccount = LoyaltyAccount::create(['customer_id' => $customer->id, 'card_code' => 'LOY-'.strtoupper(Str::random(12)), 'points_balance' => 0, 'lifetime_points' => 0, 'is_active' => true]);
                }
            } elseif ($cardCode !== '') {
                throw new BusinessRuleException('Loyallik kartasidan foydalanish uchun mijozni tanlang.');
            }
            $products = $this->stock->lockProducts(collect($data['items'])->pluck('product_id')->all(), ['unit']);

            $lines = [];
            $subtotal = 0;
            $lineDiscounts = 0;
            foreach ($data['items'] as $row) {
                $product = $products[(int) $row['product_id']] ?? null;
                if (! $product || $product->trashed() || ! $product->is_active) {
                    throw new BusinessRuleException('Mahsulot topilmadi, arxivda yoki faol emas.');
                }

                $qty = Dec::units($row['quantity'], 3);
                if ($qty <= 0) {
                    throw new BusinessRuleException("«{$product->name}»: miqdor 0 dan katta bo‘lishi kerak.");
                }
                if (! $product->unit->allow_fraction && $qty % 1000 !== 0) {
                    throw new BusinessRuleException("«{$product->name}» butun miqdorda sotiladi (kasr mumkin emas).");
                }

                // Narxni server belgilaydi; kassir uni faqat chegirma ruxsati bo'lsa o'zgartira oladi.
                $listPrice = Dec::units($pricingType === 'wholesale' && $product->wholesale_price !== null ? $product->wholesale_price : $product->sale_price, 2);
                $price = isset($row['price']) && $row['price'] !== '' ? Dec::units($row['price'], 2) : $listPrice;
                if ($price !== $listPrice && ! $canDiscount) {
                    throw new AuthorizationException("«{$product->name}»: narxni o‘zgartirish uchun chegirma ruxsati kerak.");
                }

                $gross = Dec::lineTotal($qty, $price);
                $ruleDiscount = $this->automaticDiscountFor($product, $customer, $loyaltyAccount, $cardCode, $gross);
                $manualLineDiscount = Dec::units($row['discount'] ?? 0, 2);
                if ($manualLineDiscount > 0 && ! $canDiscount) {
                    throw new AuthorizationException('Qo‘lda chegirma berish uchun ruxsatingiz yo‘q.');
                }
                $lineDiscount = $ruleDiscount + $manualLineDiscount;
                if ($lineDiscount < 0 || $lineDiscount > $gross) {
                    throw new BusinessRuleException("«{$product->name}»: chegirma qator summasidan oshib ketdi.");
                }

                $cost4 = Dec::units($product->avg_cost, 4);
                $lines[] = [
                    'product' => $product,
                    'qty' => $qty,
                    'price' => $price,
                    'gross' => $gross,
                    'discount' => $lineDiscount,
                    'net0' => $gross - $lineDiscount,
                    'cost4' => $cost4,
                    'cogs' => Dec::mulDiv($qty, $cost4, 100000),
                ];
                $subtotal += $gross;
                $lineDiscounts += $lineDiscount;
            }

            $sumNet0 = array_sum(array_column($lines, 'net0'));
            $docDiscount = Dec::units($data['discount'] ?? 0, 2);
            if ($docDiscount < 0) {
                throw new BusinessRuleException('Chegirma manfiy bo‘lishi mumkin emas.');
            }
            if ($docDiscount > 0 && ! $canDiscount) {
                throw new AuthorizationException('Chegirma berish uchun ruxsatingiz yo‘q.');
            }
            if ($docDiscount > $sumNet0) {
                throw new BusinessRuleException('Chegirma jami summadan oshib ketdi.');
            }
            $redeemPoints = max(0, (int) ($data['loyalty_points_redeem'] ?? 0));
            $pointMinorValue = max(1, (int) config('erp.loyalty_point_value_minor', 1));
            $loyaltyDiscount = 0;
            if ($redeemPoints > 0) {
                if (! $loyaltyAccount || ! $loyaltyAccount->is_active) {
                    throw new BusinessRuleException('Faol loyallik kartasi kerak.');
                }
                if ($redeemPoints > (int) $loyaltyAccount->points_balance) {
                    throw new BusinessRuleException('Loyallik ballari yetarli emas.');
                }
                $loyaltyDiscount = $redeemPoints * $pointMinorValue;
                if ($loyaltyDiscount >= $sumNet0 - $docDiscount) {
                    throw new BusinessRuleException('Loyallik ballari chegirmasi savdo summasidan kichik bo‘lishi kerak.');
                }
            }
            $effectiveDocDiscount = $docDiscount + $loyaltyDiscount;
            $shares = $this->allocate($effectiveDocDiscount, array_column($lines, 'net0'));
            $total = $sumNet0 - $effectiveDocDiscount;
            if ($total <= 0) {
                throw new BusinessRuleException('Savdo summasi noldan katta bo‘lishi kerak.');
            }

            $payments = $this->parsePayments($data['payments'] ?? []);
            $paid = array_sum(array_column($payments, 'amount'));
            if ($paid > $total) {
                throw new BusinessRuleException('To‘lov summasi jami summadan oshib ketdi (qaytimni hisoblab, faqat jami summagacha kiriting).');
            }
            $debt = $total - $paid;
            if ($debt > 0) {
                if (! $user->can('sales.debt')) {
                    throw new AuthorizationException('Qarzga sotish uchun ruxsatingiz yo‘q. To‘lovni to‘liq kiriting.');
                }
                if (! $customer) {
                    throw new BusinessRuleException('Qarzga sotish uchun mijozni tanlang.');
                }
            }

            $cogs = array_sum(array_column($lines, 'cogs'));
            $sale = Sale::create([
                'number' => 'T'.Str::random(20), // vaqtincha; quyida id asosida almashtiriladi
                'type' => 'sale',
                'status' => 'approved',
                'branch_id' => $cashbox->branch_id,
                'warehouse_id' => $warehouse->id,
                'cashbox_id' => $cashbox->id,
                'shift_id' => $shift->id,
                'customer_id' => $customer?->id,
                'subtotal' => Dec::str($subtotal, 2),
                'discount' => Dec::str($lineDiscounts + $effectiveDocDiscount, 2),
                'pricing_type' => $pricingType,
                'loyalty_points_redeemed' => $redeemPoints,
                'loyalty_points_earned' => 0,
                'total' => Dec::str($total, 2),
                'paid_amount' => Dec::str($paid, 2),
                'debt_amount' => Dec::str($debt, 2),
                'cogs' => Dec::str($cogs, 2),
                'note' => isset($data['note']) ? (trim((string) $data['note']) ?: null) : null,
                'client_token' => $data['client_token'] ?? null,
                'created_by' => $user->id,
                'sold_at' => now(),
            ]);
            $sale->update(['number' => Sale::PREFIXES['sale'].'-'.str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($lines as $i => $line) {
                $sale->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => Dec::str($line['qty'], 3),
                    'price' => Dec::str($line['price'], 2),
                    'discount' => Dec::str($line['discount'], 2),
                    'discount_share' => Dec::str($shares[$i], 2),
                    'net_total' => Dec::str($line['net0'] - $shares[$i], 2),
                    'unit_cost' => Dec::str($line['cost4'], 4),
                ]);
                $this->stock->moveForSale($sale, $warehouse->id, $line['product'], -$line['qty'], 'sale', $line['cost4'] / 10000, $user->id);
            }

            if ($redeemPoints > 0 && $loyaltyAccount) {
                $loyaltyAccount->decrement('points_balance', $redeemPoints);
                LoyaltyTransaction::create(['loyalty_account_id' => $loyaltyAccount->id, 'sale_id' => $sale->id, 'points_delta' => -$redeemPoints, 'reason' => 'Savdoda ball ishlatildi', 'created_by' => $user->id]);
            }
            if ($loyaltyAccount && $loyaltyAccount->is_active) {
                $earnDivisor = max(1, (int) config('erp.loyalty_earn_minor_per_point', 1000));
                $earned = intdiv($total, $earnDivisor);
                if ($earned > 0) {
                    $loyaltyAccount->increment('points_balance', $earned);
                    $loyaltyAccount->increment('lifetime_points', $earned);
                    $sale->update(['loyalty_points_earned' => $earned]);
                    LoyaltyTransaction::create(['loyalty_account_id' => $loyaltyAccount->id, 'sale_id' => $sale->id, 'points_delta' => $earned, 'reason' => 'Savdo uchun ball berildi', 'created_by' => $user->id]);
                }
            }

            foreach ($payments as $p) {
                $this->recordPayment($sale, $shift, $cashbox, $user, 'in', 'payment', $p['method'], $p['amount'], $p['reference'], 'sale', 'Savdo '.$sale->number);
            }

            AuditLogger::log('sales.create', $sale, null, [
                'number' => $sale->number,
                'total' => $sale->total,
                'discount' => $sale->discount,
                'paid_amount' => $sale->paid_amount,
                'debt_amount' => $sale->debt_amount,
                'customer_id' => $sale->customer_id,
                'items' => count($lines),
                'methods' => array_column($payments, 'method'),
            ], 'success', $user->id);

            return $this->fresh($sale);
        });
    }

    // ---------------------------------------------------------------- bekor qilish

    /** To'liq bekor qilish: ombor qoldig'i tiklanadi, to'lovlar joriy smenada teskari yoziladi. Tarix o'chirilmaydi. */
    public function cancel(int $saleId, ?string $reason, User $user): Sale
    {
        return DB::transaction(function () use ($saleId, $reason, $user) {
            $sale = Sale::whereKey($saleId)->lockForUpdate()->firstOrFail();
            if ($sale->type !== 'sale') {
                throw new BusinessRuleException('Faqat sotuvni bekor qilish mumkin (qaytarish hujjati bekor qilinmaydi).');
            }
            if ($sale->status === 'cancelled') {
                throw new BusinessRuleException('Sotuv allaqachon bekor qilingan.');
            }
            $reason = trim((string) $reason);
            if (mb_strlen($reason) < 3) {
                throw new BusinessRuleException('Bekor qilish sababini kiriting.');
            }
            if (Sale::where('parent_id', $sale->id)->where('type', 'return')->where('status', 'approved')->exists()) {
                throw new BusinessRuleException('Bu sotuv bo‘yicha qaytarish qilingan — bekor qilib bo‘lmaydi.');
            }

            $payments = Payment::where('sale_id', $sale->id)->where('kind', 'payment')->get();
            $shift = null;
            $cashbox = null;
            if ($payments->isNotEmpty()) {
                // Pul bugungi kassadan qaytadi, shuning uchun bekor qiluvchining ochiq smenasi kerak.
                [$shift, $cashbox] = $this->lockOpenShift($user, 'To‘lovli sotuvni bekor qilish uchun avval o‘z kassa smenangizni oching.');
            }

            $sale->load('items');
            $products = $this->stock->lockProducts($sale->items->pluck('product_id')->all());
            foreach ($sale->items as $item) {
                $this->stock->moveForSale(
                    $sale, (int) $sale->warehouse_id, $products[$item->product_id], Dec::units($item->quantity, 3),
                    'reversal', (float) $item->unit_cost, $user->id
                );
            }

            $pointTransactions = LoyaltyTransaction::where('sale_id', $sale->id)->lockForUpdate()->get();
            foreach ($pointTransactions as $pointTransaction) {
                $account = LoyaltyAccount::whereKey($pointTransaction->loyalty_account_id)->lockForUpdate()->first();
                if (!$account) continue;
                $reverseDelta = -((int) $pointTransaction->points_delta);
                if ((int) $account->points_balance + $reverseDelta < 0) {
                    throw new BusinessRuleException('Savdoda berilgan loyallik ballarining bir qismi ishlatilgan. Avval ballar balansini tekshiring.');
                }
                $account->increment('points_balance', $reverseDelta);
                if ((int) $pointTransaction->points_delta > 0) $account->decrement('lifetime_points', min((int) $account->lifetime_points, (int) $pointTransaction->points_delta));
                LoyaltyTransaction::create(['loyalty_account_id' => $account->id, 'sale_id' => $sale->id, 'points_delta' => $reverseDelta, 'reason' => 'Savdo bekor qilindi: ballar qayta hisoblandi', 'created_by' => $user->id]);
            }

            foreach ($payments as $p) {
                $this->recordPayment(
                    $sale, $shift, $cashbox, $user, 'out', 'reversal', $p->method, Dec::units($p->amount, 2), $p->reference,
                    'sale_cancel', 'Savdo bekor qilindi '.$sale->number
                );
            }

            $sale->forceFill([
                'status' => 'cancelled',
                'cancel_reason' => $reason,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ])->save();

            AuditLogger::log('sales.cancel', $sale, ['status' => 'approved'], [
                'status' => 'cancelled', 'number' => $sale->number, 'reason' => $reason, 'total' => $sale->total,
            ], 'success', $user->id);

            return $this->fresh($sale);
        });
    }

    // ---------------------------------------------------------------- qaytarish

    /** Mijozdan sotuvni (qisman yoki to'liq) qaytarish. Asl sotuvga bog'lanadi. */
    public function createReturn(int $saleId, array $data, User $user): Sale
    {
        return DB::transaction(function () use ($saleId, $data, $user) {
            $parent = Sale::whereKey($saleId)->lockForUpdate()->firstOrFail();
            if ($parent->type !== 'sale' || $parent->status !== 'approved') {
                throw new BusinessRuleException('Qaytarish faqat tasdiqlangan (bekor qilinmagan) sotuvga nisbatan yaratiladi.');
            }
            $reason = trim((string) ($data['note'] ?? ''));
            if (mb_strlen($reason) < 3) {
                throw new BusinessRuleException('Qaytarish sababini kiriting.');
            }

            [$shift, $cashbox] = $this->lockOpenShift($user, 'Qaytarishni rasmiylashtirish uchun avval kassa smenasini oching.');

            $parent->load('items');
            $returned = $this->returnedByProduct($parent->id);
            $products = $this->stock->lockProducts(collect($data['items'])->pluck('product_id')->all());

            $lines = [];
            $refund = 0;
            $cogs = 0;
            foreach ($data['items'] as $row) {
                $pid = (int) $row['product_id'];
                $item = $parent->items->firstWhere('product_id', $pid);
                if (! $item) {
                    throw new BusinessRuleException('Mahsulot asl sotuvda yo‘q.');
                }
                $product = $products[$pid];
                $qty = Dec::units($row['quantity'], 3);
                if ($qty <= 0) {
                    throw new BusinessRuleException("«{$product->name}»: miqdor 0 dan katta bo‘lishi kerak.");
                }

                $sold = Dec::units($item->quantity, 3);
                $done = $returned[$pid]['qty'] ?? 0;
                $left = $sold - $done;
                if ($qty > $left) {
                    throw new BusinessRuleException("«{$product->name}»: qaytarish mumkin bo‘lgan miqdor ".Dec::str(max($left, 0), 3).'.');
                }

                $net = Dec::units($item->net_total, 2);
                $amount = $qty === $left
                    ? $net - ($returned[$pid]['net'] ?? 0)               // oxirgi qism: yaxlitlash farqi qolmasligi uchun qoldiqni to'liq olamiz
                    : Dec::mulDiv($net, $qty, $sold, 'floor');   // pastga: jami qaytarilgan summa hech qachon sotuv summasidan oshmaydi
                $cost4 = Dec::units($item->unit_cost, 4);

                $lines[] = ['item' => $item, 'product' => $product, 'qty' => $qty, 'amount' => $amount, 'cost4' => $cost4];
                $refund += $amount;
                $cogs += Dec::mulDiv($qty, $cost4, 100000);
            }
            if ($refund <= 0) {
                throw new BusinessRuleException('Qaytariladigan summa noldan katta bo‘lishi kerak.');
            }

            // Avval shu sotuvning to'lanmagan qarzi kamayadi; qolgani mijozga pul sifatida qaytariladi.
            $debtLeft = Dec::units($parent->debt_amount, 2) - Dec::units(
                Sale::where('parent_id', $parent->id)->where('type', 'return')->where('status', 'approved')->sum('debt_amount'), 2
            );
            $debtCut = max(0, min($refund, $debtLeft));
            $cashOut = $refund - $debtCut;

            $method = null;
            $reference = null;
            if ($cashOut > 0) {
                $method = $data['refund_method'] ?? null;
                if (! in_array($method, self::METHODS, true)) {
                    throw new BusinessRuleException('Pul qaytarish usulini tanlang.');
                }
                $reference = isset($data['reference']) ? trim((string) $data['reference']) : null;
                if (in_array($method, self::REFERENCE_REQUIRED, true) && ($reference === null || $reference === '')) {
                    throw new BusinessRuleException(self::METHOD_LABELS[$method].' orqali qaytarishda chek / tranzaksiya raqami majburiy.');
                }
            }

            $return = Sale::create([
                'number' => 'T'.Str::random(20),
                'type' => 'return',
                'status' => 'approved',
                'parent_id' => $parent->id,
                'branch_id' => $cashbox->branch_id,
                'warehouse_id' => $parent->warehouse_id,
                'cashbox_id' => $cashbox->id,
                'shift_id' => $shift->id,
                'customer_id' => $parent->customer_id,
                'subtotal' => Dec::str($refund, 2),
                'discount' => Dec::str(0, 2),
                'total' => Dec::str($refund, 2),
                'paid_amount' => Dec::str($cashOut, 2),
                'debt_amount' => Dec::str($debtCut, 2),
                'cogs' => Dec::str($cogs, 2),
                'refund_method' => $method,
                'note' => $reason,
                'client_token' => $data['client_token'] ?? null,
                'created_by' => $user->id,
                'sold_at' => now(),
            ]);
            $return->update(['number' => Sale::PREFIXES['return'].'-'.str_pad((string) $return->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($lines as $line) {
                $return->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => Dec::str($line['qty'], 3),
                    'price' => $line['item']->price,
                    'discount' => Dec::str(0, 2),
                    'discount_share' => Dec::str(0, 2),
                    'net_total' => Dec::str($line['amount'], 2),
                    'unit_cost' => Dec::str($line['cost4'], 4),
                ]);
                $this->stock->moveForSale($return, (int) $parent->warehouse_id, $line['product'], $line['qty'], 'sale_return', $line['cost4'] / 10000, $user->id);
            }

            $this->adjustLoyaltyForReturn($parent, $return, $user);

            if ($cashOut > 0) {
                $this->recordPayment($return, $shift, $cashbox, $user, 'out', 'refund', $method, $cashOut, $reference, 'sale_return', 'Qaytarish '.$return->number);
            }

            AuditLogger::log('sales.return', $return, null, [
                'number' => $return->number,
                'parent' => $parent->number,
                'total' => $return->total,
                'refunded' => $return->paid_amount,
                'debt_reduced' => $return->debt_amount,
                'method' => $method,
                'reason' => $reason,
                'items' => count($lines),
            ], 'success', $user->id);

            return $this->fresh($return);
        });
    }

    /** Sotuvning qaytarish mumkin bo'lgan qatorlari. */
    public function returnable(Sale $sale): array
    {
        $sale->loadMissing('items.product.unit');
        $returned = $this->returnedByProduct($sale->id);

        return $sale->items->map(function ($item) use ($returned) {
            $sold = Dec::units($item->quantity, 3);
            $done = $returned[$item->product_id]['qty'] ?? 0;

            return [
                'product_id' => $item->product_id,
                'name' => $item->product?->name,
                'sku' => $item->product?->sku,
                'unit' => $item->product?->unit?->short_name,
                'allow_fraction' => (bool) $item->product?->unit?->allow_fraction,
                'sold' => Dec::str($sold, 3),
                'returned' => Dec::str($done, 3),
                'remaining' => Dec::str(max($sold - $done, 0), 3),
                'price' => $item->price,
                'net_total' => $item->net_total,
            ];
        })->values()->all();
    }

    // ---------------------------------------------------------------- smena yig'masi

    /** Smena bo'yicha savdo yig'masi: sotuv/qaytarish soni, summalar va to'lov usullari bo'yicha sof tushum (naqd va naqd bo'lmagan). */
    public function shiftSummary(CashboxShift $shift): array
    {
        $sales = DB::table('sales')->where('shift_id', $shift->id)->where('status', 'approved')->where('type', 'sale')
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(discount), 0) AS discount, COALESCE(SUM(debt_amount), 0) AS debt')
            ->first();
        $returns = DB::table('sales')->where('shift_id', $shift->id)->where('status', 'approved')->where('type', 'return')
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total')
            ->first();

        $byMethod = [];
        $rows = DB::table('payments')->where('shift_id', $shift->id)
            ->selectRaw('method, direction, SUM(amount) AS total')
            ->groupBy('method', 'direction')
            ->get();
        foreach ($rows as $r) {
            $byMethod[$r->method] ??= ['in' => 0, 'out' => 0];
            $byMethod[$r->method][$r->direction] += Dec::units($r->total, 2);
        }

        $methods = [];
        $nonCash = 0;
        foreach (self::METHODS as $m) {
            if (! isset($byMethod[$m])) {
                continue;
            }
            $net = $byMethod[$m]['in'] - $byMethod[$m]['out'];
            if ($m !== 'cash') {
                $nonCash += $net;
            }
            $methods[] = [
                'method' => $m,
                'received' => Dec::str($byMethod[$m]['in'], 2),
                'refunded' => Dec::str($byMethod[$m]['out'], 2),
                'net' => Dec::str($net, 2),
            ];
        }

        return [
            'sales_count' => (int) $sales->cnt,
            'sales_total' => Dec::str(Dec::units($sales->total, 2), 2),
            'sales_discount' => Dec::str(Dec::units($sales->discount, 2), 2),
            'sales_debt' => Dec::str(Dec::units($sales->debt, 2), 2),
            'returns_count' => (int) $returns->cnt,
            'returns_total' => Dec::str(Dec::units($returns->total, 2), 2),
            'non_cash_net' => Dec::str($nonCash, 2),
            'by_method' => $methods,
        ];
    }

    // ---------------------------------------------------------------- yordamchilar

    /** Foydalanuvchining ochiq smenasi va uning kassasi qulflanadi (tartib: smena -> kassa, CashService bilan bir xil). */
    private function lockOpenShift(User $user, string $missingMessage): array
    {
        $shift = CashboxShift::where('user_id', $user->id)->where('status', 'open')->lockForUpdate()->first();
        if (! $shift) {
            throw new BusinessRuleException($missingMessage);
        }
        $cashbox = Cashbox::whereKey($shift->cashbox_id)->lockForUpdate()->firstOrFail();
        if (! $cashbox->is_active) {
            throw new BusinessRuleException('Kassa faol emas.');
        }

        return [$shift, $cashbox];
    }

    /** Ombor: so'ralgan (kassa filialiga tegishli bo'lishi shart) yoki yagona faol ombor. */
    public function resolveWarehouse(Cashbox $cashbox, ?int $requested): Warehouse
    {
        $query = Warehouse::where('is_active', true)
            ->when($cashbox->branch_id, fn ($q) => $q->where('branch_id', $cashbox->branch_id));

        if ($requested) {
            $warehouse = (clone $query)->whereKey($requested)->first();
            if (! $warehouse) {
                throw new BusinessRuleException('Tanlangan ombor topilmadi, faol emas yoki bu kassa filialiga tegishli emas.');
            }

            return $warehouse;
        }

        $list = $query->orderBy('id')->get();
        if ($list->count() === 1) {
            return $list->first();
        }
        throw new BusinessRuleException($list->isEmpty() ? 'Bu kassa uchun faol ombor topilmadi.' : 'Savdo qilinadigan omborni tanlang.');
    }

    /** @return list<array{method:string,amount:int,reference:?string}> */
    private function parsePayments(array $payments): array
    {
        $out = [];
        $seen = [];
        foreach ($payments as $p) {
            $method = $p['method'] ?? null;
            if (! in_array($method, self::METHODS, true)) {
                throw new BusinessRuleException('Noma’lum to‘lov usuli.');
            }
            if (in_array($method, $seen, true)) {
                throw new BusinessRuleException(self::METHOD_LABELS[$method].' bir necha marta kiritilgan — bitta summaga birlashtiring.');
            }
            $seen[] = $method;

            $amount = Dec::units($p['amount'] ?? 0, 2);
            if ($amount <= 0) {
                throw new BusinessRuleException(self::METHOD_LABELS[$method].': summa noldan katta bo‘lishi kerak.');
            }
            $reference = isset($p['reference']) ? trim((string) $p['reference']) : null;
            if (in_array($method, self::REFERENCE_REQUIRED, true) && ($reference === null || $reference === '')) {
                throw new BusinessRuleException(self::METHOD_LABELS[$method].' uchun chek / tranzaksiya raqamini kiriting.');
            }

            $out[] = ['method' => $method, 'amount' => $amount, 'reference' => $reference ?: null];
        }

        return $out;
    }

    /**
     * To'lov (yoki qaytarish/teskari) yozuvi. Naqd bo'lsa kassa daftariga ham yoziladi (kassa — CashService orqali).
     * Click/Payme/karta/o'tkazma: faqat kassir tasdiqlagan deb qayd etiladi (is_online_verified = false).
     */
    private function recordPayment(
        Sale $sale, CashboxShift $shift, Cashbox $cashbox, User $user, string $direction, string $kind,
        string $method, int $amount, ?string $reference, string $cashCategory, string $note,
    ): void {
        $txId = null;
        if ($method === 'cash') {
            $tx = $this->cash->record($cashbox, $direction, $amount, $cashCategory, $user, $shift, $note, null, 'sale', $sale->id);
            $txId = $tx->id;
        }

        Payment::create([
            'sale_id' => $sale->id,
            'shift_id' => $shift->id,
            'cashbox_id' => $cashbox->id,
            'user_id' => $user->id,
            'direction' => $direction,
            'kind' => $kind,
            'method' => $method,
            'amount' => Dec::str($amount, 2),
            'reference' => $reference,
            'cash_transaction_id' => $txId,
            'is_online_verified' => false,
        ]);
    }

    /**
     * Hujjat chegirmasini qatorlarga proporsional taqsimlaydi (butun tiyin, yig'indisi aynan $discount).
     * Avval pastga yaxlitlangan ulushlar, qoldiq tiyinlar qatorlarning sig'imiga qarab ketma-ket qo'shiladi.
     *
     * @param  list<int>  $nets
     * @return list<int>
     */
    /** Proportionally reverse earned points and restore redeemed points for partial/full returns. */
    private function adjustLoyaltyForReturn(Sale $parent, Sale $return, User $user): void
    {
        if (!$parent->customer_id || Dec::units($parent->total, 2) <= 0) return;
        $account = LoyaltyAccount::where('customer_id', $parent->customer_id)->lockForUpdate()->first();
        if (!$account) return;
        $returnIds = Sale::where('parent_id', $parent->id)->where('type', 'return')->where('status', 'approved')->pluck('id');
        if ($returnIds->isEmpty()) return;
        $returnedMinor = Dec::units((string) Sale::whereIn('id', $returnIds)->sum('total'), 2);
        $parentTotal = Dec::units($parent->total, 2);
        $ratioNumerator = min($returnedMinor, $parentTotal);
        $targetEarnedReverse = intdiv((int) $parent->loyalty_points_earned * $ratioNumerator, $parentTotal);
        $targetRedeemedRestore = intdiv((int) $parent->loyalty_points_redeemed * $ratioNumerator, $parentTotal);
        $alreadyEarnedDelta = (int) LoyaltyTransaction::whereIn('sale_id', $returnIds)->where('reason', 'Qaytarishda berilgan ball bekor qilindi')->sum('points_delta');
        $alreadyRedeemDelta = (int) LoyaltyTransaction::whereIn('sale_id', $returnIds)->where('reason', 'Qaytarishda ishlatilgan ball tiklandi')->sum('points_delta');
        $earnDelta = -$targetEarnedReverse - $alreadyEarnedDelta;
        $redeemDelta = $targetRedeemedRestore - $alreadyRedeemDelta;
        if ($earnDelta < 0 && (int) $account->points_balance + $earnDelta < 0) {
            throw new BusinessRuleException('Bu savdodan berilgan loyallik ballarining bir qismi ishlatilgan. Qaytarishni tasdiqlashdan oldin ballar balansini tekshiring.');
        }
        if ($earnDelta !== 0) {
            $account->increment('points_balance', $earnDelta);
            if ($earnDelta < 0) $account->decrement('lifetime_points', min((int) $account->lifetime_points, abs($earnDelta)));
            LoyaltyTransaction::create(['loyalty_account_id' => $account->id, 'sale_id' => $return->id, 'points_delta' => $earnDelta, 'reason' => 'Qaytarishda berilgan ball bekor qilindi', 'created_by' => $user->id]);
        }
        if ($redeemDelta !== 0) {
            $account->increment('points_balance', $redeemDelta);
            LoyaltyTransaction::create(['loyalty_account_id' => $account->id, 'sale_id' => $return->id, 'points_delta' => $redeemDelta, 'reason' => 'Qaytarishda ishlatilgan ball tiklandi', 'created_by' => $user->id]);
        }
    }

    /** Server-side active promotion and customer-card discount rules. */
    private function automaticDiscountFor($product, $customer, $loyaltyAccount, string $cardCode, int $gross): int
    {
        $now = now();
        $rules = PriceRule::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->whereIn('kind', ['promotion', 'customer_discount'])
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $product->id))
            ->where(fn ($q) => $q->whereNull('category_id')->orWhere('category_id', $product->category_id))
            ->where(function ($q) use ($customer, $cardCode) {
                $q->where(fn ($c) => $c->whereNull('customer_id')->whereNull('customer_card_code'));
                if ($customer) $q->orWhere('customer_id', $customer->id);
                if ($cardCode !== '') $q->orWhere('customer_card_code', $cardCode);
            })->get();
        $discount = 0;
        foreach ($rules as $rule) {
            $value = Dec::units($rule->discount_value, 2);
            $part = $rule->discount_type === 'percent' ? intdiv($gross * min($value, 10000), 10000) : $value;
            $discount += max(0, $part);
        }
        return min($gross, $discount);
    }

    private function allocate(int $discount, array $nets): array
    {
        $sum = array_sum($nets);
        $shares = array_fill(0, count($nets), 0);
        if ($discount <= 0 || $sum <= 0) {
            return $shares;
        }

        $given = 0;
        foreach ($nets as $i => $net) {
            $shares[$i] = Dec::mulDiv($discount, $net, $sum, 'floor');
            $given += $shares[$i];
        }
        foreach ($nets as $i => $net) {
            if ($given >= $discount) {
                break;
            }
            $add = min($net - $shares[$i], $discount - $given);
            if ($add > 0) {
                $shares[$i] += $add;
                $given += $add;
            }
        }

        return $shares;
    }

    /** Tasdiqlangan qaytarishlar bo'yicha mahsulot kesimida: [product_id => ['qty' => int(3 xona), 'net' => int(2 xona)]]. */
    private function returnedByProduct(int $parentId): array
    {
        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.parent_id', $parentId)->where('sales.type', 'return')->where('sales.status', 'approved')
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id AS product_id, SUM(sale_items.quantity) AS qty, SUM(sale_items.net_total) AS net')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->product_id] = ['qty' => Dec::units($r->qty, 3), 'net' => Dec::units($r->net, 2)];
        }

        return $map;
    }

    private function fresh(Sale $sale): Sale
    {
        return $sale->fresh([
            'items.product.unit', 'payments', 'customer:id,name,phone', 'creator:id,name', 'canceller:id,name',
            'warehouse:id,name', 'cashbox:id,code,name', 'parent:id,number',
            'returns:id,parent_id,number,total,status,sold_at',
        ]);
    }
}
