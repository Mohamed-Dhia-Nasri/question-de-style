<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit-log foundation: sensitive changes (user/role administration,
     * later: identity merges, personal-data access, exports) create an audit
     * event. Context is a JSON bag that must NEVER contain decrypted personal
     * data, secrets, or raw external payloads — record identifiers instead.
     * Append-only by convention; rows are never updated.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100)->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('context')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
