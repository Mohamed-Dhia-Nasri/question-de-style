<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ActiveSeedingCreatorIds (the monitoring "Active seeding only" filter)
 * and the home-dashboard tile both drive on
 * (tenant_id = ? AND status IN (ACTIVE, SHIPPING)); status carried no
 * index, leaving it a residual filter after the tenant narrow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seeding_campaigns', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('seeding_campaigns', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'status']);
        });
    }
};
