<?php

namespace Tests\Unit\Enums;

use App\Shared\Enums\MentionType;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\VerificationStatus;
use PHPUnit\Framework\TestCase;

/**
 * Enum values are canonical in docs/00-meta/03-glossary.md and CLOSED. These
 * tests pin the exact value sets so an accidental rename/addition fails CI.
 */
class CanonicalEnumTest extends TestCase
{
    public function test_role_names_match_the_canonical_closed_set(): void
    {
        $this->assertSame(
            [
                'ADMIN',
                'ACCOUNT_DIRECTOR',
                'CAMPAIGN_MANAGER',
                'INFLUENCER_RELATIONS_MANAGER',
                'ANALYST',
                'CLIENT_VIEWER',
            ],
            array_column(RoleName::cases(), 'value'),
        );
    }

    public function test_staff_roles_exclude_the_client_viewer(): void
    {
        $this->assertNotContains(RoleName::ClientViewer, RoleName::staff());
        $this->assertCount(5, RoleName::staff());
    }

    public function test_metric_tiers_match_the_canonical_closed_set(): void
    {
        $this->assertSame(
            ['PUBLIC', 'DERIVED', 'ESTIMATED', 'CONFIRMED'],
            array_column(MetricTier::cases(), 'value'),
        );
    }

    public function test_mention_type_has_no_confirmed_organic_value(): void
    {
        $this->assertSame(
            ['PAID', 'SEEDED', 'LIKELY_ORGANIC', 'UNKNOWN'],
            array_column(MentionType::cases(), 'value'),
        );
    }

    public function test_verification_status_uses_ai_assessed(): void
    {
        $this->assertContains('AI_ASSESSED', array_column(VerificationStatus::cases(), 'value'));
    }
}
