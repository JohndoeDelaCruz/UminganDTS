<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PublicTracking
{
    public function timeline(Document $document): array
    {
        // Read only progress fields: free-text details and staff/office data never enter this projection.
        return $document->events()->whereIn('event_type', [
            'registered', 'status_changed', 'reopened', 'forwarded', 'return_requested',
            'transfer_acknowledge', 'transfer_reject', 'transfer_cancel',
            'return_acknowledged', 'return_rejected', 'return_cancelled',
        ])->whereNotNull('to_status')->get(['event_type', 'from_status', 'to_status', 'occurred_at'])
            ->map(function (DocumentEvent $event) {
                $status = DocumentStatus::from($event->to_status);

                return ['title' => $this->timelineTitle($event, $status), 'status' => $status->publicLabel(), 'at' => $event->occurred_at->toIso8601String()];
            })->all();
    }

    private function timelineTitle(DocumentEvent $event, DocumentStatus $status): string
    {
        if (in_array($event->event_type, ['transfer_reject', 'transfer_cancel', 'return_rejected', 'return_cancelled'], true)) {
            return 'Processing status updated';
        }

        return match ($status) {
            DocumentStatus::Received => 'Document received',
            DocumentStatus::Awaiting => $event->event_type === 'return_requested' ? 'Return for correction sent' : 'Document forwarded',
            DocumentStatus::Processing => match (true) {
                $event->event_type === 'reopened' => 'Processing reopened',
                in_array($event->from_status, ['on_hold', 'returned'], true) => 'Processing resumed',
                default => 'Processing started',
            },
            DocumentStatus::Hold => 'Processing on hold',
            DocumentStatus::Returned => 'Returned for correction',
            DocumentStatus::Completed => 'Ready for release',
            DocumentStatus::Released => 'Document released',
            DocumentStatus::Cancelled => 'Document closed',
        };
    }

    public function issue(User $actor, Document $record): string
    {
        return DB::transaction(function () use ($actor, $record) {
            $document = Document::lockForUpdate()->findOrFail($record->id);
            Gate::forUser($actor)->authorize('managePublicTracking', $document);
            $document->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $secret = $this->newCode();
                try {
                    $document->tokens()->create(['token_hash' => hash('sha256', $secret), 'token_encrypted' => $secret, 'issued_at' => now()]);
                    break;
                } catch (UniqueConstraintViolationException $e) {
                    if ($attempt === 4) {
                        throw $e;
                    }
                }
            }
            $document->update(['public_tracking_enabled' => true]);
            app(DocumentWorkflow::class)->event($document, $actor, 'public_credential_issued', null, null);

            return $secret;
        }, 3);
    }

    protected function newCode(): string
    {
        // 40 random bits; omit 0, 1, I and O to make receipt codes easier to read.
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, 31)];
        }

        return $code;
    }

    public static function normalize(string $code): string
    {
        $code = preg_replace('/[\s-]+/', '', trim($code));

        // Original 64-character hexadecimal credentials remain valid.
        return strlen($code) === 64 && ctype_xdigit($code) ? strtolower($code) : strtoupper($code);
    }

    public function receiptCredential(User $actor, Document $document): ?array
    {
        if (! $document->public_tracking_enabled || ! Gate::forUser($actor)->allows('managePublicTracking', $document)) {
            return null;
        }
        $token = $document->tokens()->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereNotNull('token_encrypted')->latest('id')->first();

        return $token ? ['reference' => $document->tracking_number, 'secret' => $token->token_encrypted] : null;
    }

    public function revoke(User $actor, Document $record): void
    {
        DB::transaction(function () use ($actor, $record) {
            $document = Document::lockForUpdate()->findOrFail($record->id);
            Gate::forUser($actor)->authorize('update', $document);
            $document->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $document->update(['public_tracking_enabled' => false]);
            app(DocumentWorkflow::class)->event($document, $actor, 'public_credential_revoked', null, null);
        }, 3);
    }
}
