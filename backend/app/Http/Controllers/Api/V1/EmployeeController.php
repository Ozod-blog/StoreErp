<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreEmployeeRequest;
use App\Http\Requests\Employees\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));

        return Employee::with('user:id,name,email')
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $q->where(function ($w) use ($like) {
                    $w->whereRaw('LOWER(employee_code) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(phone) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(position) LIKE ?', [$like])
                        ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', [$like]));
                });
            })
            ->when(in_array($request->query('status'), ['active', 'inactive', 'leave'], true), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** Hali xodim kartochkasi bog'lanmagan faol foydalanuvchilar. */
    public function availableUsers(): JsonResponse
    {
        return response()->json(
            User::where('status', 'active')->doesntHave('employee')->orderBy('name')->get(['id', 'name', 'email'])
        );
    }

    public function show(Employee $employee): Employee
    {
        return $employee->load('user:id,name,email');
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] = $data['status'] ?? 'active';
        $employee = Employee::create($data);
        AuditLogger::log('employees.create', $employee, null, $data);

        return response()->json($employee->load('user:id,name,email'), 201);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee->fill($request->validated());
        $old = Arr::only($employee->getOriginal(), array_keys($employee->getDirty()));
        $new = $employee->getDirty();
        $employee->save();
        AuditLogger::log('employees.update', $employee, $old, $new);

        return response()->json($employee->fresh()->load('user:id,name,email'));
    }

    /** Xodim o'chirilmaydi, faol emas holatga o'tadi. */
    public function destroy(Employee $employee): JsonResponse
    {
        $old = ['status' => $employee->status];
        $employee->update(['status' => 'inactive']);
        AuditLogger::log('employees.deactivate', $employee, $old, ['status' => 'inactive']);

        return response()->json(['message' => 'Xodim faol emas holatga o‘tkazildi.']);
    }
}
