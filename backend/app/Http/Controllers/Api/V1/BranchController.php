<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\BranchRequest;
use App\Models\Branch;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        if ($request->boolean('all')) {
            return Branch::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
        }

        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));

        return Branch::query()
            ->withCount(['warehouses', 'warehouses as active_warehouses_count' => fn ($q) => $q->where('is_active', true)])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $q->where(fn ($b) => $b->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(address) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$like]));
            })
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')->paginate($perPage);
    }

    public function store(BranchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $branch = Branch::create($data);
        AuditLogger::log('branches.create', $branch, null, $data);

        return response()->json($branch, 201);
    }

    public function update(BranchRequest $request, Branch $branch): JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('is_active', $data) && ! filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) && $branch->is_active && $this->hasActiveResources($branch)) {
            return response()->json(['message' => 'Filialga faol ombor, kassa yoki bank hisobi biriktirilgan. Avval ularni boshqa filialga o‘tkazing yoki faolsizlantiring.'], 422);
        }
        $branch->fill($data);
        $dirty = $branch->getDirty();
        $old = Arr::only($branch->getOriginal(), array_keys($dirty));
        $new = $dirty;
        $branch->save();
        AuditLogger::log('branches.update', $branch, $old, $new);

        return response()->json($branch->fresh());
    }

    /** Branches are archived, never hard-deleted. Active linked operations must be reassigned first. */
    public function destroy(Branch $branch): JsonResponse
    {
        if (! $branch->is_active) {
            return response()->json(['message' => 'Filial allaqachon faol emas.']);
        }

        if ($this->hasActiveResources($branch)) {
            return response()->json([
                'message' => 'Filialga faol ombor, kassa yoki bank hisobi biriktirilgan. Avval ularni boshqa filialga o‘tkazing yoki faolsizlantiring.',
            ], 422);
        }

        $branch->update(['is_active' => false]);
        AuditLogger::log('branches.deactivate', $branch, ['is_active' => true], ['is_active' => false]);

        return response()->json(['message' => 'Filial faol emas holatga o‘tkazildi.']);
    }
    private function hasActiveResources(Branch $branch): bool
    {
        $activeWarehouse = $branch->warehouses()->where('is_active', true)->exists();
        $activeCashbox = Schema::hasTable('cashboxes') && DB::table('cashboxes')->where('branch_id', $branch->id)->where('is_active', true)->exists();
        $activeBankAccount = Schema::hasTable('bank_accounts') && DB::table('bank_accounts')->where('branch_id', $branch->id)->where('is_active', true)->exists();
        return $activeWarehouse || $activeCashbox || $activeBankAccount;
    }
}
