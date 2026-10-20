<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_transfers', function (Blueprint $table) {
            $table->string('handoff_type', 16)->default('forward');
            $table->index(['from_department_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('document_transfers', function (Blueprint $table) {
            $table->dropIndex(['from_department_id', 'sent_at']);
            $table->dropColumn('handoff_type');
        });
    }
};
