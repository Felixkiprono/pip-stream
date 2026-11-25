<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Landing page visits (one row per page view), with the visitor's IP and where it maps to. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_visits', function (Blueprint $t) {
            $t->id();
            $t->string('visitor_id', 40)->index();   // per browser session
            $t->string('user_id')->nullable()->index(); // set when this visitor signs up or is signed in
            $t->string('ip', 45)->index();
            $t->string('country', 2)->nullable();
            $t->string('region', 100)->nullable();
            $t->string('city', 100)->nullable();
            $t->string('org', 200)->nullable();         // network / ISP
            $t->string('device', 10)->nullable();       // mobile | tablet | desktop
            $t->string('browser', 40)->nullable();
            $t->string('os', 40)->nullable();
            $t->string('path', 300)->nullable();
            $t->string('referrer', 500)->nullable();
            $t->string('referrer_host', 200)->nullable();
            $t->string('utm_source', 100)->nullable();
            $t->string('utm_medium', 100)->nullable();
            $t->string('utm_campaign', 100)->nullable();
            $t->text('user_agent')->nullable();
            $t->timestampTz('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_visits');
    }
};
