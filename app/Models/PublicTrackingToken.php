<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicTrackingToken extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return ['token_encrypted' => 'encrypted', 'issued_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
