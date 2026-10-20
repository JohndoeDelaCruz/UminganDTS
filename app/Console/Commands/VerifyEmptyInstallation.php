<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyEmptyInstallation extends Command
{
    protected $signature = 'umdts:verify-empty';

    protected $description = 'Read-only check that every operational table is empty before first-admin provisioning';

    public function handle(): int
    {
        $rows = [];
        $empty = true;
        foreach (['users', 'departments', 'document_types', 'documents', 'tracking_sequences', 'document_transfers', 'document_events', 'document_attachments', 'document_office_works', 'staff_notifications', 'public_tracking_tokens', 'audit_logs', 'workspace_reads'] as $table) {
            $count = DB::table($table)->count();
            $rows[] = [$table, $count];
            $empty = $empty && $count === 0;
        }
        $this->table(['Operational table', 'Rows'], $rows);
        if ($empty) {
            $this->info('Verified: installation contains zero operational rows.');
        } else {
            $this->warn('Operational data exists. No records were changed or deleted.');
        }

        return $empty ? self::SUCCESS : self::FAILURE;
    }
}
