<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Contracts\CreatorProposals;
use App\Modules\CRM\Exceptions\PlatformAccountConflict;
use App\Modules\CRM\Services\CreatorProposalIntake;
use App\Modules\CRM\Services\CreatorWriter;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SVC-CRM is the single write path for ENT-Creator / ENT-PlatformAccount
 * (ownership matrix; spec §3). Step 2 extends CreatorWriter with the
 * operator's curation paths (ADR-0014): creator update/delete and platform-
 * account add/update/remove, enforcing one-account-per-platform-per-creator
 * (the Step-1 latent gap) and global (platform, handle) uniqueness. The
 * XMC-001 seam is bound to the real CreatorProposalIntake.
 */
class CreatorWriteSeamTest extends TestCase
{
    use RefreshDatabase;

    private function provenance(): Provenance
    {
        return new Provenance(SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER, CarbonImmutable::now(), 'test-fixture-v1');
    }

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

    public function test_creator_writer_updates_creator_fields(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Old Name', 'de', RelationshipStatus::Prospect);

        $writer->updateCreator($creator, 'New Name', null, RelationshipStatus::Active);

        $this->assertDatabaseHas('creators', [
            'id' => $creator->id,
            'display_name' => 'New Name',
            'primary_language' => null,
            'relationship_status' => 'ACTIVE',
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
            $this->provenance(),
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

    public function test_one_account_per_platform_per_creator_is_enforced(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Jane Creator');
        $writer->addPlatformAccount($creator, Platform::Instagram, 'jane.one', $this->provenance());

        // A second account on a DIFFERENT platform is fine…
        $writer->addPlatformAccount($creator, Platform::TikTok, 'jane.tok', $this->provenance());

        // …but a second Instagram account is refused (data model: one per
        // ENUM-Platform presence — the latent gap Step 1 flagged).
        $this->expectException(PlatformAccountConflict::class);

        $writer->addPlatformAccount($creator, Platform::Instagram, 'jane.two', $this->provenance());
    }

    public function test_a_handle_claimed_by_another_creator_is_refused(): void
    {
        $writer = app(CreatorWriter::class);
        $first = $writer->createCreator('First');
        $writer->addPlatformAccount($first, Platform::Instagram, 'shared.handle', $this->provenance());

        $second = $writer->createCreator('Second');

        $this->expectException(PlatformAccountConflict::class);

        $writer->addPlatformAccount($second, Platform::Instagram, 'shared.handle', $this->provenance());
    }

    public function test_manual_add_stamps_the_adr0015_manual_entry_provenance(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Jane Creator');

        $account = $writer->addManualPlatformAccount(
            $creator,
            Platform::YouTube,
            'jane-channel',
            'channel bio',
            ['https://jane.example'],
        );

        $this->assertSame(SourceRegistry::AGENCY_MANUAL_ENTRY, $account->provenance->source);
        // The operator path takes no follower count (spec §2.4 field set:
        // platform + handle + bio + links); observed counts arrive via sync.
        $this->assertNull($account->follower_count);
    }

    public function test_update_platform_account_edits_fields_and_preserves_origin_provenance(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Jane Creator');
        $account = $writer->addPlatformAccount(
            $creator,
            Platform::Instagram,
            'jane.old',
            $this->provenance(),
            'scraped bio',
        );

        $writer->updatePlatformAccount($account, Platform::Instagram, 'jane.new', 'operator bio', ['https://new.example']);

        $account->refresh();
        $this->assertSame('jane.new', $account->handle);
        $this->assertSame('operator bio', $account->bio);
        $this->assertSame(['https://new.example'], $account->external_links);
        // Provenance records the record's ORIGIN and is never rewritten by an
        // edit (ADR-0015): the manual stamp must not sit on a provider-fetched
        // record. The operator's change lives in the audit trail instead.
        $this->assertSame(SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER, $account->provenance->source);
    }

    public function test_update_cannot_move_the_account_onto_an_occupied_platform(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Jane Creator');
        $writer->addPlatformAccount($creator, Platform::Instagram, 'jane.ig', $this->provenance());
        $tiktok = $writer->addPlatformAccount($creator, Platform::TikTok, 'jane.tok', $this->provenance());

        $this->expectException(PlatformAccountConflict::class);

        $writer->updatePlatformAccount($tiktok, Platform::Instagram, 'jane.tok', null, []);
    }

    public function test_update_keeping_the_same_platform_and_handle_does_not_self_conflict(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Jane Creator');
        $account = $writer->addPlatformAccount($creator, Platform::Instagram, 'jane.ig', $this->provenance());

        $writer->updatePlatformAccount($account, Platform::Instagram, 'jane.ig', 'new bio', []);

        $this->assertSame('new bio', $account->refresh()->bio);
    }

    public function test_remove_platform_account_deletes_the_row(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Jane Creator');
        $account = $writer->addPlatformAccount($creator, Platform::Instagram, 'jane.ig', $this->provenance());

        $writer->removePlatformAccount($account);

        $this->assertDatabaseMissing('platform_accounts', ['id' => $account->id]);
    }

    public function test_remove_is_refused_by_the_db_when_monitoring_history_anchors_to_the_account(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Jane Creator');
        $account = $writer->addPlatformAccount($creator, Platform::Instagram, 'jane.ig', $this->provenance());
        ContentItem::factory()->create(['platform_account_id' => $account->id]);

        // M1-owned rows are never cascaded by an M3 write (ownership matrix);
        // the restrict FK aborts the delete.
        $this->expectException(QueryException::class);

        $writer->removePlatformAccount($account);
    }

    public function test_delete_creator_removes_its_profile_managed_children(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Stray Duplicate');
        $account = $writer->addPlatformAccount($creator, Platform::Instagram, 'stray.ig', $this->provenance());
        $contact = $creator->contacts()->create(['email' => 'stray@example.test']);
        $preference = $creator->brandPreferences()->create(['preferred_brands' => ['Acme']]);
        $log = $creator->communicationLogs()->create([
            'channel' => 'email',
            'direction' => 'outbound',
            'summary' => 'first outreach',
            'occurred_at' => now(),
        ]);

        $writer->deleteCreator($creator);

        $this->assertDatabaseMissing('creators', ['id' => $creator->id]);
        $this->assertDatabaseMissing('platform_accounts', ['id' => $account->id]);
        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
        $this->assertDatabaseMissing('brand_preferences', ['id' => $preference->id]);
        $this->assertDatabaseMissing('communication_logs', ['id' => $log->id]);
    }

    public function test_delete_creator_rolls_back_entirely_when_monitoring_history_blocks_it(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Monitored Creator');
        $account = $writer->addPlatformAccount($creator, Platform::Instagram, 'monitored.ig', $this->provenance());
        $contact = $creator->contacts()->create(['email' => 'kept@example.test']);
        ContentItem::factory()->create(['platform_account_id' => $account->id]);

        try {
            $writer->deleteCreator($creator);
            $this->fail('Expected the restrict FK to abort the delete.');
        } catch (QueryException) {
            // expected
        }

        // The transaction rolled back as a whole — nothing was half-deleted.
        $this->assertDatabaseHas('creators', ['id' => $creator->id]);
        $this->assertDatabaseHas('platform_accounts', ['id' => $account->id]);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }

    public function test_delete_creator_withdraws_its_lifecycle_coupled_roster_entry(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Roster Creator'); // creation auto-enrolled it

        $this->assertDatabaseHas('monitored_subjects', ['creator_id' => $creator->id]);

        $writer->deleteCreator($creator);

        $this->assertDatabaseMissing('creators', ['id' => $creator->id]);
        $this->assertDatabaseMissing('monitored_subjects', ['creator_id' => $creator->id]);
    }

    public function test_delete_creator_is_refused_when_the_roster_entry_has_mention_history(): void
    {
        $writer = app(CreatorWriter::class);
        $creator = $writer->createCreator('Historied Creator');
        $subject = MonitoredSubject::query()->where('creator_id', $creator->id)->firstOrFail();
        Mention::factory()->create(['monitored_subject_id' => $subject->id]);

        try {
            $writer->deleteCreator($creator);
            $this->fail('Expected the restrict FK on mentions to abort the delete.');
        } catch (QueryException) {
            // expected — monitoring HISTORY always blocks, config alone never does
        }

        $this->assertDatabaseHas('creators', ['id' => $creator->id]);
        $this->assertDatabaseHas('monitored_subjects', ['id' => $subject->id]);
    }

    public function test_xmc001_is_bound_to_the_real_intake(): void
    {
        $this->assertInstanceOf(CreatorProposalIntake::class, app(CreatorProposals::class));
    }
}
