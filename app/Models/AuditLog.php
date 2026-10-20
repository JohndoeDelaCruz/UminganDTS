<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit history is append-only.'));
        static::deleting(fn () => throw new LogicException('Audit history cannot be deleted.'));
    }

    public static function record(?User $actor, string $action, Model $resource, array $metadata = []): void
    {
        static::create([
            'actor_user_id' => $actor?->id, 'action' => $action,
            'resource_type' => class_basename($resource), 'resource_id' => (string) $resource->getKey(),
            'metadata' => $metadata, 'created_at' => now(),
        ]);
    }
}
