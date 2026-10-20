<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WorkspaceActivity
{
    public function limit(string $section): int
    {
        return (int) DB::table($section === 'inbox' ? 'document_transfers' : 'document_events')->max('id');
    }

    public function viewed(User $user, string $section, int $limit): void
    {
        DB::table('workspace_reads')->upsert([
            'user_id' => $user->id, 'office_id' => $this->officeId($user),
            'section' => $section, 'last_seen_id' => $limit,
        ], ['user_id', 'office_id', 'section'], [
            'last_seen_id' => DB::raw('GREATEST(last_seen_id, VALUES(last_seen_id))'),
        ]);
    }

    public function counts(User $user): array
    {
        $office = $this->officeId($user);
        $seen = DB::table('workspace_reads')->where('user_id', $user->id)->where('office_id', $office)->pluck('last_seen_id', 'section');
        $documents = DB::table('document_events')->where('id', '>', $seen['documents'] ?? 0)
            ->where('actor_user_id', '!=', $user->id)
            ->whereIn('document_id', Document::visibleTo($user)->select('id'))
            ->distinct()->count('document_id');

        if (! $office) {
            return ['documents' => $documents, 'inbox' => 0, 'outgoing' => 0];
        }

        // New arrivals remain useful in either pending or acknowledged incoming history.
        $incoming = DB::table('document_transfers')->where('to_department_id', $office)
            ->where('id', '>', $seen['inbox'] ?? 0)->where('sent_by', '!=', $user->id)
            ->whereIn('status', ['pending', 'acknowledged'])->count();
        // Former custodians can see their own handoff outcomes, without document details.
        $outgoing = DB::table('document_events')->where('from_department_id', $office)
            ->where('id', '>', $seen['outgoing'] ?? 0)->where('actor_user_id', '!=', $user->id)
            ->whereIn('event_type', ['forwarded', 'return_requested', 'transfer_acknowledge', 'transfer_reject', 'transfer_cancel', 'return_acknowledged', 'return_rejected', 'return_cancelled'])
            ->distinct()->count('document_id');

        return ['documents' => $documents, 'inbox' => $incoming, 'outgoing' => $outgoing];
    }

    private function officeId(User $user): int
    {
        return $user->department?->is_active ? $user->department_id : 0;
    }
}
