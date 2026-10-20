<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'documents' => 'received_at',
        'document_transfers' => 'sent_at',
        'document_events' => 'occurred_at',
        'public_tracking_tokens' => 'issued_at',
        'audit_logs' => 'created_at',
    ];

    public function up(): void
    {
        // MariaDB 10.4 can silently attach ON UPDATE CURRENT_TIMESTAMP to the
        // first non-null TIMESTAMP. Workflow dates must remain explicit UTC values.
        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dateTime($column)->change());
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            // An explicit default avoids restoring MariaDB's implicit ON UPDATE.
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->timestamp($column)->useCurrent()->change());
        }
    }
};
