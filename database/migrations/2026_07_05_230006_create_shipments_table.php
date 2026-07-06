<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENT-Shipment (docs/30-data-model/00-data-model.md#ent-shipment) plus
     * the resultingContentIds pivot. Write-owner: Module 3 CRM (ownership
     * matrix); no reader modules. Manual/internal entity — no Provenance
     * envelope. Courier APIs are optional; tracking fields are plain data.
     *
     * `product_id` is REQUIRED — the key that aggregates seeding results
     * across creators (REQ-M3-013). `product_value_at_ship` is a
     * MetricValue envelope (jsonb via AsValueObject), tier CONFIRMED
     * (agency-known value of goods shipped).
     *
     * `shipment_resulting_content` models resultingContentIds as a pivot
     * (spec D2 — FLAGGED modeling deviation vs the "list of ids on the
     * entity" canonical shape) so FACT-SeedingContent can join it. Rows
     * stay EMPTY until REQ-M3-008 content matching lands in Step 3.
     */
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seeding_campaign_id')->index()->constrained();
            $table->foreignId('creator_id')->index()->constrained();
            $table->string('status', 20);
            $table->string('tracking_number')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('product_id')->index()->constrained();
            $table->integer('quantity')->nullable();
            $table->jsonb('product_value_at_ship')->nullable();
            $table->boolean('posting_required')->nullable();
            $table->boolean('posted')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        // ENUM-ShipmentStatus — closed set, canonical in docs/00-meta/03-glossary.md#enum-shipmentstatus.
        DB::statement(<<<'SQL'
            ALTER TABLE shipments ADD CONSTRAINT shipments_status_check
                CHECK (status IN ('PENDING','PREPARING','SHIPPED','IN_TRANSIT','DELIVERED','RETURNED','FAILED'))
        SQL);

        Schema::create('shipment_resulting_content', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->index()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['shipment_id', 'content_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_resulting_content');
        Schema::dropIfExists('shipments');
    }
};
