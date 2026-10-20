<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['contact_private'];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'contact_private' => 'encrypted',
            'public_tracking_enabled' => 'boolean',
            'received_at' => 'datetime', 'due_at' => 'datetime',
            'completed_at' => 'datetime', 'released_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'current_department_id');
    }

    public function sourceDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'source_department_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(DocumentTransfer::class);
    }

    public function currentOfficeWork(): BelongsTo
    {
        return $this->belongsTo(DocumentOfficeWork::class, 'current_office_work_id');
    }

    public function officeWorks(): HasMany
    {
        return $this->hasMany(DocumentOfficeWork::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PublicTrackingToken::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $q) use ($user) {
            if ($user->isAdmin()) {
                $q->where('confidentiality', 'internal');
            } else {
                $q->whereRaw('1 = 0');
            }
            if ($user->department_id && $user->department?->is_active) {
                $q->orWhere('current_department_id', $user->department_id)
                    ->orWhereHas('transfers', fn (Builder $t) => $t->where('to_department_id', $user->department_id)->where('status', 'pending'));
            }
        });
    }
}
