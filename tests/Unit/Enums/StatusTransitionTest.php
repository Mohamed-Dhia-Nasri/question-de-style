<?php

namespace Tests\Unit\Enums;

use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\SeedingCampaignStatus;
use PHPUnit\Framework\TestCase;

/**
 * Status lifecycle guards (M04 + its seeding twin). Completed and Cancelled
 * are terminal — a finished or called-off record is never revived — and
 * nothing returns to the setup-only Draft state once it has left it. Every
 * other forward/lateral move is allowed.
 */
class StatusTransitionTest extends TestCase
{
    public function test_a_terminal_campaign_cannot_transition_anywhere(): void
    {
        foreach ([CampaignStatus::Completed, CampaignStatus::Cancelled] as $terminal) {
            foreach (CampaignStatus::cases() as $to) {
                if ($to === $terminal) {
                    continue;
                }
                $this->assertFalse(
                    $terminal->canTransitionTo($to),
                    "{$terminal->value} -> {$to->value} must be blocked",
                );
            }
        }
    }

    public function test_a_campaign_never_returns_to_draft(): void
    {
        foreach ([CampaignStatus::Planned, CampaignStatus::Active, CampaignStatus::Paused] as $from) {
            $this->assertFalse($from->canTransitionTo(CampaignStatus::Draft));
        }
    }

    public function test_legitimate_campaign_moves_are_allowed(): void
    {
        $this->assertTrue(CampaignStatus::Draft->canTransitionTo(CampaignStatus::Active));
        $this->assertTrue(CampaignStatus::Active->canTransitionTo(CampaignStatus::Paused));
        $this->assertTrue(CampaignStatus::Active->canTransitionTo(CampaignStatus::Completed));
        $this->assertTrue(CampaignStatus::Active->canTransitionTo(CampaignStatus::Active)); // no-op edit
    }

    public function test_a_terminal_seeding_run_cannot_transition_anywhere(): void
    {
        foreach ([SeedingCampaignStatus::Completed, SeedingCampaignStatus::Cancelled] as $terminal) {
            foreach (SeedingCampaignStatus::cases() as $to) {
                if ($to === $terminal) {
                    continue;
                }
                $this->assertFalse(
                    $terminal->canTransitionTo($to),
                    "{$terminal->value} -> {$to->value} must be blocked",
                );
            }
        }
    }

    public function test_legitimate_seeding_moves_are_allowed(): void
    {
        $this->assertTrue(SeedingCampaignStatus::Draft->canTransitionTo(SeedingCampaignStatus::Shipping));
        $this->assertTrue(SeedingCampaignStatus::Active->canTransitionTo(SeedingCampaignStatus::Completed));
        $this->assertFalse(SeedingCampaignStatus::Active->canTransitionTo(SeedingCampaignStatus::Draft));
    }
}
