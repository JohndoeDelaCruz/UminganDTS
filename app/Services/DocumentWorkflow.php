<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DocumentWorkflow
{
    public function register(User $actor, array $data): Document
    {
        Gate::forUser($actor)->authorize('create', Document::class);

        return DB::transaction(function () use ($actor, $data) {
            $type = DocumentType::where('is_active', true)->findOrFail($data['document_type_id']);
            $received = Carbon::parse($data['received_at'], 'Asia/Manila')->utc();
            $year = (int) $received->copy()->timezone('Asia/Manila')->year;
            DB::table('tracking_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
            $sequence = DB::table('tracking_sequences')->where('year', $year)->lockForUpdate()->first();
            $number = $sequence->last_number + 1;
            DB::table('tracking_sequences')->where('year', $year)->update(['last_number' => $number]);
            $document = Document::create([
                ...$data, 'tracking_number' => sprintf('UMG-%d-%06d', $year, $number),
                'received_at' => $received, 'due_at' => $type->default_processing_days !== null ? $received->copy()->addDays($type->default_processing_days) : null,
                'source_department_id' => $actor->department_id, 'current_department_id' => $actor->department_id,
                'created_by' => $actor->id, 'status' => DocumentStatus::Received,
                'public_tracking_enabled' => false,
            ]);
            $this->event($document, $actor, 'registered', null, DocumentStatus::Received->value, null, $actor->department_id);
            if ($document->confidentiality === 'internal') {
                app(PublicTracking::class)->issue($actor, $document);
            }

            return $document->refresh();
        }, 3);
    }

    public function forward(User $actor, Document $record, int $destination, ?string $remarks): void
    {
        $this->send($actor, $record, $destination, $remarks, 'forward');
    }

    public function returnForCorrection(User $actor, Document $record, int $destination, string $reason): void
    {
        $this->send($actor, $record, $destination, trim($reason), 'return');
    }

    private function send(User $actor, Document $record, int $destination, ?string $remarks, string $handoffType): void
    {
        DB::transaction(function () use ($actor, $record, $destination, $remarks, $handoffType) {
            $document = Document::lockForUpdate()->findOrFail($record->id);
            Gate::forUser($actor)->authorize('update', $document);
            $this->assertNoPending($document);
            if (! in_array($document->status, [DocumentStatus::Received, DocumentStatus::Processing, DocumentStatus::Returned], true)) {
                $this->fail('status', 'This document must be active and off hold before sending a handoff.');
            }
            if ($handoffType === 'return' && ! $remarks) {
                $this->fail('reason', 'Explain the corrections needed before returning the document.');
            }
            if ($handoffType === 'forward') {
                app(OfficeWork::class)->assertFinished($document);
            }
            Department::where('is_active', true)->lockForUpdate()->findOrFail($destination);
            if ($destination === $document->current_department_id) {
                $this->fail('to_department_id', 'Choose a different destination office.');
            }
            $previous = $document->status->value;
            $transfer = $document->transfers()->create([
                'from_department_id' => $document->current_department_id, 'to_department_id' => $destination,
                'sent_by' => $actor->id, 'sent_at' => now(), 'status' => 'pending',
                'previous_status' => $previous, 'routing_remarks' => $remarks, 'handoff_type' => $handoffType,
            ]);
            $document->update(['status' => DocumentStatus::Awaiting]);
            $event = $this->event($document, $actor, $handoffType === 'return' ? 'return_requested' : 'forwarded', $previous, DocumentStatus::Awaiting->value, $document->current_department_id, $destination, ['remarks' => $remarks, 'handoff_type' => $handoffType]);
            app(StaffNotifications::class)->handoff($actor, $document, $transfer, $event);
        }, 3);
    }

    public function resolveTransfer(User $actor, Document $record, int $transferId, string $action, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $record, $transferId, $action, $reason) {
            $document = Document::lockForUpdate()->findOrFail($record->id);
            $transfer = $document->transfers()->lockForUpdate()->findOrFail($transferId);
            $office = $action === 'cancel' ? $transfer->from_department_id : $transfer->to_department_id;
            abort_unless($actor->is_active && $actor->department_id === $office && $actor->department?->is_active, 403);
            if ($transfer->status !== 'pending') {
                $this->fail('transfer', 'This handoff has already been resolved. Refresh to see its current state.');
            }
            if ($document->current_department_id !== $transfer->from_department_id || $document->status !== DocumentStatus::Awaiting) {
                $this->fail('transfer', 'This handoff is no longer the current pending movement.');
            }
            if ($action !== 'acknowledge' && ! $reason) {
                $this->fail('reason', 'A reason is required.');
            }
            $old = $document->status->value;
            $update = ['status' => $action === 'acknowledge' ? 'acknowledged' : ($action === 'reject' ? 'rejected' : 'cancelled'), 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_reason' => $reason];
            if ($action === 'acknowledge') {
                app(StaffNotifications::class)->resolveCorrections($document);
                app(OfficeWork::class)->close($document, 'transferred', true);
                $update += ['acknowledged_by' => $actor->id, 'acknowledged_at' => now()];
                $document->update(['current_department_id' => $transfer->to_department_id, 'status' => $transfer->handoff_type === 'return' ? DocumentStatus::Returned : DocumentStatus::Processing]);
            } else {
                $document->update(['status' => $transfer->previous_status]);
            }
            $transfer->update($update);
            if ($action !== 'acknowledge' || $transfer->handoff_type !== 'return') {
                app(StaffNotifications::class)->resolveTransfer($transfer);
            }
            $eventType = $transfer->handoff_type === 'return' ? match ($action) {
                'acknowledge' => 'return_acknowledged',
                'reject' => 'return_rejected',
                default => 'return_cancelled',
            } : 'transfer_'.$action;
            $this->event($document, $actor, $eventType, $old, $document->status->value, $transfer->from_department_id, $transfer->to_department_id, ['remarks' => $reason, 'handoff_type' => $transfer->handoff_type]);
        }, 3);
    }

    public function transition(User $actor, Document $record, string $status, ?string $reason, ?string $method): void
    {
        $reason = trim($reason ?? '') ?: null;
        $method = trim($method ?? '') ?: null;
        DB::transaction(function () use ($actor, $record, $status, $reason, $method) {
            $document = Document::lockForUpdate()->findOrFail($record->id);
            Gate::forUser($actor)->authorize('update', $document);
            $this->assertNoPending($document);
            $next = DocumentStatus::from($status);
            if ($next === DocumentStatus::Returned) {
                $this->fail('status', 'Use Return for correction and select the responsible office.');
            }
            $reopen = $document->status->terminal() && $next === DocumentStatus::Processing && $actor->isAdmin();
            if (! $reopen && ! in_array($next, $document->status->next(), true)) {
                $this->fail('status', 'This status transition is not allowed.');
            }
            if (($reopen || in_array($next, [DocumentStatus::Hold, DocumentStatus::Returned, DocumentStatus::Cancelled], true)) && ! $reason) {
                $this->fail('reason', 'A reason is required for this action.');
            }
            if ($next === DocumentStatus::Released && ! $method) {
                $this->fail('release_method', 'Record how the document was released.');
            }
            if ($next === DocumentStatus::Completed) {
                app(OfficeWork::class)->assertFinished($document);
                app(OfficeWork::class)->close($document, 'completed');
            } elseif ($next === DocumentStatus::Cancelled) {
                app(OfficeWork::class)->close($document, 'cancelled');
            } elseif ($reopen) {
                app(OfficeWork::class)->close($document, 'reopened', true);
            }
            $old = $document->status->value;
            $updates = ['status' => $next];
            if ($next === DocumentStatus::Completed) {
                $updates['completed_at'] = now();
            }
            if ($next === DocumentStatus::Released) {
                $updates += ['released_at' => now(), 'release_method' => $method];
            }
            if ($reopen) {
                $updates += ['completed_at' => null, 'released_at' => null, 'release_method' => null];
            }
            $document->update($updates);
            if ($next->terminal()) {
                app(StaffNotifications::class)->resolveDocument($document);
            } elseif ($old === DocumentStatus::Returned->value) {
                app(StaffNotifications::class)->resolveCorrections($document);
            }
            $summary = match ($next) {
                DocumentStatus::Processing => $reopen ? 'Processing reopened.' : (in_array($old, ['on_hold', 'returned'], true) ? 'Processing resumed.' : 'Processing started.'),
                DocumentStatus::Hold => 'Processing put on hold.',
                DocumentStatus::Completed => 'Processing completed.',
                DocumentStatus::Released => 'Document released.',
                DocumentStatus::Cancelled => 'Document cancelled.',
                default => 'Document status updated.',
            };
            $this->event($document, $actor, $reopen ? 'reopened' : 'status_changed', $old, $next->value, $document->current_department_id, $document->current_department_id, ['summary' => $summary, 'remarks' => $reason, 'release_method' => $method]);
        }, 3);
    }

    public function remark(User $actor, Document $record, string $remarks): void
    {
        DB::transaction(function () use ($actor, $record, $remarks) {
            $document = Document::lockForUpdate()->findOrFail($record->id);
            Gate::forUser($actor)->authorize('update', $document);
            $this->assertNoPending($document);
            if ($document->status->terminal()) {
                $this->fail('remarks', 'Reopen this document before adding a processing note.');
            }
            $this->event($document, $actor, 'remark_added', null, null, $document->current_department_id, null, ['remarks' => $remarks]);
            $document->touch();
        }, 3);
    }

    public function event(Document $document, User $actor, string $type, ?string $from, ?string $to, ?int $origin = null, ?int $destination = null, array $details = []): DocumentEvent
    {
        $event = $document->events()->create([
            'actor_user_id' => $actor->id, 'event_type' => $type, 'from_status' => $from, 'to_status' => $to,
            'from_department_id' => $origin, 'to_department_id' => $destination, 'details' => $details, 'occurred_at' => now(),
        ]);
        AuditLog::record($actor, 'document.'.$type, $document, ['from_status' => $from, 'to_status' => $to]);

        return $event;
    }

    private function assertNoPending(Document $document): void
    {
        if ($document->transfers()->where('status', 'pending')->exists()) {
            $this->fail('transfer', 'Resolve the pending handoff before changing this document.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
