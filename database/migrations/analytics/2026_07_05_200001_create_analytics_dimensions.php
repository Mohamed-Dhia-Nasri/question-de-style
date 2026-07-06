<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DIM-* — the conformed dimensions of the analytics star schema
     * (docs/30-data-model/01-analytics-model.md §3, ADR-0010 as amended by
     * ADR-0013: Neon Postgres, no TimescaleDB).
     *
     * Maintained by SVC-Analytics only; dashboards and SVC-Export read
     * rollups, never these tables directly. Enum-backed dimensions
     * (platform, content type, mention type, sector, sentiment, metric
     * tier) are seeded here verbatim from the closed glossary sets and
     * never grow at runtime. Entity-backed dimensions (creator, client,
     * brand, product, campaign, seeding campaign, geo) are upserted from
     * their OLTP sources on each refresh; DIM-Product / DIM-SeedingCampaign
     * / DIM-Geo remain empty until their source entities ship (P2/P3).
     *
     * Personal data: dimensions carry internal ids and the public persona
     * display name only — never email, phone, address, or private notes
     * (DP-005; analytics personal-data rule).
     */
    public function up(): void
    {
        // DIM-Date — derived calendar, day → week → month → quarter → year.
        Schema::create('dim_date', function (Blueprint $table) {
            $table->date('date_key')->primary();
            $table->date('week_start');
            $table->date('month_start');
            $table->date('quarter_start');
            $table->date('year_start');
            $table->unsignedSmallInteger('iso_week');
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('quarter');
            $table->unsignedSmallInteger('year');
        });

        // DIM-Creator — from ENT-Creator. size_band has no canonical band
        // definition (flagged gap) and stays NULL until an ADR defines it.
        Schema::create('dim_creator', function (Blueprint $table) {
            $table->unsignedBigInteger('creator_id')->primary();
            $table->string('display_name');
            $table->string('size_band', 30)->nullable();
            $table->timestamp('updated_at');
        });

        // DIM-Client / DIM-Brand / DIM-Product — the client → brand →
        // product hierarchy (aggregation dimensions).
        Schema::create('dim_client', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->primary();
            $table->string('name');
            $table->timestamp('updated_at');
        });

        Schema::create('dim_brand', function (Blueprint $table) {
            $table->unsignedBigInteger('brand_id')->primary();
            $table->unsignedBigInteger('client_id')->index();
            $table->string('name');
            $table->string('sector', 50)->nullable();
            $table->timestamp('updated_at');
        });

        Schema::create('dim_product', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->primary();
            $table->unsignedBigInteger('brand_id')->index();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('variant')->nullable();
            $table->timestamp('updated_at');
        });

        Schema::create('dim_campaign', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->primary();
            $table->unsignedBigInteger('brand_id')->index();
            $table->string('name');
            $table->string('status', 20);
            $table->timestamp('updated_at');
        });

        Schema::create('dim_seeding_campaign', function (Blueprint $table) {
            $table->unsignedBigInteger('seeding_campaign_id')->primary();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('brand_id')->index();
            $table->string('name');
            $table->string('seeding_type', 30);
            $table->string('status', 20);
            $table->timestamp('updated_at');
        });

        // DIM-Geo — confidence-based (city → region → country, from
        // ENT-GeoAttribution). Geo-sliced aggregates inherit the inferred
        // status and are never asserted as fact (DP-003). Empty until M2.
        Schema::create('dim_geo', function (Blueprint $table) {
            $table->unsignedBigInteger('geo_id')->primary();
            $table->unsignedBigInteger('creator_id')->index();
            $table->string('country_code', 2)->nullable();
            $table->string('region')->nullable();
            $table->string('city')->nullable();
            $table->string('confidence_level', 10);
            $table->string('verification_status', 20);
            $table->timestamp('updated_at');
        });

        // Enum-backed dimensions — closed sets, canonical in
        // docs/00-meta/03-glossary.md; seeded once, never extended.
        foreach ([
            'dim_platform' => ['INSTAGRAM', 'TIKTOK', 'YOUTUBE'],
            'dim_content_type' => ['IMAGE_POST', 'CAROUSEL', 'REEL', 'VIDEO', 'SHORT', 'LIVE'],
            'dim_mention_type' => ['PAID', 'SEEDED', 'LIKELY_ORGANIC', 'UNKNOWN'],
            'dim_sentiment' => ['POSITIVE', 'NEUTRAL', 'NEGATIVE', 'MIXED', 'UNKNOWN'],
            'dim_metric_tier' => ['PUBLIC', 'DERIVED', 'ESTIMATED', 'CONFIRMED'],
            'dim_sector' => [
                'FASHION', 'BEAUTY', 'FITNESS', 'FOOD_BEVERAGE', 'TRAVEL', 'LIFESTYLE',
                'TECH', 'GAMING', 'PARENTING_FAMILY', 'HOME_INTERIOR', 'HEALTH_WELLNESS',
                'FINANCE', 'AUTOMOTIVE', 'ENTERTAINMENT', 'SPORTS', 'EDUCATION',
                'BUSINESS', 'ART_DESIGN', 'MUSIC', 'OTHER',
            ],
        ] as $tableName => $codes) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->string('code', 30)->primary();
            });

            DB::table($tableName)->insert(array_map(
                static fn (string $code): array => ['code' => $code],
                $codes,
            ));
        }
    }

    public function down(): void
    {
        foreach ([
            'dim_sector', 'dim_metric_tier', 'dim_sentiment', 'dim_mention_type',
            'dim_content_type', 'dim_platform', 'dim_geo', 'dim_seeding_campaign',
            'dim_campaign', 'dim_product', 'dim_brand', 'dim_client', 'dim_creator',
            'dim_date',
        ] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
