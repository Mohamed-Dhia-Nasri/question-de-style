<?php

namespace Tests\Feature\Components;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The shared "last pulled/updated" stamp (<x-data-freshness>): an absolute
 * UTC date-time in day.month.year hh:mm form, with a graceful fallback when
 * nothing has been pulled yet. Times are UTC (config/app.php timezone).
 */
class DataFreshnessTest extends TestCase
{
    public function test_it_renders_an_absolute_utc_timestamp_in_the_requested_format(): void
    {
        $html = Blade::render(
            '<x-data-freshness :at="$at" label="Data updated" />',
            ['at' => Carbon::parse('2026-07-21 14:32:00')],
        );

        $this->assertStringContainsString('Data updated', $html);
        $this->assertStringContainsString('21.07.2026 14:32 UTC', $html);
        // Machine-readable instant for accessibility/tooling.
        $this->assertStringContainsString('<time', $html);
    }

    public function test_it_accepts_a_raw_database_timestamp_string(): void
    {
        $html = Blade::render(
            '<x-data-freshness :at="$at" />',
            ['at' => '2026-07-21 14:32:00'],
        );

        $this->assertStringContainsString('21.07.2026 14:32 UTC', $html);
    }

    public function test_it_shows_the_fallback_when_there_is_no_timestamp(): void
    {
        $html = Blade::render(
            '<x-data-freshness :at="$at" label="Data updated" never="not pulled yet" />',
            ['at' => null],
        );

        $this->assertStringContainsString('Data updated', $html);
        $this->assertStringContainsString('not pulled yet', $html);
        $this->assertStringNotContainsString('UTC', $html);
    }
}
