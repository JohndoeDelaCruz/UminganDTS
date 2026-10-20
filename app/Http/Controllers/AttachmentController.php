<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Services\DocumentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttachmentController extends Controller
{
    public function store(Request $request, Document $document, DocumentWorkflow $workflow)
    {
        Gate::authorize('update', $document);
        $request->validate(['attachment' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $path = null;
        try {
            DB::transaction(function () use ($request, $document, $workflow, &$path) {
                $document = Document::lockForUpdate()->findOrFail($document->id);
                Gate::authorize('update', $document);
                if ($document->status->terminal() || $document->transfers()->where('status', 'pending')->exists()) {
                    throw ValidationException::withMessages(['attachment' => 'Reopen the document and resolve pending handoffs before uploading.']);
                }
                $file = $request->file('attachment');
                $path = $file->store('documents/'.$document->id, 'local');
                $document->attachments()->create(['filename_display' => basename($file->getClientOriginalName()), 'storage_path_private' => $path, 'mime_type' => $file->getMimeType(), 'bytes' => $file->getSize(), 'uploaded_by' => $request->user()->id]);
                $workflow->event($document, $request->user(), 'attachment_uploaded', null, null);
                $document->touch();
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return to_route('documents.show', $document)->with('success', 'Private attachment uploaded.');
    }

    public function download(Request $request, DocumentAttachment $attachment)
    {
        Gate::authorize('view', $attachment->document);
        abort_unless(Storage::disk('local')->exists($attachment->storage_path_private), 404);
        AuditLog::record($request->user(), 'attachment.downloaded', $attachment, ['document_id' => $attachment->document_id]);

        return Storage::disk('local')->download($attachment->storage_path_private, $attachment->filename_display, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
