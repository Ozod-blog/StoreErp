<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\StockDocumentRequest;
use App\Models\StockDocument;
use App\Services\AuditLogger;
use App\Services\StockService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ombor hujjatlari uchun umumiy CRUD + tasdiqlash/bekor qilish.
 * Ruxsatlar route middleware'da (can:...), hujjat turlari esa $types bilan cheklanadi.
 */
abstract class AbstractDocumentController extends Controller
{
    /** @var list<string> */
    protected array $types = [];

    public function __construct(protected StockService $stock)
    {
    }

    public function index(Request $request)
    {
        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));
        $type = $request->query('type');

        return StockDocument::with(['warehouse:id,name', 'toWarehouse:id,name', 'supplier:id,name'])
            ->whereIn('type', $this->types)
            ->when(in_array($type, $this->types, true), fn ($q) => $q->where('type', $type))
            ->when(in_array($request->query('status'), ['draft', 'approved', 'cancelled'], true), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where(fn ($w) => $w
                ->where('warehouse_id', (int) $request->query('warehouse_id'))
                ->orWhere('to_warehouse_id', (int) $request->query('warehouse_id'))))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('doc_date', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('doc_date', '<=', $request->query('date_to')))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(number) LIKE ?', [$like])->orWhereRaw('LOWER(note) LIKE ?', [$like]));
            })
            ->orderByDesc('doc_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->find($id)->load($this->relations()));
    }

    public function store(StockDocumentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $token = $data['client_token'] ?? null;

        // Qayta yuborilgan so'rov ikkinchi hujjat yaratmaydi.
        if ($token && ($existing = StockDocument::where('client_token', $token)->whereIn('type', $this->types)->first())) {
            return response()->json($existing->load($this->relations()));
        }

        try {
            $doc = $this->stock->saveDraft($data, $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $token ? StockDocument::where('client_token', $token)->first() : null;
            if (! $existing) {
                throw $e;
            }

            return response()->json($existing->load($this->relations()));
        }

        return response()->json($doc, 201);
    }

    public function update(StockDocumentRequest $request, int $id): JsonResponse
    {
        $doc = $this->find($id);
        if ($doc->status !== 'draft') {
            return response()->json(['message' => 'Faqat qoralama hujjatni tahrirlash mumkin. Tasdiqlangan hujjat o‘zgarmaydi — uni bekor qiling.'], 422);
        }
        $data = $request->validated();
        if ($data['type'] !== $doc->type) {
            return response()->json(['message' => 'Hujjat turini o‘zgartirib bo‘lmaydi.'], 422);
        }

        try {
            return response()->json($this->stock->saveDraft($data, $request->user(), $doc));
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $doc = $this->find($id);

        try {
            return response()->json($this->stock->approve($doc->id, $request->user()));
        } catch (BusinessRuleException $e) {
            AuditLogger::log('stock_documents.approve', $doc, null, ['number' => $doc->number, 'error' => $e->getMessage()], 'failed');

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $doc = $this->find($id);
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            return response()->json($this->stock->cancel($doc->id, $request->user(), $request->input('reason')));
        } catch (BusinessRuleException $e) {
            AuditLogger::log('stock_documents.cancel', $doc, null, ['number' => $doc->number, 'error' => $e->getMessage()], 'failed');

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function find(int $id): StockDocument
    {
        return StockDocument::whereIn('type', $this->types)->findOrFail($id);
    }

    /** @return list<string> */
    protected function relations(): array
    {
        return ['items.product.unit', 'warehouse', 'toWarehouse', 'supplier', 'parent:id,number', 'creator:id,name', 'approver:id,name'];
    }
}
