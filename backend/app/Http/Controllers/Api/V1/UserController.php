<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));

        return User::with('roles:id,name,slug')
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]));
            })
            ->when(in_array($request->query('status'), ['active', 'inactive'], true), fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function show(User $user): User
    {
        return $user->load('roles:id,name,slug');
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $roleIds = $data['role_ids'] ?? [];
        unset($data['role_ids']);
        $data['status'] = $data['status'] ?? 'active';

        $user = DB::transaction(function () use ($data, $roleIds) {
            $user = User::create($data); // parol modelning "hashed" cast orqali hash qilinadi
            $user->roles()->sync($roleIds);

            return $user;
        });

        AuditLogger::log('users.create', $user, null, Arr::except($data, ['password']) + ['role_ids' => $roleIds]);

        return response()->json($user->load('roles:id,name,slug'), 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $roleIds = array_key_exists('role_ids', $data) ? array_map('intval', $data['role_ids']) : null;
        unset($data['role_ids']);
        if (array_key_exists('password', $data) && blank($data['password'])) {
            unset($data['password']); // bo'sh parol = o'zgartirmaslik
        }

        if ($this->wouldRemoveLastAdmin($user, $data['status'] ?? null, $roleIds)) {
            return response()->json(['message' => 'Oxirgi faol administratorni o‘chirib yoki rolini olib bo‘lmaydi.'], 422);
        }

        $user->fill($data);
        $oldValues = Arr::only($user->getOriginal(), array_keys($user->getDirty()));
        $newValues = $user->getDirty();

        DB::transaction(function () use ($user, $roleIds, $data) {
            $user->save();
            if ($roleIds !== null) {
                $user->roles()->sync($roleIds);
            }
            // Parol o'zgargan yoki hisob o'chirilgan bo'lsa, eski tokenlar bekor qilinadi.
            if (isset($data['password']) || ($data['status'] ?? 'active') === 'inactive') {
                $user->tokens()->delete();
            }
        });

        if ($roleIds !== null) {
            $newValues['role_ids'] = $roleIds;
        }
        AuditLogger::log('users.update', $user, $oldValues, $newValues);

        return response()->json($user->fresh()->load('roles:id,name,slug'));
    }

    /** Foydalanuvchi o'chirilmaydi (tarixiy hujjatlar unga bog'lanadi) — faol emas holatga o'tkaziladi. */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'O‘zingizning hisobingizni o‘chira olmaysiz.'], 422);
        }
        if ($this->wouldRemoveLastAdmin($user, 'inactive', null)) {
            return response()->json(['message' => 'Oxirgi faol administratorni o‘chirib bo‘lmaydi.'], 422);
        }

        DB::transaction(function () use ($user) {
            $user->update(['status' => 'inactive']);
            $user->tokens()->delete();
        });
        AuditLogger::log('users.deactivate', $user, ['status' => 'active'], ['status' => 'inactive']);

        return response()->json(['message' => 'Foydalanuvchi faol emas holatga o‘tkazildi.']);
    }

    /** Berilgan o'zgarishdan keyin tizimda faol administrator qolmasa true. */
    private function wouldRemoveLastAdmin(User $user, ?string $newStatus, ?array $newRoleIds): bool
    {
        if ($user->status !== 'active' || ! $user->hasRole('admin')) {
            return false;
        }

        $adminRoleId = Role::where('slug', 'admin')->value('id');
        $staysActive = ($newStatus ?? $user->status) === 'active';
        $staysAdmin = $newRoleIds === null || in_array($adminRoleId, $newRoleIds, true);
        if ($staysActive && $staysAdmin) {
            return false;
        }

        return User::where('status', 'active')
            ->where('id', '!=', $user->id)
            ->whereHas('roles', fn ($q) => $q->where('slug', 'admin'))
            ->doesntExist();
    }
}
