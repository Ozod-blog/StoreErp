<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cashbox;
use App\Models\CashboxShift;
use App\Models\CashTransaction;
use App\Models\Employee;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashShiftsTest extends TestCase
{
    use RefreshDatabase;

    private function api($user)
    {
        return $this->withHeaders($this->bearer($user));
    }

    private function box(string $code = 'K-1'): Cashbox
    {
        return Cashbox::create(['code' => $code, 'name' => "Kassa $code"]);
    }

    private function balance(Cashbox $c): float
    {
        return (float) app(\App\Services\CashService::class)->balanceUnits($c->id) / 100;
    }

    private function openShift($user, Cashbox $c, float $opening = 100000, array $extra = [])
    {
        return $this->api($user)->postJson('/api/v1/shifts/open', $extra + ['cashbox_id' => $c->id, 'opening_cash' => $opening]);
    }

    public function test_cashier_opens_own_shift_and_first_opening_creates_balance(): void
    {
        $cashier = $this->makeUser('cashier');
        $box = $this->box();

        $id = $this->openShift($cashier, $box, 100000)->assertStatus(201)->json('shift.id');

        $this->assertSame('open', CashboxShift::find($id)->status);
        $this->assertEquals(100000, $this->balance($box));
        $this->assertTrue(AuditLog::where('action', 'shifts.open')->exists());
    }

    public function test_second_open_shift_on_same_cashbox_or_user_is_rejected(): void
    {
        $a = $this->makeUser('cashier', ['email' => 'a@test.uz']);
        $b = $this->makeUser('cashier', ['email' => 'b@test.uz']);
        $box = $this->box();
        $box2 = $this->box('K-2');

        $this->openShift($a, $box)->assertStatus(201);
        $this->openShift($b, $box)->assertStatus(422);   // kassa band
        $this->openShift($a, $box2)->assertStatus(422);  // xodimda ochiq smena bor
    }

    public function test_cashier_cannot_open_shift_for_another_user(): void
    {
        $a = $this->makeUser('cashier', ['email' => 'a@test.uz']);
        $b = $this->makeUser('cashier', ['email' => 'b@test.uz']);

        $this->openShift($a, $this->box(), 0, ['user_id' => $b->id])->assertStatus(403);
    }

    public function test_manager_can_open_shift_for_cashier(): void
    {
        $manager = $this->makeUser('manager', ['email' => 'm@test.uz']);
        $cashier = $this->makeUser('cashier', ['email' => 'c@test.uz']);
        $box = $this->box();

        $this->openShift($manager, $box, 0, ['user_id' => $cashier->id])->assertStatus(201);
        $this->assertSame($cashier->id, CashboxShift::first()->user_id);
    }

    public function test_cash_in_out_updates_expected_and_closing_with_exact_cash_has_no_difference(): void
    {
        $cashier = $this->makeUser('cashier');
        $box = $this->box();
        $id = $this->openShift($cashier, $box, 100000)->json('shift.id');

        $this->api($cashier)->postJson('/api/v1/cash-transactions', ['cashbox_id' => $box->id, 'direction' => 'in', 'amount' => 50000, 'note' => 'Kunlik tushum'])->assertStatus(201);
        $this->api($cashier)->postJson('/api/v1/cash-transactions', ['cashbox_id' => $box->id, 'direction' => 'out', 'amount' => 20000.5, 'note' => 'Suv xarid'])->assertStatus(201);

        $this->api($cashier)->getJson("/api/v1/shifts/$id")->assertOk()->assertJsonPath('summary.expected_cash', '129999.50');

        $this->api($cashier)->postJson("/api/v1/shifts/$id/close", ['actual_cash' => 129999.5])
            ->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('difference', '0.00');
        $this->assertEquals(129999.5, $this->balance($box));
    }

    public function test_difference_requires_reason_and_is_recorded(): void
    {
        $cashier = $this->makeUser('cashier');
        $box = $this->box();
        $id = $this->openShift($cashier, $box, 100000)->json('shift.id');

        $this->api($cashier)->postJson("/api/v1/shifts/$id/close", ['actual_cash' => 95000])->assertStatus(422);
        $this->assertSame('open', CashboxShift::find($id)->status);

        $this->api($cashier)->postJson("/api/v1/shifts/$id/close", ['actual_cash' => 95000, 'reason' => 'Qaytim xato berilgan'])
            ->assertOk()->assertJsonPath('difference', '-5000.00')->assertJsonPath('expected_cash', '100000.00');

        $this->assertEquals(95000, $this->balance($box)); // balans haqiqiy naqd pulga tenglashadi
        $this->assertTrue(CashTransaction::where('category', 'shift_difference')->where('direction', 'out')->exists());
    }

    public function test_closed_shift_cannot_be_closed_twice(): void
    {
        $cashier = $this->makeUser('cashier');
        $box = $this->box();
        $id = $this->openShift($cashier, $box, 1000)->json('shift.id');

        $this->api($cashier)->postJson("/api/v1/shifts/$id/close", ['actual_cash' => 1000])->assertOk();
        $this->api($cashier)->postJson("/api/v1/shifts/$id/close", ['actual_cash' => 1000])->assertStatus(422);
        $this->assertEquals(1, CashTransaction::count()); // faqat boshlang'ich moslashtirish, ikkinchi marta hech narsa yozilmagan
    }

    public function test_cashier_cannot_close_or_see_other_cashiers_shift(): void
    {
        $a = $this->makeUser('cashier', ['email' => 'a@test.uz']);
        $b = $this->makeUser('cashier', ['email' => 'b@test.uz']);
        $id = $this->openShift($a, $this->box(), 1000)->json('shift.id');

        $this->api($b)->getJson("/api/v1/shifts/$id")->assertStatus(403);
        $this->api($b)->postJson("/api/v1/shifts/$id/close", ['actual_cash' => 1000])->assertStatus(403);
        $this->api($b)->getJson('/api/v1/shifts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cash_out_cannot_exceed_balance(): void
    {
        $cashier = $this->makeUser('cashier');
        $box = $this->box();
        $this->openShift($cashier, $box, 1000);

        $this->api($cashier)->postJson('/api/v1/cash-transactions', ['cashbox_id' => $box->id, 'direction' => 'out', 'amount' => 1000.01, 'note' => 'Ortiqcha'])->assertStatus(422);
        $this->assertEquals(1000, $this->balance($box));
    }

    public function test_cash_transaction_is_idempotent_by_client_token(): void
    {
        $cashier = $this->makeUser('cashier');
        $box = $this->box();
        $this->openShift($cashier, $box, 1000);
        $body = ['cashbox_id' => $box->id, 'direction' => 'in', 'amount' => 500, 'note' => 'Kirim', 'client_token' => 'tok-1'];

        $this->api($cashier)->postJson('/api/v1/cash-transactions', $body)->assertStatus(201);
        $this->api($cashier)->postJson('/api/v1/cash-transactions', $body)->assertOk();

        $this->assertEquals(1500, $this->balance($box));
        $this->assertSame(1, CashTransaction::where('client_token', 'tok-1')->count());
    }

    public function test_manual_operation_without_open_shift_needs_cashbox_update_permission(): void
    {
        $cashier = $this->makeUser('cashier', ['email' => 'c@test.uz']);
        $manager = $this->makeUser('manager', ['email' => 'm@test.uz']);
        $box = $this->box();
        $body = ['cashbox_id' => $box->id, 'direction' => 'in', 'amount' => 1000, 'note' => 'Boshlang‘ich'];

        $this->api($cashier)->postJson('/api/v1/cash-transactions', $body)->assertStatus(403);
        $this->api($manager)->postJson('/api/v1/cash-transactions', $body)->assertStatus(201);
    }

    public function test_handover_closes_current_and_opens_next_with_counted_cash(): void
    {
        $a = $this->makeUser('cashier', ['email' => 'a@test.uz']);
        $b = $this->makeUser('cashier', ['email' => 'b@test.uz']);
        $box = $this->box();
        $id = $this->openShift($a, $box, 100000)->json('shift.id');

        $res = $this->api($a)->postJson("/api/v1/shifts/$id/handover", ['actual_cash' => 100000, 'to_user_id' => $b->id])->assertOk();

        $this->assertSame('closed', $res->json('closed.status'));
        $this->assertSame('open', $res->json('opened.status'));
        $this->assertSame($b->id, $res->json('opened.user_id'));
        $this->assertSame('100000.00', $res->json('opened.opening_cash'));
        $this->assertSame($id, $res->json('opened.handover_from_shift_id'));
        $this->assertEquals(1, CashboxShift::where('status', 'open')->count());
    }

    public function test_handover_requires_target_user(): void
    {
        $a = $this->makeUser('cashier', ['email' => 'a@test.uz']);
        $id = $this->openShift($a, $this->box(), 1000)->json('shift.id');

        $this->api($a)->postJson("/api/v1/shifts/$id/handover", ['actual_cash' => 1000])->assertStatus(422);
        $this->api($a)->postJson("/api/v1/shifts/$id/handover", ['actual_cash' => 1000, 'to_user_id' => $a->id])->assertStatus(422);
    }

    public function test_opening_differing_from_book_balance_requires_note(): void
    {
        $a = $this->makeUser('cashier', ['email' => 'a@test.uz']);
        $b = $this->makeUser('cashier', ['email' => 'b@test.uz']);
        $box = $this->box();

        $id = $this->openShift($a, $box, 1000)->json('shift.id');
        $this->api($a)->postJson("/api/v1/shifts/$id/close", ['actual_cash' => 1000])->assertOk();

        $this->openShift($b, $box, 900)->assertStatus(422);
        $this->openShift($b, $box, 900, ['note' => 'Sanoqda 100 so‘m kam chiqdi'])->assertStatus(201);
        $this->assertEquals(900, $this->balance($box));
    }

    public function test_review_requires_permission_and_works_once(): void
    {
        $cashier = $this->makeUser('cashier', ['email' => 'c@test.uz']);
        $manager = $this->makeUser('manager', ['email' => 'm@test.uz']);
        $id = $this->openShift($cashier, $this->box(), 1000)->json('shift.id');

        $this->api($manager)->postJson("/api/v1/shifts/$id/review")->assertStatus(422); // hali ochiq
        $this->api($cashier)->postJson("/api/v1/shifts/$id/close", ['actual_cash' => 1000])->assertOk();

        $this->api($cashier)->postJson("/api/v1/shifts/$id/review")->assertStatus(403);
        $this->api($manager)->postJson("/api/v1/shifts/$id/review", ['note' => 'Tekshirildi'])->assertOk()->assertJsonPath('reviewer.id', $manager->id);
        $this->api($manager)->postJson("/api/v1/shifts/$id/review")->assertStatus(422);
    }

    public function test_cashbox_crud_needs_permissions_and_deactivation_is_guarded(): void
    {
        $cashier = $this->makeUser('cashier', ['email' => 'c@test.uz']);
        $manager = $this->makeUser('manager', ['email' => 'm@test.uz']);

        $this->api($cashier)->postJson('/api/v1/cashboxes', ['code' => 'K-9', 'name' => 'Yangi'])->assertStatus(403);
        $id = $this->api($manager)->postJson('/api/v1/cashboxes', ['code' => 'K-9', 'name' => 'Yangi'])->assertStatus(201)->json('id');
        $this->api($manager)->postJson('/api/v1/cashboxes', ['code' => 'K-9', 'name' => 'Takror'])->assertStatus(422);

        $this->api($manager)->postJson('/api/v1/cash-transactions', ['cashbox_id' => $id, 'direction' => 'in', 'amount' => 10, 'note' => 'Test'])->assertStatus(201);
        // manager delete ruxsatiga ega emas; admin qoldiq borida faolsizlantira olmaydi
        $this->api($manager)->deleteJson("/api/v1/cashboxes/$id")->assertStatus(403);
        $admin = $this->makeUser('admin', ['email' => 'ad@test.uz']);
        $this->api($admin)->deleteJson("/api/v1/cashboxes/$id")->assertStatus(422);
        $this->assertDatabaseHas('cashboxes', ['id' => $id, 'is_active' => true]);
    }

    public function test_cashbox_list_shows_balance(): void
    {
        $manager = $this->makeUser('manager');
        $box = $this->box();
        $this->api($manager)->postJson('/api/v1/cash-transactions', ['cashbox_id' => $box->id, 'direction' => 'in', 'amount' => 2500.5, 'note' => 'Boshlang‘ich'])->assertStatus(201);

        $this->api($manager)->getJson('/api/v1/cashboxes')->assertOk()->assertJsonPath('data.0.balance', '2500.50');
    }

    public function test_work_schedule_manage_and_alternating_days(): void
    {
        $manager = $this->makeUser('manager', ['email' => 'm@test.uz']);
        $s1 = $this->makeUser('cashier', ['email' => 's1@test.uz']);
        $s2 = $this->makeUser('cashier', ['email' => 's2@test.uz']);
        $e1 = Employee::create(['user_id' => $s1->id, 'employee_code' => 'E1', 'position' => 'Sotuvchi']);
        $e2 = Employee::create(['user_id' => $s2->id, 'employee_code' => 'E2', 'position' => 'Sotuvchi']);

        $this->api($manager)->postJson('/api/v1/work-schedules', ['employee_id' => $e1->id, 'dates' => ['2026-10-12', '2026-10-14'], 'type' => 'work', 'start_time' => '09:00', 'end_time' => '18:00'])->assertStatus(201);
        $this->api($manager)->postJson('/api/v1/work-schedules', ['employee_id' => $e2->id, 'dates' => ['2026-10-13', '2026-10-15'], 'type' => 'work', 'start_time' => '09:00', 'end_time' => '18:00'])->assertStatus(201);
        $this->api($manager)->postJson('/api/v1/work-schedules', ['employee_id' => $e1->id, 'work_date' => '2026-10-13', 'type' => 'off'])->assertStatus(201);

        // upsert: bir xodim va sana uchun bitta yozuv
        $this->api($manager)->postJson('/api/v1/work-schedules', ['employee_id' => $e1->id, 'work_date' => '2026-10-13', 'type' => 'vacation'])->assertStatus(201);
        $this->assertEquals(1, WorkSchedule::where('employee_id', $e1->id)->where('work_date', '2026-10-13')->count());

        $this->api($manager)->getJson('/api/v1/work-schedules?date_from=2026-10-12&date_to=2026-10-18')->assertOk()->assertJsonCount(5);
        $this->api($s1)->getJson('/api/v1/my-schedule?date_from=2026-10-12&date_to=2026-10-18')->assertOk()->assertJsonCount(3);
    }

    public function test_work_schedule_validation_and_permissions(): void
    {
        $manager = $this->makeUser('manager', ['email' => 'm@test.uz']);
        $cashier = $this->makeUser('cashier', ['email' => 'c@test.uz']);
        $emp = Employee::create(['user_id' => $cashier->id, 'employee_code' => 'E1', 'position' => 'Sotuvchi']);

        $this->api($manager)->postJson('/api/v1/work-schedules', ['employee_id' => $emp->id, 'work_date' => '2026-10-12', 'type' => 'work'])->assertStatus(422); // vaqt yo'q
        $this->api($manager)->postJson('/api/v1/work-schedules', ['employee_id' => $emp->id, 'work_date' => '2026-10-12', 'type' => 'work', 'start_time' => '18:00', 'end_time' => '09:00'])->assertStatus(422);
        $this->api($cashier)->postJson('/api/v1/work-schedules', ['employee_id' => $emp->id, 'work_date' => '2026-10-12', 'type' => 'off'])->assertStatus(403);
        $this->api($cashier)->getJson('/api/v1/work-schedules?date_from=2026-10-12&date_to=2026-10-18')->assertStatus(403);
    }

    public function test_opening_shift_on_day_off_returns_warning_but_is_not_blocked(): void
    {
        $manager = $this->makeUser('manager', ['email' => 'm@test.uz']);
        $cashier = $this->makeUser('cashier', ['email' => 'c@test.uz']);
        $emp = Employee::create(['user_id' => $cashier->id, 'employee_code' => 'E1', 'position' => 'Sotuvchi']);
        WorkSchedule::create(['employee_id' => $emp->id, 'work_date' => now('Asia/Tashkent')->toDateString(), 'type' => 'off']);

        $res = $this->openShift($manager, $this->box(), 0, ['user_id' => $cashier->id])->assertStatus(201);
        $this->assertNotNull($res->json('warning'));
    }

    public function test_cash_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/cashboxes')->assertStatus(401);
        $this->postJson('/api/v1/shifts/open', [])->assertStatus(401);
        $this->getJson('/api/v1/work-schedules?date_from=2026-10-12&date_to=2026-10-18')->assertStatus(401);
    }

    public function test_viewer_cannot_open_shift_or_move_cash(): void
    {
        $viewer = $this->makeUser('viewer');
        $box = $this->box();

        $this->openShift($viewer, $box)->assertStatus(403);
        $this->api($viewer)->postJson('/api/v1/cash-transactions', ['cashbox_id' => $box->id, 'direction' => 'in', 'amount' => 1, 'note' => 'Test'])->assertStatus(403);
    }
}
