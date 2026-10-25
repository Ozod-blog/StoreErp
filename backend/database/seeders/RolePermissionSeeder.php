<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::all() as $p) {
            Permission::updateOrCreate(['slug' => $p['slug']], ['name' => $p['name']]);
        }

        // Eski (2-bosqich) "seller" roli kassirga aylantiriladi.
        if (Role::where('slug', 'seller')->exists() && ! Role::where('slug', 'cashier')->exists()) {
            Role::where('slug', 'seller')->update(['slug' => 'cashier']);
        }

        $allIds = Permission::pluck('id', 'slug');

        foreach (PermissionCatalog::roleDefaults() as $slug => $slugs) {
            [$name, $description] = PermissionCatalog::roleLabels()[$slug];
            $role = Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'description' => $description]);

            if (in_array($slug, ['admin', 'owner'], true)) {
                // admin va owner doim barcha ruxsatlarga ega (yangi modul qo'shilganda ham).
                $role->permissions()->sync($allIds->values()->all());
            } elseif ($role->wasRecentlyCreated || $role->permissions()->count() === 0) {
                // Boshqa rollarning qo'lda sozlangan ruxsatlari qayta seed qilinganda buzilmaydi.
                $role->permissions()->sync($allIds->only($slugs)->values()->all());
            }
        }
    }
}
