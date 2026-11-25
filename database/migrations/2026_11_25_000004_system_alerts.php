<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * system_alerts: one row per incident the watchdog found (a worker or the engine stopped, errors
 * spiked, a customer's MetaTrader went quiet), open until it recovers. Shown in Admin → System.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_alerts', function (Blueprint $t) {
            $t->id();
            $t->string('key', 120)->index();          // what is down: worker:queue, engine:risk, ea:<broker id> ...
            $t->string('severity', 10)->default('error'); // error | warn
            $t->string('title', 200);
            $t->text('detail')->nullable();
            $t->string('user_id')->nullable()->index(); // set for a customer's own account (EA offline)
            $t->timestampTz('started_at')->useCurrent();
            $t->timestampTz('notified_at')->nullable();
            $t->timestampTz('resolved_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
    }
};
