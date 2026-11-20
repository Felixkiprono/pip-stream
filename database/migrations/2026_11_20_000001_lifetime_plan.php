<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** "Lifetime": free forever with Pro limits, for the accounts listed in PS_LIFETIME_EMAILS. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->insertOrIgnore([
            'id' => 'lifetime', 'name' => 'Lifetime', 'max_accounts' => 5, 'allow_live' => true, 'price_cents' => 0, 'currency' => 'KES',
        ]);
    }

    public function down(): void
    {
        DB::table('plans')->where('id', 'lifetime')->delete();
    }
};
