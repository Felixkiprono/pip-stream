<?php

use Database\Seeders\SuperadminSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.last_seen_at (set by the 'active' middleware at most every 5 minutes) for the
 * superadmin's active-user numbers. Also seeds the superadmin again when it is still missing:
 * the first try ran inside the founding-members migration, before PS_SUPERADMIN_PASSWORD may
 * have been in the server's .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->timestampTz('last_seen_at')->nullable()->index();
        });

        $email = strtolower(trim((string) config('pipstream.superadmin_email')));
        if ($email !== '' && ! DB::table('users')->where('email', $email)->where('role', 'superadmin')->exists()) {
            (new SuperadminSeeder)->run();
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('last_seen_at'));
    }
};
