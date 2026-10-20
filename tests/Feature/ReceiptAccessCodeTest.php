<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentWorkflow;
use App\Services\PublicTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReceiptAccessCodeTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $code): User
    {
        $office = Department::create(['code' => $code, 'name' => 'Office '.$code, 'is_active' => true]);

        return User::factory()->create(['role' => 'staff', 'department_id' => $office->id]);
    }

    private function data(string $classification = 'internal'): array
    {
        $type = DocumentType::first() ?? DocumentType::create(['code' => 'TEST', 'name' => 'Test category', 'is_active' => true]);

        return ['subject' => 'Private receipt subject', 'sender_name' => 'Private sender', 'contact_private' => 'Private contact',
            'document_type_id' => $type->id, 'direction' => 'incoming', 'priority' => 'normal', 'confidentiality' => $classification,
            'received_at' => now()->subMinute()->timezone('Asia/Manila')->format('Y-m-d\TH:i')];
    }

    public function test_registration_automatically_creates_a_short_code_and_keeps_it_for_receipt_reprints(): void
    {
        $staff = $this->staff('A');
        $this->actingAs($staff)->post('/documents', $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $document = Document::firstOrFail();
        $this->assertTrue($document->public_tracking_enabled);
        $token = $document->tokens()->sole();
        $code = $token->token_encrypted;
        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{8}$/', $code);
        $this->assertSame(hash('sha256', $code), $token->token_hash);
        $this->assertNotSame($code, $token->getRawOriginal('token_encrypted'));
        $this->assertStringNotContainsString($code, json_encode(DB::table('public_tracking_tokens')->first()));
        $this->assertArrayNotHasKey('token_hash', $token->toArray());
        $this->assertArrayNotHasKey('token_encrypted', $token->toArray());
        $events = $document->events()->count();
        for ($i = 0; $i < 2; $i++) {
            $this->get('/documents/'.$document->id)->assertInertia(fn (Assert $page) => $page
                ->where('receiptCredential.reference', $document->tracking_number)->where('receiptCredential.secret', $code)
                ->where('publicTrackingUrl', route('track'))->missing('flash.publicCredential'));
        }
        $this->assertSame($events, $document->events()->count());
        $this->assertDatabaseCount('public_tracking_tokens', 1);
        foreach (['document_events' => 'details', 'audit_logs' => 'metadata'] as $table => $column) {
            $this->assertStringNotContainsString($code, json_encode(DB::table($table)->pluck($column)));
        }
    }

    public function test_citizens_can_use_lowercase_grouped_or_spaced_codes_without_private_data_disclosure(): void
    {
        $staff = $this->staff('A');
        $document = app(DocumentWorkflow::class)->register($staff, $this->data());
        $code = $document->tokens()->sole()->token_encrypted;
        foreach ([$code, strtolower($code), substr($code, 0, 4).'-'.substr($code, 4), ' '.substr($code, 0, 4).' '.substr($code, 4).' '] as $input) {
            $response = $this->post('/track', ['reference' => ' '.strtolower($document->tracking_number).' ', 'secret' => $input]);
            $response->assertInertia(fn (Assert $page) => $page->where('result.reference', $document->tracking_number)
                ->where('result.status', 'Received')->missing('result.subject')->missing('result.sender_name')
                ->missing('result.contact_private')->missing('result.secret')->missing('result.attachments'));
            $this->assertStringNotContainsString($code, $response->getContent());
        }
        $this->post('/track', ['reference' => 'UMG-2000-999999', 'secret' => $code])->assertInertia(fn (Assert $page) => $page->where('result', null));
    }

    public function test_confidential_registration_never_enables_public_tracking_or_includes_a_code(): void
    {
        $staff = $this->staff('A');
        $this->actingAs($staff)->post('/documents', $this->data('confidential'))->assertSessionHasNoErrors();
        $document = Document::firstOrFail();
        $this->assertFalse($document->public_tracking_enabled);
        $this->assertDatabaseCount('public_tracking_tokens', 0);
        $this->get('/documents/'.$document->id)->assertInertia(fn (Assert $page) => $page->where('receiptCredential', null));
        $this->post('/documents/'.$document->id.'/public-credential', ['action' => 'issue'])->assertForbidden();
    }

    public function test_only_the_current_custodian_gets_the_reprint_code_not_incoming_staff_or_other_administrators(): void
    {
        $a = $this->staff('A');
        $b = $this->staff('B');
        $document = app(DocumentWorkflow::class)->register($a, $this->data());
        $code = $document->tokens()->sole()->token_encrypted;
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => null]);
        $this->actingAs($admin)->get('/documents/'.$document->id)->assertInertia(fn (Assert $page) => $page->where('receiptCredential', null));
        $workflow = app(DocumentWorkflow::class);
        $workflow->forward($a, $document, $b->department_id, null);
        $this->actingAs($b)->get('/documents/'.$document->id)->assertInertia(fn (Assert $page) => $page->where('receiptCredential', null));
        $this->actingAs($a)->get('/documents/'.$document->id)->assertInertia(fn (Assert $page) => $page->where('receiptCredential.secret', $code));
        $workflow->resolveTransfer($b, $document, $document->transfers()->firstOrFail()->id, 'acknowledge', null);
        $this->actingAs($b)->get('/documents/'.$document->id)->assertInertia(fn (Assert $page) => $page->where('receiptCredential.secret', $code));
        foreach (['/dashboard', '/documents', '/inbox?view=received', '/outgoing', '/reports'] as $url) {
            $response = $this->get($url)->assertInertia(fn (Assert $page) => $page->missing('receiptCredential')->missing('flash.publicCredential'));
            $this->assertStringNotContainsString($code, $response->getContent());
        }
    }

    public function test_replacement_and_revocation_update_receipts_without_reenabling_them_on_page_views(): void
    {
        $staff = $this->staff('A');
        $document = app(DocumentWorkflow::class)->register($staff, $this->data());
        $old = $document->tokens()->sole()->token_encrypted;
        $this->actingAs($staff)->post('/documents/'.$document->id.'/public-credential', ['action' => 'issue'])->assertSessionHasNoErrors();
        $new = $document->tokens()->whereNull('revoked_at')->sole()->token_encrypted;
        $this->assertNotSame($old, $new);
        $this->post('/track', ['reference' => $document->tracking_number, 'secret' => $old])->assertInertia(fn (Assert $page) => $page->where('result', null));
        $this->get('/documents/'.$document->id)->assertInertia(fn (Assert $page) => $page->where('receiptCredential.secret', $new));
        $this->post('/documents/'.$document->id.'/public-credential', ['action' => 'revoke'])->assertSessionHasNoErrors();
        $this->get('/documents/'.$document->id)->assertInertia(fn (Assert $page) => $page->where('receiptCredential', null)->where('document.public_tracking_enabled', false));
        $this->post('/track', ['reference' => $document->tracking_number, 'secret' => $new])->assertInertia(fn (Assert $page) => $page->where('result', null));
    }

    public function test_original_long_codes_stay_valid_without_a_reprint_copy_and_expired_codes_are_rejected(): void
    {
        $staff = $this->staff('A');
        $document = app(DocumentWorkflow::class)->register($staff, $this->data());
        app(PublicTracking::class)->revoke($staff, $document);
        $legacy = bin2hex(random_bytes(32));
        $token = $document->tokens()->create(['token_hash' => hash('sha256', $legacy), 'issued_at' => now()]);
        $document->refresh()->update(['public_tracking_enabled' => true]);
        $this->post('/track', ['reference' => $document->tracking_number, 'secret' => strtoupper($legacy)])->assertInertia(fn (Assert $page) => $page->where('result.reference', $document->tracking_number));
        $this->actingAs($staff)->get('/documents/'.$document->id)->assertInertia(fn (Assert $page) => $page->where('receiptCredential', null));
        $this->assertNull($token->fresh()->revoked_at);
        $token->update(['expires_at' => now()->subSecond()]);
        $this->post('/track', ['reference' => $document->tracking_number, 'secret' => $legacy])->assertInertia(fn (Assert $page) => $page->where('result', null));
    }

    public function test_failed_automatic_issuance_rolls_back_registration_and_history(): void
    {
        $staff = $this->staff('A');
        $data = $this->data();
        $this->mock(PublicTracking::class)->shouldReceive('issue')->once()->andThrow(new \RuntimeException('Issuance failure'));
        try {
            app(DocumentWorkflow::class)->register($staff, $data);
            $this->fail('Registration should fail when automatic code issuance fails.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Issuance failure', $e->getMessage());
        }
        foreach (['documents', 'document_events', 'public_tracking_tokens', 'audit_logs', 'tracking_sequences'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_random_code_collisions_are_retried_without_invalidating_the_new_receipt(): void
    {
        $staff = $this->staff('A');
        $document = app(DocumentWorkflow::class)->register($staff, $this->data());
        $old = $document->tokens()->sole()->token_encrypted;
        $new = ($old[0] === 'A' ? 'B' : 'A').substr($old, 1);
        $tracking = $this->partialMock(PublicTracking::class);
        $tracking->shouldAllowMockingProtectedMethods()->shouldReceive('newCode')->twice()->andReturn($old, $new);
        $this->assertSame($new, $tracking->issue($staff, $document));
        $this->assertSame($new, $document->tokens()->whereNull('revoked_at')->sole()->token_encrypted);
        $this->assertDatabaseCount('public_tracking_tokens', 2);
    }
}
