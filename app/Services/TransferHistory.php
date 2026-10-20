<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentTransfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TransferHistory
{
    public function page(Request $request, string $mode): array
    {
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['pending', 'received'])],
            'search' => ['nullable', 'string', 'max:150'],
            'office_id' => ['nullable', 'integer', 'exists:departments,id'],
            'transfer_status' => ['nullable', Rule::in(['pending', 'acknowledged', 'rejected', 'cancelled'])],
            'handoff_type' => ['nullable', Rule::in(['forward', 'return'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $actor = $request->user();
        $officeId = $actor->department?->is_active ? $actor->department_id : 0;
        $incoming = $mode === 'inbox';
        $view = $incoming ? ($filters['view'] ?? 'pending') : 'sent';
        $query = DocumentTransfer::query()->where($incoming ? 'to_department_id' : 'from_department_id', $officeId);
        if ($incoming) {
            $query->where('status', $view === 'received' ? 'acknowledged' : 'pending');
        } elseif ($filters['transfer_status'] ?? null) {
            $query->where('status', $filters['transfer_status']);
        }
        if ($filters['handoff_type'] ?? null) {
            $query->where('handoff_type', $filters['handoff_type']);
        }
        if ($filters['office_id'] ?? null) {
            $query->where($incoming ? 'from_department_id' : 'to_department_id', $filters['office_id']);
        }
        if ($text = $filters['search'] ?? null) {
            $text = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
            // Former custodians may search their historical reference, but cannot infer private subjects.
            $query->whereHas('document', fn (Builder $document) => $document->where(function (Builder $q) use ($actor, $text) {
                $q->whereRaw("tracking_number LIKE ? ESCAPE '!'", [$text])
                    ->orWhere(fn (Builder $visible) => $visible->visibleTo($actor)->whereRaw("subject LIKE ? ESCAPE '!'", [$text]));
            }));
        }
        $dateColumn = $view === 'received' ? 'acknowledged_at' : 'sent_at';
        if ($filters['from'] ?? null) {
            $query->where($dateColumn, '>=', Carbon::parse($filters['from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if ($filters['to'] ?? null) {
            $query->where($dateColumn, '<=', Carbon::parse($filters['to'], 'Asia/Manila')->endOfDay()->utc());
        }
        $transfers = $query->select(['id', 'document_id', 'from_department_id', 'to_department_id', 'handoff_type', 'status', 'sent_at', 'acknowledged_at', 'resolved_at'])
            ->with(['document:id,tracking_number,subject', 'fromDepartment:id,name', 'toDepartment:id,name'])
            ->orderByDesc($dateColumn)->orderByDesc('id')->paginate(15)->withQueryString();
        $visible = Document::visibleTo($actor)->whereIn('id', $transfers->getCollection()->pluck('document_id'))->pluck('id')->flip();
        $transfers->through(function (DocumentTransfer $transfer) use ($visible) {
            $canOpen = $visible->has($transfer->document_id);

            // Explicit allowlist: never serialize the related document or transfer remarks into history.
            return [
                'id' => $transfer->id, 'reference' => $transfer->document->tracking_number,
                'document_id' => $canOpen ? $transfer->document_id : null,
                'subject' => $canOpen ? $transfer->document->subject : null, 'can_open' => $canOpen,
                'from_office' => $transfer->fromDepartment->name, 'to_office' => $transfer->toDepartment->name,
                'handoff_type' => $transfer->handoff_type, 'status' => $transfer->status,
                'sent_at' => $transfer->sent_at->toIso8601String(),
                'acknowledged_at' => $transfer->acknowledged_at?->toIso8601String(),
                'resolved_at' => $transfer->resolved_at?->toIso8601String(),
            ];
        });
        $counts = DocumentTransfer::where('to_department_id', $officeId)
            ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return ['transfers' => $transfers, 'filters' => $filters, 'view' => $view,
            'counts' => ['pending' => (int) ($counts['pending'] ?? 0), 'received' => (int) ($counts['acknowledged'] ?? 0)]];
    }
}
