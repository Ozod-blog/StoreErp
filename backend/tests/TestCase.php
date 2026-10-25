<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public const PASSWORD = 'Str0ngPass!2026';

    /** Berilgan rolga ega foydalanuvchi yaratadi (rollar/ruxsatlar seed qilinadi). */
    protected function makeUser(string $roleSlug = 'viewer', array $attributes = []): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create($attributes + ['password' => self::PASSWORD, 'status' => 'active']);
        $user->roles()->attach(Role::where('slug', $roleSlug)->value('id'));

        return $user;
    }

    /** Haqiqiy Sanctum tokeni bilan Authorization sarlavhasini qaytaradi. */
    protected function bearer(User $user): array
{
    $this->app['auth']->forgetGuards();   // <-- shu qator qo'shiladi

    return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
}
}
