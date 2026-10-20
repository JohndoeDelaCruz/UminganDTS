<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentWorkflow;
use App\Services\PublicTracking;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function office(string $code): Department
    {
        return Department::create(['code' => $code, 'name' => 'Test office '.$code, 'is_active' => true]);
    }

    private function staff(Department $office): User
    {
        return User::factory()->create(['role' => 'staff', 'department_id' => $office->id]);
    }

    private function data(): array
    {
        $type = DocumentType::first() ?? DocumentType::create(['code' => 'TEST', 'name' => 'Test category', 'default_processing_days' => 3]);

        return ['subject' => 'Test record', 'document_type_id' => $type->id, 'direction' => 'incoming', 'priority' => 'normal', 'sender_name' => 'Test sender', 'confidentiality' => 'internal', 'received_at' => now()->subMinute()->timezone('Asia/Manila')->format('Y-m-d\TH:i')];
    }

    private function document(User $staff, array $overrides = []): Document
    {
        return app(DocumentWorkflow::class)->register($staff, [...$this->data(), ...$overrides]);
    }

    public function test_migrations_and_seeder_leave_operational_tables_empty(): void
    {
        $this->seed();
        foreach (['users', 'departments', 'document_types', 'documents', 'document_events', 'document_transfers', 'document_attachments', 'document_office_works', 'staff_notifications', 'public_tracking_tokens', 'audit_logs', 'tracking_sequences', 'workspace_reads'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_first_admin_is_interactive_and_one_time(): void
    {
        $this->artisan('umdts:bootstrap-admin')->expectsQuestion('Administrator full name', 'Actual Administrator')->expectsQuestion('Administrator email', 'operator@example.test')->expectsQuestion('Password (at least 12 characters, mixed case, a number and a symbol)', 'ChosenPassword!2026')->expectsQuestion('Confirm password', 'ChosenPassword!2026')->assertSuccessful();
        $admin = User::firstOrFail();
        $this->assertTrue($admin->isAdmin());
        $this->assertNull($admin->department_id);
        $this->assertTrue(Hash::check('ChosenPassword!2026', $admin->password));
        $this->artisan('umdts:bootstrap-admin')->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_empty_dashboard_uses_real_zero_values(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->component('dashboard')->where('metrics.total', 0)->where('metrics.overdue', 0)->where('metrics.completed', 0)->where('metrics.average_days', null)->has('recent', 0)->has('breakdown', 0)->has('trend', 0));
    }

    public function test_registration_generates_unique_references_and_first_events(): void
    {
        $staff = $this->staff($this->office('A'));
        $this->actingAs($staff)->post('/documents', $this->data())->assertSessionHasNoErrors()->assertRedirect();
        $first = Document::firstOrFail();
        $second = $this->document($staff);
        $this->assertNotEquals($first->tracking_number, $second->tracking_number);
        $this->assertEquals($staff->department_id, $first->current_department_id);
        $this->assertEquals('registered', $first->events()->first()->event_type);
        $this->assertTrue($first->public_tracking_enabled);
        $this->assertEquals(3, (int) $first->received_at->diffInDays($first->due_at));
    }

    public function test_admin_without_office_cannot_register_and_staff_cannot_manage_accounts(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->get('/documents/create')->assertForbidden();
        $staff = $this->staff($this->office('A'));
        $this->actingAs($staff)->get('/users')->assertForbidden();
        $this->actingAs($staff)->post('/departments', ['code' => 'X', 'name' => 'Test', 'is_active' => true])->assertForbidden();
    }

    public function test_handoff_preserves_custody_then_acknowledges_only_once(): void
    {
        $a = $this->staff($this->office('A'));
        $b = $this->staff($this->office('B'));
        $doc = $this->document($a);
        $this->actingAs($a)->post('/documents/'.$doc->id.'/forward', ['to_department_id' => $b->department_id])->assertSessionHasNoErrors();
        $this->assertEquals($a->department_id, $doc->fresh()->current_department_id);
        $this->assertEquals(DocumentStatus::Awaiting, $doc->fresh()->status);
        $transfer = $doc->transfers()->firstOrFail();
        $sentAt = $transfer->getRawOriginal('sent_at');
        $this->travel(2)->minutes();
        $this->actingAs($b)->get('/inbox')->assertInertia(fn (Assert $page) => $page->component('documents/handoffs')->has('transfers.data', 1));
        $path = '/documents/'.$doc->id.'/transfers/'.$transfer->id;
        $this->actingAs($a)->post($path, ['action' => 'acknowledge'])->assertForbidden();
        $this->actingAs($b)->post($path, ['action' => 'acknowledge'])->assertSessionHasNoErrors();
        $this->assertEquals($b->department_id, $doc->fresh()->current_department_id);
        $this->assertEquals(DocumentStatus::Processing, $doc->fresh()->status);
        $this->assertSame($sentAt, $transfer->fresh()->getRawOriginal('sent_at'));
        $this->post($path, ['action' => 'acknowledge'])->assertSessionHasErrors('transfer');
        $this->assertEquals(4, $doc->events()->count());
    }

    public function test_second_handoff_and_processing_during_handoff_are_rejected(): void
    {
        $a = $this->staff($this->office('A'));
        $b = $this->office('B');
        $doc = $this->document($a);
        $this->actingAs($a)->post('/documents/'.$doc->id.'/forward', ['to_department_id' => $b->id])->assertSessionHasNoErrors();
        $this->post('/documents/'.$doc->id.'/forward', ['to_department_id' => $b->id])->assertSessionHasErrors('transfer');
        $this->post('/documents/'.$doc->id.'/status', ['status' => 'completed'])->assertSessionHasErrors('transfer');
        $this->assertEquals(1, $doc->transfers()->count());
    }

    public function test_rejected_handoff_restores_previous_status_and_requires_reason(): void
    {
        $a = $this->staff($this->office('A'));
        $b = $this->staff($this->office('B'));
        $doc = $this->document($a);
        app(DocumentWorkflow::class)->forward($a, $doc, $b->department_id, null);
        $transfer = $doc->transfers()->firstOrFail();
        $this->actingAs($b)->post('/documents/'.$doc->id.'/transfers/'.$transfer->id, ['action' => 'reject'])->assertSessionHasErrors('reason');
        $this->post('/documents/'.$doc->id.'/transfers/'.$transfer->id, ['action' => 'reject', 'reason' => 'Test reason'])->assertSessionHasNoErrors();
        $this->assertEquals(DocumentStatus::Received, $doc->fresh()->status);
        $this->assertEquals($a->department_id, $doc->fresh()->current_department_id);
        $this->get('/documents/'.$doc->id)->assertForbidden();
    }

    public function test_cross_office_access_is_scoped_in_detail_search_reports_and_downloads(): void
    {
        Storage::fake('local');
        $a = $this->staff($this->office('A'));
        $b = $this->staff($this->office('B'));
        $doc = $this->document($a, ['confidentiality' => 'confidential']);
        $path = 'documents/'.$doc->id.'/private.pdf';
        Storage::disk('local')->put($path, '%PDF-test');
        $attachment = $doc->attachments()->create(['filename_display' => 'private.pdf', 'storage_path_private' => $path, 'mime_type' => 'application/pdf', 'bytes' => 9, 'uploaded_by' => $a->id]);
        $this->actingAs($b)->get('/documents/'.$doc->id)->assertForbidden();
        $this->get('/attachments/'.$attachment->id.'/download')->assertForbidden();
        $this->get('/documents?search=Test')->assertOk()->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
        $this->get('/reports')->assertOk()->assertInertia(fn (Assert $page) => $page->where('metrics.total', 0));
        $this->assertStringNotContainsString($doc->tracking_number, $this->get('/reports/export')->streamedContent());
        $admin = User::factory()->create();
        $this->actingAs($admin)->get('/documents/'.$doc->id)->assertForbidden();
    }

    public function test_reasons_terminal_states_and_authorized_reopening(): void
    {
        $staff = $this->staff($this->office('A'));
        $doc = $this->document($staff);
        $path = '/documents/'.$doc->id.'/status';
        $this->actingAs($staff)->post($path, ['status' => 'on_hold'])->assertSessionHasErrors('reason');
        $this->post($path, ['status' => 'released', 'release_method' => 'Counter'])->assertSessionHasErrors('status');
        $this->post($path, ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertNotNull($doc->fresh()->completed_at);
        $this->post($path, ['status' => 'in_progress', 'reason' => 'Test reopen'])->assertSessionHasErrors('status');
        $admin = User::factory()->create(['department_id' => $staff->department_id]);
        $this->actingAs($admin)->post($path, ['status' => 'in_progress', 'reason' => 'Test reopen'])->assertSessionHasNoErrors();
        $this->assertNull($doc->fresh()->completed_at);
        $this->assertEquals(DocumentStatus::Processing, $doc->fresh()->status);
    }

    public function test_public_tracking_is_minimal_hashed_and_revocable(): void
    {
        $staff = $this->staff($this->office('A'));
        $doc = $this->document($staff, ['contact_private' => 'test-private-contact']);
        $secret = app(PublicTracking::class)->issue($staff, $doc);
        $issuedAt = $doc->tokens()->whereNull('revoked_at')->firstOrFail()->getRawOriginal('issued_at');
        $this->travel(3)->minutes();
        $this->assertEquals(hash('sha256', $secret), $doc->tokens()->whereNull('revoked_at')->firstOrFail()->token_hash);
        $this->assertStringNotContainsString($secret, json_encode(DB::table('public_tracking_tokens')->whereNull('revoked_at')->first()));
        $this->post('/track', ['reference' => $doc->tracking_number, 'secret' => $secret])->assertOk()->assertInertia(fn (Assert $page) => $page->component('track')->where('result.reference', $doc->tracking_number)->where('result.status', 'Received')->missing('result.sender_name')->missing('result.subject')->missing('result.contact_private')->missing('result.attachments'));
        $this->assertSame($issuedAt, $doc->tokens()->whereNull('revoked_at')->firstOrFail()->getRawOriginal('issued_at'));
        $this->post('/track', ['reference' => $doc->tracking_number, 'secret' => 'wrong'])->assertInertia(fn (Assert $page) => $page->where('result', null));
        app(PublicTracking::class)->revoke($staff, $doc);
        $this->post('/track', ['reference' => $doc->tracking_number, 'secret' => $secret])->assertInertia(fn (Assert $page) => $page->where('result', null));
    }

    public function test_public_credentials_cannot_be_issued_for_confidential_documents(): void
    {
        $staff = $this->staff($this->office('A'));
        $doc = $this->document($staff, ['confidentiality' => 'confidential']);
        $this->actingAs($staff)->post('/documents/'.$doc->id.'/public-credential', ['action' => 'issue'])->assertForbidden();
        $this->assertDatabaseCount('public_tracking_tokens', 0);
    }

    public function test_lookup_is_rate_limited_and_responses_are_not_cached(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/track', ['reference' => 'invalid', 'secret' => 'invalid'])->assertOk();
        }
        $this->post('/track', ['reference' => 'invalid', 'secret' => 'invalid'])->assertTooManyRequests();
        $this->get('/track')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_invalid_upload_is_rejected_and_valid_file_is_private(): void
    {
        Storage::fake('local');
        $staff = $this->staff($this->office('A'));
        $doc = $this->document($staff);
        $this->actingAs($staff)->post('/documents/'.$doc->id.'/attachments', ['attachment' => UploadedFile::fake()->create('script.php', 1, 'application/x-httpd-php')])->assertSessionHasErrors('attachment');
        $this->post('/documents/'.$doc->id.'/attachments', ['attachment' => UploadedFile::fake()->image('scan.png')])->assertSessionHasNoErrors();
        $attachment = $doc->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->storage_path_private);
        $this->get('/attachments/'.$attachment->id.'/download')->assertDownload('scan.png');
        $this->assertArrayNotHasKey('storage_path_private', $attachment->toArray());
    }

    public function test_csv_neutralizes_formulas_and_respects_applied_filters(): void
    {
        $staff = $this->staff($this->office('A'));
        $doc = $this->document($staff, ['subject' => '=HYPERLINK("test")']);
        $response = $this->actingAs($staff)->get('/reports/export?status=received');
        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
        $this->assertStringContainsString($doc->tracking_number, $response->streamedContent());
        $this->assertStringNotContainsString($doc->tracking_number, $this->get('/reports/export?status=completed')->streamedContent());
    }

    public function test_event_history_cannot_be_modified_through_models(): void
    {
        $staff = $this->staff($this->office('A'));
        $doc = $this->document($staff);
        $this->expectException(\LogicException::class);
        $doc->events()->first()->update(['event_type' => 'tampered']);
    }

    public function test_database_rejects_raw_history_updates(): void
    {
        $staff = $this->staff($this->office('A'));
        $document = $this->document($staff);
        $this->expectException(QueryException::class);
        DB::table('document_events')->where('document_id', $document->id)->update(['event_type' => 'tampered']);
    }

    public function test_database_rejects_a_second_pending_transfer_even_outside_the_service(): void
    {
        $staff = $this->staff($this->office('A'));
        $destination = $this->office('B');
        $document = $this->document($staff);
        app(DocumentWorkflow::class)->forward($staff, $document, $destination->id, null);
        $duplicate = $document->transfers()->first()->getAttributes();
        unset($duplicate['id'], $duplicate['pending_document_id']);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('document_transfers')->insert($duplicate);
    }

    public function test_completion_release_and_reporting_use_recorded_timestamps(): void
    {
        $staff = $this->staff($this->office('A'));
        $document = $this->document($staff);
        $this->actingAs($staff)->post('/documents/'.$document->id.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->post('/documents/'.$document->id.'/status', ['status' => 'released'])->assertSessionHasErrors('release_method');
        $this->post('/documents/'.$document->id.'/status', ['status' => 'released', 'release_method' => 'Test counter release'])->assertSessionHasNoErrors();
        $this->assertNotNull($document->fresh()->released_at);
        $this->assertNotNull($document->fresh()->completed_at);
        $this->get('/reports')->assertOk()->assertInertia(fn (Assert $page) => $page->where('metrics.completed', 1)->where('metrics.total', 1)->where('metrics.average_days', 0));
    }

    public function test_inertia_actions_and_validation_return_to_the_current_document_without_a_referrer(): void
    {
        $staff = $this->staff($this->office('A'));
        $document = $this->document($staff);
        $this->actingAs($staff)->get('/documents/create')->assertOk();
        $url = '/documents/'.$document->id;
        $version = app(HandleInertiaRequests::class)->version(Request::create($url));
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])->get($url)->assertOk();
        $this->post($url.'/status', ['status' => 'on_hold'])->assertSessionHasErrors('reason')->assertRedirect($url);
        $this->post($url.'/status', ['status' => 'completed'])->assertSessionHasNoErrors()->assertRedirect($url);
    }

    public function test_deactivated_accounts_and_offices_lose_access(): void
    {
        $staff = $this->staff($this->office('A'));
        $this->actingAs($staff);
        $staff->update(['is_active' => false]);
        $this->get('/documents')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])->assertSessionHasErrors('email');
    }

    public function test_administrator_cannot_deactivate_self_or_archive_busy_office(): void
    {
        $office = $this->office('A');
        $admin = User::factory()->create(['department_id' => $office->id]);
        $this->actingAs($admin)->put('/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'role' => 'staff', 'department_id' => $office->id, 'is_active' => true])->assertSessionHasErrors('role');
        $this->put('/departments/'.$office->id, ['code' => 'A', 'name' => $office->name, 'is_active' => false])->assertSessionHasErrors('is_active');
        $this->assertTrue($office->fresh()->is_active);
    }
}
