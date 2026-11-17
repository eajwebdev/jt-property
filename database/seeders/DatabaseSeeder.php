<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * "php artisan migrate:fresh --seed --force" (or "db:seed --force") loads everything in one go:
 *  1. the owner login (owner@jt, password = SEED_PASSWORD; a staff demo login too on a developer's computer),
 *  2. the real Mubarak Boarding House data from the October 2026 Statement of Accounts,
 *  3. the demo properties (Riverside / Hillside Demo).
 * Every seeder is safe to run twice. Automated tests keep only the small demo data they were written against.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $local = app()->environment('local', 'testing');
        $password = config('app.seed_password');

        if (empty($password)) {
            if (! $local) {
                throw new RuntimeException('Set SEED_PASSWORD in .env before seeding the server (it becomes the owner password).');
            }

            $password = 'password';
        }

        $users = [[$local ? 'Owner Demo' : 'Owner', 'owner@jt', Role::Owner]];
        if ($local) {
            $users[] = ['Staff Demo', 'staff@jt', Role::Staff];
        }

        foreach ($users as [$name, $email, $role]) {
            $user = User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'role' => $role,
                'password' => $password,
                'email_verified_at' => now(),
            ]);

            if ($local && ! Hash::check($password, $user->password)) {
                $user->forceFill(['password' => $password])->save();
            }
        }

        if (app()->environment('testing')) {
            $this->call(DemoSeeder::class);

            return;
        }

        // Real data first, so its account numbers start at JT-000001 as on the printed statements.
        $this->call(MubarakBoardingHouseSeeder::class);
        $this->call(DemoSeeder::class);
    }
}
