<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('document_transfers', 'pending_document_id')) {
            Schema::table('document_transfers', function (Blueprint $table) {
                $table->string('pending_document_id', 36)->nullable()->storedAs("CASE WHEN status = 'pending' THEN document_id ELSE NULL END")->unique();
            });
        }
        foreach (['document_events', 'audit_logs'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $action) {
                $trigger = $table.'_no_'.strtolower($action);
                if (! DB::table('information_schema.triggers')->whereRaw('TRIGGER_SCHEMA = DATABASE()')->where('TRIGGER_NAME', $trigger)->exists()) {
                    DB::unprepared("CREATE TRIGGER $trigger BEFORE $action ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'History is append-only'");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['document_events', 'audit_logs'] as $table) {
            foreach (['update', 'delete'] as $action) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_{$action}");
            }
        }
        Schema::table('document_transfers', function (Blueprint $table) {
            $table->dropUnique(['pending_document_id']);
            $table->dropColumn('pending_document_id');
        });
    }
};
