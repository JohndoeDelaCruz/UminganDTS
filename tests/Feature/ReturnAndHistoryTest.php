<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReturnAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $code): User
    {
        $office = Department::create(['code' => $code, 'name' => 'Office '.$code, 'is_active' => true]);

        return User::factory()->create(['role' => 'staff', 'department_id' => $office->id]);
    }

    private function document(User $staff, array $overrides = []): Document
    {
        $type = DocumentType::first() ?? DocumentType::create(['code' => 'TEST', 'name' => 'Test category', 'is_active' => true]);
        $this->actingAs($staff)->post('/documents', [
            'subject' => 'Private subject 3f18', 'description' => 'Private description 63dc',
            'sender_name' => 'Private sender 849a', 'contact_private' => 'Private contact f1c0',
            'document_type_id' => $type->id, 'direction' => 'incoming', 'priority' => 'normal',
            'confidentiality' => 'internal', 'received_at' => now()->subMinute()->timezone('Asia/Manila')->format('Y-m-d\TH:i'),
            ...$overrides,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Document::latest()->firstOrFail();
    }

    private function forward(User $from, User $to, Document $document): void
    {
        $this->actingAs($from)->post('/documents/'.$document->id.'/forward', ['to_department_id' => $to->department_id, 'remarks' => 'Private routing note c1a8'])->assertSessionHasNoErrors();
        $transfer = $document->transfers()->where('status', 'pending')->firstOrFail();
        $this->actingAs($to)->post('/documents/'.$document->id.'/transfers/'.$transfer->id, ['action' => 'acknowledge'])->assertSessionHasNoErrors();
    }

    public function test_return_is_a_pending_handoff_and_changes_custody_only_on_acknowledgment(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $this->forward($a, $b, $document);
        $url = '/documents/'.$document->id;
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('returnDepartmentId', $a->department_id));
        $this->post($url.'/return', ['to_department_id' => $a->department_id, 'reason' => 'Correct the reference'])->assertSessionHasNoErrors();
        $return = $document->transfers()->where('status', 'pending')->firstOrFail();
        $sentAt = $return->getRawOriginal('sent_at');
        $this->assertSame('return', $return->handoff_type);
        $this->assertSame('Correct the reference', $return->routing_remarks);
        $this->assertSame($b->department_id, $document->fresh()->current_department_id);
        $this->assertSame(DocumentStatus::Awaiting, $document->fresh()->status);
        $this->actingAs($a)->get('/inbox')->assertInertia(fn (Assert $page) => $page->where('transfers.total', 1)->where('transfers.data.0.handoff_type', 'return')->where('transfers.data.0.can_open', true));
        $this->travel(2)->minutes();
        $this->post($url.'/transfers/'.$return->id, ['action' => 'acknowledge'])->assertSessionHasNoErrors();
        $this->assertSame($a->department_id, $document->fresh()->current_department_id);
        $this->assertSame(DocumentStatus::Returned, $document->fresh()->status);
        $this->assertSame($sentAt, $return->fresh()->getRawOriginal('sent_at'));
        $this->assertSame(1, $document->events()->where('event_type', 'return_requested')->count());
        $this->assertSame(1, $document->events()->where('event_type', 'return_acknowledged')->count());
        $eventCount = $document->events()->count();
        $this->post($url.'/transfers/'.$return->id, ['action' => 'acknowledge'])->assertSessionHasErrors('transfer');
        $this->assertSame($eventCount, $document->events()->count());
        $this->get('/inbox?view=received')->assertInertia(fn (Assert $page) => $page->where('transfers.total', 1)->where('counts.pending', 0)->where('counts.received', 1));
        $this->post($url.'/status', ['status' => 'in_progress'])->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Processing, $document->fresh()->status);
    }

    public function test_return_requires_valid_destination_reason_and_current_custody(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $c = $this->staff('C');
        $document = $this->document($a);
        $url = '/documents/'.$document->id.'/return';
        $this->post($url, [])->assertSessionHasErrors(['to_department_id', 'reason']);
        $this->post($url, ['to_department_id' => $b->department_id, 'reason' => '   '])->assertSessionHasErrors('reason');
        $this->post($url, ['to_department_id' => $a->department_id, 'reason' => 'Correction'])->assertSessionHasErrors('to_department_id');
        $b->department->update(['is_active' => false]);
        $this->post($url, ['to_department_id' => $b->department_id, 'reason' => 'Correction'])->assertSessionHasErrors('to_department_id');
        $this->actingAs($c)->post($url, ['to_department_id' => $a->department_id, 'reason' => 'Correction'])->assertForbidden();
        $this->assertDatabaseCount('document_transfers', 0);
        $this->assertSame(DocumentStatus::Received, $document->fresh()->status);
    }

    public function test_return_is_blocked_during_hold_terminal_states_and_another_handoff(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $url = '/documents/'.$document->id;
        $data = ['to_department_id' => $b->department_id, 'reason' => 'Correction'];
        $this->post($url.'/status', ['status' => 'on_hold', 'reason' => 'Wait'])->assertSessionHasNoErrors();
        $this->post($url.'/return', $data)->assertSessionHasErrors('status');
        $this->post($url.'/status', ['status' => 'in_progress'])->assertSessionHasNoErrors();
        $this->post($url.'/return', $data)->assertSessionHasNoErrors();
        $this->post($url.'/return', $data)->assertSessionHasErrors('transfer');
        $this->post($url.'/forward', ['to_department_id' => $b->department_id])->assertSessionHasErrors('transfer');
        $this->assertDatabaseCount('document_transfers', 1);
        $return = $document->transfers()->firstOrFail();
        $this->post($url.'/transfers/'.$return->id, ['action' => 'cancel', 'reason' => 'Cancel test'])->assertSessionHasNoErrors();
        $this->post($url.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->post($url.'/return', $data)->assertSessionHasErrors('status');
        $this->assertSame(DocumentStatus::Completed, $document->fresh()->status);
    }

    public function test_a_return_cannot_be_simulated_with_only_a_status_change(): void
    {
        $a = $this->staff('A');
        $document = $this->document($a);
        $this->post('/documents/'.$document->id.'/status', ['status' => 'returned', 'reason' => 'Correction'])->assertSessionHasErrors('status');
        $this->assertSame(DocumentStatus::Received, $document->fresh()->status);
        $this->assertDatabaseCount('document_transfers', 0);
    }

    public function test_rejected_and_cancelled_returns_restore_sender_status_and_remain_in_history(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $c = $this->staff('C');
        $document = $this->document($a);
        $this->forward($a, $b, $document);
        $url = '/documents/'.$document->id;
        $this->post($url.'/return', ['to_department_id' => $a->department_id, 'reason' => 'Correction'])->assertSessionHasNoErrors();
        $return = $document->transfers()->where('status', 'pending')->firstOrFail();
        $this->actingAs($c)->post($url.'/transfers/'.$return->id, ['action' => 'acknowledge'])->assertForbidden();
        $this->actingAs($a)->post($url.'/transfers/'.$return->id, ['action' => 'reject'])->assertSessionHasErrors('reason');
        $this->post($url.'/transfers/'.$return->id, ['action' => 'reject', 'reason' => 'Wrong destination'])->assertSessionHasNoErrors();
        $this->assertSame($b->department_id, $document->fresh()->current_department_id);
        $this->assertSame(DocumentStatus::Processing, $document->fresh()->status);
        $this->actingAs($b)->post($url.'/return', ['to_department_id' => $a->department_id, 'reason' => 'Correction'])->assertSessionHasNoErrors();
        $second = $document->transfers()->where('status', 'pending')->firstOrFail();
        $this->post($url.'/transfers/'.$second->id, ['action' => 'cancel'])->assertSessionHasErrors('reason');
        $this->post($url.'/transfers/'.$second->id, ['action' => 'cancel', 'reason' => 'Resolve locally'])->assertSessionHasNoErrors();
        $this->get('/outgoing')->assertInertia(fn (Assert $page) => $page->where('transfers.total', 2));
        $this->get('/outgoing?transfer_status=rejected')->assertInertia(fn (Assert $page) => $page->where('transfers.total', 1)->where('transfers.data.0.status', 'rejected'));
        $this->get('/outgoing?transfer_status=cancelled')->assertInertia(fn (Assert $page) => $page->where('transfers.total', 1));
        $this->assertSame(DocumentStatus::Processing, $document->fresh()->status);
        $this->assertSame(1, $document->events()->where('event_type', 'return_rejected')->count());
        $this->assertSame(1, $document->events()->where('event_type', 'return_cancelled')->count());
    }

    public function test_sent_and_received_history_survive_later_handoffs_without_exposing_private_data(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $c = $this->staff('C');
        $outsider = $this->staff('D');
        $document = $this->document($a, ['confidentiality' => 'confidential']);
        $this->forward($a, $b, $document);
        $this->forward($b, $c, $document);
        $response = $this->actingAs($a)->get('/outgoing')->assertOk()->assertInertia(fn (Assert $page) => $page->component('documents/handoffs')->where('transfers.total', 1)
            ->where('transfers.data.0.reference', $document->tracking_number)->where('transfers.data.0.can_open', false)->where('transfers.data.0.document_id', null)
            ->where('transfers.data.0.subject', null)->where('transfers.data.0.to_office', 'Office B')->missing('transfers.data.0.current_department_id')->missing('transfers.data.0.routing_remarks'));
        foreach (['Private subject 3f18', 'Private description 63dc', 'Private sender 849a', 'Private contact f1c0', 'Private routing note c1a8'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->get('/documents/'.$document->id)->assertForbidden();
        $this->get('/outgoing?search=3f18')->assertInertia(fn (Assert $page) => $page->where('transfers.total', 0));
        $this->get('/outgoing?search='.$document->tracking_number)->assertInertia(fn (Assert $page) => $page->where('transfers.total', 1));
        $this->actingAs($b)->get('/inbox?view=received')->assertInertia(fn (Assert $page) => $page->where('transfers.total', 1)->where('transfers.data.0.can_open', false)->where('transfers.data.0.subject', null));
        $this->get('/inbox')->assertInertia(fn (Assert $page) => $page->where('transfers.total', 0));
        $this->actingAs($outsider)->get('/outgoing?search='.$document->tracking_number)->assertInertia(fn (Assert $page) => $page->where('transfers.total', 0));
        $this->get('/inbox?view=received')->assertInertia(fn (Assert $page) => $page->where('transfers.total', 0));
    }

    public function test_accessible_handoff_links_open_only_documents_the_office_can_currently_view(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $this->post('/documents/'.$document->id.'/forward', ['to_department_id' => $b->department_id])->assertSessionHasNoErrors();
        $this->get('/outgoing?search=3f18')->assertInertia(fn (Assert $page) => $page->where('transfers.data.0.document_id', $document->id)->where('transfers.data.0.subject', $document->subject)->where('transfers.data.0.can_open', true));
        $this->actingAs($b)->get('/inbox')->assertInertia(fn (Assert $page) => $page->where('transfers.data.0.can_open', true)->where('transfers.data.0.document_id', $document->id));
    }

    public function test_received_dates_use_acknowledgment_while_outgoing_dates_use_sent_time(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $this->post('/documents/'.$document->id.'/forward', ['to_department_id' => $b->department_id])->assertSessionHasNoErrors();
        $transfer = $document->transfers()->firstOrFail();
        $this->travel(1)->days();
        $today = now()->timezone('Asia/Manila')->toDateString();
        $this->actingAs($b)->post('/documents/'.$document->id.'/transfers/'.$transfer->id, ['action' => 'acknowledge'])->assertSessionHasNoErrors();
        $this->get('/inbox?view=received&from='.$today.'&to='.$today)->assertInertia(fn (Assert $page) => $page->where('transfers.total', 1));
        $this->actingAs($a)->get('/outgoing?from='.$today.'&to='.$today)->assertInertia(fn (Assert $page) => $page->where('transfers.total', 0));
    }

    public function test_history_preserves_every_movement_when_a_document_visits_an_office_again(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $this->forward($a, $b, $document);
        $this->forward($b, $a, $document);
        $this->forward($a, $b, $document);
        $this->actingAs($a)->get('/outgoing')->assertInertia(fn (Assert $page) => $page->where('transfers.total', 2)->has('transfers.data', 2));
        $this->actingAs($b)->get('/inbox?view=received')->assertInertia(fn (Assert $page) => $page->where('transfers.total', 2)->has('transfers.data', 2));
    }

    public function test_administrator_without_office_does_not_get_an_unscoped_transfer_history(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $this->forward($a, $b, $document);
        $admin = User::factory()->create(['department_id' => null]);
        $this->actingAs($admin)->get('/outgoing')->assertInertia(fn (Assert $page) => $page->where('transfers.total', 0));
        $this->get('/inbox?view=received')->assertInertia(fn (Assert $page) => $page->where('transfers.total', 0));
    }
}
