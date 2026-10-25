<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    /** Rollar ro'yxati foydalanuvchi formasi (users.view) va Rollar sahifasi (roles.view) uchun kerak. */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('roles.view') || $user->hasPermission('users.view'), 403);

        return Role::with('permissions:id,name,slug')->withCount('users')->orderBy('name')->get();
    }

    public function permissions(): JsonResponse
    {
        $groups = Permission::orderBy('slug')->get(['id', 'name', 'slug'])
            ->groupBy(fn ($p) => explode('.', $p->slug)[0])
            ->map(fn ($items, $module) => [
                'module' => $module,
                'label' => PermissionCatalog::moduleLabel($module),
                'permissions' => $items->values(),
            ])
            ->values();

        return response()->json($groups);
    }

    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        if ($role->slug === 'admin') {
            return response()->json(['message' => 'Administrator roli ruxsatlarini o‘zgartirib bo‘lmaydi.'], 422);
        }

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,slug'],
        ]);

        $old = $role->permissions()->pluck('slug')->sort()->values()->all();
        $ids = Permission::whereIn('slug', $data['permissions'])->pluck('id')->all();

        DB::transaction(fn () => $role->permissions()->sync($ids));

        $new = $role->permissions()->pluck('slug')->sort()->values()->all();
        AuditLogger::log('roles.update_permissions', $role, ['permissions' => $old], ['permissions' => $new]);

        return response()->json($role->load('permissions:id,name,slug'));
    }
}
