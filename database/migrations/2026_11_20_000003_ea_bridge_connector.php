<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** MetaTrader 4 and 5 through the PipStream EA running in the customer's own terminal. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('connectors')->insertOrIgnore(['name' => 'ea_bridge', 'kind' => 'cfd', 'enabled' => true, 'config' => '{}']);
    }

    public function down(): void
    {
        DB::table('connectors')->where('name', 'ea_bridge')->delete();
    }
};
