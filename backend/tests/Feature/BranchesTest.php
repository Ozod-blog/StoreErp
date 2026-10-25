<?php

namespace Tests\Feature;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_and_update_branch(): void
    {
        $admin = $this->makeUser('admin');
        $headers = $this->bearer($admin);

        $created = $this->withHeaders($headers)->postJson('/api/v1/branches', [
            'code' => 'BR-TAS', 'name' => 'Toshkent filiali', 'address' => 'Toshkent',
            'phone' => '+998 90 000 00 00', 'manager_name' => 'Filial rahbari', 'is_active' => true,
        ])->assertCreated()->assertJsonPath('code', 'BR-TAS')->assertJsonPath('name', 'Toshkent filiali');

        $id = $created->json('id');
        $this->withHeaders($headers)->getJson('/api/v1/branches?search=Toshkent')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->withHeaders($headers)->patchJson("/api/v1/branches/{$id}", ['name' => 'Toshkent markaziy filiali', 'code' => 'BR-TAS'])->assertOk()->assertJsonPath('name', 'Toshkent markaziy filiali');
    }

    public function test_branch_endpoints_require_permissions(): void
    {
        $viewer = $this->makeUser('viewer');
        $this->withHeaders($this->bearer($viewer))->postJson('/api/v1/branches', ['code' => 'BR-01', 'name' => 'Sinov'])->assertForbidden();
    }

    public function test_branch_is_archived_not_hard_deleted(): void
    {
        $admin = $this->makeUser('admin');
        $branch = Branch::create(['code' => 'BR-ARCH', 'name' => 'Arxiv filial', 'is_active' => true]);
        $this->withHeaders($this->bearer($admin))->deleteJson("/api/v1/branches/{$branch->id}")->assertOk();
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'is_active' => false]);
    }
}
