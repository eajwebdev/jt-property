<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed demo logins (one per role). Existing accounts are never modified.
     */
    public function run(): void
    {
        $password = config('app.seed_password');

        if (empty($password)) {
            if (! app()->environment('local', 'testing')) {
                throw new RuntimeException('Set SEED_PASSWORD before seeding a non-local environment.');
            }

            $password = 'password';
        }

        $demo = [
            ['Owner Demo', 'owner@jt.test', Role::Owner],
            ['Staff Demo', 'staff@jt.test', Role::Staff],
            ['Accountant Demo', 'accountant@jt.test', Role::Accountant],
        ];

        foreach ($demo as [$name, $email, $role]) {
            User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'role' => $role,
                'password' => $password,
                'email_verified_at' => now(),
            ]);
        }

        if (app()->environment('local', 'testing')) {
            $this->call(DemoSeeder::class);
        }
    }
}
