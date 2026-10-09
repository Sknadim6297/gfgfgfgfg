<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AdminSeeder extends Seeder
{
    private const EMAIL = 'admin@quickpaymoney.com';

    public function run(): void
    {
        if (config('app.env') !== 'testing' && DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('Configure DB_CONNECTION=mysql before creating an admin.');
        }

        $password = (string) config('admin.password');

        $validator = Validator::make(
            ['password' => $password],
            [
                'password' => ['required', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            ]
        );

        if ($validator->fails()) {
            throw new RuntimeException('Set a strong ADMIN_PASSWORD in the environment before seeding.');
        }

        DB::transaction(function () use ($password): void {
            $existing = User::where('email', self::EMAIL)->lockForUpdate()->first();

            if ($existing && $existing->role !== 'admin') {
                throw new RuntimeException('The admin email belongs to an existing customer account.');
            }

            if ($existing) {
                if (config('admin.reset_password', false) === true) {
                    $existing->forceFill(['password' => Hash::make($password)])->save();
                }

                return;
            }

            $admin = new User;
            $admin->forceFill([
                'name' => 'Quick PayMoney Admin',
                'email' => self::EMAIL,
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'role' => 'admin',
                'account_status' => 'active',
                'remember_token' => Str::random(60),
            ])->save();

            AuditLog::create([
                'subject_user_id' => $admin->id,
                'event' => 'admin.credentials_provisioned',
                'metadata' => ['account_status' => 'active'],
            ]);
        });
    }
}
