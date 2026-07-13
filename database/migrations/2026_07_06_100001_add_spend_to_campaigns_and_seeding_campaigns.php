<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agency-entered spend — the CPE/CPM input (module-3 §2.8, AC-M3-015;
     * docs/30-data-model/00-data-model.md:587 presumes it without defining
     * a field). `spend` is a MetricValue envelope (jsonb via AsValueObject),
     * tier CONFIRMED with metric label 'spend': the agency knows what it
     * spent — exact products.unit_value precedent; envelope shape is
     * enforced by the value-object layer, not a jsonb CHECK.
     *
     * FLAGGED DEVIATION (spec D1): no canonical ENT-Campaign /
     * ENT-SeedingCampaign field defines spend — schema-level addition
     * awaiting a data-model doc amendment (same class as external_id).
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->jsonb('spend')->nullable();
        });

        Schema::table('seeding_campaigns', function (Blueprint $table) {
            $table->jsonb('spend')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('spend');
        });

        Schema::table('seeding_campaigns', function (Blueprint $table) {
            $table->dropColumn('spend');
        });
    }
};
