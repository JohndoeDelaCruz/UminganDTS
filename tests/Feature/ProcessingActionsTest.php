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

class ProcessingActionsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $code): User
    {
        $office = Department::create(['code' => $code, 'name' => 'Office '.$code, 'is_active' => true]);

        return User::factory()->create(['role' => 'staff', 'department_id' => $office->id]);
    }

    private function document(User $user): Document
    {
        $category = DocumentType::create(['code' => 'TEST', 'name' => 'Test category', 'is_active' => true]);

        return app(DocumentWorkflow::class)->register($user, ['subject' => 'Processing action test', 'document_type_id' => $category->id,
            'direction' => 'incoming', 'priority' => 'normal', 'confidentiality' => 'confidential', 'sender_name' => 'QA sender',
            'received_at' => now()->subMinute()->timezone('Asia/Manila')->format('Y-m-d\TH:i')]);
    }

    public function test_start_complete_and_release_record_summaries_and_times_without_manual_processing_notes(): void
    {
        $staff = $this->staff('A');
        $document = $this->document($staff);
        $url = '/documents/'.$document->id;
        $this->actingAs($staff)->get($url)->assertOk();
        $this->assertSame(DocumentStatus::Received, $document->fresh()->status);
        $this->assertNull($document->fresh()->completed_at);
        $this->post($url.'/status', ['status' => 'in_progress'])->assertSessionHasNoErrors();
        $event = $document->events()->reorder()->latest('id')->firstOrFail();
        $this->assertSame('Processing started.', $event->details['summary']);
        $this->assertNull($event->details['remarks']);
        $this->assertSame($staff->id, $event->actor_user_id);
        $events = $document->events()->count();
        $this->post($url.'/status', ['status' => 'in_progress'])->assertSessionHasErrors('status');
        $this->assertSame($events, $document->events()->count());
        $this->post($url.'/status', ['status' => 'released', 'release_method' => 'Counter'])->assertSessionHasErrors('status');
        $this->travel(2)->minutes();
        $this->post($url.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame('Processing completed.', $document->events()->reorder()->latest('id')->firstOrFail()->details['summary']);
        $this->assertSame(now()->toDateTimeString(), $document->fresh()->completed_at->toDateTimeString());
        $this->post($url.'/status', ['status' => 'released'])->assertSessionHasErrors('release_method');
        $this->assertSame(DocumentStatus::Completed, $document->fresh()->status);
        $this->travel(1)->minutes();
        $this->post($url.'/status', ['status' => 'released', 'release_method' => 'Collected at the office'])->assertSessionHasNoErrors();
        $this->assertSame(now()->toDateTimeString(), $document->fresh()->released_at->toDateTimeString());
        $event = $document->events()->reorder()->latest('id')->firstOrFail();
        $this->assertSame('Document released.', $event->details['summary']);
        $this->assertSame('Collected at the office', $event->details['release_method']);
        $this->assertSame(0, $document->events()->where('event_type', 'remark_added')->count());
        $this->assertSame(3, $document->events()->where('event_type', 'status_changed')->count());
    }

    public function test_hold_and_resume_generate_activity_but_hold_and_cancellation_still_need_reasons(): void
    {
        $staff = $this->staff('A');
        $document = $this->document($staff);
        $url = '/documents/'.$document->id.'/status';
        $this->actingAs($staff)->post($url, ['status' => 'on_hold', 'reason' => '   '])->assertSessionHasErrors('reason');
        $this->post($url, ['status' => 'on_hold', 'reason' => 'Waiting for supporting documents'])->assertSessionHasNoErrors();
        $event = $document->events()->reorder()->latest('id')->firstOrFail();
        $this->assertSame('Processing put on hold.', $event->details['summary']);
        $this->assertSame('Waiting for supporting documents', $event->details['remarks']);
        $this->post($url, ['status' => 'in_progress'])->assertSessionHasNoErrors();
        $this->assertSame('Processing resumed.', $document->events()->reorder()->latest('id')->firstOrFail()->details['summary']);
        $this->post($url, ['status' => 'cancelled'])->assertSessionHasErrors('reason');
        $this->post($url, ['status' => 'cancelled', 'reason' => 'Duplicate request'])->assertSessionHasNoErrors();
        $event = $document->events()->reorder()->latest('id')->firstOrFail();
        $this->assertSame('Document cancelled.', $event->details['summary']);
        $this->assertSame('Duplicate request', $event->details['remarks']);
    }

    public function test_admin_reopening_keeps_a_reason_and_clears_completion_and_release_dates(): void
    {
        $staff = $this->staff('A');
        $document = $this->document($staff);
        $url = '/documents/'.$document->id.'/status';
        $this->actingAs($staff)->post($url, ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->post($url, ['status' => 'released', 'release_method' => 'Counter'])->assertSessionHasNoErrors();
        $this->post($url, ['status' => 'in_progress', 'reason' => 'Review again'])->assertSessionHasErrors('status');
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => $staff->department_id]);
        $this->actingAs($admin)->post($url, ['status' => 'in_progress'])->assertSessionHasErrors('reason');
        $this->post($url, ['status' => 'in_progress', 'reason' => 'Additional review needed'])->assertSessionHasNoErrors();
        $event = $document->events()->reorder()->latest('id')->firstOrFail();
        $this->assertSame('reopened', $event->event_type);
        $this->assertSame('Processing reopened.', $event->details['summary']);
        $this->assertSame('Additional review needed', $event->details['remarks']);
        $this->assertNull($document->fresh()->completed_at);
        $this->assertNull($document->fresh()->released_at);
        $this->assertNull($document->fresh()->release_method);
    }

    public function test_quick_actions_keep_custody_and_pending_handoff_guards(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $url = '/documents/'.$document->id;
        $this->actingAs($b)->post($url.'/status', ['status' => 'completed'])->assertForbidden();
        app(DocumentWorkflow::class)->forward($a, $document, $b->department_id, null);
        $this->actingAs($a)->post($url.'/status', ['status' => 'completed'])->assertSessionHasErrors('transfer');
        $this->actingAs($b)->post($url.'/status', ['status' => 'completed'])->assertForbidden();
        $this->assertSame(0, $document->events()->where('event_type', 'status_changed')->count());
        $this->assertSame(DocumentStatus::Awaiting, $document->fresh()->status);
    }

    public function test_optional_staff_notes_are_independent_of_automatic_status_entries(): void
    {
        $staff = $this->staff('A');
        $document = $this->document($staff);
        $url = '/documents/'.$document->id;
        $this->actingAs($staff)->post($url.'/status', ['status' => 'in_progress'])->assertSessionHasNoErrors();
        $this->post($url.'/remarks', ['remarks' => 'Additional information from the client'])->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Processing, $document->fresh()->status);
        $this->assertSame(1, $document->events()->where('event_type', 'status_changed')->count());
        $this->assertSame(1, $document->events()->where('event_type', 'remark_added')->count());
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('document.events.1.details.summary', 'Processing started.')
            ->where('document.events.2.details.remarks', 'Additional information from the client'));
    }
}
