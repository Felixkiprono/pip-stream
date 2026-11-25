<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which version of the Terms / Privacy Policy / Risk Disclosure a customer accepted at sign-up, when, and from which IP. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('terms_version', 20)->nullable();
            $t->timestampTz('terms_accepted_at')->nullable();
            $t->string('terms_accepted_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['terms_version', 'terms_accepted_at', 'terms_accepted_ip']));
    }
};
