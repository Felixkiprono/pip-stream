<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** MetaTrader 4 and 5 through MetaApi's hosted terminals (metaapi.cloud). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('connectors')->insertOrIgnore(['name' => 'metaapi', 'kind' => 'cfd', 'enabled' => true, 'config' => '{}']);
    }

    public function down(): void
    {
        DB::table('connectors')->where('name', 'metaapi')->delete();
    }
};
