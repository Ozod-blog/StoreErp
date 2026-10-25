<?php

namespace Tests\Feature;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_are_available_to_admin_and_have_expected_shape(): void
    {
        $admin = $this->makeUser('admin');
        $this->withHeaders($this->bearer($admin))
            ->getJson('/api/v1/reports/dashboard')
            ->assertOk()
            ->assertJsonStructure(['period' => ['date_from', 'date_to'], 'kpis' => ['sales_net', 'gross_profit', 'approved_expenses', 'cash_balance', 'bank_balance', 'low_stock_products']]);

        $this->withHeaders($this->bearer($admin))->getJson('/api/v1/reports/sales')->assertOk()->assertJsonStructure(['summary', 'daily', 'top_products']);
        $this->withHeaders($this->bearer($admin))->getJson('/api/v1/reports/inventory')->assertOk()->assertJsonStructure(['summary', 'data']);
        $this->withHeaders($this->bearer($admin))->getJson('/api/v1/reports/finance')->assertOk()->assertJsonStructure(['cash_movements', 'bank_movements', 'expenses_by_category']);
    }

    public function test_reports_require_reports_view_permission(): void
    {
        $cashier = $this->makeUser('cashier');
        $this->withHeaders($this->bearer($cashier))->getJson('/api/v1/reports/dashboard')->assertStatus(403);
    }

    public function test_branch_filter_options_only_return_active_branches(): void
    {
        $admin = $this->makeUser('admin');
        Branch::create(['name' => 'Faol filial', 'is_active' => true]);
        Branch::create(['name' => 'Nofaol filial', 'is_active' => false]);

        $response = $this->withHeaders($this->bearer($admin))->getJson('/api/v1/reports/branch-options')->assertOk();
        $this->assertSame(['Faol filial'], collect($response->json())->pluck('name')->all());
    }
}
