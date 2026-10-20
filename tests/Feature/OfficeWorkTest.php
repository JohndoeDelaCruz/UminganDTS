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

class OfficeWorkTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $code, ?User $colleague = null): User
    {
        $office = $colleague?->department ?? Department::create(['code' => $code, 'name' => 'PRIVATE office '.$code, 'is_active' => true]);

        return User::factory()->create(['name' => 'PRIVATE handler '.$code, 'role' => 'staff', 'department_id' => $office->id]);
    }

    private function document(User $user, string $classification = 'confidential'): Document
    {
        $category = DocumentType::first() ?? DocumentType::create(['code' => 'QA', 'name' => 'Correspondence', 'is_active' => true]);

        return app(DocumentWorkflow::class)->register($user, ['subject' => 'PRIVATE office work test', 'document_type_id' => $category->id,
            'direction' => 'incoming', 'priority' => 'normal', 'confidentiality' => $classification, 'sender_name' => 'PRIVATE sender',
            'received_at' => now()->subMinute()->timezone('Asia/Manila')->format('Y-m-d\TH:i')]);
    }

    private function act(User $actor, Document $document, string $action, array $extra = [])
    {
        return $this->actingAs($actor)->post('/documents/'.$document->id.'/office-work', [
            'action' => $action, 'revision' => $document->fresh()->office_work_revision, ...$extra,
        ]);
    }

    private function finish(User $actor, Document $document): void
    {
        $this->act($actor, $document, 'claim')->assertSessionHasNoErrors();
        $this->act($actor, $document, 'start', ['work_type' => 'review'])->assertSessionHasNoErrors();
        $this->act($actor, $document, 'finish', ['review_result' => 'reviewed'])->assertSessionHasNoErrors();
    }

    private function acknowledge(User $recipient, Document $document): void
    {
        $transfer = $document->transfers()->where('status', 'pending')->sole();
        app(DocumentWorkflow::class)->resolveTransfer($recipient, $document, $transfer->id, 'acknowledge', null);
    }

    public function test_assignment_and_personal_queue_require_an_active_handler_in_the_custodian_office(): void
    {
        $a = $this->staff('A');
        $colleague = $this->staff('AA', $a);
        $b = $this->staff('B');
        $document = $this->document($a);
        $this->get('/my-work')->assertRedirect('/login');
        $this->post('/documents/'.$document->id.'/office-work', ['action' => 'claim', 'revision' => 0])->assertRedirect('/login');
        $this->act($b, $document, 'claim')->assertForbidden();
        $this->act($a, $document, 'assign', ['staff_id' => $b->id])->assertSessionHasErrors('office_work');
        $b->update(['is_active' => false, 'department_id' => $a->department_id]);
        $this->act($a, $document, 'assign', ['staff_id' => $b->id])->assertSessionHasErrors('office_work');
        $this->act($a, $document, 'assign', ['staff_id' => $colleague->id])->assertSessionHasNoErrors();
        $this->assertSame($colleague->id, $document->fresh()->currentOfficeWork->assigned_to);
        $this->assertSame(DocumentStatus::Received, $document->fresh()->status);
        $this->actingAs($a)->get('/my-work')->assertInertia(fn (Assert $page) => $page->component('my-work')->has('documents.data', 0));
        $this->actingAs($colleague)->get('/my-work')->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)->where('documents.data.0.id', $document->id));
        $this->get('/documents/'.$document->id)->assertInertia(fn (Assert $page) => $page->has('handlers', 2)->where('document.current_office_work.handler.id', $colleague->id));
        // Assignment records responsibility; colleagues retain the office's existing permissions.
        $this->act($a, $document, 'claim')->assertSessionHasNoErrors();
        $this->assertSame($a->id, $document->fresh()->currentOfficeWork->assigned_to);
        $this->assertSame(1, $document->officeWorks()->count());
        $this->actingAs($colleague)->get('/my-work')->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
    }

    public function test_start_and_finish_automatically_record_work_without_completing_the_document(): void
    {
        $a = $this->staff('A');
        $document = $this->document($a);
        $this->freezeTime();
        $this->act($a, $document, 'start', ['work_type' => 'review'])->assertSessionHasErrors('office_work');
        $this->act($a, $document, 'claim')->assertSessionHasNoErrors();
        $assigned = $document->fresh()->currentOfficeWork->assigned_at->toDateTimeString();
        $this->act($a, $document, 'finish', ['review_result' => 'reviewed'])->assertSessionHasErrors('office_work');
        $this->travel(2)->minutes();
        $this->act($a, $document, 'start', ['work_type' => 'check_requirements'])->assertSessionHasNoErrors();
        $work = $document->fresh()->currentOfficeWork;
        $this->assertSame(DocumentStatus::Processing, $document->fresh()->status);
        $this->assertSame('in_progress', $work->status);
        $this->assertSame(now()->toDateTimeString(), $work->started_at->toDateTimeString());
        $this->assertSame($assigned, $work->assigned_at->toDateTimeString());
        $this->assertSame(1, $document->events()->where('event_type', 'status_changed')->count());
        $this->travel(3)->minutes();
        $this->act($a, $document, 'finish', ['review_result' => 'verified', 'remarks' => 'PRIVATE result details'])->assertSessionHasNoErrors();
        $work = $document->fresh()->currentOfficeWork;
        $this->assertSame('finished', $work->status);
        $this->assertSame('verified', $work->review_result);
        $this->assertSame('PRIVATE result details', $work->remarks);
        $this->assertSame(now()->toDateTimeString(), $work->finished_at->toDateTimeString());
        $this->assertSame(DocumentStatus::Processing, $document->fresh()->status);
        $this->assertNull($document->fresh()->completed_at);
        $event = $document->events()->where('event_type', 'office_work_finished')->sole();
        $this->assertNull($event->to_status);
        $this->assertSame('Office work finished: Requirements verified.', $event->details['summary']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.office_work_finished']);
        $this->get('/my-work?work_state=finished')->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));
        $this->post('/documents/'.$document->id.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Completed, $document->fresh()->status);
        $this->assertSame('completed', $work->fresh()->closed_reason);
        $this->get('/my-work')->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
    }

    public function test_unfinished_work_blocks_normal_forwarding_and_final_completion_but_legacy_records_remain_usable(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $url = '/documents/'.$document->id;
        $this->act($a, $document, 'claim')->assertSessionHasNoErrors();
        $this->post($url.'/forward', ['to_department_id' => $b->department_id])->assertSessionHasErrors('office_work');
        $this->post($url.'/status', ['status' => 'completed'])->assertSessionHasErrors('office_work');
        $this->assertSame(0, $document->transfers()->count());
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('nextStatuses', fn ($states) => ! collect($states)->contains('value', 'completed')));
        $legacy = $this->document($a);
        $this->post('/documents/'.$legacy->id.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame(0, $legacy->officeWorks()->count());
    }

    public function test_new_office_tasks_preserve_prior_results_and_do_not_duplicate_document_start_events(): void
    {
        $a = $this->staff('A');
        $colleague = $this->staff('AA', $a);
        $document = $this->document($a);
        $this->finish($a, $document);
        $first = $document->fresh()->currentOfficeWork;
        $this->act($a, $document, 'assign', ['staff_id' => $colleague->id])->assertSessionHasNoErrors();
        $this->act($colleague, $document, 'start', ['work_type' => 'prepare_response'])->assertSessionHasNoErrors();
        $this->act($colleague, $document, 'finish', ['review_result' => 'prepared'])->assertSessionHasNoErrors();
        $this->assertSame(2, $document->officeWorks()->count());
        $this->assertSame('reviewed', $first->fresh()->review_result);
        $this->assertSame($a->id, $first->fresh()->assigned_to);
        $this->assertSame('prepared', $document->fresh()->currentOfficeWork->review_result);
        $this->assertSame(1, $document->events()->where('event_type', 'status_changed')->count());
        $this->get('/documents/'.$document->id)->assertInertia(fn (Assert $page) => $page->has('document.office_works', 2));
    }

    public function test_acknowledgment_closes_the_old_assignment_and_each_new_office_visit_needs_a_fresh_handler(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $this->finish($a, $document);
        $first = $document->fresh()->currentOfficeWork;
        $oldRevision = $document->fresh()->office_work_revision;
        $workflow = app(DocumentWorkflow::class);
        $workflow->forward($a, $document, $b->department_id, null);
        $this->assertSame($first->id, $document->fresh()->current_office_work_id);
        $this->assertNull($first->fresh()->closed_at);
        $this->act($a, $document, 'claim')->assertSessionHasErrors('office_work');
        $this->act($b, $document, 'claim')->assertForbidden();
        $this->actingAs($a)->get('/my-work')->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
        $this->acknowledge($b, $document);
        $this->assertNull($document->fresh()->current_office_work_id);
        $this->assertSame('transferred', $first->fresh()->closed_reason);
        $this->assertSame('finished', $first->fresh()->status);
        $this->actingAs($a)->get('/documents/'.$document->id)->assertForbidden();
        $this->finish($b, $document);
        $second = $document->fresh()->currentOfficeWork;
        $this->assertNotNull($second->incoming_transfer_id);
        $workflow->returnForCorrection($b, $document, $a->department_id, 'Missing original');
        $this->acknowledge($a, $document);
        $this->assertSame(DocumentStatus::Returned, $document->fresh()->status);
        $this->actingAs($a)->post('/documents/'.$document->id.'/office-work', ['action' => 'claim', 'revision' => $oldRevision])->assertSessionHasErrors('office_work');
        $this->finish($a, $document);
        $this->assertSame(3, $document->officeWorks()->count());
        $this->assertNotSame($first->id, $document->fresh()->current_office_work_id);
        $this->assertSame(DocumentStatus::Processing, $document->fresh()->status);
        $this->actingAs($b)->get('/my-work')->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
    }

    public function test_rejected_and_cancelled_handoffs_keep_the_assignment_and_finished_result(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $this->finish($a, $document);
        $work = $document->fresh()->currentOfficeWork;
        $workflow = app(DocumentWorkflow::class);
        foreach (['reject' => $b, 'cancel' => $a] as $action => $actor) {
            $workflow->forward($a, $document, $b->department_id, null);
            $workflow->resolveTransfer($actor, $document, $document->transfers()->where('status', 'pending')->sole()->id, $action, 'Please check');
            $this->assertSame($work->id, $document->fresh()->current_office_work_id);
            $this->assertNull($work->fresh()->closed_at);
            $this->assertSame('reviewed', $work->fresh()->review_result);
            $this->actingAs($a)->get('/my-work')->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));
        }
    }

    public function test_return_for_correction_can_close_unfinished_work_only_when_acknowledged(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $this->act($a, $document, 'claim')->assertSessionHasNoErrors();
        $this->act($a, $document, 'start', ['work_type' => 'check_requirements'])->assertSessionHasNoErrors();
        $work = $document->fresh()->currentOfficeWork;
        $this->post('/documents/'.$document->id.'/return', ['to_department_id' => $b->department_id, 'reason' => 'Missing original'])->assertSessionHasNoErrors();
        $this->assertNull($work->fresh()->closed_at);
        $this->acknowledge($b, $document);
        $this->assertSame('closed', $work->fresh()->status);
        $this->assertNull($work->fresh()->finished_at);
        $this->assertSame('transferred', $work->fresh()->closed_reason);
    }

    public function test_hold_cancellation_and_reopening_preserve_work_history(): void
    {
        $a = $this->staff('A');
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => $a->department_id]);
        $document = $this->document($a);
        $url = '/documents/'.$document->id.'/status';
        $this->act($a, $document, 'claim')->assertSessionHasNoErrors();
        $this->post($url, ['status' => 'on_hold', 'reason' => 'Waiting'])->assertSessionHasNoErrors();
        $this->act($a, $document, 'start', ['work_type' => 'review'])->assertSessionHasErrors('office_work');
        $this->get('/my-work')->assertInertia(fn (Assert $page) => $page->where('documents.data.0.status', 'on_hold'));
        $this->post($url, ['status' => 'in_progress'])->assertSessionHasNoErrors();
        $this->act($a, $document, 'start', ['work_type' => 'review'])->assertSessionHasNoErrors();
        $work = $document->fresh()->currentOfficeWork;
        $this->post($url, ['status' => 'on_hold', 'reason' => 'Waiting again'])->assertSessionHasNoErrors();
        $this->act($a, $document, 'finish', ['review_result' => 'reviewed'])->assertSessionHasErrors('office_work');
        $this->post($url, ['status' => 'cancelled', 'reason' => 'Duplicate'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $work->fresh()->status);
        $this->assertSame('cancelled', $work->fresh()->closed_reason);
        $this->act($a, $document, 'claim')->assertSessionHasErrors('office_work');
        $this->actingAs($admin)->post($url, ['status' => 'in_progress', 'reason' => 'Review again'])->assertSessionHasNoErrors();
        $this->assertNull($document->fresh()->current_office_work_id);
        $this->act($a, $document, 'claim')->assertSessionHasNoErrors();
        $this->assertSame(2, $document->officeWorks()->count());
        $this->assertSame('cancelled', $work->fresh()->closed_reason);
    }

    public function test_reassignment_preserves_start_time_and_moved_handlers_must_be_replaced(): void
    {
        $a = $this->staff('A');
        $colleague = $this->staff('AA', $a);
        $b = $this->staff('B');
        $document = $this->document($a);
        $this->act($a, $document, 'assign', ['staff_id' => $colleague->id])->assertSessionHasNoErrors();
        $this->act($a, $document, 'start', ['work_type' => 'review'])->assertSessionHasNoErrors();
        $started = $document->fresh()->currentOfficeWork->started_at->toDateTimeString();
        $colleague->update(['department_id' => $b->department_id]);
        $this->actingAs($colleague)->get('/my-work')->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
        $this->act($a, $document, 'finish', ['review_result' => 'reviewed'])->assertSessionHasErrors('office_work');
        $this->act($a, $document, 'claim')->assertSessionHasNoErrors();
        $this->assertSame($started, $document->fresh()->currentOfficeWork->started_at->toDateTimeString());
        $this->act($a, $document, 'finish', ['review_result' => 'reviewed'])->assertSessionHasNoErrors();
    }

    public function test_stale_duplicate_and_invalid_work_submissions_do_not_overwrite_history(): void
    {
        $a = $this->staff('A');
        $document = $this->document($a);
        $url = '/documents/'.$document->id.'/office-work';
        $this->actingAs($a)->post($url, ['action' => 'claim'])->assertSessionHasErrors('revision');
        $this->act($a, $document, 'claim')->assertSessionHasNoErrors();
        $events = $document->events()->count();
        $this->post($url, ['action' => 'claim', 'revision' => 0])->assertSessionHasErrors('office_work');
        $this->assertSame($events, $document->events()->count());
        $this->act($a, $document, 'start', ['work_type' => 'arbitrary'])->assertSessionHasErrors('work_type');
        $this->act($a, $document, 'start', ['work_type' => 'review'])->assertSessionHasNoErrors();
        $this->act($a, $document, 'start', ['work_type' => 'review'])->assertSessionHasErrors('office_work');
        $this->act($a, $document, 'finish', ['review_result' => 'arbitrary'])->assertSessionHasErrors('review_result');
        $this->act($a, $document, 'finish', ['review_result' => 'reviewed', 'remarks' => str_repeat('x', 2001)])->assertSessionHasErrors('remarks');
        $this->act($a, $document, 'finish', ['review_result' => 'reviewed'])->assertSessionHasNoErrors();
        $this->act($a, $document, 'finish', ['review_result' => 'reviewed'])->assertSessionHasErrors('office_work');
        $this->assertSame(1, $document->events()->where('event_type', 'office_work_finished')->count());
    }

    public function test_my_work_filters_personal_access_and_does_not_leak_other_offices_confidential_work(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $admin = User::factory()->create(['role' => 'admin']);
        $document = $this->document($a);
        $this->finish($a, $document);
        $this->actingAs($a)->get('/my-work?search=notfound')->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
        $this->get('/my-work?work_state=assigned')->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
        $this->get('/my-work?search='.$document->tracking_number)->assertInertia(fn (Assert $page) => $page->has('documents.data', 1));
        $this->get('/my-work?work_state=closed')->assertSessionHasErrors('work_state');
        $this->actingAs($b)->get('/my-work')->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
        $this->actingAs($admin)->get('/my-work')->assertInertia(fn (Assert $page) => $page->where('hasOffice', false)->has('documents.data', 0));
        $this->get('/documents/'.$document->id)->assertForbidden();
        $a->department->update(['is_active' => false]);
        $this->actingAs($a)->get('/my-work')->assertRedirect('/login');
        $a->update(['role' => 'admin']);
        $this->actingAs($a)->get('/my-work')->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
        $this->act($a, $document, 'claim')->assertForbidden();
    }

    public function test_public_tracking_keeps_office_assignments_and_review_details_private(): void
    {
        $a = $this->staff('A');
        $document = $this->document($a, 'internal');
        $this->act($a, $document, 'claim')->assertSessionHasNoErrors();
        $this->act($a, $document, 'start', ['work_type' => 'review'])->assertSessionHasNoErrors();
        $this->act($a, $document, 'finish', ['review_result' => 'reviewed', 'remarks' => 'PRIVATE review result details'])->assertSessionHasNoErrors();
        $this->post('/documents/'.$document->id.'/remarks', ['remarks' => 'PRIVATE note'])->assertSessionHasNoErrors();
        $response = $this->post('/track', ['reference' => $document->tracking_number, 'secret' => $document->tokens()->sole()->token_encrypted]);
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('result.timeline', 2)->where('result.status', 'Processing'));
        $result = json_encode($response->viewData('page')['props']['result']);
        foreach (['PRIVATE', 'office_work', 'assigned_to', 'review_result', 'Review completed'] as $private) {
            $this->assertStringNotContainsString($private, $result);
        }
    }
}
