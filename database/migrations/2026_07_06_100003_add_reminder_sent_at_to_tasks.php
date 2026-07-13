<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deadline reminders fire exactly once (module-3 §2.9, AC-M3-017):
     * `reminder_sent_at` is the idempotency stamp for the
     * qds:send-task-reminders command — NULL until the reminder fires,
     * stamped once, never re-fired.
     *
     * FLAGGED DEVIATION (spec D8): not in the canonical ENT-Task field
     * table — schema-level addition awaiting a data-model doc amendment.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
