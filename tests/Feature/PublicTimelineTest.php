<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicTimelineTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $code): User
    {
        $office = Department::create(['code' => $code, 'name' => 'PRIVATE office '.$code, 'is_active' => true]);

        return User::factory()->create(['name' => 'PRIVATE staff '.$code, 'role' => 'staff', 'department_id' => $office->id]);
    }

    private function document(User $staff): Document
    {
        $this->freezeTime();
        $category = DocumentType::create(['code' => 'TEST', 'name' => 'Correspondence', 'is_active' => true]);

        return app(DocumentWorkflow::class)->register($staff, [
            'subject' => 'PRIVATE subject', 'sender_name' => 'PRIVATE sender', 'description' => 'PRIVATE description',
            'contact_private' => 'PRIVATE contact', 'document_type_id' => $category->id, 'direction' => 'incoming',
            'priority' => 'normal', 'confidentiality' => 'internal',
            'received_at' => now()->subHour()->timezone('Asia/Manila')->format('Y-m-d\TH:i'),
        ]);
    }

    private function row(string $title, string $status): array
    {
        return ['title' => $title, 'status' => $status, 'at' => now()->toIso8601String()];
    }

    private function assertTimeline(Document $document, array $expected): void
    {
        $response = $this->post('/track', ['reference' => $document->tracking_number, 'secret' => $document->tokens()->sole()->token_encrypted]);
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('track')
            ->where('result', [
                'reference' => $document->tracking_number, 'category' => 'Correspondence',
                'status' => $document->fresh()->status->publicLabel(), 'updated_at' => $document->fresh()->updated_at->toIso8601String(),
                'timeline' => $expected,
            ]));
        $this->assertStringNotContainsString('PRIVATE', json_encode($response->viewData('page')['props']['result']));
    }

    public function test_lifecycle_uses_recorded_event_times_and_excludes_notes_files_codes_and_private_metadata(): void
    {
        $staff = $this->staff('A');
        $document = $this->document($staff);
        $workflow = app(DocumentWorkflow::class);
        $expected = [$this->row('Document received', 'Received')];
        $this->travel(5)->minutes();
        $workflow->transition($staff, $document, 'in_progress', null, null);
        $expected[] = $this->row('Processing started', 'Processing');
        $workflow->remark($staff, $document, 'PRIVATE note');
        $workflow->event($document, $staff, 'attachment_uploaded', null, 'in_progress', null, null, ['summary' => 'PRIVATE filename.pdf']);
        $workflow->event($document, $staff, 'internal_review', null, 'in_progress', null, null, ['summary' => 'PRIVATE review']);
        $this->travel(5)->minutes();
        $workflow->transition($staff, $document, 'completed', null, null);
        $expected[] = $this->row('Ready for release', 'Ready for release');
        $this->travel(5)->minutes();
        $workflow->transition($staff, $document, 'released', null, 'PRIVATE recipient and release method');
        $expected[] = $this->row('Document released', 'Released');
        $this->assertTimeline($document, $expected);
        $this->assertSame(8, $document->events()->count());
        $this->assertNotSame($document->received_at->toIso8601String(), $expected[0]['at']);
    }

    public function test_hold_return_and_resume_remain_distinct_without_disclosing_reasons_or_offices(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $workflow = app(DocumentWorkflow::class);
        $expected = [$this->row('Document received', 'Received')];
        $this->travel(1)->minutes();
        $workflow->transition($a, $document, 'in_progress', null, null);
        $expected[] = $this->row('Processing started', 'Processing');
        $this->travel(1)->minutes();
        $workflow->transition($a, $document, 'on_hold', 'PRIVATE hold reason', null);
        $expected[] = $this->row('Processing on hold', 'Action required');
        $this->travel(1)->minutes();
        $workflow->transition($a, $document, 'in_progress', null, null);
        $expected[] = $this->row('Processing resumed', 'Processing');
        $this->travel(1)->minutes();
        $workflow->returnForCorrection($a, $document, $b->department_id, 'PRIVATE correction reason');
        $expected[] = $this->row('Return for correction sent', 'Processing');
        $this->travel(1)->minutes();
        $workflow->resolveTransfer($b, $document, $document->transfers()->sole()->id, 'acknowledge', null);
        $expected[] = $this->row('Returned for correction', 'Action required');
        $this->travel(1)->minutes();
        $workflow->transition($b, $document, 'in_progress', null, null);
        $expected[] = $this->row('Processing resumed', 'Processing');
        $this->assertTimeline($document, $expected);
    }

    public function test_forward_and_receipt_milestones_are_preserved_even_when_the_public_status_is_the_same(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $workflow = app(DocumentWorkflow::class);
        $expected = [$this->row('Document received', 'Received')];
        $this->travel(1)->minutes();
        $workflow->forward($a, $document, $b->department_id, 'PRIVATE routing note');
        $expected[] = $this->row('Document forwarded', 'Processing');
        $this->assertTimeline($document, $expected);
        $this->travel(1)->minutes();
        $workflow->resolveTransfer($b, $document, $document->transfers()->sole()->id, 'acknowledge', null);
        $expected[] = $this->row('Processing started', 'Processing');
        $this->assertTimeline($document, $expected);
    }

    public function test_rejected_and_cancelled_handoffs_show_only_the_restored_public_status(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $workflow = app(DocumentWorkflow::class);
        $expected = [$this->row('Document received', 'Received')];
        $this->travel(1)->minutes();
        $workflow->forward($a, $document, $b->department_id, 'PRIVATE routing note');
        $expected[] = $this->row('Document forwarded', 'Processing');
        $this->travel(1)->minutes();
        $workflow->resolveTransfer($b, $document, $document->transfers()->sole()->id, 'reject', 'PRIVATE rejection');
        $expected[] = $this->row('Processing status updated', 'Received');
        $this->travel(1)->minutes();
        $workflow->returnForCorrection($a, $document, $b->department_id, 'PRIVATE correction');
        $expected[] = $this->row('Return for correction sent', 'Processing');
        $this->travel(1)->minutes();
        $workflow->resolveTransfer($a, $document, $document->transfers()->where('status', 'pending')->sole()->id, 'cancel', 'PRIVATE cancellation');
        $expected[] = $this->row('Processing status updated', 'Received');
        $this->assertTimeline($document, $expected);
    }

    public function test_reopening_closure_and_legacy_events_use_fixed_public_labels_instead_of_stored_free_text(): void
    {
        $staff = $this->staff('A');
        $document = $this->document($staff);
        $workflow = app(DocumentWorkflow::class);
        $expected = [$this->row('Document received', 'Received')];
        $this->travel(1)->minutes();
        $workflow->transition($staff, $document, 'completed', null, null);
        $expected[] = $this->row('Ready for release', 'Ready for release');
        $admin = User::factory()->create(['name' => 'PRIVATE administrator', 'role' => 'admin', 'department_id' => $staff->department_id]);
        $this->travel(1)->minutes();
        $workflow->transition($admin, $document, 'in_progress', 'PRIVATE reopening reason', null);
        $expected[] = $this->row('Processing reopened', 'Processing');
        $this->travel(1)->minutes();
        // A historical event may have no generated summary, or have private free text in that field.
        $document->update(['status' => DocumentStatus::Hold]);
        $workflow->event($document, $staff, 'status_changed', 'in_progress', 'on_hold', null, null, ['summary' => 'PRIVATE legacy summary', 'remarks' => 'PRIVATE reason']);
        $expected[] = $this->row('Processing on hold', 'Action required');
        $this->travel(1)->minutes();
        $workflow->transition($staff, $document, 'cancelled', 'PRIVATE closure reason', null);
        $expected[] = $this->row('Document closed', 'Closed');
        $this->assertTimeline($document, $expected);
    }
}
