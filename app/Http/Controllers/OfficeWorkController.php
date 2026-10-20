<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\OfficeWork;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OfficeWorkController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'work_state' => ['nullable', Rule::in(['assigned', 'in_progress', 'finished'])]]);
        $actor = $request->user();
        $query = Document::visibleTo($actor)->where('current_department_id', $actor->department_id)
            ->whereHas('department', fn ($q) => $q->where('is_active', true))
            ->whereNotIn('status', [DocumentStatus::Completed->value, DocumentStatus::Released->value, DocumentStatus::Cancelled->value, DocumentStatus::Awaiting->value])
            ->whereHas('currentOfficeWork', function ($q) use ($actor, $filters) {
                $q->where('assigned_to', $actor->id)->whereNull('closed_at');
                if ($filters['work_state'] ?? null) {
                    $q->where('status', $filters['work_state']);
                }
            });
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('subject', 'like', '%'.$search.'%')->orWhere('tracking_number', 'like', '%'.$search.'%'));
        }

        return Inertia::render('my-work', [
            'documents' => $query->with(['type', 'department', 'currentOfficeWork.handler:id,name'])->oldest('received_at')->paginate(15)->withQueryString(),
            'filters' => $filters, 'hasOffice' => (bool) ($actor->department_id && $actor->department?->is_active),
        ]);
    }

    public function store(Request $request, Document $document, OfficeWork $work)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['claim', 'assign', 'start', 'finish'])],
            'revision' => ['required', 'integer', 'min:0'],
            'staff_id' => ['required_if:action,assign', 'nullable', 'integer'],
            'work_type' => ['required_if:action,start', 'nullable', Rule::in(array_keys(OfficeWork::TYPES))],
            'review_result' => ['required_if:action,finish', 'nullable', Rule::in(array_keys(OfficeWork::RESULTS))],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        $work->act($request->user(), $document, $data);

        return to_route('documents.show', $document)->with('success', match ($data['action']) {
            'claim', 'assign' => 'Handler assigned. The document is now in their My Work queue.',
            'start' => 'Office work started. The time and timeline were recorded automatically.',
            default => 'Office work finished. You can now forward or complete the document.',
        });
    }
}
