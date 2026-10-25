<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\CashTransactionRequest;
use App\Models\CashTransaction;
use App\Services\CashService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashTransactionController extends Controller
{
    public function __construct(private CashService $cash)
    {
    }

    public function index(Request $request)
    {
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $user = $request->user();
        // Smenalarni ko'rib chiqish yoki kassani boshqarish ruxsati bo'lmaganlar faqat o'z smenalari yozuvlarini ko'radi.
        $all = $user->can('shifts.approve') || $user->can('cashboxes.update');
        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $tz = 'Asia/Tashkent';

        return CashTransaction::with(['cashbox:id,code,name', 'user:id,name'])
            ->when(! $all, fn ($q) => $q->whereHas('shift', fn ($s) => $s->where('user_id', $user->id)))
            ->when($request->filled('cashbox_id'), fn ($q) => $q->where('cashbox_id', (int) $request->query('cashbox_id')))
            ->when($request->filled('shift_id'), fn ($q) => $q->where('shift_id', (int) $request->query('shift_id')))
            ->when(in_array($request->query('direction'), ['in', 'out'], true), fn ($q) => $q->where('direction', $request->query('direction')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', (string) $request->query('category')))
            ->when($request->filled('date_from'), fn ($q) => $q->where('occurred_at', '>=', Carbon::parse($request->query('date_from'), $tz)->startOfDay()->utc()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('occurred_at', '<=', Carbon::parse($request->query('date_to'), $tz)->endOfDay()->utc()))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function store(CashTransactionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $token = $data['client_token'] ?? null;

        // Qayta yuborilgan so'rov ikkinchi yozuv yaratmaydi (idempotentlik).
        if ($token && ($existing = CashTransaction::where('client_token', $token)->first())) {
            return response()->json($existing);
        }

        try {
            $tx = $this->cash->manual($data['cashbox_id'], $data['direction'], $data['amount'], $data['note'], $request->user(), $token);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $token ? CashTransaction::where('client_token', $token)->first() : null;
            if (! $existing) {
                throw $e;
            }

            return response()->json($existing);
        }

        return response()->json($tx->load(['cashbox:id,code,name', 'user:id,name']), 201);
    }
}
