<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Parties\CustomerRequest;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Services\AuditLogger;
use App\Services\PartyLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** Mijozlar moduli (7-bosqich): ro'yxat + qarz, kartochka, xaridlar tarixi, to'lovlar, o'zaro hisob-kitob varaqasi. */
class CustomerController extends Controller
{
    public function __construct(private PartyLedgerService $ledger)
    {
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
        $filter = fn ($q) => $q->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
            ->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(phone) LIKE ?', [$like])));

        // POS tanlovi: faol mijozlar, ixcham ro'yxat (6-bosqich bilan mos).
        if ($request->boolean('all')) {
            return Customer::where('is_active', true)->tap($filter)->orderBy('name')->limit(50)->get(['id', 'name', 'phone']);
        }

        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $debt = PartyLedgerService::CUSTOMER_DEBT_SQL;

        return Customer::query()->select('customers.*')->selectRaw("$debt AS debt")
            ->tap($filter)
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->boolean('has_debt'), fn ($q) => $q->whereRaw("$debt > 0"))
            ->when($request->query('sort') === 'debt', fn ($q) => $q->orderByRaw("$debt DESC")->orderBy('name'), fn ($q) => $q->orderBy('name'))
            ->paginate($perPage);
    }

    public function summary(): JsonResponse
    {
        return response()->json($this->ledger->totals('customer'));
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json($customer->toArray() + ['summary' => $this->ledger->customerSummary($customer->id)]);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $customer = Customer::create($data);
        AuditLogger::log('customers.create', $customer, null, $data);

        return response()->json($customer, 201);
    }

    public function update(CustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->fill($request->validated());
        $old = Arr::only($customer->getOriginal(), array_keys($customer->getDirty()));
        $new = $customer->getDirty();

        if (array_key_exists('is_active', $new) && ! $customer->is_active && $this->ledger->customerDebtUnits($customer->id) !== 0) {
            return response()->json(['message' => 'Qarzi (yoki ortiqcha to‘lovi) bor mijozni faolsizlantirib bo‘lmaydi.'], 422);
        }

        $customer->save();
        AuditLogger::log('customers.update', $customer, $old, $new);

        return response()->json($customer->fresh());
    }

    /** Mijoz o'chirilmaydi (savdo tarixi bog'liq) — faolsizlantiriladi; qarz 0 bo'lishi shart. */
    public function destroy(Customer $customer): JsonResponse
    {
        if ($this->ledger->customerDebtUnits($customer->id) !== 0) {
            return response()->json(['message' => 'Qarzi (yoki ortiqcha to‘lovi) bor mijozni faolsizlantirib bo‘lmaydi.'], 422);
        }
        $customer->update(['is_active' => false]);
        AuditLogger::log('customers.deactivate', $customer, ['is_active' => true], ['is_active' => false]);

        return response()->json(['message' => 'Mijoz faol emas holatga o‘tkazildi.']);
    }

    /** Xaridlar tarixi: sotuvlar va qaytarishlar (bekor qilinganlari ham ko'rinadi, qarzga hisoblanmaydi). */
    public function sales(Request $request, Customer $customer)
    {
        $perPage = max(5, min((int) $request->query('per_page', 20), 100));

        return Sale::where('customer_id', $customer->id)
            ->when(in_array($request->query('type'), ['sale', 'return'], true), fn ($q) => $q->where('type', $request->query('type')))
            ->orderByDesc('sold_at')->orderByDesc('id')
            ->paginate($perPage, ['id', 'number', 'type', 'status', 'parent_id', 'sold_at', 'total', 'paid_amount', 'debt_amount']);
    }

    public function payments(Request $request, Customer $customer)
    {
        $perPage = max(5, min((int) $request->query('per_page', 20), 100));

        return CustomerPayment::with('creator:id,name')->where('customer_id', $customer->id)
            ->orderByDesc('paid_at')->orderByDesc('id')->paginate($perPage);
    }

    public function statement(Request $request, Customer $customer): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return response()->json(
            ['party' => $customer->only(['id', 'name', 'phone'])]
            + $this->ledger->statement('customer', $customer->id, $request->query('from'), $request->query('to'))
            + ['summary' => $this->ledger->customerSummary($customer->id)]
        );
    }
}
