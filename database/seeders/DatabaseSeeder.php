<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * "php artisan migrate:fresh --seed --force" (or "db:seed --force") loads everything in one go:
 *  1. the owner login (owner@jt, password = SEED_PASSWORD),
 *  2. removal of the original sample properties when they are still present,
 *  3. the real Mubarak Boarding House data from the October 2026 Statement of Accounts.
 * Automated tests retain their separate demo fixtures.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $local = app()->environment('local', 'testing');
        $testing = app()->environment('testing');
        $password = config('app.seed_password');

        if (empty($password)) {
            if (! $local) {
                throw new RuntimeException('Set SEED_PASSWORD in .env before seeding the server (it becomes the owner password).');
            }

            $password = 'password';
        }

        $users = [['Jun Trio', 'owner@jt', Role::Owner]];
        if ($testing) {
            $users[] = ['Staff Demo', 'staff@jt', Role::Staff];
        }

        foreach ($users as [$name, $email, $role]) {
            $user = User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'role' => $role,
                'password' => $password,
                'email_verified_at' => now(),
            ]);

            if ($email === 'owner@jt' && $user->name !== 'Jun Trio') {
                $user->forceFill(['name' => 'Jun Trio'])->save();
            }

            if ($local && ! Hash::check($password, $user->password)) {
                $user->forceFill(['password' => $password])->save();
            }
        }

        if ($testing) {
            $this->call(DemoSeeder::class);

            return;
        }

        $this->call(RemoveDemoDataSeeder::class);

        $this->call(MubarakBoardingHouseSeeder::class);
    }
}
