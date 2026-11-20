<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Reference data every install needs: connectors, plans and the generic parser profile. */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([['paper', 'paper'], ['mt5_bridge', 'cfd'], ['deriv_options', 'options']] as [$name, $kind]) {
            DB::table('connectors')->insertOrIgnore(['name' => $name, 'kind' => $kind, 'enabled' => true, 'config' => '{}']);
        }
        DB::table('plans')->insertOrIgnore([
            ['id' => 'trial', 'name' => 'Free trial', 'max_accounts' => 1, 'allow_live' => false, 'price_cents' => 0, 'currency' => 'KES'],
            ['id' => 'pro', 'name' => 'Pro', 'max_accounts' => 3, 'allow_live' => true, 'price_cents' => 250000, 'currency' => 'KES'],
        ]);
        DB::table('parser_profiles')->insertOrIgnore([
            'id' => 'pp_default', 'name' => 'Generic signals', 'version' => 1, 'min_confidence' => 0.8, 'config' => '{}',
        ]);
    }

    public function down(): void {}
};
