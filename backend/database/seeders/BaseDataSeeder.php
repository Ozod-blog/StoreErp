<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Boshlang'ich (demo EMAS) ma'lumotlar: standart o'lchov birliklari, asosiy filial, asosiy ombor va asosiy kassa.
 * Qayta ishga tushirilganda mavjud yozuvlarni o'zgartirmaydi (firstOrCreate).
 */
class BaseDataSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['Dona', 'dona', false],
            ['Kilogramm', 'kg', true],
            ['Gramm', 'g', true],
            ['Litr', 'l', true],
            ['Metr', 'm', true],
            ['Quti', 'quti', false],
        ];
        foreach ($units as [$name, $short, $fraction]) {
            Unit::firstOrCreate(['short_name' => $short], ['name' => $name, 'allow_fraction' => $fraction, 'is_active' => true]);
        }

        $branch = Branch::firstOrCreate(['name' => 'Asosiy filial'], ['is_active' => true]);
        Warehouse::firstOrCreate(
            ['code' => 'WH-001'],
            ['name' => 'Asosiy ombor', 'branch_id' => $branch->id, 'is_active' => true]
        );
        Cashbox::firstOrCreate(
            ['code' => 'KS-001'],
            ['name' => 'Asosiy kassa', 'branch_id' => $branch->id, 'is_active' => true]
        );
    }
}
