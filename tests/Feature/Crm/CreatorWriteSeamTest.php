<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Contracts\CreatorProposals;
use App\Modules\CRM\DTO\CreatorProposal;
use App\Modules\CRM\Services\CreatorWriter;
use App\Modules\CRM\Services\PendingCreatorProposals;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Exceptions\NotYetImplemented;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SVC-CRM is the single write path for ENT-Creator / ENT-PlatformAccount
 * (ownership matrix; spec §3): CreatorWriter performs sanctioned in-module
 * writes, and the XMC-001 seam (CreatorProposals) is bound so M1/M2 have a
 * stable proposal target whose body lands in Step 2.
 */
class CreatorWriteSeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_writer_creates_a_creator(): void
    {
        $creator = app(CreatorWriter::class)->createCreator(
            'Jane Creator',
            'fr',
            RelationshipStatus::Prospect,
        );

        $this->assertDatabaseHas('creators', [
            'id' => $creator->id,
            'display_name' => 'Jane Creator',
            'primary_language' => 'fr',
            'relationship_status' => 'PROSPECT',
        ]);
    }

    public function test_creator_writer_attaches_a_platform_account_with_provenance(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Jane Creator');

        $account = $writer->addPlatformAccount(
            $creator,
            Platform::Instagram,
            'jane.creator',
            new Provenance(SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER, CarbonImmutable::now(), 'test-fixture-v1'),
            'bio text',
            ['https://example.test'],
            new MetricValue(12_000, MetricTier::Public),
        );

        $this->assertSame($creator->id, $account->creator_id);
        $this->assertDatabaseHas('platform_accounts', [
            'id' => $account->id,
            'creator_id' => $creator->id,
            'platform' => 'INSTAGRAM',
            'handle' => 'jane.creator',
        ]);
    }

    public function test_xmc001_is_bound_and_declared_not_yet_implemented(): void
    {
        $contract = app(CreatorProposals::class);

        $this->assertInstanceOf(PendingCreatorProposals::class, $contract);

        $this->expectException(NotYetImplemented::class);

        $contract->propose(new CreatorProposal(
            displayName: 'Proposed Creator',
            platform: Platform::TikTok,
            handle: 'proposed.creator',
            bio: null,
            externalLinks: [],
            followerCount: null,
            provenance: new Provenance(SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER, CarbonImmutable::now(), 'test-fixture-v1'),
        ));
    }
}
