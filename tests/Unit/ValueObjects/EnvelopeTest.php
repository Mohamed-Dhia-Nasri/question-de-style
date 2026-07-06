<?php

namespace Tests\Unit\ValueObjects;

use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use App\Shared\ValueObjects\ReachEstimate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The four shared envelopes enforce the confidence-first + provenance-first
 * doctrine (ADR-0008, DP-001/002/003) at construction time — these tests pin
 * that enforcement.
 */
class EnvelopeTest extends TestCase
{
    public function test_provenance_accepts_registered_sources(): void
    {
        $provenance = new Provenance(
            source: SourceRegistry::YOUTUBE_DATA_API_V3,
            fetchedAt: CarbonImmutable::parse('2026-07-04T00:00:00Z'),
            sourceVersion: 'v3',
        );

        $this->assertSame(SourceRegistry::YOUTUBE_DATA_API_V3, $provenance->source);
        $this->assertSame($provenance->toArray(), Provenance::fromArray($provenance->toArray())->toArray());
    }

    public function test_provenance_rejects_unregistered_source(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a registered SRC-* id');

        new Provenance(
            source: 'SRC-invented-provider',
            fetchedAt: CarbonImmutable::now(),
            sourceVersion: '1',
        );
    }

    public function test_provenance_rejects_empty_source_version(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Provenance(
            source: SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER,
            fetchedAt: CarbonImmutable::now(),
            sourceVersion: '',
        );
    }

    public function test_source_registry_contains_exactly_the_eleven_canonical_sources(): void
    {
        $this->assertCount(11, SourceRegistry::all());
        $this->assertTrue(SourceRegistry::isRegistered('SRC-clockworks-tiktok-scraper'));
        $this->assertFalse(SourceRegistry::isRegistered('SRC-modash'));
    }

    public function test_reach_estimate_rejects_public_and_derived_tiers(): void
    {
        foreach ([MetricTier::Public, MetricTier::Derived] as $invalidTier) {
            try {
                new ReachEstimate(amount: 1000, tier: $invalidTier, method: 'view-based model v1');
                $this->fail("ReachEstimate accepted invalid tier {$invalidTier->value}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('ESTIMATED or CONFIRMED', $e->getMessage());
            }
        }
    }

    public function test_reach_estimate_requires_a_method(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReachEstimate(amount: 1000, tier: MetricTier::Estimated, method: '');
    }

    public function test_reach_estimate_round_trips(): void
    {
        $estimate = new ReachEstimate(amount: 55000.0, tier: MetricTier::Estimated, method: 'view-based model v1');

        $this->assertSame($estimate->toArray(), ReachEstimate::fromArray($estimate->toArray())->toArray());
    }

    public function test_confidence_assessment_requires_signals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConfidenceAssessment(
            value: 'DE',
            confidenceLevel: ConfidenceLevel::High,
            signals: [],
            verificationStatus: VerificationStatus::AiAssessed,
        );
    }

    public function test_low_confidence_ai_output_routes_to_human_review(): void
    {
        $lowConfidence = new ConfidenceAssessment(
            value: 'DE',
            confidenceLevel: ConfidenceLevel::Low,
            signals: ['bio-language'],
            verificationStatus: VerificationStatus::AiAssessed,
        );

        $reviewed = new ConfidenceAssessment(
            value: 'DE',
            confidenceLevel: ConfidenceLevel::Low,
            signals: ['bio-language'],
            verificationStatus: VerificationStatus::HumanReviewed,
        );

        $this->assertTrue($lowConfidence->needsHumanReview());
        $this->assertFalse($reviewed->needsHumanReview());
    }

    public function test_metric_value_round_trips_with_tier(): void
    {
        $metric = new MetricValue(amount: 1234.0, tier: MetricTier::Public);

        $this->assertSame($metric->toArray(), MetricValue::fromArray($metric->toArray())->toArray());
        $this->assertSame('PUBLIC', $metric->toArray()['tier']);
    }
}
