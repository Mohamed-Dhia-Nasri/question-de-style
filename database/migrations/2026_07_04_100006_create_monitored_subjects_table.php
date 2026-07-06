<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-MonitoredSubject (docs/30-data-model/00-data-model.md#ent-monitoredsubject).
     * Write-owner: Module 1 Monitoring (ownership matrix). Internal
     * configuration record — NOT externally sourced, so no Provenance
     * envelope (DP-002 does not apply).
     *
     * v1 is roster-first (ADR-0011): the active subject type is CREATOR,
     * referencing the tracked creator. Open-web term subjects
     * (BRAND/KEYWORD/HASHTAG/HANDLE + `terms`) are DEFERRED (DEF-006) — the
     * columns/values exist per the canonical model but are unused in v1.
     */
    public function up(): void
    {
        Schema::create('monitored_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 20);
            $table->string('label');
            $table->foreignId('creator_id')->nullable()->index()->constrained();
            $table->jsonb('terms')->nullable();
            $table->jsonb('platforms');
            $table->foreignId('campaign_id')->nullable()->index()->constrained();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index(['subject_type', 'active']);
        });

        // ENUM-MonitoredSubjectType — closed set, canonical in docs/00-meta/03-glossary.md#enum-monitoredsubjecttype.
        DB::statement(<<<'SQL'
            ALTER TABLE monitored_subjects ADD CONSTRAINT monitored_subjects_subject_type_check
                CHECK (subject_type IN ('CREATOR','BRAND','KEYWORD','HASHTAG','HANDLE'))
        SQL);

        // A CREATOR (roster) subject must reference the tracked creator
        // (data model: creatorId is "set when subjectType = CREATOR").
        DB::statement(<<<'SQL'
            ALTER TABLE monitored_subjects ADD CONSTRAINT monitored_subjects_creator_presence_check
                CHECK (subject_type <> 'CREATOR' OR creator_id IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('monitored_subjects');
    }
};
