<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the owner and staff demo logins.
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
            ['Owner Demo', 'owner@jt', Role::Owner],
            ['Staff Demo', 'staff@jt', Role::Staff],
        ];

        foreach ($demo as [$name, $email, $role]) {
            $user = User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'role' => $role,
                'password' => $password,
                'email_verified_at' => now(),
            ]);

            if (app()->environment('local', 'testing') && ! Hash::check($password, $user->password)) {
                $user->forceFill(['password' => $password])->save();
            }
        }

        // Local development starts from the real October 2026 Statement of Accounts (Mubarak Boarding House).
        // Tests keep the small fake demo data they were written against.
        if (app()->environment('local')) {
            $this->call(MubarakBoardingHouseSeeder::class);
        } elseif (app()->environment('testing')) {
            $this->call(DemoSeeder::class);
        }
    }
}
