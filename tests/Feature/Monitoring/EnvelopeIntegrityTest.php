<?php

namespace Tests\Feature\Monitoring;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * DP-002 (provenance mandatory on externally-sourced records) and DP-003
 * (confidence on every inferred value) enforced at the persistence layer.
 */
class EnvelopeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_externally_sourced_tables_reject_missing_provenance(): void
    {
        foreach (['platform_accounts', 'content_items', 'stories', 'comments', 'mentions', 'recognition_detections', 'metric_snapshots'] as $table) {
            $column = DB::selectOne(
                'select is_nullable from information_schema.columns where table_name = ? and column_name = ?',
                [$table, 'provenance'],
            );

            $this->assertSame('NO', $column->is_nullable, "[{$table}.provenance] must be NOT NULL (DP-002).");
        }
    }

    public function test_inferred_value_tables_reject_missing_confidence_assessment(): void
    {
        foreach ([['mentions', 'classification'], ['recognition_detections', 'assessment'], ['sentiment_analyses', 'assessment']] as [$table, $column]) {
            $found = DB::selectOne(
                'select is_nullable from information_schema.columns where table_name = ? and column_name = ?',
                [$table, $column],
            );

            $this->assertSame('NO', $found->is_nullable, "[{$table}.{$column}] must be NOT NULL (DP-003).");
        }
    }

    public function test_provenance_round_trips_through_the_cast(): void
    {
        $contentItem = ContentItem::factory()->create()->fresh();

        $this->assertInstanceOf(Provenance::class, $contentItem->provenance);
        $this->assertSame('SRC-apify-instagram-scraper', $contentItem->provenance->source);
        $this->assertSame('test-fixture-v1', $contentItem->provenance->sourceVersion);
        $this->assertNotNull($contentItem->provenance->fetchedAt);
    }

    public function test_provenance_with_unregistered_source_cannot_be_persisted(): void
    {
        // DP-006 stack lock: the Provenance VO rejects non-registry sources,
        // and the cast routes every write through the VO constructor.
        $this->expectException(InvalidArgumentException::class);

        ContentItem::factory()->create([
            'provenance' => [
                'source' => 'SRC-invented-provider',
                'fetchedAt' => now()->toIso8601String(),
                'sourceVersion' => 'v1',
            ],
        ]);
    }

    public function test_classification_round_trips_and_flags_review_need(): void
    {
        $mention = Mention::factory()->lowConfidence()->create()->fresh();

        $this->assertInstanceOf(ConfidenceAssessment::class, $mention->classification);
        $this->assertSame(ConfidenceLevel::Low, $mention->classification->confidenceLevel);
        $this->assertSame(VerificationStatus::AiAssessed, $mention->classification->verificationStatus);
        $this->assertTrue($mention->classification->needsHumanReview());
    }

    public function test_confidence_assessment_requires_signals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mention::factory()->create([
            'classification' => [
                'value' => 'UNKNOWN',
                'confidenceLevel' => 'LOW',
                'signals' => [],
                'verificationStatus' => 'AI_ASSESSED',
            ],
        ]);
    }

    public function test_metric_values_keep_their_tier_through_persistence(): void
    {
        $snapshot = MetricSnapshot::factory()->create([
            'metrics' => [
                new MetricValue(120_000, MetricTier::Public),
                new MetricValue(3.4, MetricTier::Derived),
            ],
        ])->fresh();

        $this->assertSame(MetricTier::Public, $snapshot->metrics[0]->tier);
        $this->assertSame(MetricTier::Derived, $snapshot->metrics[1]->tier);
    }

    public function test_review_queue_query_uses_the_envelope_fields(): void
    {
        Mention::factory()->count(2)->create();
        $lowConfidence = Mention::factory()->lowConfidence()->create();

        $queue = Mention::query()
            ->where(DB::raw("classification->>'verificationStatus'"), VerificationStatus::AiAssessed->value)
            ->whereIn(DB::raw("classification->>'confidenceLevel'"), [ConfidenceLevel::Low->value, ConfidenceLevel::Unknown->value])
            ->pluck('id');

        $this->assertContains($lowConfidence->id, $queue);
        $this->assertCount(1, $queue);
    }
}
