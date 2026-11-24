<?php

namespace Database\Seeders;

use App\Models\User;
use App\Pipstream\Accounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The superadmin (PS_SUPERADMIN_EMAIL, default felixkiprono@yahoo.com) with the password in
 * PS_SUPERADMIN_PASSWORD. The password is never in the repo: set it in the server's .env.
 * Safe to rerun: creates the account, or promotes it and resets its password to the env value.
 *   php artisan db:seed --class=SuperadminSeeder --force
 */
class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) config('pipstream.superadmin_email')));
        $password = (string) config('pipstream.superadmin_password');
        if ($email === '' || $password === '') {
            $this->command?->warn('PS_SUPERADMIN_PASSWORD is not set: superadmin not seeded');

            return;
        }
        $user = User::where('email', $email)->first();
        if ($user) {
            $user->forceFill(['role' => 'superadmin', 'password' => $password, 'status' => 'active'])->save();
        } else {
            $user = User::forceCreate(['name' => 'Felix', 'email' => $email, 'password' => $password,
                'role' => 'superadmin', 'email_verified_at' => now()]);
            app(Accounts::class)->onboard($user, quiet: true);
        }
        app(Accounts::class)->grantLifetime($user); // full access for testing everything
        DB::table('audit_log')->insert(['actor' => 'laravel', 'action' => 'superadmin_seeded', 'entity' => 'user',
            'entity_id' => $user->id, 'detail' => '{}']);
        $this->command?->info("superadmin: {$email}");
    }
}
