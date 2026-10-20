<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentOfficeWork;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OfficeWork
{
    public const TYPES = ['review' => 'Review document', 'check_requirements' => 'Check requirements', 'prepare_response' => 'Prepare response'];

    public const RESULTS = ['reviewed' => 'Review completed', 'verified' => 'Requirements verified', 'prepared' => 'Response prepared'];

    public function act(User $actor, Document $record, array $data): void
    {
        DB::transaction(function () use ($actor, $record, $data) {
            $document = Document::lockForUpdate()->findOrFail($record->id);
            Gate::forUser($actor)->authorize('update', $document);
            if ($document->transfers()->where('status', 'pending')->exists()) {
                $this->fail('Resolve the pending handoff before changing office work.');
            }
            if ($document->status->terminal()) {
                $this->fail('Reopen the document before adding office work.');
            }
            if ((int) $document->office_work_revision !== (int) $data['revision']) {
                $this->fail('Office work has changed. Refresh the page before trying again.');
            }
            $work = $document->currentOfficeWork;
            $action = $data['action'];
            $workflow = app(DocumentWorkflow::class);
            if (in_array($action, ['claim', 'assign'], true)) {
                $handler = $this->handler($action === 'claim' ? $actor->id : (int) $data['staff_id'], $document);
                if (! $work || $work->status === 'finished') {
                    $work = $this->newWork($document, $actor, $handler);
                } else {
                    $work->update(['assigned_to' => $handler->id, 'assigned_by' => $actor->id, 'assigned_at' => now()]);
                }
                $event = $workflow->event($document, $actor, 'office_work_assigned', null, null, $document->current_department_id, null,
                    ['summary' => 'Office work assigned to '.$handler->name.'.', 'office_work_id' => $work->id, 'handler_id' => $handler->id]);
                app(StaffNotifications::class)->assigned($actor, $document, $work, $event);
            } else {
                if ($document->status === DocumentStatus::Hold) {
                    $this->fail('Resume processing before starting or finishing office work.');
                }
                if (! $work) {
                    $this->fail('Claim this document or assign a handler first.');
                }
                $handler = $this->handler($work->assigned_to, $document);
                if ($action === 'start') {
                    if ($work->status === 'finished') {
                        $work = $this->newWork($document, $actor, $handler);
                    } elseif ($work->status !== 'assigned') {
                        $this->fail('This office task has already started.');
                    }
                    if (in_array($document->status, [DocumentStatus::Received, DocumentStatus::Returned], true)) {
                        $workflow->transition($actor, $document, DocumentStatus::Processing->value, null, null);
                    }
                    $work->update(['status' => 'in_progress', 'work_type' => $data['work_type'], 'started_at' => now()]);
                    $workflow->event($document, $actor, 'office_work_started', null, null, $document->current_department_id, null,
                        ['summary' => 'Office work started: '.self::TYPES[$work->work_type].'.', 'office_work_id' => $work->id, 'handler_id' => $handler->id]);
                } elseif ($action === 'finish') {
                    if ($work->status !== 'in_progress') {
                        $this->fail('Start this office task before finishing it.');
                    }
                    $work->update(['status' => 'finished', 'finished_at' => now(), 'review_result' => $data['review_result'], 'remarks' => trim($data['remarks'] ?? '') ?: null]);
                    app(StaffNotifications::class)->resolveWork($work);
                    $workflow->event($document, $actor, 'office_work_finished', null, null, $document->current_department_id, null,
                        ['summary' => 'Office work finished: '.self::RESULTS[$work->review_result].'.', 'remarks' => $work->remarks, 'office_work_id' => $work->id, 'handler_id' => $handler->id]);
                }
            }
            $document->update(['office_work_revision' => $document->office_work_revision + 1]);
        }, 3);
    }

    // Called only inside a workflow transaction with the document locked.
    public function close(Document $document, string $reason, bool $clear = false): void
    {
        $work = $document->currentOfficeWork;
        if ($work) {
            app(StaffNotifications::class)->resolveWork($work);
        }
        if ($work && ! $work->closed_at) {
            $work->update(['status' => $work->status === 'finished' ? 'finished' : 'closed', 'closed_at' => now(), 'closed_reason' => $reason]);
        }
        if ($clear || $work) {
            $document->update(['current_office_work_id' => $clear ? null : $document->current_office_work_id, 'office_work_revision' => $document->office_work_revision + 1]);
            $document->unsetRelation('currentOfficeWork');
        }
    }

    public function assertFinished(Document $document): void
    {
        if ($document->currentOfficeWork && $document->currentOfficeWork->status !== 'finished') {
            $this->fail('Finish the current office task before forwarding or completing the document. Use Return for correction if corrections are needed.');
        }
    }

    private function handler(int $id, Document $document): User
    {
        $handler = User::whereKey($id)->where('is_active', true)->where('department_id', $document->current_department_id)
            ->whereHas('department', fn ($q) => $q->where('is_active', true))->lockForUpdate()->first();
        if (! $handler) {
            $this->fail('Choose an active handler from the current office. Reassign work if the previous handler has moved or is inactive.');
        }

        return $handler;
    }

    private function newWork(Document $document, User $actor, User $handler): DocumentOfficeWork
    {
        $work = $document->officeWorks()->create([
            'department_id' => $document->current_department_id,
            'incoming_transfer_id' => $document->transfers()->where('status', 'acknowledged')->where('to_department_id', $document->current_department_id)->latest('id')->value('id'),
            'assigned_to' => $handler->id, 'assigned_by' => $actor->id, 'assigned_at' => now(),
        ]);
        $document->current_office_work_id = $work->id;
        $document->setRelation('currentOfficeWork', $work);

        return $work;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['office_work' => $message]);
    }
}
