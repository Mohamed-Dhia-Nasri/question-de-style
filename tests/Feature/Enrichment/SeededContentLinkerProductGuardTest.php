<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Platform\Enrichment\Matching\SeededContentLinker;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeededContentLinkerProductGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_only_seeded_is_not_auto_linked(): void
    {
        // A real, resolvable shipment reference for the SAME creator — the
        // linker WOULD auto-link this without the guard (mirrors
        // SeededContentLinkerTest::makeShipment/makeSeededMention), so this
        // test actually exercises the product-unconfirmed short-circuit
        // instead of passing vacuously on a dangling shipment id.
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);
        $shipment = Shipment::factory()->create([
            'seeding_campaign_id' => SeedingCampaign::factory()->create()->id,
            'creator_id' => $creator->id,
            'shipped_at' => now()->subDays(5),
        ]);

        // A MEDIUM AI SEEDED mention flagged product-unconfirmed must NOT auto-link.
        $mention = Mention::factory()->create([
            'content_item_id' => $content->id,
            'mention_type' => MentionType::Seeded,
            'classification' => new ConfidenceAssessment(
                MentionType::Seeded->value,
                ConfidenceLevel::Medium,
                ['shipment-record:'.$shipment->id, 'product-unconfirmed'],
                VerificationStatus::AiAssessed,
            ),
        ]);

        $summary = app(SeededContentLinker::class)->run();

        // Nothing linked because the only candidate is product-unconfirmed.
        $this->assertSame(0, $summary->linked);
        $this->assertDatabaseMissing('shipment_resulting_content', ['shipment_id' => $shipment->id]);
        $this->assertNull($mention->refresh()->campaign_id);
    }
}
