<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('role', 16)->default('staff');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 150);
            $table->unsignedSmallInteger('default_processing_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('tracking_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tracking_number', 40)->unique();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('subject', 250);
            $table->text('description')->nullable();
            $table->string('direction', 16);
            $table->string('priority', 16)->default('normal');
            $table->string('sender_name', 180);
            $table->text('contact_private')->nullable();
            $table->string('originating_reference', 100)->nullable();
            $table->foreignId('source_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('current_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('received');
            $table->string('confidentiality', 16)->default('internal');
            $table->boolean('public_tracking_enabled')->default(false);
            $table->dateTime('received_at');
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_method', 180)->nullable();
            $table->timestamps();
            $table->index(['current_department_id', 'status']);
            $table->index(['document_type_id', 'received_at']);
            $table->index('received_at');
        });
        Schema::create('document_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('to_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('sent_at');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('previous_status', 32);
            $table->text('routing_remarks')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestamps();
            $table->index(['to_department_id', 'status']);
            $table->index(['document_id', 'status']);
        });
        Schema::create('document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->foreignId('from_department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('to_department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->json('details')->nullable();
            $table->dateTime('occurred_at');
            $table->index(['document_id', 'occurred_at']);
        });
        Schema::create('document_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained()->restrictOnDelete();
            $table->string('filename_display');
            $table->string('storage_path_private')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('bytes');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('public_tracking_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->dateTime('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('resource_type', 80);
            $table->string('resource_id', 80);
            $table->json('metadata')->nullable();
            $table->dateTime('created_at');
            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'public_tracking_tokens', 'document_attachments', 'document_events', 'document_transfers', 'documents', 'tracking_sequences', 'document_types'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['role', 'is_active']);
        });
        Schema::dropIfExists('departments');
    }
};
