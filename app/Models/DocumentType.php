<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    protected $fillable = ['code', 'name', 'is_active', 'default_processing_days'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'default_processing_days' => 'integer'];
    }
}
