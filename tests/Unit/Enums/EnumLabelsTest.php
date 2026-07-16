<?php

namespace Tests\Unit\Enums;

use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\SeedingType;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Enums\TaskStatus;
use App\Shared\Enums\VerificationStatus;
use PHPUnit\Framework\TestCase;

class EnumLabelsTest extends TestCase
{
    public function test_every_presented_enum_case_has_a_clean_label(): void
    {
        $enums = [
            SectorLabel::class, Platform::class, TaskStatus::class, MetricTier::class,
            ContentType::class, VerificationStatus::class, CampaignStatus::class,
            SeedingCampaignStatus::class, ShipmentStatus::class, RelationshipStatus::class,
            SeedingType::class,
        ];

        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                $label = $case->label();
                $this->assertNotSame('', $label, "{$enum}::{$case->name} label empty");
                $this->assertStringNotContainsString('_', $label, "{$enum}::{$case->name} label leaks underscore");
                $this->assertNotSame(strtoupper($label), $label, "{$enum}::{$case->name} label is ALL CAPS");
            }
        }
    }

    public function test_workflow_enums_have_one_line_descriptions(): void
    {
        $enums = [
            CampaignStatus::class, SeedingCampaignStatus::class, ShipmentStatus::class,
            TaskStatus::class, RelationshipStatus::class, SeedingType::class, MetricTier::class,
        ];

        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertNotSame('', $case->description(), "{$enum}::{$case->name} description empty");
            }
        }
    }

    public function test_metric_tier_plain_words(): void
    {
        $this->assertSame('From platform', MetricTier::Public->label());
        $this->assertSame('Calculated', MetricTier::Derived->label());
        $this->assertSame('Estimate', MetricTier::Estimated->label());
        $this->assertSame('Entered by you', MetricTier::Confirmed->label());
    }
}
