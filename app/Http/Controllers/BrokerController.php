<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BrokerAccount;
use App\Pipstream\Bus;
use App\Pipstream\Vault;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Broker passwords are sealed to the engine's executor key the moment they arrive and are
 *  never readable again by Laravel, the API or the app. */
class BrokerController extends Controller
{
    public function __construct(private Bus $bus) {}

    private function present(BrokerAccount $b): array
    {
        $status = null;
        if ($b->connector === 'metaapi') {
            // written by the engine while it registers and starts the MetaTrader terminal
            $raw = rescue(fn () => $this->bus->redis()->client()->get(config('pipstream.stream_prefix').":acct:{$b->id}:status"), null, false);
            $status = $raw ? json_decode($raw, true) : ['state' => 'connecting', 'detail' => 'Waiting for the trading engine'];
        }

        return ['id' => $b->id, 'connector' => $b->connector, 'label' => $b->label, 'enabled' => $b->enabled,
            'connected' => $b->connector === 'paper' || ($status ? $status['state'] === 'connected' : $b->hasCredentials()),
            'status' => $status, 'platform' => $b->symbol_map['_platform'] ?? null,
            'login_hint' => $b->symbol_map['_login_hint'] ?? null];
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
            'connector' => ['required', Rule::in(['metaapi', 'mt5_bridge', 'deriv_options'])],
            'label' => ['nullable', 'string', 'max:60'],
            'platform' => ['required_if:connector,metaapi', 'nullable', Rule::in(['mt4', 'mt5'])],
            'login' => ['required_if:connector,mt5_bridge,metaapi', 'nullable', 'string', 'max:40'],
            'password' => ['required_if:connector,mt5_bridge,metaapi', 'nullable', 'string', 'max:200'],
            'server' => ['required_if:connector,mt5_bridge,metaapi', 'nullable', 'string', 'max:120'],
            'token' => ['required_if:connector,deriv_options', 'nullable', 'string', 'max:200'],
        ]);
        $u = $r->user();
        $max = $u->billing?->plan?->max_accounts ?? 1;
        abort_if($u->brokerAccounts()->where('connector', '!=', 'paper')->count() >= $max, 402,
            "Your plan allows {$max} broker account(s).");
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
        $broker->update(['enabled' => false, 'credentials_ciphertext' => null]);
        $r->user()->copyRules()->where('broker_account_id', $broker->id)->update(['enabled' => false]);
        AuditLog::record($r->user()->id, 'broker_removed', 'broker_account', $broker->id);
        $bus->publish('config.changed', ['entity' => 'broker_account', 'entity_id' => $broker->id]);

        return ['ok' => true];
    }
}
