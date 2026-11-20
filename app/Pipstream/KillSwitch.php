<?php

namespace App\Pipstream;

use App\Models\AuditLog;

/** Global and per-user pause. Read by the engine's risk worker AND executor before every trade. */
class KillSwitch
{
    public function __construct(private Bus $bus) {}

    private function key(?string $userId): string
    {
        $p = config('pipstream.stream_prefix');

        return $userId ? "{$p}:killswitch:user:{$userId}" : "{$p}:killswitch:global";
    }

    public function isOn(?string $userId = null): bool
    {
        return $this->bus->redis()->client()->get($this->key($userId)) === '1';
    }

    public function set(bool $on, ?string $userId, string $actor): void
    {
        $this->bus->redis()->client()->set($this->key($userId), $on ? '1' : '0');
        AuditLog::record($actor, $on ? 'kill_switch_on' : 'kill_switch_off', $userId ? 'user' : 'global', $userId);
        $this->bus->publish('config.changed', ['entity' => 'kill_switch', 'entity_id' => $userId]);
    }
}
