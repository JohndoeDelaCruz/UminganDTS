<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Http\Requests\StoreDocumentRequest;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentSearch;
use App\Services\DocumentWorkflow;
use App\Services\PublicTracking;
use App\Services\TransferHistory;
use App\Services\WorkspaceActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DocumentController extends Controller
{
    public function index(Request $request, DocumentSearch $search, TransferHistory $history, WorkspaceActivity $activity)
    {
        $mode = $request->route()->getName();
        // Snapshot before reading the page so activity arriving afterwards remains unread.
        $limit = $activity->limit($mode);
        if (in_array($mode, ['inbox', 'outgoing'], true)) {
            $page = $history->page($request, $mode);
            if (! $request->prefetch()) {
                $activity->viewed($request->user(), $mode, $limit);
            }

            return Inertia::render('documents/handoffs', [
                ...$page, 'mode' => $mode,
                'departments' => Department::orderBy('name')->get(['id', 'name']),
                'canCreate' => $request->user()->can('create', Document::class),
            ]);
        }
        $filters = $search->filters($request);
        $query = $search->query($request, $filters);
        $documents = $query->with(['type', 'department'])->latest('received_at')->paginate(15)->withQueryString();
        if (! $request->prefetch()) {
            $activity->viewed($request->user(), 'documents', $limit);
        }

        return Inertia::render('documents/index', [
            'documents' => $documents,
            'filters' => $filters, 'mode' => $mode,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'types' => DocumentType::orderBy('name')->get(['id', 'name']),
            'statuses' => $this->statuses(), 'canCreate' => $request->user()->can('create', Document::class),
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', Document::class);

        return Inertia::render('documents/create', ['types' => DocumentType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'default_processing_days'])]);
    }

    public function store(StoreDocumentRequest $request, DocumentWorkflow $workflow)
    {
        $data = $request->safe()->except('attachment');
        $path = null;
        try {
            $document = DB::transaction(function () use ($request, $data, $workflow, &$path) {
                $document = $workflow->register($request->user(), $data);
                if ($request->hasFile('attachment')) {
                    $file = $request->file('attachment');
                    $path = $file->store('documents/'.$document->id, 'local');
                    $document->attachments()->create(['filename_display' => basename($file->getClientOriginalName()), 'storage_path_private' => $path, 'mime_type' => $file->getMimeType(), 'bytes' => $file->getSize(), 'uploaded_by' => $request->user()->id]);
                    $workflow->event($document, $request->user(), 'attachment_uploaded', null, null);
                }

                return $document;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return to_route('documents.show', $document)->with('success', 'Document registered. Its tracking reference is ready.');
    }

    public function show(Request $request, Document $document, PublicTracking $tracking)
    {
        Gate::authorize('view', $document);
        $document->load(['type', 'department', 'sourceDepartment', 'creator:id,name', 'events.actor:id,name', 'events.fromDepartment', 'events.toDepartment', 'attachments', 'transfers.fromDepartment', 'transfers.toDepartment', 'transfers.sender:id,name', 'currentOfficeWork.handler:id,name', 'officeWorks.handler:id,name', 'officeWorks.department:id,name']);
        $next = $document->status->next();
        if ($document->currentOfficeWork && $document->currentOfficeWork->status !== 'finished') {
            $next = array_values(array_filter($next, fn ($status) => $status !== DocumentStatus::Completed));
        }
        if ($request->user()->isAdmin() && $document->status->terminal()) {
            $next[] = DocumentStatus::Processing;
        }
        $departments = Department::where('is_active', true)->where('id', '!=', $document->current_department_id)->orderBy('name')->get(['id', 'name']);
        $previousOffice = $document->transfers()->where('status', 'acknowledged')->where('to_department_id', $document->current_department_id)
            ->latest('acknowledged_at')->latest('id')->value('from_department_id');
        $returnOffice = $departments->firstWhere('id', $previousOffice) ?? $departments->firstWhere('id', $document->source_department_id);

        return Inertia::render('documents/show', [
            'document' => [...$document->toArray(), 'contact_private' => $document->contact_private],
            'departments' => $departments, 'returnDepartmentId' => $returnOffice?->id,
            'canUpdate' => $request->user()->can('update', $document),
            'handlers' => $request->user()->can('update', $document)
                ? User::where('is_active', true)->where('department_id', $document->current_department_id)->orderBy('name')->get(['id', 'name']) : [],
            'canManagePublic' => $request->user()->can('managePublicTracking', $document),
            'receiptCredential' => $tracking->receiptCredential($request->user(), $document),
            'publicTrackingUrl' => route('track'),
            'nextStatuses' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], $next),
        ])->withViewData('noStore', true);
    }

    public function forward(Request $request, Document $document, DocumentWorkflow $workflow)
    {
        $data = $request->validate(['to_department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)], 'remarks' => ['nullable', 'string', 'max:2000']]);
        $workflow->forward($request->user(), $document, (int) $data['to_department_id'], $data['remarks'] ?? null);

        return to_route('documents.show', $document)->with('success', 'Document forwarded. Your office retains custody until acknowledgment.');
    }

    public function transfer(Request $request, Document $document, int $transfer, DocumentWorkflow $workflow)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['acknowledge', 'reject', 'cancel'])], 'reason' => ['nullable', 'string', 'max:2000']]);
        $workflow->resolveTransfer($request->user(), $document, $transfer, $data['action'], $data['reason'] ?? null);

        return to_route('documents.index')->with('success', 'Handoff '.$data['action'].' action recorded.');
    }

    public function returnForCorrection(Request $request, Document $document, DocumentWorkflow $workflow)
    {
        $data = $request->validate([
            'to_department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->returnForCorrection($request->user(), $document, (int) $data['to_department_id'], $data['reason']);

        return to_route('documents.show', $document)->with('success', 'Return sent for correction. Your office retains custody until acknowledgment.');
    }

    public function status(Request $request, Document $document, DocumentWorkflow $workflow)
    {
        $data = $request->validate(['status' => ['required', Rule::enum(DocumentStatus::class)], 'reason' => ['nullable', 'string', 'max:2000'], 'release_method' => ['nullable', 'string', 'max:180']]);
        $workflow->transition($request->user(), $document, $data['status'], $data['reason'] ?? null, $data['release_method'] ?? null);

        return to_route('documents.show', $document)->with('success', 'Status updated and added to the permanent timeline.');
    }

    public function remark(Request $request, Document $document, DocumentWorkflow $workflow)
    {
        $data = $request->validate(['remarks' => ['required', 'string', 'max:2000']]);
        $workflow->remark($request->user(), $document, $data['remarks']);

        return to_route('documents.show', $document)->with('success', 'Processing note added.');
    }

    public function publicCredential(Request $request, Document $document, PublicTracking $tracking)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['issue', 'revoke'])]]);
        if ($data['action'] === 'revoke') {
            $tracking->revoke($request->user(), $document);

            return to_route('documents.show', $document)->with('success', 'Public tracking disabled and all existing credentials revoked.');
        }
        $tracking->issue($request->user(), $document);

        return to_route('documents.show', $document)->with('success', 'Receipt access code generated. Print the receipt to give it to the citizen. Any previous code is no longer valid.');
    }

    private function statuses(): array
    {
        return array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], DocumentStatus::cases());
    }
}
