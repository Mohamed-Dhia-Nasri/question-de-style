<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Contracts\CreatorProposals;
use App\Modules\CRM\DTO\CreatorProposal;
use App\Modules\CRM\Exceptions\PlatformAccountConflict;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * XMC-001 body (AC-M3-002): M1/M2 proposals create a fresh Creator +
 * PlatformAccount via SVC-CRM — the proposing module never writes those
 * tables. Per ADR-0014 there is NO dedup: every proposal creates a new
 * Creator; duplicate identities are reconciled by an operator by hand.
 */
class CreatorProposalIntakeTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(string $displayName = 'Proposed Creator', string $handle = 'proposed.creator'): CreatorProposal
    {
        return new CreatorProposal(
            displayName: $displayName,
            platform: Platform::TikTok,
            handle: $handle,
            bio: 'observed bio',
            externalLinks: ['https://observed.example'],
            followerCount: new MetricValue(42_000, MetricTier::Public),
            provenance: new Provenance(SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER, CarbonImmutable::now(), 'actor-v3'),
        );
    }

    public function test_a_proposal_creates_a_fresh_creator_with_the_proposed_account(): void
    {
        $creator = app(CreatorProposals::class)->propose($this->proposal());

        $this->assertDatabaseHas('creators', [
            'id' => $creator->id,
            'display_name' => 'Proposed Creator',
        ]);

        $account = PlatformAccount::where('creator_id', $creator->id)->sole();
        $this->assertSame(Platform::TikTok, $account->platform);
        $this->assertSame('proposed.creator', $account->handle);
        $this->assertSame('observed bio', $account->bio);
        $this->assertSame(['https://observed.example'], $account->external_links);
        $this->assertSame(42_000.0, $account->follower_count?->amount);
        $this->assertSame(MetricTier::Public, $account->follower_count?->tier);
        // The proposal's Provenance rides onto the account unchanged (DP-002).
        $this->assertSame(SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER, $account->provenance->source);
        $this->assertSame('actor-v3', $account->provenance->sourceVersion);
    }

    public function test_no_dedup_a_second_proposal_creates_a_second_creator(): void
    {
        $intake = app(CreatorProposals::class);

        // Same display name, different handle — plausibly the same person,
        // but identity is operator-managed (ADR-0014): both are created and
        // a human deletes the stray if they are one person.
        $first = $intake->propose($this->proposal('Jane Doe', 'jane.main'));
        $second = $intake->propose($this->proposal('Jane Doe', 'jane.backup'));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Creator::where('display_name', 'Jane Doe')->count());
    }

    public function test_a_proposal_for_an_already_claimed_handle_is_refused_without_an_orphan_creator(): void
    {
        $intake = app(CreatorProposals::class);
        $intake->propose($this->proposal('Original', 'taken.handle'));

        $creatorsBefore = Creator::count();

        try {
            $intake->propose($this->proposal('Duplicate Observation', 'taken.handle'));
            $this->fail('Expected the globally-unique (platform, handle) invariant to refuse the proposal.');
        } catch (PlatformAccountConflict) {
            // expected
        }

        // The transaction rolled back — no account-less Creator was left behind.
        $this->assertSame($creatorsBefore, Creator::count());
        $this->assertDatabaseMissing('creators', ['display_name' => 'Duplicate Observation']);
    }
}
