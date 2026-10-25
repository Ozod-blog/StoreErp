<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Support\Barcode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ProductController extends Controller
{
    private const SORTABLE = ['name', 'sku', 'sale_price', 'purchase_price', 'created_at'];

    public function index(Request $request)
    {
        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'name';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        return Product::query()
            ->with(['category:id,name', 'unit:id,name,short_name,allow_fraction'])
            ->withSum('stocks as total_stock', 'quantity')
            ->when($request->query('archived') === '1', fn ($q) => $q->onlyTrashed())
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(sku) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(barcode) LIKE ?', [$like]));
            })
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', (int) $request->query('category_id')))
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            // Kam qolgan: minimal qoldiq belgilangan va barcha omborlardagi jami qoldiq undan kam.
            ->when($request->boolean('low_stock'), fn ($q) => $q
                ->where('min_stock', '>', 0)
                ->whereRaw('COALESCE((SELECT SUM(ws.quantity) FROM warehouse_stocks ws WHERE ws.product_id = products.id), 0) < products.min_stock'))
            ->orderBy($sort, $dir)
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function show(Product $product): Product
    {
        return $product->load(['category:id,name', 'unit:id,name,short_name,allow_fraction'])
            ->loadSum('stocks as total_stock', 'quantity');
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['min_stock'] = $data['min_stock'] ?? 0;
        $product = Product::create($data);
        AuditLogger::log('products.create', $product, null, $data);

        return response()->json($product->load(['category:id,name', 'unit:id,name,short_name,allow_fraction']), 201);
    }

    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();
        $data['min_stock'] = $data['min_stock'] ?? 0;

        // Qoldiqli mahsulotning birligini almashtirish tarixiy miqdorlarni buzadi.
        if ((int) $data['unit_id'] !== (int) $product->unit_id && $product->stocks()->exists()) {
            return response()->json(['message' => 'Omborda qoldig‘i bor mahsulotning o‘lchov birligini o‘zgartirib bo‘lmaydi.'], 422);
        }

        $product->fill($data);
        $old = Arr::only($product->getOriginal(), array_keys($product->getDirty()));
        $new = $product->getDirty();
        $product->save();
        AuditLogger::log('products.update', $product, $old, $new);

        return response()->json($product->fresh()->load(['category:id,name', 'unit:id,name,short_name,allow_fraction']));
    }

    /**
     * Shtrix-kodi yo'q mahsulotga ichki EAN-13 kod beradi. Mavjud kodni hech qachon almashtirmaydi:
     * allaqachon chop etilgan yorliqlar va skanerlash odatlari buzilmasligi uchun.
     */
    public function generateBarcode(Product $product): JsonResponse
    {
        if ($product->barcode !== null && $product->barcode !== '') {
            return response()->json(['message' => 'Mahsulotda shtrix-kod allaqachon bor.'], 422);
        }

        $code = Barcode::internalFromId($product->id);
        for ($i = 0; $i < 10 && Product::withTrashed()->where('barcode', $code)->exists(); $i++) {
            $code = Barcode::internalRandom();
        }
        if (Product::withTrashed()->where('barcode', $code)->exists()) {
            return response()->json(['message' => 'Bo‘sh shtrix-kod topilmadi, qayta urinib ko‘ring.'], 422);
        }

        $product->barcode = $code;
        $product->save();
        AuditLogger::log('products.update', $product, ['barcode' => null], ['barcode' => $code]);

        return response()->json($product->fresh()->load(['category:id,name', 'unit:id,name,short_name,allow_fraction']));
    }

    /** Mahsulot o'chirilmaydi — arxivlanadi (soft delete); tarixiy savdo va hujjatlar buzilmaydi. */
    public function destroy(Product $product): JsonResponse
    {
        $product->delete();
        AuditLogger::log('products.archive', $product, null, ['archived' => true]);

        return response()->json(['message' => 'Mahsulot arxivlandi.']);
    }

    public function restore(int $id): JsonResponse
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $product->restore();
        AuditLogger::log('products.restore', $product, ['archived' => true], ['archived' => false]);

        return response()->json($product->load(['category:id,name', 'unit:id,name,short_name,allow_fraction']));
    }
}
