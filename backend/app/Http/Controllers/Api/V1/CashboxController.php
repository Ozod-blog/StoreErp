<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\CashboxRequest;
use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\CashboxShift;
use App\Models\CashTransaction;
use App\Services\AuditLogger;
use App\Services\CashService;
use App\Support\Dec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CashboxController extends Controller
{
    public function __construct(private CashService $cash)
    {
    }

    private function withBalance()
    {
        return Cashbox::query()->addSelect([
            'cashboxes.*',
            'balance' => CashTransaction::query()
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)")
                ->whereColumn('cash_transactions.cashbox_id', 'cashboxes.id'),
        ]);
    }

    public function index(Request $request)
    {
        $q = $this->withBalance()->with(['branch:id,name', 'openShift.user:id,name']);

        if ($request->boolean('all')) {
            return $q->where('is_active', true)->orderBy('name')->get();
        }

        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));

        return $q
            ->when($search !== '', function ($w) use ($search) {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $w->where(fn ($x) => $x->whereRaw('LOWER(cashboxes.name) LIKE ?', [$like])->orWhereRaw('LOWER(cashboxes.code) LIKE ?', [$like]));
            })
            ->when($request->query('status') === 'active', fn ($w) => $w->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($w) => $w->where('is_active', false))
            ->orderBy('name')
            ->paginate($perPage)
            ->through(function ($c) {
                $c->balance = Dec::str(Dec::units($c->balance, 2), 2);

                return $c;
            });
    }

    public function branchOptions(): JsonResponse
    {
        return response()->json(Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']));
    }

    public function store(CashboxRequest $request): JsonResponse
    {
        $data = $request->validated();
        $cashbox = Cashbox::create($data);
        AuditLogger::log('cashboxes.create', $cashbox, null, $data);

        return response()->json($cashbox->load('branch:id,name'), 201);
    }

    public function update(CashboxRequest $request, Cashbox $cashbox): JsonResponse
    {
        $data = $request->validated();
        $deactivating = array_key_exists('is_active', $data) && ! filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        if ($deactivating && $cashbox->is_active) {
            if ($error = $this->cannotDeactivate($cashbox)) {
                return response()->json(['message' => $error], 422);
            }
        }

        $cashbox->fill($data);
        $old = Arr::only($cashbox->getOriginal(), array_keys($cashbox->getDirty()));
        $new = $cashbox->getDirty();
        $cashbox->save();
        AuditLogger::log('cashboxes.update', $cashbox, $old, $new);

        return response()->json($cashbox->fresh()->load('branch:id,name'));
    }

    /** Kassa o'chirilmaydi: faol emas holatga o'tadi (ochiq smena yoki qoldiq bo'lmasa). */
    public function destroy(Cashbox $cashbox): JsonResponse
    {
        if ($error = $this->cannotDeactivate($cashbox)) {
            return response()->json(['message' => $error], 422);
        }

        $cashbox->update(['is_active' => false]);
        AuditLogger::log('cashboxes.deactivate', $cashbox, ['is_active' => true], ['is_active' => false]);

        return response()->json(['message' => 'Kassa faol emas holatga o‘tkazildi.']);
    }

    private function cannotDeactivate(Cashbox $cashbox): ?string
    {
        if (CashboxShift::where('cashbox_id', $cashbox->id)->where('status', 'open')->exists()) {
            return 'Kassada ochiq smena bor — avval smenani yoping.';
        }
        if ($this->cash->balanceUnits($cashbox->id) !== 0) {
            return 'Kassada naqd pul qoldig‘i bor — avval uni chiqim qiling yoki bankka topshiring.';
        }

        return null;
    }
}
