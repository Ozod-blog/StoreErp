<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Parties\SupplierRequest;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\AuditLogger;
use App\Services\PartyLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** Yetkazib beruvchilar moduli (7-bosqich): ro'yxat + qarz, kartochka, xaridlar, to'lovlar, o'zaro hisob-kitob varaqasi. */
class SupplierController extends Controller
{
    public function __construct(private PartyLedgerService $ledger)
    {
    }

    public function index(Request $request)
    {
        // Xarid formasi tanlovi (4-bosqich bilan mos).
        if ($request->boolean('all')) {
            return Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone']);
        }

        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));
        $debt = PartyLedgerService::SUPPLIER_DEBT_SQL;

        return Supplier::query()->select('suppliers.*')->selectRaw("$debt AS debt")
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(phone) LIKE ?', [$like]));
            })
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->boolean('has_debt'), fn ($q) => $q->whereRaw("$debt > 0"))
            ->when($request->query('sort') === 'debt', fn ($q) => $q->orderByRaw("$debt DESC")->orderBy('name'), fn ($q) => $q->orderBy('name'))
            ->paginate($perPage);
    }

    public function summary(): JsonResponse
    {
        return response()->json($this->ledger->totals('supplier'));
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return response()->json($supplier->toArray() + ['summary' => $this->ledger->supplierSummary($supplier->id)]);
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        $data = $request->validated();
        $supplier = Supplier::create($data);
        AuditLogger::log('suppliers.create', $supplier, null, $data);

        return response()->json($supplier, 201);
    }

    public function update(SupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier->fill($request->validated());
        $old = Arr::only($supplier->getOriginal(), array_keys($supplier->getDirty()));
        $new = $supplier->getDirty();

        if (array_key_exists('is_active', $new) && ! $supplier->is_active && $this->ledger->supplierDebtUnits($supplier->id) !== 0) {
            return response()->json(['message' => 'Qarzi (yoki ortiqcha to‘lovi) bor yetkazib beruvchini faolsizlantirib bo‘lmaydi.'], 422);
        }

        $supplier->save();
        AuditLogger::log('suppliers.update', $supplier, $old, $new);

        return response()->json($supplier->fresh());
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        if ($this->ledger->supplierDebtUnits($supplier->id) !== 0) {
            return response()->json(['message' => 'Qarzi (yoki ortiqcha to‘lovi) bor yetkazib beruvchini faolsizlantirib bo‘lmaydi.'], 422);
        }
        $supplier->update(['is_active' => false]);
        AuditLogger::log('suppliers.deactivate', $supplier, ['is_active' => true], ['is_active' => false]);

        return response()->json(['message' => 'Yetkazib beruvchi faol emas holatga o‘tkazildi.']);
    }

    /** Xaridlar va yetkazib beruvchiga qaytarishlar tarixi. */
    public function documents(Request $request, Supplier $supplier)
    {
        $perPage = max(5, min((int) $request->query('per_page', 20), 100));

        return StockDocument::where('supplier_id', $supplier->id)->whereIn('type', ['purchase', 'purchase_return'])
            ->when(in_array($request->query('type'), ['purchase', 'purchase_return'], true), fn ($q) => $q->where('type', $request->query('type')))
            ->orderByDesc('doc_date')->orderByDesc('id')
            ->paginate($perPage, ['id', 'number', 'type', 'status', 'doc_date', 'total', 'paid_amount', 'payment_method']);
    }

    public function payments(Request $request, Supplier $supplier)
    {
        $perPage = max(5, min((int) $request->query('per_page', 20), 100));

        return SupplierPayment::with('creator:id,name')->where('supplier_id', $supplier->id)
            ->orderByDesc('paid_at')->orderByDesc('id')->paginate($perPage);
    }

    public function statement(Request $request, Supplier $supplier): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return response()->json(
            ['party' => $supplier->only(['id', 'name', 'phone'])]
            + $this->ledger->statement('supplier', $supplier->id, $request->query('from'), $request->query('to'))
            + ['summary' => $this->ledger->supplierSummary($supplier->id)]
        );
    }
}
