<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportUminganOfficesTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_does_not_write_configuration_or_audits(): void
    {
        $this->artisan('umdts:import-umingan-offices')->expectsOutputToContain('Would add 24 offices')->assertSuccessful();
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_explicit_import_is_audited_and_can_be_repeated_without_duplicates(): void
    {
        $this->artisan('umdts:import-umingan-offices --apply')->assertSuccessful();
        $this->artisan('umdts:import-umingan-offices --apply')->expectsOutputToContain('Added 0 offices; kept 24 existing entries.')->assertSuccessful();
        $this->assertDatabaseCount('departments', 24);
        $this->assertDatabaseCount('audit_logs', 24);
        $audit = AuditLog::firstOrFail();
        $this->assertNull($audit->actor_user_id);
        $this->assertSame('department.imported', $audit->action);
        $this->assertContains('https://elgu-umingan-pangasinan-news.e.gov.ph/directory', $audit->metadata['source_urls']);
        foreach (['users', 'documents', 'document_types'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_alias_matching_preserves_existing_codes_names_and_inactive_status(): void
    {
        $office = Department::create(['code' => 'EXISTING-MAYOR', 'name' => 'Mayor’s Office', 'is_active' => false]);
        $this->artisan('umdts:import-umingan-offices --apply')->assertSuccessful();
        $this->assertDatabaseCount('departments', 24);
        $this->assertDatabaseCount('audit_logs', 23);
        $this->assertSame('EXISTING-MAYOR', $office->fresh()->code);
        $this->assertSame('Mayor’s Office', $office->fresh()->name);
        $this->assertFalse($office->fresh()->is_active);
    }

    public function test_code_conflict_does_not_partially_import_offices(): void
    {
        Department::create(['code' => 'MNAO', 'name' => 'Existing unrelated office']);
        $this->artisan('umdts:import-umingan-offices --apply')->expectsOutputToContain('already assigned to another office')->assertFailed();
        $this->assertDatabaseCount('departments', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_ambiguous_aliases_fail_without_changing_existing_records(): void
    {
        Department::create(['code' => 'HEALTH', 'name' => 'Municipal Health Office']);
        Department::create(['code' => 'RHU1', 'name' => 'Rural Health Unit I']);
        $this->artisan('umdts:import-umingan-offices --apply')->expectsOutputToContain('Multiple existing entries match')->assertFailed();
        $this->assertDatabaseCount('departments', 2);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
