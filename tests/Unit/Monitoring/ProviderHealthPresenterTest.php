<?php

namespace Tests\Unit\Monitoring;

use App\Modules\Monitoring\Support\ProviderHealthPresenter;
use App\Platform\Ingestion\SourceRegistry;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * ProviderHealthPresenter turns raw ProviderHealthService::overview() rows
 * into plain-English, non-technical status rows for the Monitoring "Data
 * collection status" panel: friendly names, a three-level status with a
 * short reason, worst-first ordering, and only sources that have actually
 * run. Pure presentation — no DB.
 */
class ProviderHealthPresenterTest extends TestCase
{
    /**
     * One overview() row for a source, healthy by default; override to shape
     * the specific state under test.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function make(array $overrides = []): array
    {
        return array_merge([
            'status' => 'HEALTHY',
            'last_success_at' => '2026-07-18T10:00:00+00:00',
            'last_failure_at' => null,
            'consecutive_failures' => 0,
            'window_hours' => 24,
            'total_calls' => 5,
            'success_rate' => 1.0,
            'avg_duration_ms' => 100.0,
            'p95_duration_ms' => 200.0,
            'invalid_response_rate' => 0.0,
            'stale_data_warning' => false,
            'recent_errors' => [],
        ], $overrides);
    }

    public function test_label_maps_known_sources_to_friendly_names(): void
    {
        $this->assertSame('TikTok', ProviderHealthPresenter::label(SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER));
        $this->assertSame('Instagram Reels', ProviderHealthPresenter::label(SourceRegistry::APIFY_INSTAGRAM_REEL_SCRAPER));
        $this->assertSame('Speech-to-text (Google)', ProviderHealthPresenter::label(SourceRegistry::GOOGLE_SPEECH_TO_TEXT));
    }

    public function test_label_falls_back_to_the_raw_id_when_unknown(): void
    {
        $this->assertSame('SRC-made-up', ProviderHealthPresenter::label('SRC-made-up'));
    }

    public function test_sources_that_never_ran_are_omitted(): void
    {
        $rows = ProviderHealthPresenter::rows([
            SourceRegistry::GOOGLE_VIDEO_INTELLIGENCE => $this->make([
                'status' => 'UNKNOWN',
                'last_success_at' => null,
                'total_calls' => 0,
                'consecutive_failures' => 0,
            ]),
            SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER => $this->make(),
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('TikTok', $rows[0]['name']);
    }

    public function test_failing_source_reads_not_working_with_a_plain_reason(): void
    {
        $rows = ProviderHealthPresenter::rows([
            SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER => $this->make([
                'status' => 'FAILING',
                'consecutive_failures' => 2,
            ]),
        ]);

        $this->assertSame('broken', $rows[0]['status']);
        $this->assertSame('Not working', $rows[0]['status_label']);
        $this->assertSame('error', $rows[0]['status_color']);
        $this->assertSame('The last 2 checks failed.', $rows[0]['detail']);
    }

    public function test_single_failure_uses_singular_check(): void
    {
        $rows = ProviderHealthPresenter::rows([
            SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER => $this->make([
                'status' => 'HEALTHY',
                'consecutive_failures' => 1,
            ]),
        ]);

        $this->assertSame('broken', $rows[0]['status']);
        $this->assertSame('The last 1 check failed.', $rows[0]['detail']);
    }

    public function test_stale_source_reads_delayed(): void
    {
        $rows = ProviderHealthPresenter::rows([
            SourceRegistry::APIFY_INSTAGRAM_REEL_SCRAPER => $this->make([
                'stale_data_warning' => true,
            ]),
        ]);

        $this->assertSame('delayed', $rows[0]['status']);
        $this->assertSame('Delayed', $rows[0]['status_label']);
        $this->assertSame('warning', $rows[0]['status_color']);
        $this->assertSame('No new data recently.', $rows[0]['detail']);
    }

    public function test_degraded_source_reads_some_errors(): void
    {
        $rows = ProviderHealthPresenter::rows([
            SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER => $this->make([
                'status' => 'DEGRADED',
            ]),
        ]);

        $this->assertSame('errors', $rows[0]['status']);
        $this->assertSame('Some errors', $rows[0]['status_label']);
        $this->assertSame('warning', $rows[0]['status_color']);
    }

    public function test_healthy_source_reads_working_with_no_extra_reason(): void
    {
        $rows = ProviderHealthPresenter::rows([
            SourceRegistry::YOUTUBE_DATA_API_V3 => $this->make(),
        ]);

        $this->assertSame('working', $rows[0]['status']);
        $this->assertSame('Working', $rows[0]['status_label']);
        $this->assertSame('success', $rows[0]['status_color']);
        $this->assertSame('', $rows[0]['detail']);
    }

    public function test_failing_takes_priority_over_stale(): void
    {
        $rows = ProviderHealthPresenter::rows([
            SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER => $this->make([
                'status' => 'FAILING',
                'consecutive_failures' => 3,
                'stale_data_warning' => true,
            ]),
        ]);

        $this->assertSame('broken', $rows[0]['status']);
    }

    public function test_rows_are_ordered_worst_first_then_by_name(): void
    {
        $rows = ProviderHealthPresenter::rows([
            SourceRegistry::YOUTUBE_DATA_API_V3 => $this->make(),                                    // working — YouTube
            SourceRegistry::APIFY_INSTAGRAM_SCRAPER => $this->make(),                                 // working — Instagram
            SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER => $this->make(['status' => 'DEGRADED']),    // errors  — Instagram posts
            SourceRegistry::APIFY_INSTAGRAM_REEL_SCRAPER => $this->make(['stale_data_warning' => true]), // delayed — Instagram Reels
            SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER => $this->make(['status' => 'FAILING', 'consecutive_failures' => 1]), // broken — TikTok
        ]);

        $this->assertSame(
            ['TikTok', 'Instagram Reels', 'Instagram posts', 'Instagram', 'YouTube'],
            array_column($rows, 'name'),
        );
    }

    public function test_last_success_is_parsed_to_carbon_or_null(): void
    {
        $rows = ProviderHealthPresenter::rows([
            SourceRegistry::YOUTUBE_DATA_API_V3 => $this->make(['last_success_at' => '2026-07-18T10:00:00+00:00']),
            SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER => $this->make([
                'status' => 'FAILING',
                'consecutive_failures' => 1,
                'last_success_at' => null,
            ]),
        ]);

        $byName = collect($rows)->keyBy('name');

        $this->assertInstanceOf(CarbonImmutable::class, $byName['YouTube']['last_success_at']);
        $this->assertTrue($byName['YouTube']['last_success_at']->equalTo(CarbonImmutable::parse('2026-07-18T10:00:00+00:00')));
        $this->assertNull($byName['TikTok']['last_success_at']);
    }
}
