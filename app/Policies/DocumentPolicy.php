<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $user->is_active && Document::visibleTo($user)->whereKey($document->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->department_id && $user->department?->is_active;
    }

    public function update(User $user, Document $document): bool
    {
        return $this->create($user) && $document->current_department_id === $user->department_id;
    }

    public function managePublicTracking(User $user, Document $document): bool
    {
        return $this->update($user, $document) && $document->confidentiality === 'internal';
    }
}
