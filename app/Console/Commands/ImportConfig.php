<?php

namespace App\Console\Commands;

use App\Pipstream\Bus;
use App\Pipstream\Vault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * Load a PipStream YAML config (e.g. engine/config/pipstream.yaml) for demos and the
 * experiment's admin-managed channels. Idempotent. Broker credentials come from the env
 * vars named in `credentials_env` and are sealed to the executor's public key.
 */
class ImportConfig extends Command
{
    protected $signature = 'pipstream:import-config {file=engine/config/pipstream.yaml}';

    protected $description = 'Import channels, parser profiles, readers, broker accounts and copy rules from YAML';

    public function handle(Bus $bus): int
    {
        $cfg = Yaml::parseFile(base_path($this->argument('file')));
        $n = [];
        DB::transaction(function () use ($cfg, &$n) {
            foreach ($cfg['plans'] ?? [] as $p) {
                DB::table('plans')->upsert([['id' => $p['id'], 'name' => $p['name'], 'max_accounts' => $p['max_accounts'] ?? 1,
                    'allow_live' => $p['allow_live'] ?? false, 'price_cents' => $p['price_cents'] ?? 0,
                    'currency' => $p['currency'] ?? 'KES']], ['id']);
            }
            foreach ($cfg['users'] ?? [] as $u) {
                DB::table('users')->upsert([['id' => $u['id'], 'email' => $u['email'], 'name' => $u['name'] ?? $u['display_name'] ?? null,
                    'role' => $u['role'] ?? 'customer']], ['id'], ['email', 'name', 'role']);
                if ($plan = $u['plan'] ?? null) {
                    DB::table('billing_subscriptions')->upsert([['id' => 'bsub_'.$u['id'], 'user_id' => $u['id'],
                        'plan_id' => $plan, 'status' => 'trialing', 'provider' => 'seed']], ['user_id'], ['plan_id']);
                }
                $n['users'] = ($n['users'] ?? 0) + 1;
            }
            foreach ($cfg['telegram_accounts'] ?? [] as $t) {
                DB::table('telegram_accounts')->insertOrIgnore(['id' => $t['id'], 'user_id' => $t['user_id'], 'status' => 'pending']);
            }
            foreach ($cfg['parser_profiles'] ?? [] as $p) {
                $body = array_diff_key($p, array_flip(['id', 'name', 'version', 'min_confidence']));
                DB::table('parser_profiles')->upsert([['id' => $p['id'], 'name' => $p['name'] ?? $p['id'], 'version' => $p['version'] ?? 1,
                    'min_confidence' => $p['min_confidence'] ?? 0.8, 'config' => json_encode((object) $body)]], ['id']);
            }
            foreach ($cfg['channels'] ?? [] as $c) {
                DB::table('channels')->upsert([['id' => $c['id'], 'tg_chat_id' => (int) $c['tg_chat_id'], 'title' => $c['title'] ?? null,
                    'username' => $c['username'] ?? null, 'parser_profile_id' => $c['parser_profile'] ?? 'pp_default',
                    'active' => $c['active'] ?? true]], ['id']);
                foreach ($c['readers'] ?? [] as $r) {
                    DB::table('channel_readers')->upsert([['channel_id' => $c['id'], 'telegram_account_id' => $r['account'],
                        'role' => $r['role'] ?? 'active']], ['channel_id', 'telegram_account_id'], ['role']);
                }
                foreach ($c['subscribers'] ?? [] as $uid) {
                    DB::table('subscriptions')->insertOrIgnore(['user_id' => $uid, 'channel_id' => $c['id']]);
                }
                $n['channels'] = ($n['channels'] ?? 0) + 1;
            }
            foreach ($cfg['broker_accounts'] ?? [] as $a) {
                $creds = $a['credentials'] ?? [];
                $ok = true;
                foreach ($a['credentials_env'] ?? [] as $field => $env) {
                    $v = env($env);
                    $v === null || $v === '' ? $ok = false : $creds[$field] = $v;
                }
                $cipher = $creds && config('pipstream.executor_public_key') ? Vault::sealForExecutor($creds) : null;
                DB::table('broker_accounts')->upsert([['id' => $a['id'], 'user_id' => $a['user_id'], 'connector' => $a['connector'],
                    'label' => $a['label'] ?? null, 'credentials_ciphertext' => $cipher,
                    'symbol_map' => json_encode((object) ($a['symbol_map'] ?? [])), 'enabled' => ($a['enabled'] ?? true) && $ok]],
                    ['id'], ['connector', 'label', 'symbol_map', 'enabled']);
                if ($cipher) {
                    DB::table('broker_accounts')->where('id', $a['id'])->update(['credentials_ciphertext' => $cipher]);
                }
            }
            foreach ($cfg['copy_rules'] ?? [] as $r) {
                DB::table('copy_rules')->upsert([[
                    'id' => $r['id'], 'user_id' => $r['user_id'], 'channel_id' => $r['channel_id'],
                    'broker_account_id' => $r['broker_account_id'], 'enabled' => $r['enabled'] ?? true,
                    'mode' => $r['mode'] ?? 'shadow', 'sizing_mode' => $r['sizing_mode'] ?? 'fixed',
                    'fixed_lots' => $r['fixed_lots'] ?? null, 'risk_pct' => $r['risk_pct'] ?? null,
                    'multiplier' => $r['multiplier'] ?? null, 'stake' => $r['stake'] ?? null, 'max_lot' => $r['max_lot'] ?? 1.0,
                    'tp_split' => $r['tp_split'] ?? 'equal', 'filters' => json_encode((object) ($r['filters'] ?? [])),
                    'limits' => json_encode((object) ($r['limits'] ?? []))]], ['id']);
                $n['copy_rules'] = ($n['copy_rules'] ?? 0) + 1;
            }
        });
        $bus->publish('config.changed', ['entity' => 'channel']);
        $this->info('imported: '.json_encode($n));

        return self::SUCCESS;
    }
}
