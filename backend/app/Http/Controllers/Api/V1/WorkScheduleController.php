<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\WorkScheduleRequest;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Ish jadvali (kim qachon ishlaydi). Kassa smenasidan alohida tushuncha. */
class WorkScheduleController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        return $this->range($request->query('date_from'), $request->query('date_to'), $request->filled('employee_id') ? (int) $request->query('employee_id') : null);
    }

    /** Joriy xodimning o'z jadvali (employees.view shart emas). */
    public function mine(Request $request)
    {
        $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $employee = Employee::where('user_id', $request->user()->id)->first();
        if (! $employee) {
            return response()->json([]);
        }

        return $this->range($request->query('date_from'), $request->query('date_to'), $employee->id);
    }

    private function range(string $from, string $to, ?int $employeeId)
    {
        // Katta oraliqni so'rab serverni yuklamaslik uchun 93 kun bilan cheklanadi.
        if (strtotime($to) - strtotime($from) > 92 * 86400) {
            abort(422, 'Oraliq 93 kundan oshmasligi kerak.');
        }

        return WorkSchedule::query()
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->whereBetween('work_date', [$from, $to])
            ->orderBy('work_date')
            ->get(['id', 'employee_id', 'work_date', 'type', 'start_time', 'end_time', 'note']);
    }

    /** Bir kun yoki bir nechta kunni belgilash (mavjud bo'lsa yangilanadi). */
    public function store(WorkScheduleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $dates = $data['dates'] ?? [$data['work_date']];
        $isWork = $data['type'] === 'work';

        DB::transaction(function () use ($data, $dates, $isWork, $request) {
            foreach ($dates as $date) {
                $row = WorkSchedule::firstOrNew(['employee_id' => $data['employee_id'], 'work_date' => $date]);
                $old = $row->exists ? $row->only(['type', 'start_time', 'end_time', 'note']) : null;
                $row->fill([
                    'type' => $data['type'],
                    'start_time' => $isWork ? $data['start_time'] : null,
                    'end_time' => $isWork ? $data['end_time'] : null,
                    'note' => $data['note'] ?? null,
                    'created_by' => $row->exists ? $row->created_by : $request->user()->id,
                ]);
                $row->save();
                AuditLogger::log('work_schedules.'.($old ? 'update' : 'create'), $row, $old, $row->only(['employee_id', 'work_date', 'type', 'start_time', 'end_time', 'note']));
            }
        });

        return response()->json(['message' => 'Jadval saqlandi.', 'count' => count($dates)], 201);
    }

    /** Jadval — rejalashtirish ma'lumoti (moliyaviy tarix emas), shuning uchun kunni tozalash mumkin; audit yoziladi. */
    public function destroy(WorkSchedule $workSchedule): JsonResponse
    {
        $old = $workSchedule->only(['employee_id', 'work_date', 'type', 'start_time', 'end_time', 'note']);
        $workSchedule->delete();
        AuditLogger::log('work_schedules.delete', $workSchedule, $old, null);

        return response()->json(['message' => 'Kun jadvaldan olib tashlandi.']);
    }
}
