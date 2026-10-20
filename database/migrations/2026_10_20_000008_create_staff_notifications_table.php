<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->constrained('document_events')->restrictOnDelete();
            $table->foreignId('office_work_id')->nullable()->constrained('document_office_works')->restrictOnDelete();
            $table->foreignId('transfer_id')->nullable()->constrained('document_transfers')->restrictOnDelete();
            $table->string('kind', 30);
            $table->dateTime('created_at');
            $table->dateTime('read_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->unique(['user_id', 'event_id']);
            $table->index(['user_id', 'department_id', 'resolved_at', 'id'], 'staff_notifications_inbox');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notifications');
    }
};
