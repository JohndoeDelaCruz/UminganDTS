<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentOfficeWork;
use App\Models\DocumentTransfer;
use App\Models\StaffNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StaffNotifications
{
    public function assigned(User $actor, Document $document, DocumentOfficeWork $work, DocumentEvent $event): void
    {
        if (StaffNotification::where('event_id', $event->id)->exists()) {
            return;
        }
        // Reassignment retires earlier alerts, including when the handler later returns to this task.
        $this->resolveWork($work);
        if ($work->assigned_to !== $actor->id) {
            $this->create([$work->assigned_to], $document, $document->current_department_id, $event,
                ['kind' => 'assigned', 'office_work_id' => $work->id]);
        }
    }

    public function handoff(User $actor, Document $document, DocumentTransfer $transfer, DocumentEvent $event): void
    {
        $recipients = User::where('department_id', $transfer->to_department_id)->where('is_active', true)->whereKeyNot($actor->id)->pluck('id')->all();
        $this->create($recipients, $document, $transfer->to_department_id, $event,
            ['kind' => $transfer->handoff_type === 'return' ? 'returned' : 'incoming', 'transfer_id' => $transfer->id]);
    }

    private function create(array $recipients, Document $document, int $office, DocumentEvent $event, array $source): void
    {
        if (! $recipients) {
            return;
        }
        DB::table('staff_notifications')->insertOrIgnore(array_map(fn ($userId) => [
            'user_id' => $userId, 'department_id' => $office, 'document_id' => $document->id,
            'event_id' => $event->id, 'created_at' => $event->occurred_at->toDateTimeString(), ...$source,
        ], $recipients));
    }

    public function resolveWork(DocumentOfficeWork $work): void
    {
        StaffNotification::where('office_work_id', $work->id)->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    public function resolveTransfer(DocumentTransfer $transfer): void
    {
        StaffNotification::where('transfer_id', $transfer->id)->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    public function resolveCorrections(Document $document): void
    {
        StaffNotification::where('document_id', $document->id)->where('department_id', $document->current_department_id)
            ->where('kind', 'returned')->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    public function resolveDocument(Document $document): void
    {
        StaffNotification::where('document_id', $document->id)->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    public function available(User $user): Builder
    {
        $query = StaffNotification::where('user_id', $user->id)->where('department_id', $user->department_id)->whereNull('resolved_at');
        if (! $user->is_active || ! $user->department_id || ! $user->department?->is_active) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('document', fn ($q) => $q->visibleTo($user))
            ->where(function ($q) use ($user) {
                $q->where(function ($assignment) use ($user) {
                    $assignment->where('kind', 'assigned')->whereHas('document', function ($record) use ($user) {
                        $record->where('current_department_id', $user->department_id)
                            ->whereIn('status', ['received', 'in_progress', 'on_hold', 'returned'])
                            ->whereHas('currentOfficeWork', fn ($work) => $work->whereColumn('document_office_works.id', 'staff_notifications.office_work_id')
                                ->where('assigned_to', $user->id)->whereIn('status', ['assigned', 'in_progress'])->whereNull('closed_at'));
                    });
                })->orWhere(function ($movement) use ($user) {
                    $movement->whereIn('kind', ['incoming', 'returned'])->whereHas('transfer', function ($transfer) use ($user) {
                        $transfer->where('to_department_id', $user->department_id)->where(function ($state) {
                            $state->where('status', 'pending')->orWhere(function ($correction) {
                                $correction->where('handoff_type', 'return')->where('status', 'acknowledged')
                                    ->whereHas('document', fn ($record) => $record->where('status', 'returned')->whereColumn('documents.current_department_id', 'staff_notifications.department_id'));
                            });
                        });
                    });
                });
            });
    }

    public function feed(User $user, int $page = 1): array
    {
        $query = $this->available($user);
        $unread = (clone $query)->whereNull('read_at')->count();
        $through = (int) (clone $query)->max('id');
        $total = (clone $query)->count();
        $last = max(1, (int) ceil($total / 10));
        $page = min($page, $last);
        $items = $query->with(['document:id,tracking_number', 'transfer:id,status'])->latest('id')->offset(($page - 1) * 10)->limit(10)->get()
            ->map(fn (StaffNotification $notification) => [
                'id' => $notification->id, 'kind' => $notification->kind,
                'reference' => $notification->document->tracking_number,
                'title' => match ($notification->kind) {
                    'assigned' => 'Assigned to you', 'returned' => 'Return for correction', default => 'Incoming document',
                },
                'message' => match ($notification->kind) {
                    'assigned' => 'Review and finish your assigned office task.',
                    'returned' => $notification->transfer->status === 'pending'
                        ? 'Acknowledge the return, then review the requested corrections.' : 'Review the requested corrections and resume processing.',
                    default => 'Acknowledge receipt for your office.',
                },
                'created_at' => $notification->created_at->toIso8601String(), 'read_at' => $notification->read_at?->toIso8601String(),
                'open_url' => route('notifications.open', $notification, false),
            ])->all();

        return ['items' => $items, 'unread' => $unread, 'through_id' => $through, 'page' => $page, 'last_page' => $last, 'total' => $total];
    }

    public function markRead(User $user, int $through): void
    {
        DB::transaction(fn () => $this->available($user)->where('id', '<=', $through)->whereNull('read_at')->update(['read_at' => now()]), 3);
    }

    public function open(User $user, int $id): ?Document
    {
        // Lock in the same order as workflow actions, so custody cannot change during this check.
        $own = StaffNotification::where('user_id', $user->id)->where('department_id', $user->department_id)->findOrFail($id);

        return DB::transaction(function () use ($user, $own) {
            $document = Document::lockForUpdate()->findOrFail($own->document_id);
            $notification = $this->available($user)->whereKey($own->id)->lockForUpdate()->first();
            if (! $notification) {
                return null;
            }
            Gate::forUser($user)->authorize('view', $document);
            if (! $notification->read_at) {
                $notification->update(['read_at' => now()]);
            }

            return $document;
        }, 3);
    }
}
