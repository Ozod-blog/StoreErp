<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\UnitRequest;
use App\Models\Product;
use App\Models\Unit;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        // ?all=1 — formalardagi tanlov ro'yxati uchun (faqat faol birliklar, sahifalashsiz)
        if ($request->boolean('all')) {
            return Unit::where('is_active', true)->orderBy('name')->get(['id', 'name', 'short_name', 'allow_fraction']);
        }

        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));

        return Unit::withCount('products')
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(short_name) LIKE ?', [$like]));
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function store(UnitRequest $request): JsonResponse
    {
        $data = $request->validated();
        $unit = Unit::create($data);
        AuditLogger::log('units.create', $unit, null, $data);

        return response()->json($unit, 201);
    }

    public function update(UnitRequest $request, Unit $unit): JsonResponse
    {
        $data = $request->validated();

        // Kasrni o'chirishdan oldin: mavjud kasrli minimal qoldiq yoki ombor qoldig'i bo'lsa ruxsat berilmaydi.
        $disablingFraction = array_key_exists('allow_fraction', $data) && ! filter_var($data['allow_fraction'], FILTER_VALIDATE_BOOLEAN);
        if ($disablingFraction && $unit->allow_fraction) {
            $hasFractions = Product::withTrashed()
                ->where('unit_id', $unit->id)
                ->where(function ($q) {
                    $q->whereRaw('min_stock <> CAST(min_stock AS INTEGER)')
                        ->orWhereHas('stocks', fn ($s) => $s->whereRaw('quantity <> CAST(quantity AS INTEGER)'));
                })->exists();
            if ($hasFractions) {
                return response()->json(['message' => 'Bu birlikdagi mahsulotlarda kasrli miqdor mavjud — kasrni o‘chirib bo‘lmaydi.'], 422);
            }
        }

        $unit->fill($data);
        $old = Arr::only($unit->getOriginal(), array_keys($unit->getDirty()));
        $new = $unit->getDirty();
        $unit->save();
        AuditLogger::log('units.update', $unit, $old, $new);

        return response()->json($unit->fresh());
    }

    public function destroy(Unit $unit): JsonResponse
    {
        if (Product::withTrashed()->where('unit_id', $unit->id)->exists()) {
            return response()->json(['message' => 'Bu birlik mahsulotlarda ishlatilgan — o‘chirib bo‘lmaydi. Uni faol emas holatga o‘tkazing.'], 422);
        }

        $old = $unit->toArray();
        $unit->delete();
        AuditLogger::log('units.delete', $unit, $old, null);

        return response()->json(['message' => 'O‘lchov birligi o‘chirildi.']);
    }
}
