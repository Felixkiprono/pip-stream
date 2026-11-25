<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BrokerAccount;
use App\Pipstream\Bus;
use App\Pipstream\Vault;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Broker passwords are sealed to the engine's executor key the moment they arrive and are
 *  never readable again by Laravel, the API or the app. */
class BrokerController extends Controller
{
    public function __construct(private Bus $bus) {}

    /** Also used by the superadmin's Brokers page. Never includes credentials. */
    public function present(BrokerAccount $b): array
    {
        $status = null;
        if ($b->connector === 'metaapi') {
            // written by the engine while it registers and starts the MetaTrader terminal
            $raw = rescue(fn () => $this->bus->redis()->client()->get(config('pipstream.stream_prefix').":acct:{$b->id}:status"), null, false);
            $status = $raw ? json_decode($raw, true) : ['state' => 'connecting', 'detail' => 'Waiting for the trading engine'];
        }

        if ($b->connector === 'ea_bridge') {
            $status = $this->eaStatus($b);
        }

        return ['id' => $b->id, 'connector' => $b->connector, 'label' => $b->label, 'enabled' => $b->enabled,
            'connected' => $b->connector === 'paper' || ($status ? $status['state'] === 'connected' : $b->hasCredentials()),
            'status' => $status, 'platform' => $b->symbol_map['_platform'] ?? null,
            'login_hint' => $b->symbol_map['_login_hint'] ?? null];
    }

    /** The EA checks in about once a second; its last check-in and account state live in redis. */
    private function eaStatus(BrokerAccount $b): array
    {
        $p = config('pipstream.stream_prefix');
        [$seen, $raw] = rescue(fn () => $this->bus->redis()->client()->mget(["{$p}:eares:{$b->id}:seen", "{$p}:eares:{$b->id}:state"]), [null, null], false);
        $st = $raw ? json_decode($raw, true) : null;
        if (! $seen) {
            return ['state' => 'connecting', 'detail' => 'Waiting for the PipStream EA to check in from your terminal'];
        }
        $age = time() - (int) $seen;
        $acct = $st ? array_filter(['balance' => $st['balance'] ?? null, 'equity' => $st['equity'] ?? null,
            'currency' => $st['currency'] ?? null, 'login' => $st['login'] ?? null, 'server' => $st['server'] ?? null,
            'company' => $st['company'] ?? null, 'demo' => isset($st['demo']) ? (bool) $st['demo'] : null,
            'platform' => $st['platform'] ?? null, 'ea_version' => $st['ea_version'] ?? null, 'positions' => count($st['positions'] ?? [])], fn ($v) => $v !== null) : null;
        if ($age > 15) {
            return ['state' => 'error', 'detail' => 'Your MetaTrader terminal is offline (last seen '.now()->subSeconds($age)->diffForHumans().')', 'account' => $acct, 'seen_s' => $age];
        }
        if ($st && array_key_exists('broker_connected', $st) && ! $st['broker_connected']) {
            return ['state' => 'error', 'detail' => "MetaTrader isn't connected to your broker. In MetaTrader: File → Login to Trade Account.", 'account' => $acct, 'seen_s' => $age];
        }
        if ($st && empty($st['trade_allowed'])) {
            return ['state' => 'error', 'detail' => 'EA is running, but Algo Trading is off in MetaTrader. Turn it on in the toolbar.', 'account' => $acct, 'seen_s' => $age];
        }

        return ['state' => 'connected', 'detail' => 'EA running', 'account' => $acct, 'seen_s' => $age];
    }

    /** A new EA key (the old one stops working). Shown once. */
    public function eaKey(Request $r, BrokerAccount $broker)
    {
        abort_unless($broker->user_id === $r->user()->id && $broker->connector === 'ea_bridge' && $broker->enabled, 404);
        $key = 'psk_'.Str::random(40);
        $old = $broker->symbol_map['_ea_key_hash'] ?? null;
        $broker->update(['symbol_map' => ['_ea_key_hash' => EaController::hashKey($key)] + (array) $broker->symbol_map]);
        if ($old) {
            Cache::forget("ea-key:{$old}");
        }
        AuditLog::record($r->user()->id, 'ea_key_rotated', 'broker_account', $broker->id);

        return ['broker' => $this->present($broker), 'ea_key' => $key];
    }

    /** "Enter the code shown on your chart": pairs a running EA with this customer. */
    public function pair(Request $r, Bus $bus)
    {
        $data = $r->validate(['code' => ['required', 'digits:6'], 'broker_id' => ['sometimes', 'nullable', 'string']]);
        $u = $r->user();
        // "Set up" on an existing EA row: the code links that row instead of adding a new one
        $existing = ! empty($data['broker_id'])
            ? BrokerAccount::where('id', $data['broker_id'])->where('user_id', $u->id)->where('connector', 'ea_bridge')->first()
            : null;
        abort_if(! empty($data['broker_id']) && ! $existing, 404);
        if (! $existing) {
            $max = $u->billing?->plan?->max_accounts ?? 1;
            abort_if($u->brokerAccounts()->where('connector', '!=', 'paper')->where('enabled', true)->count() >= $max, 402,
                "Your plan allows {$max} broker account(s).");
        }
        $p = EaController::claimPairing($data['code']);
        if (! $p) {
            throw \Illuminate\Validation\ValidationException::withMessages(['code' => 'That code is not valid or has expired. Check the code on your chart.']);
        }
        $b = $existing ?? BrokerAccount::create(['user_id' => $u->id, 'connector' => 'ea_bridge', 'label' => 'MetaTrader',
            'credentials_ciphertext' => null, 'enabled' => true, 'symbol_map' => []]);
        $key = EaController::link($b, $p);
        EaController::deliverKey($p['token'], $p, $key);
        AuditLog::record($u->id, $existing ? 'broker_relinked' : 'broker_added', 'broker_account', $b->id);
        $bus->publish('config.changed', ['entity' => 'broker_account', 'entity_id' => $b->id]);

        return ['broker' => $this->present($b)];
    }

    /** One-click setup: a one-line command for Mac or Windows that installs the EA, allows the address and links it. */
    public function installer(Request $r)
    {
        $data = $r->validate(['platform' => ['required', Rule::in(['mt4', 'mt5'])],
            'broker_id' => ['sometimes', 'nullable', 'string']]);
        $u = $r->user();
        $b = ! empty($data['broker_id'])
            ? BrokerAccount::where('id', $data['broker_id'])->where('user_id', $u->id)->where('connector', 'ea_bridge')->firstOrFail()
            : null;
        if (! $b) {
            $max = $u->billing?->plan?->max_accounts ?? 1;
            abort_if($u->brokerAccounts()->where('connector', '!=', 'paper')->where('enabled', true)->count() >= $max, 402,
                "Your plan allows {$max} broker account(s).");
            $b = BrokerAccount::create(['user_id' => $u->id, 'connector' => 'ea_bridge',
                'label' => ($data['platform'] === 'mt4' ? 'MetaTrader 4' : 'MetaTrader 5').' (installing)',
                'credentials_ciphertext' => null, 'enabled' => true, 'symbol_map' => ['_platform' => $data['platform']]]);
            AuditLog::record($u->id, 'broker_added', 'broker_account', $b->id);
        }
        $token = EaController::makeInstallToken($b, $data['platform']);
        $base = EaController::eaUrl($r)."/ea/install/{$token}";

        return ['broker' => $this->present($b),
            'mac' => "curl -fsSL {$base}.sh | bash",
            'windows' => "irm {$base}.ps1 | iex"];
    }

    /** Live help while pairing: the address the EA must reach, and whether a MetaTrader from here already reached it. */
    public function pairStatus(Request $r)
    {
        $seen = EaController::seenFrom((string) $r->ip());

        return ['ea_url' => EaController::eaUrl($r),
            'seen' => $seen && time() - $seen['at'] < 30 ? ['platform' => $seen['platform'], 'company' => $seen['company']] : null];
    }

    /** "Test connection" (and on demo accounts "Test order"): the engine runs it and writes each step. */
    public function test(Request $r, BrokerAccount $broker, Bus $bus)
    {
        abort_unless($broker->user_id === $r->user()->id && $broker->enabled, 404);
        $data = $r->validate(['order' => ['sometimes', 'boolean']]);
        $order = (bool) ($data['order'] ?? false);
        if ($order) {
            abort_unless(\Illuminate\Support\Facades\RateLimiter::attempt("test-order:{$broker->id}", 1, fn () => true, 60), 429,
                'One test order a minute, please.');
        }
        $id = Str::random(16);
        $bus->publish('config.changed', ['entity' => 'broker_test', 'entity_id' => $broker->id, 'test_id' => $id, 'order' => $order]);
        AuditLog::record($r->user()->id, $order ? 'broker_test_order' : 'broker_test', 'broker_account', $broker->id);

        return ['test_id' => $id];
    }

    public function testResult(Request $r, BrokerAccount $broker, string $test)
    {
        abort_unless($broker->user_id === $r->user()->id && ctype_alnum($test), 404);
        $raw = $this->bus->redis()->client()->get(config('pipstream.stream_prefix').":acct:{$broker->id}:test:{$test}");

        return ['test' => $raw ? json_decode($raw, true) : ['state' => 'queued', 'steps' => []]];
    }

    public function show(Request $r, BrokerAccount $broker)
    {
        abort_unless($broker->user_id === $r->user()->id, 404);

        return ['broker' => $this->present($broker)];
    }

    public function index(Request $r)
    {
        return ['brokers' => $r->user()->brokerAccounts()->where('enabled', true)->orderBy('created_at')->get()->map(fn ($b) => $this->present($b))];
    }

    public function store(Request $r, Bus $bus)
    {
        $data = $r->validate([
            'connector' => ['required', Rule::in(['ea_bridge', 'metaapi', 'mt5_bridge', 'deriv_options'])],
            'label' => ['nullable', 'string', 'max:60'],
            'platform' => ['required_if:connector,metaapi,ea_bridge', 'nullable', Rule::in(['mt4', 'mt5'])],
            'login' => ['required_if:connector,mt5_bridge,metaapi', 'nullable', 'string', 'max:40'],
            'password' => ['required_if:connector,mt5_bridge,metaapi', 'nullable', 'string', 'max:200'],
            'server' => ['required_if:connector,mt5_bridge,metaapi', 'nullable', 'string', 'max:120'],
            'token' => ['required_if:connector,deriv_options', 'nullable', 'string', 'max:200'],
        ]);
        $u = $r->user();
        // hosted MetaTrader costs us per account, so it's a paid-plan feature; the EA stays free
        abort_if($data['connector'] === 'metaapi' && ($u->billing?->plan?->id ?? 'trial') === 'trial', 402,
            'Hosted MetaTrader is part of Pro. Upgrade in Plan & billing, or use the free MetaTrader EA.');
        $max = $u->billing?->plan?->max_accounts ?? 1;
        abort_if($u->brokerAccounts()->where('connector', '!=', 'paper')->count() >= $max, 402,
            "Your plan allows {$max} broker account(s).");
        if ($data['connector'] === 'ea_bridge') {
            // No password at all: the EA in the customer's terminal logs in with this key.
            $key = 'psk_'.Str::random(40);
            $b = BrokerAccount::create(['user_id' => $u->id, 'connector' => 'ea_bridge',
                'label' => ($data['label'] ?? null) ?: ($data['platform'] === 'mt4' ? 'MetaTrader 4 (EA)' : 'MetaTrader 5 (EA)'),
                'credentials_ciphertext' => null, 'enabled' => true,
                'symbol_map' => ['_platform' => $data['platform'], '_ea_key_hash' => EaController::hashKey($key)]]);
            AuditLog::record($u->id, 'broker_added', 'broker_account', $b->id);
            $bus->publish('config.changed', ['entity' => 'broker_account', 'entity_id' => $b->id]);

            return ['broker' => $this->present($b), 'ea_key' => $key];
        }
        $mt = in_array($data['connector'], ['mt5_bridge', 'metaapi'], true);
        $creds = match ($data['connector']) {
            // MetaApi gets the password once; the engine then keeps only MetaApi's account id
            'metaapi' => ['login' => trim($data['login']), 'password' => $data['password'],
                'server' => trim($data['server']), 'platform' => $data['platform']],
            'mt5_bridge' => ['login' => $data['login'], 'password' => $data['password'], 'server' => $data['server']],
            default => ['token' => $data['token']],
        };
        $hint = $mt ? '•••'.substr($data['login'], -3) : '•••'.substr($data['token'], -4);
        $label = match ($data['connector']) {
            'metaapi' => ($data['platform'] === 'mt4' ? 'MetaTrader 4' : 'MetaTrader 5').' · '.trim($data['server']),
            'mt5_bridge' => 'MetaTrader 5',
            default => 'Deriv',
        };
        $map = ['_login_hint' => $hint];
        if ($data['connector'] === 'metaapi') {
            $map['_platform'] = $data['platform'];
        }
        $b = BrokerAccount::create(['user_id' => $u->id, 'connector' => $data['connector'],
            'label' => ($data['label'] ?? null) ?: $label,
            'credentials_ciphertext' => Vault::sealForExecutor($creds),
            'symbol_map' => $map, 'enabled' => true]);
        unset($creds, $data);
        AuditLog::record($u->id, 'broker_added', 'broker_account', $b->id);
        $bus->publish('config.changed', ['entity' => 'broker_account', 'entity_id' => $b->id]);

        return ['broker' => $this->present($b)];
    }

    public function destroy(Request $r, BrokerAccount $broker, Bus $bus)
    {
        abort_unless($broker->user_id === $r->user()->id && $broker->connector !== 'paper', 404);
        if ($old = $broker->symbol_map['_ea_key_hash'] ?? null) {
            Cache::forget("ea-key:{$old}");
        }
        $broker->update(['enabled' => false, 'credentials_ciphertext' => null]);
        $r->user()->copyRules()->where('broker_account_id', $broker->id)->update(['enabled' => false]);
        AuditLog::record($r->user()->id, 'broker_removed', 'broker_account', $broker->id);
        $bus->publish('config.changed', ['entity' => 'broker_account', 'entity_id' => $broker->id]);

        return ['ok' => true];
    }
}
