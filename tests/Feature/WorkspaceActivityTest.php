<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentWorkflow;
use App\Services\WorkspaceActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceActivityTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $office, ?int $department = null): User
    {
        $department ??= Department::create(['code' => $office, 'name' => 'Office '.$office, 'is_active' => true])->id;

        return User::factory()->create(['role' => 'staff', 'department_id' => $department]);
    }

    private function document(User $user, string $classification = 'internal'): Document
    {
        $type = DocumentType::first() ?? DocumentType::create(['code' => 'TEST', 'name' => 'Test category', 'is_active' => true]);

        return app(DocumentWorkflow::class)->register($user, [
            'subject' => 'Private activity subject', 'sender_name' => 'Private sender',
            'document_type_id' => $type->id, 'direction' => 'incoming', 'priority' => 'normal',
            'confidentiality' => $classification, 'received_at' => now()->subMinute()->timezone('Asia/Manila')->format('Y-m-d\TH:i'),
        ]);
    }

    public function test_empty_counters_and_activity_endpoint_require_authentication(): void
    {
        $this->getJson('/workspace/activity')->assertUnauthorized();
        $staff = $this->staff('A');
        $this->actingAs($staff)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('unreadActivity', ['documents' => 0, 'inbox' => 0, 'outgoing' => 0]));
        $this->getJson('/workspace/activity')->assertExactJson(['documents' => 0, 'inbox' => 0, 'outgoing' => 0]);
        $this->assertDatabaseCount('workspace_reads', 0);
    }

    public function test_opening_each_tab_clears_only_that_tabs_unseen_activity(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        app(DocumentWorkflow::class)->forward($a, $document, $b->department_id, null);
        $this->actingAs($b)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('unreadActivity.documents', 1)->where('unreadActivity.inbox', 1));
        $this->get('/documents')->assertInertia(fn (Assert $page) => $page->where('unreadActivity.documents', 0)->where('unreadActivity.inbox', 1));
        $this->get('/inbox')->assertInertia(fn (Assert $page) => $page->where('unreadActivity.documents', 0)->where('unreadActivity.inbox', 0));
        $this->assertSame('pending', $document->transfers()->firstOrFail()->status);
        // Read state belongs to the account and survives a fresh user/session context.
        $this->actingAs($b->fresh())->getJson('/workspace/activity')->assertExactJson(['documents' => 0, 'inbox' => 0, 'outgoing' => 0]);
    }

    public function test_documents_count_changed_records_and_ignore_ones_own_actions(): void
    {
        $a = $this->staff('A');
        $colleague = $this->staff('AA', $a->department_id);
        $document = $this->document($a);
        $this->actingAs($a)->getJson('/workspace/activity')->assertJsonPath('documents', 0);
        app(DocumentWorkflow::class)->remark($colleague, $document, 'First note');
        app(DocumentWorkflow::class)->remark($colleague, $document, 'Second note');
        $this->getJson('/workspace/activity')->assertJsonPath('documents', 1);
        $this->document($colleague);
        $this->getJson('/workspace/activity')->assertJsonPath('documents', 2);
        $this->get('/documents');
        $this->getJson('/workspace/activity')->assertJsonPath('documents', 0);
        app(DocumentWorkflow::class)->remark($colleague, $document, 'New activity after viewing');
        $this->getJson('/workspace/activity')->assertJsonPath('documents', 1);
    }

    public function test_staff_in_the_same_office_have_independent_read_state(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $colleague = $this->staff('BB', $b->department_id);
        $document = $this->document($a);
        app(DocumentWorkflow::class)->forward($a, $document, $b->department_id, null);
        $this->actingAs($b)->get('/inbox');
        $this->getJson('/workspace/activity')->assertJsonPath('inbox', 0);
        $this->actingAs($colleague)->getJson('/workspace/activity')->assertJsonPath('inbox', 1);
    }

    public function test_prefetch_does_not_mark_tabs_read_and_polling_does_not_change_previous_url(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        app(DocumentWorkflow::class)->forward($a, $document, $b->department_id, null);
        $this->actingAs($b)->get('/inbox', ['Purpose' => 'prefetch'])->assertOk();
        $this->assertDatabaseCount('workspace_reads', 0);
        $this->get('/documents/'.$document->id)->assertOk();
        $previous = session()->previousUrl();
        $this->getJson('/workspace/activity', ['X-Requested-With' => 'XMLHttpRequest'])->assertJsonPath('inbox', 1);
        $this->assertSame($previous, session()->previousUrl());
        $this->get('/inbox?office_id=invalid')->assertSessionHasErrors('office_id');
        $this->assertDatabaseCount('workspace_reads', 0);
    }

    public function test_read_cursor_does_not_hide_later_activity_or_move_backwards(): void
    {
        $a = $this->staff('A');
        $colleague = $this->staff('AA', $a->department_id);
        $document = $this->document($a);
        $activity = app(WorkspaceActivity::class);
        $old = $activity->limit('documents');
        app(DocumentWorkflow::class)->remark($colleague, $document, 'After page snapshot');
        $activity->viewed($a, 'documents', $old);
        $this->assertSame(1, $activity->counts($a)['documents']);
        $latest = $activity->limit('documents');
        $activity->viewed($a, 'documents', $latest);
        $activity->viewed($a, 'documents', $old);
        $this->assertSame(0, $activity->counts($a)['documents']);
        $this->assertSame($latest, (int) DB::table('workspace_reads')->value('last_seen_id'));
    }

    public function test_counters_do_not_reveal_other_offices_or_confidential_documents(): void
    {
        $a = $this->staff('A');
        $outsider = $this->staff('B');
        $this->document($a, 'confidential');
        $this->document($a);
        $this->actingAs($outsider)->getJson('/workspace/activity')->assertExactJson(['documents' => 0, 'inbox' => 0, 'outgoing' => 0]);
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => null]);
        $this->actingAs($admin)->getJson('/workspace/activity')->assertExactJson(['documents' => 1, 'inbox' => 0, 'outgoing' => 0]);
    }

    public function test_outgoing_reports_new_handoff_outcomes_after_custody_changes(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a, 'confidential');
        $workflow = app(DocumentWorkflow::class);
        $workflow->forward($a, $document, $b->department_id, null);
        $transfer = $document->transfers()->firstOrFail();
        $this->actingAs($a)->get('/outgoing');
        $workflow->resolveTransfer($b, $document, $transfer->id, 'acknowledge', null);
        $this->getJson('/workspace/activity')->assertExactJson(['documents' => 0, 'inbox' => 0, 'outgoing' => 1]);
        $this->get('/outgoing')->assertInertia(fn (Assert $page) => $page->where('unreadActivity.outgoing', 0)->where('transfers.data.0.can_open', false));
        $this->get('/documents/'.$document->id)->assertForbidden();
        $workflow->returnForCorrection($b, $document, $a->department_id, 'Correction required');
        $this->getJson('/workspace/activity')->assertJsonPath('inbox', 1)->assertJsonPath('outgoing', 0);
    }

    public function test_cancelled_arrivals_no_longer_show_an_incoming_counter(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $workflow = app(DocumentWorkflow::class);
        $workflow->forward($a, $document, $b->department_id, null);
        $this->actingAs($b)->getJson('/workspace/activity')->assertJsonPath('inbox', 1);
        $workflow->resolveTransfer($a, $document, $document->transfers()->firstOrFail()->id, 'cancel', 'Sent by mistake');
        $this->getJson('/workspace/activity')->assertExactJson(['documents' => 0, 'inbox' => 0, 'outgoing' => 0]);
    }

    public function test_office_reassignment_does_not_reuse_another_offices_read_cursor(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $reader = $this->staff('AA', $a->department_id);
        $this->document($b);
        $this->actingAs($reader)->get('/documents');
        $reader->update(['department_id' => $b->department_id]);
        $this->actingAs($reader->fresh())->getJson('/workspace/activity')->assertJsonPath('documents', 1);
    }
}
