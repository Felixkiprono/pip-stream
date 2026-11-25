<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\SuperadminSeeder;
use Illuminate\Console\Command;

/**
 * Is the superadmin in this database?   php artisan pipstream:superadmin
 * Create it, or reset its password to PS_SUPERADMIN_PASSWORD:   php artisan pipstream:superadmin --seed
 */
class SuperadminStatus extends Command
{
    protected $signature = 'pipstream:superadmin {--seed : create the superadmin, or promote it and reset its password to PS_SUPERADMIN_PASSWORD}';

    protected $description = 'Show whether the superadmin account exists (and optionally seed it)';

    public function handle(): int
    {
        if ($this->option('seed')) {
            (new SuperadminSeeder)->setCommand($this)->run();
        }
        $email = strtolower(trim((string) config('pipstream.superadmin_email')));
        $this->line('PS_SUPERADMIN_EMAIL:    '.$email);
        $this->line('PS_SUPERADMIN_PASSWORD: '.(config('pipstream.superadmin_password') ? 'set' : 'NOT SET'));
        $u = User::where('email', $email)->first();
        if (! $u) {
            $this->error('No account with that email yet.');

            return self::FAILURE;
        }
        $this->line("Account:                {$u->id}, role {$u->role}, status {$u->status}, plan ".($u->billing?->plan_id ?? 'none'));
        $this->line('Last seen:              '.($u->last_seen_at?->toDateTimeString() ?? 'never'));
        if (! $u->isSuperadmin()) {
            $this->warn('The account exists but is not the superadmin. Run with --seed to promote it.');

            return self::FAILURE;
        }
        $this->info('Superadmin is in place.');

        return self::SUCCESS;
    }
}
