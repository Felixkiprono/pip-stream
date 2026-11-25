<?php

namespace App\Pipstream;

use App\Models\AuditLog;
use App\Models\BrokerAccount;

/**
 * Real-money safety for MetaTrader accounts linked through the PipStream EA.
 *
 * The EA reports whether its account is demo or real. A real-money account only gets new
 * trades after the customer confirmed live copying for that exact login: the confirmation is
 * stored as symbol_map._live_ok_login, and the EA learns it from every sync answer
 * (OK|poll|time|1). If the terminal later logs into another real account, the login no longer
 * matches and the EA refuses to open trades there until the customer confirms again.
 * Closing and changing trades that are already open is never blocked.
 */
class LiveGate
{
    /** The account state the EA last reported, or null while it has not checked in. */
    public static function state(BrokerAccount $b): ?array
    {
        if ($b->connector !== 'ea_bridge') {
            return null;
        }
        $p = config('pipstream.stream_prefix', 'ps');
        $raw = rescue(fn () => app(Bus::class)->redis()->client()->get("{$p}:eares:{$b->id}:state"), null, false);
        $st = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($st) ? $st : null;
    }

    /** demo | real | unknown, as the terminal itself reports it. */
    public static function kind(?array $state): string
    {
        if (! $state || ! array_key_exists('demo', $state)) {
            return 'unknown';
        }

        return $state['demo'] === true ? 'demo' : 'real';
    }

    /** Has the customer confirmed real-money copying for the login this state is from? */
    public static function confirmedFor(BrokerAccount $b, ?array $state): bool
    {
        $login = (string) ($state['login'] ?? '');
        $ok = (string) (($b->symbol_map ?? [])['_live_ok_login'] ?? '');

        return $login !== '' && $login !== '0' && hash_equals($ok, $login);
    }

    /** What the EA is told on each sync: may it open trades on this account? */
    public static function allowsOpening(BrokerAccount $b, ?array $state): bool
    {
        return self::kind($state) === 'demo' || self::confirmedFor($b, $state);
    }

    /** Record the customer's confirmation for the login the terminal reports right now. */
    public static function confirm(BrokerAccount $b, array $state, string $actor): void
    {
        $login = (string) ($state['login'] ?? '');
        $b->update(['symbol_map' => array_merge((array) $b->symbol_map, [
            '_live_ok_login' => $login, '_live_ok_at' => now()->toIso8601String()])]);
        AuditLog::record($actor, 'live_confirm', 'broker_account', $b->id, [
            'login' => $login, 'server' => $state['server'] ?? null, 'company' => $state['company'] ?? null]);
    }

    /** The details a confirmation dialog shows, so the customer knows exactly which account it is. */
    public static function describe(BrokerAccount $b, ?array $state): array
    {
        return array_filter(['broker_id' => $b->id, 'label' => $b->label, 'login' => $state['login'] ?? null,
            'server' => $state['server'] ?? null, 'company' => $state['company'] ?? null,
            'balance' => $state['balance'] ?? null, 'currency' => $state['currency'] ?? null], fn ($v) => $v !== null);
    }
}
