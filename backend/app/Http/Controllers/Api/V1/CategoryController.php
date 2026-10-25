<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CategoryRequest;
use App\Models\Category;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        if ($request->boolean('all')) {
            return Category::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        }

        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));

        return Category::withCount('products')
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $q->whereRaw('LOWER(name) LIKE ?', [$like]);
            })
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $category = Category::create($data);
        AuditLogger::log('categories.create', $category, null, $data);

        return response()->json($category, 201);
    }

    public function update(CategoryRequest $request, Category $category): JsonResponse
    {
        $category->fill($request->validated());
        $old = Arr::only($category->getOriginal(), array_keys($category->getDirty()));
        $new = $category->getDirty();
        $category->save();
        AuditLogger::log('categories.update', $category, $old, $new);

        return response()->json($category->fresh());
    }

    /** Kategoriya o'chirilmaydi — arxivlanadi (mahsulotlar bog'liqligi saqlanadi). Qayta faollashtirish: update(is_active=true). */
    public function destroy(Category $category): JsonResponse
    {
        $category->update(['is_active' => false]);
        AuditLogger::log('categories.archive', $category, ['is_active' => true], ['is_active' => false]);

        return response()->json(['message' => 'Kategoriya arxivlandi.']);
    }
}
