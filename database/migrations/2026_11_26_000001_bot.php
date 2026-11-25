<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PipStream bot (@pip_stream_bot, Telegram Bot API):
 *   bot_chats       every private chat that talked to the bot, linked to a customer or not yet
 *   bot_messages    support inbox and everything the team sent (replies, direct messages, broadcasts)
 *   bot_broadcasts  one row per broadcast, with its progress
 * notification_prefs (channel telegram_bot) stays the link the alert code reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_chats', function (Blueprint $t) {
            $t->bigInteger('chat_id')->primary();
            $t->string('user_id')->nullable()->index();
            $t->string('username', 100)->nullable();
            $t->string('first_name', 120)->nullable();
            $t->boolean('trade_alerts')->default(true);
            $t->boolean('daily_summary')->default(true);
            $t->jsonb('state')->nullable();              // a step-by-step flow in progress (link by email)
            $t->timestampTz('linked_at')->nullable();
            $t->timestampTz('last_seen_at')->nullable();
            $t->timestampTz('blocked_at')->nullable();   // the customer blocked the bot: don't send
            $t->timestampTz('created_at')->useCurrent();
        });
        Schema::create('bot_messages', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('chat_id')->index();
            $t->string('user_id')->nullable()->index();
            $t->string('direction', 3);                  // in | out
            $t->string('kind', 20);                      // support | reply | direct | broadcast
            $t->text('text');
            $t->string('admin_id')->nullable();          // who sent it from Admin
            $t->unsignedBigInteger('broadcast_id')->nullable();
            $t->timestampTz('read_at')->nullable();      // support messages: seen in Admin
            $t->timestampTz('created_at')->useCurrent()->index();
        });
        Schema::create('bot_broadcasts', function (Blueprint $t) {
            $t->id();
            $t->string('admin_id');
            $t->string('segment', 40);
            $t->text('text');
            $t->integer('total')->default(0);
            $t->integer('sent')->default(0);
            $t->integer('failed')->default(0);
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_broadcasts');
        Schema::dropIfExists('bot_messages');
        Schema::dropIfExists('bot_chats');
    }
};
