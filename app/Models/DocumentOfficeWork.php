<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentOfficeWork extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_fill_keys(['assigned_at', 'started_at', 'finished_at', 'closed_at'], 'datetime');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
