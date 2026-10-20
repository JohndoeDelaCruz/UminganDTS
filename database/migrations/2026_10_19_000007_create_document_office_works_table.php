<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_office_works', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('incoming_transfer_id')->nullable()->constrained('document_transfers')->restrictOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('assigned_at');
            $table->string('work_type', 40)->default('review');
            $table->string('status', 30)->default('assigned');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('review_result', 40)->nullable();
            $table->text('remarks')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->string('closed_reason', 30)->nullable();
            $table->timestamps();
            $table->index(['assigned_to', 'status']);
            $table->index(['document_id', 'department_id']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('current_office_work_id')->nullable()->constrained('document_office_works')->restrictOnDelete();
            $table->unsignedInteger('office_work_revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_office_work_id');
            $table->dropColumn('office_work_revision');
        });
        Schema::dropIfExists('document_office_works');
    }
};
