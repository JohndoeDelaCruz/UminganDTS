<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Zero represents an administrator without an assigned office.
            $table->unsignedBigInteger('office_id');
            $table->string('section', 16);
            $table->unsignedBigInteger('last_seen_id')->default(0);
            $table->unique(['user_id', 'office_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_reads');
    }
};
