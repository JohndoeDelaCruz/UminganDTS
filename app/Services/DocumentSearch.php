<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class DocumentSearch
{
    public function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }

    public function query(Request $request, array $filters): Builder
    {
        $query = Document::visibleTo($request->user());
        if ($search = $filters['search'] ?? null) {
            // Escape SQL LIKE wildcards so search text remains literal.
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn (Builder $q) => $q->whereRaw("subject LIKE ? ESCAPE '!'", [$search])->orWhereRaw("tracking_number LIKE ? ESCAPE '!'", [$search])->orWhereRaw("sender_name LIKE ? ESCAPE '!'", [$search]));
        }
        foreach (['status' => 'status', 'department_id' => 'current_department_id', 'document_type_id' => 'document_type_id'] as $filter => $column) {
            if ($filters[$filter] ?? null) {
                $query->where($column, $filters[$filter]);
            }
        }
        if ($filters['from'] ?? null) {
            $query->where('received_at', '>=', Carbon::parse($filters['from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if ($filters['to'] ?? null) {
            $query->where('received_at', '<=', Carbon::parse($filters['to'], 'Asia/Manila')->endOfDay()->utc());
        }

        return $query;
    }

    public function metrics(Builder $query): array
    {
        $counts = (clone $query)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $completed = (clone $query)->whereIn('status', ['completed', 'released'])->whereNotNull('completed_at')->whereColumn('completed_at', '>=', 'received_at');
        $average = $completed->selectRaw('AVG(TIMESTAMPDIFF(SECOND, received_at, completed_at)) / 86400 AS days')->value('days');

        return [
            'total' => (clone $query)->count(),
            'pending' => (int) ($counts['received'] ?? 0) + (int) ($counts['awaiting_acknowledgment'] ?? 0),
            'in_progress' => (int) ($counts['in_progress'] ?? 0) + (int) ($counts['on_hold'] ?? 0) + (int) ($counts['returned'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0) + (int) ($counts['released'] ?? 0),
            'overdue' => (clone $query)->whereNotIn('status', ['completed', 'released', 'cancelled'])->where('due_at', '<', now())->count(),
            'average_days' => $average === null ? null : round((float) $average, 1),
        ];
    }
}
