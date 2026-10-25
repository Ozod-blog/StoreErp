<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CheckoutRequest;
use App\Http\Requests\Sales\ReturnRequest;
use App\Models\Sale;
use App\Services\AuditLogger;
use App\Services\SaleService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    private const REL = [
        'items.product.unit', 'payments', 'customer:id,name,phone', 'creator:id,name', 'canceller:id,name',
        'warehouse:id,name', 'cashbox:id,code,name', 'parent:id,number', 'returns:id,parent_id,number,total,status,sold_at',
    ];

    public function __construct(private SaleService $sales)
    {
    }

    /** Hisobot/menejer ruxsati bo'lmaganlar (kassir) faqat o'zi qilgan sotuvlarni ko'radi. */
    private function seesAll(Request $request): bool
    {
        $u = $request->user();

        return $u->can('sales.update') || $u->can('sales.cancel') || $u->can('reports.view');
    }

    private function authorizeView(Request $request, Sale $sale): void
    {
        if (! $this->seesAll($request) && $sale->created_by !== $request->user()->id) {
            abort(403, 'Bu sotuvni ko‘rishga ruxsatingiz yo‘q.');
        }
    }

    public function index(Request $request)
    {
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));
        $tz = 'Asia/Tashkent';

        return Sale::with(['customer:id,name', 'creator:id,name', 'cashbox:id,code,name', 'parent:id,number'])
            ->when(! $this->seesAll($request), fn ($q) => $q->where('created_by', $request->user()->id))
            ->when(in_array($request->query('type'), ['sale', 'return'], true), fn ($q) => $q->where('type', $request->query('type')))
            ->when(in_array($request->query('status'), ['approved', 'cancelled'], true), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('cashbox_id'), fn ($q) => $q->where('cashbox_id', (int) $request->query('cashbox_id')))
            ->when($request->filled('shift_id'), fn ($q) => $q->where('shift_id', (int) $request->query('shift_id')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', (int) $request->query('customer_id')))
            ->when($request->filled('user_id') && $this->seesAll($request), fn ($q) => $q->where('created_by', (int) $request->query('user_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->where('sold_at', '>=', Carbon::parse($request->query('date_from'), $tz)->startOfDay()->utc()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('sold_at', '<=', Carbon::parse($request->query('date_to'), $tz)->endOfDay()->utc()))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(number) LIKE ?', [$like])
                    ->orWhereHas('customer', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$like])));
            })
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $sale = Sale::with(self::REL)->findOrFail($id);
        $this->authorizeView($request, $sale);

        // Chek rekvizitlari (do'kon nomi, manzil) — tarixdan chek chiqarish uchun (sales.create ruxsatisiz ham).
        return response()->json($sale->toArray() + ['receipt_info' => config('erp.receipt')]);
    }

    public function quote(CheckoutRequest $request): JsonResponse
    {
        try { return response()->json($this->sales->quote($request->validated(), $request->user())); }
        catch (BusinessRuleException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        catch (AuthorizationException $e) { return response()->json(['message' => $e->getMessage()], 403); }
    }

    public function store(CheckoutRequest $request): JsonResponse
    {
        $data = $request->validated();
        $token = $data['client_token'] ?? null;

        // Qayta yuborilgan so'rov ikkinchi sotuv yaratmaydi (omborni ikki marta kamaytirmaydi, pulni ikki marta yozmaydi).
        if ($token && ($existing = $this->existing($token, $request->user()->id))) {
            return response()->json($existing);
        }

        try {
            $sale = $this->sales->checkout($data, $request->user());
        } catch (BusinessRuleException $e) {
            AuditLogger::log('sales.create', null, null, ['error' => $e->getMessage()], 'failed');

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (AuthorizationException $e) {
            AuditLogger::log('sales.create', null, null, ['error' => $e->getMessage()], 'failed');

            return response()->json(['message' => $e->getMessage() ?: 'Bu amalni bajarishga ruxsatingiz yo‘q.'], 403);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $token ? $this->existing($token, $request->user()->id) : null;
            if (! $existing) {
                throw $e;
            }

            return response()->json($existing);
        }

        return response()->json($sale, 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $sale = Sale::findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        try {
            return response()->json($this->sales->cancel($sale->id, $data['reason'], $request->user()));
        } catch (BusinessRuleException $e) {
            AuditLogger::log('sales.cancel', $sale, null, ['number' => $sale->number, 'error' => $e->getMessage()], 'failed');

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function returnable(Request $request, int $id): JsonResponse
    {
        $sale = Sale::where('type', 'sale')->findOrFail($id);
        $this->authorizeView($request, $sale);

        return response()->json([
            'sale' => $sale->only(['id', 'number', 'status', 'customer_id', 'total', 'paid_amount', 'debt_amount']),
            'items' => $this->sales->returnable($sale),
        ]);
    }

    public function storeReturn(ReturnRequest $request, int $id): JsonResponse
    {
        $parent = Sale::where('type', 'sale')->findOrFail($id);
        $data = $request->validated();
        $token = $data['client_token'] ?? null;

        if ($token && ($existing = $this->existing($token, $request->user()->id))) {
            return response()->json($existing);
        }

        try {
            $return = $this->sales->createReturn($parent->id, $data, $request->user());
        } catch (BusinessRuleException $e) {
            AuditLogger::log('sales.return', $parent, null, ['number' => $parent->number, 'error' => $e->getMessage()], 'failed');

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $token ? $this->existing($token, $request->user()->id) : null;
            if (! $existing) {
                throw $e;
            }

            return response()->json($existing);
        }

        return response()->json($return, 201);
    }

    /** Shu token bilan shu foydalanuvchi yaratgan hujjat (boshqa foydalanuvchining hujjati qaytarilmaydi). */
    private function existing(string $token, int $userId): ?Sale
    {
        return Sale::with(self::REL)->where('client_token', $token)->where('created_by', $userId)->first();
    }
}
