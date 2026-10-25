<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('slug', 'admin')->firstOrFail();

        if ($adminRole->users()->exists()) {
            $this->command?->info('Administrator allaqachon mavjud — o‘tkazib yuborildi.');

            return;
        }

        $password = config('erp.admin_password');
        $generated = false;

        if (! $password) {
            if (app()->environment('production')) {
                throw new RuntimeException('Productionda .env faylida ADMIN_PASSWORD majburiy.');
            }
            $password = Str::password(16, symbols: false);
            $generated = true;
        }

        $admin = User::create([
            'name' => config('erp.admin_name'),
            'email' => config('erp.admin_email'),
            'password' => $password,
            'status' => 'active',
        ]);
        $admin->roles()->attach($adminRole->id);

        $this->command?->info('Administrator yaratildi: '.$admin->email);
        if ($generated) {
            $this->command?->warn("Vaqtinchalik parol (faqat hozir ko‘rsatiladi, saqlab oling): $password");
        }
    }
}
