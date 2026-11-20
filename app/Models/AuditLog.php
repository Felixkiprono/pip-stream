<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only (a database trigger refuses UPDATE and DELETE). */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['detail' => 'array', 'at' => 'datetime'];

    public static function record(string $actor, string $action, ?string $entity = null, ?string $entityId = null, array $detail = []): void
    {
        static::query()->insert(['actor' => $actor, 'action' => $action, 'entity' => $entity,
            'entity_id' => $entityId, 'detail' => json_encode((object) $detail)]);
    }
}
