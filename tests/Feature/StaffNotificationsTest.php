<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\StaffNotification;
use App\Models\User;
use App\Services\DocumentWorkflow;
use App\Services\OfficeWork;
use App\Services\StaffNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $code, ?User $colleague = null): User
    {
        $office = $colleague?->department ?? Department::create(['code' => $code, 'name' => 'PRIVATE office '.$code, 'is_active' => true]);

        return User::factory()->create(['name' => 'PRIVATE staff '.$code, 'role' => 'staff', 'department_id' => $office->id]);
    }

    private function document(User $actor, string $classification = 'confidential'): Document
    {
        $category = DocumentType::first() ?? DocumentType::create(['code' => 'TEST', 'name' => 'Correspondence', 'is_active' => true]);

        return app(DocumentWorkflow::class)->register($actor, ['subject' => 'PRIVATE subject', 'document_type_id' => $category->id,
            'direction' => 'incoming', 'priority' => 'normal', 'confidentiality' => $classification, 'sender_name' => 'PRIVATE sender',
            'contact_private' => 'PRIVATE contact', 'description' => 'PRIVATE description',
            'received_at' => now()->subMinute()->timezone('Asia/Manila')->format('Y-m-d\TH:i')]);
    }

    private function assign(User $actor, Document $document, User $handler): void
    {
        app(OfficeWork::class)->act($actor, $document, ['action' => 'assign', 'staff_id' => $handler->id, 'revision' => $document->fresh()->office_work_revision]);
    }

    private function feed(User $user, int $page = 1): array
    {
        return $this->actingAs($user)->getJson('/workspace/notifications?page='.$page)->assertOk()->json();
    }

    private function send(User $from, User $to, Document $document, bool $return = false): int
    {
        $workflow = app(DocumentWorkflow::class);
        if ($return) {
            $workflow->returnForCorrection($from, $document, $to->department_id, 'PRIVATE correction reason');
        } else {
            $workflow->forward($from, $document, $to->department_id, 'PRIVATE routing note');
        }

        return $document->transfers()->where('status', 'pending')->sole()->id;
    }

    public function test_empty_notifications_require_authentication_and_do_not_seed_or_backfill_records(): void
    {
        $this->getJson('/workspace/notifications')->assertUnauthorized();
        $this->postJson('/workspace/notifications/read', ['through_id' => 1])->assertUnauthorized();
        $this->postJson('/workspace/notifications/1/open')->assertUnauthorized();
        $staff = $this->staff('A');
        $this->document($staff);
        $this->assertSame(['items' => [], 'unread' => 0, 'through_id' => 0, 'page' => 1, 'last_page' => 1, 'total' => 0], $this->feed($staff));
        $this->assertDatabaseCount('staff_notifications', 0);
        $this->getJson('/workspace/notifications')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('staffNotifications.unread', 0));
    }

    public function test_only_the_assigned_colleague_receives_an_assignment_alert_and_self_claims_are_quiet(): void
    {
        $a = $this->staff('A');
        $colleague = $this->staff('AA', $a);
        $third = $this->staff('AAA', $a);
        $document = $this->document($a);
        $this->assign($a, $document, $a);
        $this->assertDatabaseCount('staff_notifications', 0);
        $this->assign($a, $document, $colleague);
        $feed = $this->feed($colleague);
        $this->assertSame(1, $feed['unread']);
        $this->assertSame('assigned', $feed['items'][0]['kind']);
        $this->assertSame($document->tracking_number, $feed['items'][0]['reference']);
        $this->assertSame('Assigned to you', $feed['items'][0]['title']);
        $this->assertSame(0, $this->feed($a)['unread']);
        $this->assertSame(0, $this->feed($third)['unread']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($feed));
        $this->assertCount(8, $feed['items'][0]);
    }

    public function test_incoming_handoffs_notify_each_active_office_member_with_independent_persistent_reads(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $colleague = $this->staff('BB', $b);
        $inactive = $this->staff('BBB', $b);
        $inactive->update(['is_active' => false]);
        $document = $this->document($a);
        $this->send($a, $b, $document);
        $this->assertDatabaseCount('staff_notifications', 2);
        $feed = $this->feed($b);
        $this->assertSame('incoming', $feed['items'][0]['kind']);
        $eventCount = $document->events()->count();
        $this->post($feed['items'][0]['open_url'])->assertRedirect('/documents/'.$document->id);
        $this->assertSame(0, $this->feed($b->fresh())['unread']);
        $this->assertSame(1, $this->feed($colleague)['unread']);
        $this->assertSame($eventCount, $document->events()->count());
        $this->assertSame(DocumentStatus::Awaiting, $document->fresh()->status);
        $this->assertSame('pending', $document->transfers()->sole()->status);
        $this->assertSame($a->department_id, $document->fresh()->current_department_id);
        $this->assertSame(0, $this->feed($a)['unread']);
        $this->assertDatabaseMissing('staff_notifications', ['user_id' => $inactive->id]);
    }

    public function test_opening_a_notification_never_marks_another_users_alert_or_sidebar_tab_read(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $colleague = $this->staff('BB', $b);
        $document = $this->document($a);
        $this->send($a, $b, $document);
        $feed = $this->feed($b);
        $this->actingAs($colleague)->post($feed['items'][0]['open_url'])->assertNotFound();
        $this->feed($b);
        $this->post($feed['items'][0]['open_url'])->assertRedirect();
        $this->assertDatabaseCount('workspace_reads', 0);
        $this->getJson('/workspace/activity')->assertJsonPath('inbox', 1);
        $this->assertSame(1, $this->feed($colleague)['unread']);
    }

    public function test_read_all_uses_a_snapshot_does_not_mark_later_alerts_or_other_recipients_and_does_not_delete_rows(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $colleague = $this->staff('BB', $b);
        $first = $this->document($a);
        $this->send($a, $b, $first);
        $snapshot = $this->feed($b)['through_id'];
        $second = $this->document($a);
        $this->send($a, $b, $second);
        $this->actingAs($b)->from('/dashboard')->post('/workspace/notifications/read', ['through_id' => $snapshot])->assertRedirect('/dashboard');
        $this->assertSame(1, $this->feed($b)['unread']);
        $this->assertSame(2, $this->feed($colleague)['unread']);
        $this->assertDatabaseCount('staff_notifications', 4);
        $this->assertSame(2, count($this->feed($b)['items']));
        $readAt = StaffNotification::where('user_id', $b->id)->where('document_id', $first->id)->sole()->read_at->toDateTimeString();
        $this->travel(1)->minutes();
        $this->actingAs($b)->post('/workspace/notifications/read', ['through_id' => $snapshot]);
        $this->assertSame($readAt, StaffNotification::where('user_id', $b->id)->where('document_id', $first->id)->sole()->read_at->toDateTimeString());
        $this->post('/workspace/notifications/read', ['through_id' => -1])->assertSessionHasErrors('through_id');
    }

    public function test_reassigning_away_and_back_does_not_reactivate_an_old_assignment_alert(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('AA', $a);
        $c = $this->staff('AAA', $a);
        $document = $this->document($a);
        $this->assign($a, $document, $b);
        $old = $this->feed($b)['items'][0];
        $this->assign($a, $document, $c);
        $this->assertSame(0, $this->feed($b)['unread']);
        $this->actingAs($b)->from('/my-work')->post($old['open_url'])->assertRedirect('/my-work')->assertSessionHas('success');
        $this->assign($a, $document, $b);
        $this->assertSame(1, $this->feed($b)['unread']);
        $this->assertSame(0, $this->feed($c)['unread']);
        $this->assertNotSame($old['id'], $this->feed($b)['items'][0]['id']);
        $this->assertNotNull(StaffNotification::findOrFail($old['id'])->resolved_at);
    }

    public function test_finished_work_and_final_completion_remove_alerts_without_modifying_workflow_history(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('AA', $a);
        $document = $this->document($a);
        $this->assign($a, $document, $b);
        $work = app(OfficeWork::class);
        $work->act($b, $document, ['action' => 'start', 'revision' => $document->fresh()->office_work_revision, 'work_type' => 'review']);
        $this->assertSame(1, $this->feed($b)['unread']);
        $work->act($b, $document, ['action' => 'finish', 'revision' => $document->fresh()->office_work_revision, 'review_result' => 'reviewed']);
        $this->assertSame(0, $this->feed($b)['total']);
        $this->assertSame(DocumentStatus::Processing, $document->fresh()->status);
        $this->assertDatabaseCount('staff_notifications', 1);
        $this->assertSame(5, $document->events()->count());
        app(DocumentWorkflow::class)->transition($b, $document, 'completed', null, null);
        $this->assertSame(0, $this->feed($b)['unread']);
    }

    public function test_resolved_forward_handoffs_disappear_and_stale_links_cannot_acknowledge_or_open_private_records(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        foreach (['cancel', 'reject', 'acknowledge'] as $action) {
            $document = $this->document($a);
            $transfer = $this->send($a, $b, $document);
            $old = $this->feed($b)['items'][0];
            app(DocumentWorkflow::class)->resolveTransfer($action === 'cancel' ? $a : $b, $document, $transfer, $action, $action === 'acknowledge' ? null : 'PRIVATE reason');
            $this->assertSame(0, $this->feed($b)['total']);
            $this->actingAs($b)->from('/dashboard')->post($old['open_url'])->assertRedirect('/dashboard');
            $this->assertNotNull(StaffNotification::findOrFail($old['id'])->resolved_at);
            $this->assertNull(StaffNotification::findOrFail($old['id'])->read_at);
            if ($action !== 'acknowledge') {
                $this->get('/documents/'.$document->id)->assertForbidden();
            }
        }
    }

    public function test_return_alerts_remain_actionable_after_receipt_until_corrections_resume_and_do_not_revive_on_a_later_return(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $workflow = app(DocumentWorkflow::class);
        $transfer = $this->send($a, $b, $document, true);
        $feed = $this->feed($b);
        $this->assertSame('returned', $feed['items'][0]['kind']);
        $this->assertStringContainsString('Acknowledge', $feed['items'][0]['message']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($feed));
        $old = $feed['items'][0]['id'];
        $workflow->resolveTransfer($b, $document, $transfer, 'acknowledge', null);
        $this->assertSame(1, $this->feed($b)['unread']);
        $this->assertStringContainsString('resume processing', $this->feed($b)['items'][0]['message']);
        $workflow->transition($b, $document, 'in_progress', null, null);
        $this->assertSame(0, $this->feed($b)['total']);
        $transfer = $this->send($b, $a, $document);
        $workflow->resolveTransfer($a, $document, $transfer, 'acknowledge', null);
        $transfer = $this->send($a, $b, $document, true);
        $workflow->resolveTransfer($b, $document, $transfer, 'acknowledge', null);
        $this->assertSame(1, $this->feed($b)['unread']);
        $this->assertNotSame($old, $this->feed($b)['items'][0]['id']);
    }

    public function test_pending_movements_temporarily_hide_assignments_but_cancelled_returns_restore_them(): void
    {
        $a = $this->staff('A');
        $reviewer = $this->staff('AA', $a);
        $b = $this->staff('B');
        $document = $this->document($a);
        $this->assign($a, $document, $reviewer);
        $transfer = $this->send($a, $b, $document, true);
        $this->assertSame(0, $this->feed($reviewer)['unread']);
        app(DocumentWorkflow::class)->resolveTransfer($a, $document, $transfer, 'cancel', 'PRIVATE reason');
        $this->assertSame(1, $this->feed($reviewer)['unread']);
        $this->assertSame(0, $this->feed($b)['unread']);
    }

    public function test_notifications_stay_scoped_to_the_recipient_and_current_active_office_even_for_administrators(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $c = $this->staff('C');
        $admin = User::factory()->create(['role' => 'admin']);
        $document = $this->document($a);
        $this->send($a, $b, $document);
        $old = $this->feed($b)['items'][0];
        $this->assertSame(0, $this->feed($admin)['unread']);
        $this->post($old['open_url'])->assertNotFound();
        $b->update(['department_id' => $c->department_id]);
        $this->assertSame(0, $this->feed($b->fresh())['unread']);
        $this->post($old['open_url'])->assertNotFound();
        $b->update(['role' => 'admin', 'department_id' => $oldOffice = $document->transfers()->sole()->to_department_id]);
        Department::findOrFail($oldOffice)->update(['is_active' => false]);
        $this->assertSame(0, $this->feed($b->fresh())['unread']);
        $this->assertSame(0, $this->feed($a)['unread']);
    }

    public function test_a_cancelled_movement_restores_an_existing_correction_alert_until_custody_actually_changes(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = $this->document($a);
        $workflow = app(DocumentWorkflow::class);
        $transfer = $this->send($a, $b, $document, true);
        $workflow->resolveTransfer($b, $document, $transfer, 'acknowledge', null);
        $old = $this->feed($b)['items'][0]['id'];
        $transfer = $this->send($b, $a, $document, true);
        $this->assertSame(0, $this->feed($b)['total']);
        $workflow->resolveTransfer($b, $document, $transfer, 'cancel', 'Check again');
        $this->assertSame($old, $this->feed($b)['items'][0]['id']);
        $transfer = $this->send($b, $a, $document, true);
        $workflow->resolveTransfer($a, $document, $transfer, 'acknowledge', null);
        $this->assertSame(0, $this->feed($b)['total']);
        $this->assertNotNull(StaffNotification::findOrFail($old)->resolved_at);
    }

    public function test_feed_is_read_only_paginated_and_does_not_replace_form_validation_return_urls(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('AA', $a);
        for ($i = 0; $i < 12; $i++) {
            $this->assign($a, $this->document($a), $b);
        }
        $feed = $this->feed($b);
        $this->assertSame(12, $feed['unread']);
        $this->assertCount(10, $feed['items']);
        $this->assertSame(2, $feed['last_page']);
        $older = $this->feed($b, 2);
        $this->assertCount(2, $older['items']);
        $this->assertEmpty(array_intersect(array_column($feed['items'], 'id'), array_column($older['items'], 'id')));
        $this->assertSame(2, $this->feed($b, 999)['page']);
        $this->assertDatabaseMissing('staff_notifications', ['read_at' => now()->toDateTimeString()]);
        $this->actingAs($b)->get('/my-work')->assertOk();
        $previous = session()->previousUrl();
        $this->getJson('/workspace/notifications', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->assertSame($previous, session()->previousUrl());
        $this->getJson('/workspace/notifications?page=bad')->assertUnprocessable();
        $this->assertDatabaseCount('workspace_reads', 0);
    }

    public function test_notifications_are_transactional_deduplicated_and_do_not_expose_private_data_in_public_tracking(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('AA', $a);
        $document = $this->document($a, 'internal');
        DB::beginTransaction();
        $this->assign($a, $document, $b);
        $this->assertDatabaseCount('staff_notifications', 1);
        DB::rollBack();
        $this->assertDatabaseCount('staff_notifications', 0);
        $this->assertNull($document->fresh()->current_office_work_id);
        $this->assign($a, $document, $b);
        $event = $document->events()->where('event_type', 'office_work_assigned')->sole();
        app(StaffNotifications::class)->assigned($a, $document, $document->fresh()->currentOfficeWork, $event);
        $this->assertDatabaseCount('staff_notifications', 1);
        $this->assertSame(1, $this->feed($b)['unread']);
        $response = $this->actingAs($b)->post('/track', ['reference' => $document->tracking_number, 'secret' => $document->tokens()->sole()->token_encrypted]);
        $response->assertInertia(fn (Assert $page) => $page->where('staffNotifications', null)->has('result.timeline', 1));
        $this->assertStringNotContainsString('PRIVATE', json_encode($response->viewData('page')['props']['result']));
        $this->assertNull($document->fresh()->currentOfficeWork->finished_at);
    }
}
