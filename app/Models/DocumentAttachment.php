<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAttachment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['storage_path_private'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
