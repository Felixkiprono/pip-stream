<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Pipstream\Accounts;
use Illuminate\Console\Command;

/** Put every account listed in PS_LIFETIME_EMAILS on the free-for-life plan (safe to rerun). */
class SyncLifetime extends Command
{
    protected $signature = 'pipstream:lifetime';

    protected $description = 'Give the accounts in PS_LIFETIME_EMAILS the Lifetime plan';

    public function handle(Accounts $accounts): int
    {
        $emails = config('pipstream.lifetime_emails');
        if (! $emails) {
            $this->warn('PS_LIFETIME_EMAILS is empty');

            return self::SUCCESS;
        }
        $users = User::whereIn('email', $emails)->get();
        foreach ($users as $u) {
            $accounts->grantLifetime($u);
            $this->line("lifetime: {$u->email}");
        }
        $missing = array_diff($emails, $users->pluck('email')->all());
        foreach ($missing as $m) {
            $this->line("not signed up yet (gets Lifetime when they register): {$m}");
        }

        return self::SUCCESS;
    }
}
