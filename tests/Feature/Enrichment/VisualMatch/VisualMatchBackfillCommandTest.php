<?php

namespace Tests\Feature\Enrichment\VisualMatch;

use App\Models\Tenant;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Models\EnrichmentRun;
use App\Platform\Enrichment\Support\EnrichmentRunStatus;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisualMatchBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        config([
            'qds.enrichment.visual_match.enabled' => true,
            // Provider deliberately NOT configured: the matcher's own gate
            // yields skipped:not-configured before any spend (spec §3 gate
            // order: switch → provider → creator → candidates → …), which
            // lets these tests assert SELECTION without the full stack.
            'services.google_embeddings.credentials_path' => null,
        ]);
    }

    /** Deterministic gradient JPEG: mid luminance, stddev well above the flat threshold. */
    private function frameBytes(): string
    {
        $img = imagecreatetruecolor(64, 64);

        for ($x = 0; $x < 64; $x++) {
            for ($y = 0; $y < 64; $y++) {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, ($x * 4) % 256, ($y * 4) % 256, 128));
            }
        }

        ob_start();
        imagejpeg($img, null, 90);

        return (string) ob_get_clean();
    }

    private function makeKeyframe(ContentItem|Story $owner, int $ordinal, ?int $timestampMs): Keyframe
    {
        $path = sprintf(
            'tenants/%d/keyframes/test/%s-%d/%d.jpg',
            (int) $owner->tenant_id,
            class_basename($owner),
            $owner->id,
            $ordinal,
        );
        Storage::disk('media')->put($path, $this->frameBytes());

        return Keyframe::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'ordinal' => $ordinal,
            'timestamp_ms' => $timestampMs,
            'storage_disk' => 'media',
            'storage_path' => $path,
            'width' => 64,
            'height' => 64,
            'kind' => KeyframeKind::VideoSample,
            'checksum' => hash('sha256', $path),
            'source_checksum' => str_repeat('b', 64),
            'provenance' => new Provenance('SRC-apify-instagram-reel-scraper', CarbonImmutable::now(), 'keyframes-v1'),
        ]);
    }

    private function completedRun(ContentItem|Story $target): void
    {
        EnrichmentRun::query()->create([
            $target instanceof ContentItem ? 'content_item_id' : 'story_id' => $target->id,
            'correlation_id' => 'backfill-test-seed',
            'status' => EnrichmentRunStatus::Completed,
            'started_at' => CarbonImmutable::now()->subDay(),
            'finished_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    private function makeEligibleContentItem(?CarbonImmutable $publishedAt = null): ContentItem
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create([
            'published_at' => $publishedAt ?? CarbonImmutable::now()->subDays(2),
        ]);
        $this->makeKeyframe($item, 0, 0);
        $this->completedRun($item);

        return $item;
    }

    public function test_disabled_switch_processes_nothing(): void
    {
        config(['qds.enrichment.visual_match.enabled' => false]);
        $this->makeEligibleContentItem();

        $this->artisan('qds:visual-match-backfill')
            ->expectsOutputToContain('Visual matching is disabled')
            ->assertSuccessful();
    }

    public function test_selects_only_posts_with_keyframes_and_a_completed_run(): void
    {
        $tenantId = (int) $this->defaultTenant->id;

        $this->makeEligibleContentItem();
        $this->makeEligibleContentItem();

        // Eligible story (null-timestamp thumbnail frame).
        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $story = Story::factory()->for($account, 'platformAccount')->create([
            'captured_at' => CarbonImmutable::now()->subDays(2),
        ]);
        $this->makeKeyframe($story, 0, null);
        $this->completedRun($story);

        // No keyframes → ineligible.
        $noFrames = ContentItem::factory()
            ->for(PlatformAccount::factory()->for(Creator::factory()), 'platformAccount')
            ->create(['published_at' => CarbonImmutable::now()->subDays(2)]);
        $this->completedRun($noFrames);

        // No COMPLETED enrichment run → ineligible.
        $noRun = ContentItem::factory()
            ->for(PlatformAccount::factory()->for(Creator::factory()), 'platformAccount')
            ->create(['published_at' => CarbonImmutable::now()->subDays(2)]);
        $this->makeKeyframe($noRun, 0, 0);

        $this->artisan('qds:visual-match-backfill')
            ->expectsOutputToContain("Tenant {$tenantId}: 2 content item(s), 1 story(ies) eligible.")
            ->expectsOutputToContain('skipped:not-configured ×3')
            ->expectsOutputToContain('Backfill done: 3 target(s) processed, 0 attribution re-run(s).')
            ->assertSuccessful();
    }

    public function test_days_window_bounds_the_sweep(): void
    {
        $tenantId = (int) $this->defaultTenant->id;
        $this->makeEligibleContentItem(CarbonImmutable::now()->subDays(40));

        $this->artisan('qds:visual-match-backfill')
            ->expectsOutputToContain("Tenant {$tenantId}: 0 content item(s), 0 story(ies) eligible.")
            ->assertSuccessful();

        $this->artisan('qds:visual-match-backfill', ['--days' => 60])
            ->expectsOutputToContain("Tenant {$tenantId}: 1 content item(s), 0 story(ies) eligible.")
            ->assertSuccessful();
    }

    public function test_tenant_option_scopes_the_sweep(): void
    {
        $this->makeEligibleContentItem();

        $other = Tenant::factory()->create(['name' => 'Other Tenant']);
        $this->withTenant($other, fn (): ContentItem => $this->makeEligibleContentItem());

        $this->artisan('qds:visual-match-backfill', ['--tenant' => $other->id])
            ->expectsOutputToContain("Tenant {$other->id}: 1 content item(s), 0 story(ies) eligible.")
            ->doesntExpectOutputToContain("Tenant {$this->defaultTenant->id}:")
            ->assertSuccessful();
    }

    public function test_dry_run_reports_without_executing(): void
    {
        $tenantId = (int) $this->defaultTenant->id;
        $this->makeEligibleContentItem();
        $this->makeEligibleContentItem();

        $this->artisan('qds:visual-match-backfill', ['--dry-run' => true])
            ->expectsOutputToContain("Tenant {$tenantId}: would process 2 content item(s), 0 story(ies) [dry-run].")
            ->expectsOutputToContain('Dry run — nothing executed.')
            ->doesntExpectOutputToContain('skipped:not-configured')
            ->assertSuccessful();
    }

    public function test_completed_match_writes_visual_evidence_and_reruns_attribution(): void
    {
        // Real pipeline end-to-end (pgvector exact scan included); only the
        // provider seam is stubbed. Stub vector === stored photo embedding
        // ⇒ cosine similarity 1.0 ⇒ AUTO (two distinct-timestamp frames).
        $vector = array_fill(0, 3072, 0.0);
        $vector[0] = 1.0;

        $this->app->instance(EmbeddingProvider::class, new class($vector) implements EmbeddingProvider
        {
            /** @param list<float> $vector */
            public function __construct(private array $vector) {}

            public function embedImage(string $bytes, string $mimeType): array
            {
                return $this->vector;
            }

            public function modelVersion(): string
            {
                return 'gemini-embedding-2';
            }

            public function isConfigured(): bool
            {
                return true;
            }
        });

        $creator = Creator::factory()->create();
        MonitoredSubject::factory()->create(['creator_id' => $creator->id]);
        $account = PlatformAccount::factory()->for($creator)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create([
            'published_at' => CarbonImmutable::now()->subDays(2),
        ]);
        // Identical bytes at DISTINCT timestamps: dedup groups them into one
        // representative (1 billed call) whose represented span still counts
        // as 2 distinct-timestamp support (spec §8) — AUTO is reachable.
        $this->makeKeyframe($item, 0, 0);
        $this->makeKeyframe($item, 1, 2000);
        $this->completedRun($item);

        $product = Product::factory()->create(['category' => null]); // default thresholds (auto 0.65)
        $campaign = SeedingCampaign::factory()->create([
            'status' => SeedingCampaignStatus::Active,
            'product_id' => $product->id,
        ]);
        Shipment::factory()->create([
            'seeding_campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => CarbonImmutable::now()->subDays(5), // in the 60-day window
        ]);

        $photo = ProductReferencePhoto::factory()->create(['product_id' => $product->id]);
        // Raw insert via VectorLiteral: independent of the model's cast, and
        // exactly how pgvector text literals are written (Task 1 contract).
        DB::table('product_photo_embeddings')->insert([
            'tenant_id' => (int) $this->defaultTenant->id,
            'product_reference_photo_id' => $photo->id,
            'model_version' => 'gemini-embedding-2',
            'embedding' => VectorLiteral::fromArray($vector),
            'created_at' => CarbonImmutable::now(),
        ]);

        $this->artisan('qds:visual-match-backfill')
            ->expectsOutputToContain('completed:matched=1,review=0,rejected=0 ×1')
            ->expectsOutputToContain('Backfill done: 1 target(s) processed, 1 attribution re-run(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('visual_match_runs', [
            'content_item_id' => $item->id,
            'outcome' => 'matched',
        ]);
        $this->assertDatabaseHas('recognition_detections', [
            'content_item_id' => $item->id,
            'recognition_type' => 'VISUAL_PRODUCT',
            'provider_label' => 'visual-product:'.$product->id,
            'product_id' => $product->id,
        ]);
        // The re-run attribution classified the visual HIGH product-level
        // alignment against the in-window shipment: SEEDED (spec §9).
        $this->assertDatabaseHas('mentions', [
            'content_item_id' => $item->id,
            'mention_type' => 'SEEDED',
        ]);
    }
}
