<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\DocumentType;
use App\Services\DocumentSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OverviewController extends Controller
{
    public function dashboard(Request $request, DocumentSearch $search)
    {
        $filters = $search->filters($request);
        $query = $search->query($request, $filters);
        $breakdown = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->get()->map(fn ($row) => ['label' => $row->status->label(), 'value' => (int) $row->total]);
        $trend = (clone $query)->selectRaw("DATE_FORMAT(CONVERT_TZ(received_at, '+00:00', '+08:00'), '%Y-%m') AS month, COUNT(*) AS total")->groupBy('month')->orderBy('month', 'desc')->limit(6)->get()->reverse()->values();

        return Inertia::render('dashboard', [
            'metrics' => $search->metrics($query), 'breakdown' => $breakdown, 'trend' => $trend,
            'recent' => (clone $query)->with(['type', 'department'])->latest()->limit(5)->get(),
            'filters' => $filters, 'departments' => Department::orderBy('name')->get(['id', 'name']),
            'onboarding' => ['departments' => Department::exists(), 'types' => DocumentType::exists(), 'office' => (bool) $request->user()->department?->is_active],
        ]);
    }

    public function reports(Request $request, DocumentSearch $search)
    {
        $filters = $search->filters($request);
        $query = $search->query($request, $filters);

        return Inertia::render('reports', [
            'documents' => (clone $query)->with(['type', 'department'])->latest('received_at')->paginate(20)->withQueryString(),
            'metrics' => $search->metrics($query), 'filters' => $filters,
            'departments' => Department::orderBy('name')->get(['id', 'name']), 'types' => DocumentType::orderBy('name')->get(['id', 'name']),
            'statuses' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], DocumentStatus::cases()),
        ]);
    }

    public function export(Request $request, DocumentSearch $search)
    {
        $filters = $search->filters($request);
        $query = $search->query($request, $filters)->with(['type', 'department'])->orderBy('id');
        AuditLog::record($request->user(), 'report.exported', $request->user(), ['filters' => array_diff_key($filters, ['search' => true])]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Tracking reference', 'Subject', 'Category', 'Current office', 'Status', 'Priority', 'Received (Asia/Manila)', 'Due (Asia/Manila)'], ',', '"', '');
            foreach ($query->lazy(200) as $document) {
                $values = [$document->tracking_number, $document->subject, $document->type->name, $document->department->name, $document->status->label(), $document->priority, $document->received_at->timezone('Asia/Manila')->format('Y-m-d H:i'), $document->due_at?->timezone('Asia/Manila')->format('Y-m-d H:i')];
                $safe = array_map(fn ($value) => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : ($value ?? ''), $values);
                fputcsv($out, $safe, ',', '"', '');
            }
            fclose($out);
        }, 'umdts-report-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
