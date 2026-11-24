<?php

namespace App\Pipstream;

use App\Models\AuditLog;
use App\Models\BillingSubscription;
use App\Models\BrokerAccount;
use App\Models\Channel;
use App\Models\CopyRule;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Notifications\Welcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Customer-side actions that touch several tables, kept out of the controllers. */
class Accounts
{
    public function __construct(private Bus $bus) {}

    /** Every new customer gets a free practice (paper) account and a trial, so the first
     *  signal can be copied without risking money. The first founding_slots customers become
     *  founding members: free for life with every feature. `quiet`: no welcome email. */
    public function onboard(User $user, bool $quiet = false): void
    {
        DB::transaction(function () use ($user) {
            BrokerAccount::create(['user_id' => $user->id, 'connector' => 'paper', 'label' => 'Practice account',
                'symbol_map' => [], 'enabled' => true]);
            BillingSubscription::create(['user_id' => $user->id, 'plan_id' => config('pipstream.default_plan'),
                'status' => 'trialing', 'provider' => 'trial',
                'current_period_end' => now()->addDays(config('pipstream.trial_days'))]);
            DB::table('notification_prefs')->insertOrIgnore(['user_id' => $user->id, 'channel' => 'app']);
        });
        AuditLog::record('laravel', 'user_registered', 'user', $user->id);
        $founding = $this->claimFoundingSpot($user);
        if (! $founding && in_array(strtolower($user->email), config('pipstream.lifetime_emails'), true)) {
            $this->grantLifetime($user);
        }
        if (! $quiet) {
            self::safely(fn () => $user->notify(new Welcome($founding)));
        }
    }

    /** Founding members: the first `founding_slots` customers to sign up, numbered 1..N, get the
     *  Lifetime plan. A Postgres advisory lock makes the count exact under concurrent sign-ups;
     *  the unique founding_number column is the backstop. Returns the number, or null. */
    public function claimFoundingSpot(User $user): ?int
    {
        if ($user->founding_number) {
            return $user->founding_number;
        }
        if (($user->role ?? 'customer') !== 'customer') {
            return null;
        }
        $slots = (int) config('pipstream.founding_slots');
        $n = DB::transaction(function () use ($user, $slots) {
            DB::select("select pg_advisory_xact_lock(hashtext('pipstream:founding'))");
            $taken = (int) DB::table('users')->whereNotNull('founding_number')->count();
            if ($taken >= $slots) {
                return null;
            }
            $n = $taken + 1;
            DB::table('users')->where('id', $user->id)->update(['founding_number' => $n]);

            return $n;
        });
        if (! $n) {
            return null;
        }
        $user->forceFill(['founding_number' => $n])->syncOriginalAttribute('founding_number');
        AuditLog::record('laravel', 'founding_member', 'user', $user->id, ['number' => $n]);
        $this->grantLifetime($user);

        return $n;
    }

    /** Founding and Lifetime members keep their plan: nothing (a payment, a trial change) may replace it. */
    public static function isLifetime(User $user): bool
    {
        return (bool) $user->founding_number || $user->billing()->where('plan_id', 'lifetime')->exists();
    }

    /** Re-applies Lifetime if a founding member's plan ever drifted (cheap check, run on /auth/me). */
    public function ensureFounding(User $user): void
    {
        if ($user->founding_number && ! $user->billing()->where('plan_id', 'lifetime')->where('status', 'active')
            ->whereNull('current_period_end')->exists()) {
            $this->grantLifetime($user);
        }
    }

    /** Email and the engine bus must never break sign-up, linking or a migration. */
    public static function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning('side effect failed', ['error' => $e->getMessage()]);
        }
    }

    /** Free for life: Lifetime plan, active, no end date. */
    public function grantLifetime(User $user): void
    {
        BillingSubscription::updateOrCreate(['user_id' => $user->id],
            ['plan_id' => 'lifetime', 'status' => 'active', 'provider' => 'lifetime', 'current_period_end' => null]);
        AuditLog::record('laravel', 'lifetime_granted', 'user', $user->id);
        self::safely(fn () => $this->bus->publish('billing.changed', ['user_id' => $user->id, 'plan_id' => 'lifetime', 'status' => 'active']));
    }

    /** What the app shows about the customer's plan: tier, limits and usage. */
    public function plan(User $user): array
    {
        $b = $user->billing()->with('plan')->first();
        $p = $b?->plan;
        $premium = $p && ($p->id === 'lifetime' || $p->price_cents > 0) && $b->isEntitled();

        return [
            'id' => $p?->id, 'name' => $p?->name ?? 'No plan', 'status' => $b?->status,
            'tier' => $premium ? 'premium' : 'free',
            'lifetime' => $p?->id === 'lifetime',
            'founding' => $user->founding_number ? (int) $user->founding_number : null,
            'until' => $b?->current_period_end,
            'days_left' => $b?->current_period_end ? max(0, (int) ceil(now()->diffInDays($b->current_period_end, false))) : null,
            'limits' => [
                'brokers' => (int) ($p?->max_accounts ?? 1),
                'live' => (bool) ($p?->allow_live ?? false),
            ],
            'usage' => [
                'brokers' => $user->brokerAccounts()->where('connector', '!=', 'paper')->count(),
                'channels' => DB::table('subscriptions')->where('user_id', $user->id)->count(),
            ],
        ];
    }

    public function practiceAccount(User $user): BrokerAccount
    {
        return $user->brokerAccounts()->where('connector', 'paper')->orderBy('created_at')->firstOrFail();
    }

    /** Follow a channel the customer's own Telegram account has joined. The channel is read
     *  once for everyone: the first linked account becomes the active reader, the rest stand by. */
    public function follow(User $user, TelegramAccount $acc, int $tgChatId, ?string $title, ?string $username): Channel
    {
        $channel = DB::transaction(function () use ($user, $acc, $tgChatId, $title, $username) {
            $channel = Channel::firstOrCreate(['tg_chat_id' => $tgChatId],
                ['title' => $title, 'username' => $username, 'parser_profile_id' => 'pp_default', 'active' => true]);
            $hasActive = DB::table('channel_readers')->where('channel_id', $channel->id)->where('role', 'active')->exists();
            DB::table('channel_readers')->insertOrIgnore(['channel_id' => $channel->id,
                'telegram_account_id' => $acc->id, 'role' => $hasActive ? 'standby' : 'active']);
            DB::table('subscriptions')->insertOrIgnore(['user_id' => $user->id, 'channel_id' => $channel->id]);
            if (! CopyRule::where('user_id', $user->id)->where('channel_id', $channel->id)->exists()) {
                CopyRule::create(array_merge(Presets::apply('balanced'), [
                    'user_id' => $user->id, 'channel_id' => $channel->id,
                    'broker_account_id' => $this->practiceAccount($user)->id, 'mode' => 'paper', 'enabled' => true,
                    'tp_split' => 'equal', 'filters' => []]));
            }

            return $channel;
        });
        AuditLog::record($user->id, 'channel_followed', 'channel', $channel->id);
        $this->bus->publish('config.changed', ['entity' => 'channel_reader', 'entity_id' => $channel->id]);

        return $channel;
    }

    public function unfollow(User $user, Channel $channel): void
    {
        DB::transaction(function () use ($user, $channel) {
            DB::table('subscriptions')->where(['user_id' => $user->id, 'channel_id' => $channel->id])->delete();
            CopyRule::where('user_id', $user->id)->where('channel_id', $channel->id)->update(['enabled' => false]);
            $mine = $user->telegramAccounts()->pluck('id');
            DB::table('channel_readers')->where('channel_id', $channel->id)->whereIn('telegram_account_id', $mine)->delete();
            // promote a standby reader if the active one just left
            if (! DB::table('channel_readers')->where('channel_id', $channel->id)->where('role', 'active')->exists()) {
                $next = DB::table('channel_readers')->where('channel_id', $channel->id)->first();
                if ($next) {
                    DB::table('channel_readers')->where(['channel_id' => $channel->id,
                        'telegram_account_id' => $next->telegram_account_id])->update(['role' => 'active']);
                }
            }
        });
        $this->bus->publish('config.changed', ['entity' => 'channel_reader', 'entity_id' => $channel->id]);
    }

    /** Where the customer is in setup. Drives the app's onboarding screens. */
    public function onboarding(User $user): array
    {
        $tg = $user->telegramAccounts()->where('status', 'active')->whereNotNull('session_ciphertext')->exists();
        $channels = DB::table('subscriptions')->where('user_id', $user->id)->count();
        $realBroker = $user->brokerAccounts()->where('connector', '!=', 'paper')->whereNotNull('credentials_ciphertext')->exists();
        $rules = $user->copyRules()->where('enabled', true)->count();
        $steps = [
            'telegram' => $tg,
            'channels' => $channels > 0,
            'broker' => true,               // practice account always exists; a real broker is optional
            'style' => $rules > 0,
        ];

        return ['steps' => $steps, 'complete' => ! in_array(false, $steps, true), 'real_broker' => $realBroker,
            'channels' => $channels];
    }
}
