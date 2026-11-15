<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed demo logins (one per role).
     */
    public function run(): void
    {
        $demo = [
            ['Owner Demo', 'owner@jt.test', Role::Owner],
            ['Staff Demo', 'staff@jt.test', Role::Staff],
            ['Accountant Demo', 'accountant@jt.test', Role::Accountant],
        ];

        foreach ($demo as [$name, $email, $role]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'role' => $role,
                'password' => env('SEED_PASSWORD') ?: 'password',
                'email_verified_at' => now(),
            ]);
        }
    }
}
