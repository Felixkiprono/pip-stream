<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Pipstream\Accounts;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeAdmin extends Command
{
    protected $signature = 'pipstream:admin {email} {--name=Admin} {--password=}';

    protected $description = 'Create an admin user (or promote an existing one)';

    public function handle(Accounts $accounts): int
    {
        $email = strtolower($this->argument('email'));
        $user = User::where('email', $email)->first();
        if ($user) {
            $user->forceFill(['role' => 'admin'])->save();
            $this->info("{$email} is now an admin");

            return self::SUCCESS;
        }
        $password = $this->option('password') ?: Str::password(16, symbols: false);
        $user = User::create(['email' => $email, 'name' => $this->option('name'), 'password' => $password, 'role' => 'admin']);
        $accounts->onboard($user);
        $this->info("admin {$email} created".($this->option('password') ? '' : " with password: {$password}"));

        return self::SUCCESS;
    }
}
