<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\CloseShiftRequest;
use App\Http\Requests\Cash\OpenShiftRequest;
use App\Models\CashboxShift;
use App\Models\User;
use App\Services\CashService;
use App\Services\SaleService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    private const REL = ['cashbox:id,code,name', 'user:id,name', 'closer:id,name', 'reviewer:id,name', 'handedOverTo:id,name'];

    public function __construct(private CashService $cash, private SaleService $sales)
    {
    }

    private function canSeeAll(Request $request): bool
    {
        return $request->user()->can('shifts.approve');
    }

    private function authorizeView(Request $request, CashboxShift $shift): void
    {
        if (! $this->canSeeAll($request) && $shift->user_id !== $request->user()->id) {
            abort(403, 'Bu smenani ko‘rishga ruxsatingiz yo‘q.');
        }
    }

    private function detail(CashboxShift $shift): array
    {
        $shift->load(self::REL);
        $data = $shift->toArray();
        $data['summary'] = $this->cash->summary($shift);
        $data['sales_summary'] = $this->sales->shiftSummary($shift);

        return $data;
    }

    public function index(Request $request)
    {
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $tz = 'Asia/Tashkent';

        return CashboxShift::with(self::REL)
            ->when(! $this->canSeeAll($request), fn ($q) => $q->where('user_id', $request->user()->id))
            ->when(in_array($request->query('status'), ['open', 'closed'], true), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->query('has_difference') === '1', fn ($q) => $q->where('difference', '<>', 0))
            ->when($request->filled('cashbox_id'), fn ($q) => $q->where('cashbox_id', (int) $request->query('cashbox_id')))
            ->when($request->filled('user_id') && $this->canSeeAll($request), fn ($q) => $q->where('user_id', (int) $request->query('user_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->where('opened_at', '>=', Carbon::parse($request->query('date_from'), $tz)->startOfDay()->utc()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('opened_at', '<=', Carbon::parse($request->query('date_to'), $tz)->endOfDay()->utc()))
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** Joriy foydalanuvchining ochiq smenasi (bo'lmasa shift = null). */
    public function current(Request $request): JsonResponse
    {
        $shift = CashboxShift::where('user_id', $request->user()->id)->where('status', 'open')->first();

        return response()->json(['shift' => $shift ? $this->detail($shift) : null]);
    }

    public function show(Request $request, CashboxShift $shift): JsonResponse
    {
        $this->authorizeView($request, $shift);

        return response()->json($this->detail($shift));
    }

    /** Smena ochish uchun tanlanadigan xodimlar (smena ochish ruxsati bor, ochiq smenasi yo'q). */
    public function cashierOptions(Request $request): JsonResponse
    {
        $me = $request->user();
        $busy = CashboxShift::where('status', 'open')->pluck('user_id')->all();

        $users = User::where('status', 'active')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $u) => ! in_array($u->id, $busy, true) && $u->hasPermission('shifts.create'))
            ->filter(fn (User $u) => $u->id === $me->id || $me->can('shifts.approve'))
            ->values();

        return response()->json($users);
    }

    /** Smenani topshirish uchun qabul qiluvchilar (o'zidan boshqa, ochiq smenasiz). */
    public function handoverCandidates(Request $request): JsonResponse
    {
        $busy = CashboxShift::where('status', 'open')->pluck('user_id')->all();

        $users = User::where('status', 'active')->where('id', '<>', $request->user()->id)->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $u) => ! in_array($u->id, $busy, true) && $u->hasPermission('shifts.create'))
            ->values();

        return response()->json($users);
    }

    public function open(OpenShiftRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();
        $cashierId = (int) ($data['user_id'] ?? $me->id);

        if ($cashierId !== $me->id && ! $me->can('shifts.approve')) {
            abort(403, 'Boshqa xodim uchun smena ochishga ruxsatingiz yo‘q.');
        }

        try {
            $shift = $this->cash->openShift($data['cashbox_id'], $cashierId, $data['opening_cash'], $data['note'] ?? null, $me);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (UniqueConstraintViolationException $e) {
            return response()->json(['message' => 'Bu kassada yoki xodimda allaqachon faol smena bor.'], 422);
        }

        $cashier = User::find($cashierId);

        return response()->json([
            'shift' => $this->detail($shift),
            'warning' => $cashier ? $this->cash->scheduleWarning($cashier) : null,
        ], 201);
    }

    public function close(CloseShiftRequest $request, CashboxShift $shift): JsonResponse
    {
        $this->authorizeView($request, $shift);
        $data = $request->validated();

        try {
            $closed = $this->cash->closeShift($shift->id, $data['actual_cash'], $data['reason'] ?? null, $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->detail($closed));
    }

    public function handover(CloseShiftRequest $request, CashboxShift $shift): JsonResponse
    {
        $this->authorizeView($request, $shift);
        $data = $request->validated();

        try {
            [$closed, $new] = $this->cash->handover($shift->id, $data['actual_cash'], $data['reason'] ?? null, (int) $data['to_user_id'], $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (UniqueConstraintViolationException $e) {
            return response()->json(['message' => 'Qabul qiluvchida yoki kassada allaqachon faol smena bor.'], 422);
        }

        return response()->json(['closed' => $this->detail($closed), 'opened' => $this->detail($new)]);
    }

    public function review(Request $request, CashboxShift $shift): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        try {
            $reviewed = $this->cash->review($shift->id, $data['note'] ?? null, $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->detail($reviewed));
    }
}
