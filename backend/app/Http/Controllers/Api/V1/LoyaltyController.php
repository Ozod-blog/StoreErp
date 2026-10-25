<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LoyaltyController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        return LoyaltyAccount::with('customer:id,name,phone')
            ->when($search !== '', fn($q) => $q->where(fn($w) => $w->where('card_code', 'like', '%'.$search.'%')->orWhereHas('customer', fn($c) => $c->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%'))))
            ->orderByDesc('id')->paginate(max(5, min((int)$request->query('per_page', 25), 100)));
    }

    public function issue(Customer $customer)
    {
        if (!$customer->is_active) return response()->json(['message' => 'Faol mijoz tanlang.'], 422);
        $account = LoyaltyAccount::firstOrCreate(['customer_id' => $customer->id], ['card_code' => 'LOY-'.strtoupper(Str::random(12)), 'points_balance' => 0, 'lifetime_points' => 0, 'is_active' => true]);
        return response()->json(['data' => $account->load('customer:id,name,phone')], $account->wasRecentlyCreated ? 201 : 200);
    }
}
