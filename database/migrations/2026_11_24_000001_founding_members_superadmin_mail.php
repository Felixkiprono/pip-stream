<?php

use App\Pipstream\Ids;
use Database\Seeders\SuperadminSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Founding members (the first PS_FOUNDING_SLOTS customers, default 10, free for life with
 * every feature), a "superadmin" role, and platform_settings: installation-wide settings
 * managed from the admin console, stored encrypted with APP_KEY (e.g. the Resend API key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->smallInteger('founding_number')->nullable()->unique();
        });
        DB::statement('alter table users drop constraint if exists users_role_check');
        DB::statement("alter table users add constraint users_role_check check (role in ('superadmin','admin','customer'))");

        Schema::create('platform_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->longText('value')->nullable();
            $t->timestampsTz();
        });

        // Customers who already signed up before this release were first: they take the
        // founding spots in sign-up order (no emails sent from a migration).
        $slots = (int) config('pipstream.founding_slots');
        $first = DB::table('users')->where('role', 'customer')->orderBy('created_at')->orderBy('id')->limit($slots)->pluck('id');
        foreach ($first as $i => $id) {
            DB::table('users')->where('id', $id)->update(['founding_number' => $i + 1]);
            $this->lifetime($id);
            DB::table('audit_log')->insert(['actor' => 'laravel', 'action' => 'founding_member', 'entity' => 'user',
                'entity_id' => $id, 'detail' => json_encode(['number' => $i + 1])]);
        }

        // Superadmin from PS_SUPERADMIN_EMAIL / PS_SUPERADMIN_PASSWORD, when set (safe to rerun as a seeder).
        (new SuperadminSeeder)->run();
    }

    private function lifetime(string $userId): void
    {
        $values = ['plan_id' => 'lifetime', 'status' => 'active', 'provider' => 'lifetime', 'current_period_end' => null, 'updated_at' => now()];
        if (! DB::table('billing_subscriptions')->where('user_id', $userId)->update($values)) {
            DB::table('billing_subscriptions')->insert($values + ['id' => Ids::new('bsub'), 'user_id' => $userId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        DB::table('users')->where('role', 'superadmin')->update(['role' => 'admin']);
        DB::statement('alter table users drop constraint if exists users_role_check');
        DB::statement("alter table users add constraint users_role_check check (role in ('admin','customer'))");
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('founding_number'));
    }
};
