<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-Task (docs/30-data-model/00-data-model.md#ent-task).
     * Write-owner: Module 3 CRM (ownership matrix); no reader modules.
     * Manual/internal entity — no Provenance envelope.
     *
     * `assignee_user_id` follows the existing users-FK precedent
     * (content_hashtags.resolved_by): nullable + nullOnDelete so removing
     * a user unassigns their tasks instead of blocking the delete.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('status', 20);
            $table->foreignId('assignee_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->foreignId('creator_id')->nullable()->index()->constrained();
            $table->foreignId('campaign_id')->nullable()->index()->constrained();
            $table->timestamps();
        });

        // ENUM-TaskStatus — closed set, canonical in docs/00-meta/03-glossary.md#enum-taskstatus.
        DB::statement(<<<'SQL'
            ALTER TABLE tasks ADD CONSTRAINT tasks_status_check
                CHECK (status IN ('OPEN','IN_PROGRESS','BLOCKED','DONE','CANCELLED'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
