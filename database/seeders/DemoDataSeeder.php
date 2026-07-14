<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\DocumentAttachment;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\CRM\Models\Task;
use App\Modules\Monitoring\Models\Comment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Modules\Monitoring\Models\HashtagList;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Hashtags\HashtagNormalizer;
use App\Platform\Enrichment\Reach\ReachCalculator;
use App\Platform\Enrichment\Support\EmvConfigurationStatus;
use App\Platform\Enrichment\Support\ReachConfigurationStatus;
use App\Platform\Export\Models\ExportJob;
use App\Platform\Export\ReportFilters;
use App\Platform\Export\Support\ExportJobStatus;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\ExportFormat;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\SeedingType;
use App\Shared\Enums\SentimentLabel;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Enums\TaskStatus;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Tenancy\TenantContext;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Full-coverage local demo data: ~300 creators plus everything needed to
 * light up every feature page (monitoring, review queue, EMV, operations,
 * exports, CRM, seeding, results). Synthetic data only (DP-005).
 *
 * Append-only by design — never deletes (metric_snapshots and emv_results
 * carry append-only triggers). Intended for a fresh or near-fresh local DB:
 *   php artisan db:seed --class=DemoDataSeeder
 * then build the star schema from the seeded operational rows:
 *   php artisan qds:refresh-rollups   (QDS_ANALYTICS_ROLLUP_REFRESH_ENABLED=true)
 */
class DemoDataSeeder extends Seeder
{
    private const FIXTURE_VERSION = 'demo-seed-v1';

    /** 1x1 transparent PNG for showcase story media (media route works). */
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const PDF_STUB = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\nxref\n0 4\ntrailer<</Size 4/Root 1 0 R>>\n%%EOF\n";

    /** Curated demo brands: [name, sector, client index 0-7]. */
    private const BRANDS = [
        ['Velura Cosmetics', SectorLabel::Beauty, 0],
        ['PureGlow Skin', SectorLabel::Beauty, 0],
        ['Atelier Nord', SectorLabel::Fashion, 1],
        ['Marlow & Stitch', SectorLabel::Fashion, 1],
        ['KinFit Athletics', SectorLabel::Fitness, 2],
        ['CorePulse', SectorLabel::Fitness, 2],
        ['Verdana Foods', SectorLabel::FoodBeverage, 3],
        ['Brauhaus Klar', SectorLabel::FoodBeverage, 3],
        ['Wanderlicht Travel', SectorLabel::Travel, 4],
        ['Fernweh Gear', SectorLabel::Travel, 4],
        ['Hearthside Living', SectorLabel::HomeInterior, 5],
        ['Lumen & Loft', SectorLabel::HomeInterior, 5],
        ['ByteWave Audio', SectorLabel::Tech, 6],
        ['Nexon Labs', SectorLabel::Tech, 6],
        ['VitaSense', SectorLabel::HealthWellness, 7],
        ['Stillwasser Spa', SectorLabel::HealthWellness, 7],
        ['Kindermond', SectorLabel::ParentingFamily, 3],
        ['PlayForge Games', SectorLabel::Gaming, 6],
        ['Klangraum Records', SectorLabel::Music, 1],
        ['Studio Umlaut', SectorLabel::ArtDesign, 5],
    ];

    private Collection $staff;

    private Collection $clients;

    private Collection $brands;

    private Collection $products;

    private Collection $creators;

    /** @var array<int, list<PlatformAccount>> keyed by creator id */
    private array $accountsByCreator = [];

    /** @var array<int, int> creator id => monitored subject id */
    private array $subjectByCreator = [];

    private Collection $campaigns;

    private Collection $seedingCampaigns;

    /** @var array<int, list<string>> creator id => restricted brand names (lowercase) */
    private array $restrictedBrands = [];

    /** @var array<int, list<int>> creator id => content item ids */
    private array $contentByCreator = [];

    /** @var array<int, ContentItem> content id => item (metrics reused for EMV inputs) */
    private array $contentIndex = [];

    /** @var array<int, bool> content ids that already carry a mention */
    private array $mentionedContent = [];

    private CarbonImmutable $now;

    /** ADR-0019: the founding tenant every demo row belongs to. */
    private int $tenantId;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoDataSeeder is local/testing only (DP-005: no synthetic data on reachable deployments).');
        }

        mt_srand(20260707);
        fake()->seed(20260707);
        $this->now = CarbonImmutable::now();

        $this->call(DatabaseSeeder::class); // roles + the two @qds.test accounts + the founding tenant

        // ADR-0019: everything the demo creates belongs to the founding
        // tenant DatabaseSeeder just ensured. Setting the context makes all
        // Eloquent/factory creation auto-stamp via BelongsToTenant; the bulk
        // DB::table() inserts below stamp tenant_id explicitly.
        $tenant = Tenant::query()->where('name', 'Question de Style')->firstOrFail();
        app(TenantContext::class)->set($tenant);
        $this->tenantId = (int) $tenant->id;

        // One transaction: a failure anywhere rolls the whole demo set back
        // (files written to disk are not rolled back — harmless orphans).
        DB::transaction(function (): void {
            $this->seedStaffUsers();
            $this->seedClientsBrandsProducts();
            $this->seedCreators();
            $this->seedRoster();
            $this->seedAccountSnapshots();
            $this->seedContent();
            $this->seedStories();
            $this->seedHashtags();
            $this->seedSentiment();
            $this->seedRecognition();
            $this->seedComments();
            $this->seedEmv();
            $this->seedReach();
            $this->seedCampaigns();
            $this->seedCommunicationLogs();
            $this->seedSeedingAndShipments();
            $this->seedOrganicMentions();
            $this->seedTasks();
            $this->seedDocuments();
            $this->seedExports();
            $this->seedOperations();
        });

        $this->command->info('Demo data seeded. Now run: php artisan qds:refresh-rollups');
    }

    private function seedStaffUsers(): void
    {
        $defs = [
            ['director@qds.test', 'Dana Direktor', RoleName::AccountDirector],
            ['cm.anna@qds.test', 'Anna Kampagne', RoleName::CampaignManager],
            ['cm.jonas@qds.test', 'Jonas Planer', RoleName::CampaignManager],
            ['irm@qds.test', 'Rita Relations', RoleName::InfluencerRelationsManager],
            ['analyst@qds.test', 'Alex Analyst', RoleName::Analyst],
        ];

        foreach ($defs as [$email, $name, $role]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['display_name' => $name, 'password' => 'password', 'active' => true],
            );
            $user->syncRoles([$role->value]);
        }

        $this->staff = User::query()->where('email', 'like', '%@qds.test')->get();
        $this->command->info('Users: '.$this->staff->count());
    }

    private function seedClientsBrandsProducts(): void
    {
        $this->clients = Client::factory()->count(8)->create();

        $this->brands = collect(self::BRANDS)->map(fn (array $def) => Brand::factory()->create([
            'client_id' => $this->clients[$def[2]]->id,
            'name' => $def[0],
            'sector' => $def[1],
            'aliases' => [mb_strtolower($def[0]), '@'.Str::slug($def[0], '')],
        ]));

        $nouns = ['Serum', 'Kit', 'Set', 'Edition', 'Bundle', 'Collection', 'Box', 'Duo'];
        $this->products = $this->brands->flatMap(fn (Brand $brand) => Product::factory()
            ->count(random_int(3, 4))
            ->sequence(fn ($seq) => [
                'brand_id' => $brand->id,
                'name' => Str::before($brand->name, ' ').' '.$nouns[$seq->index % count($nouns)].' '.fake()->numerify('##'),
                'category' => $brand->sector,
                'unit_value' => new MetricValue(fake()->randomFloat(2, 9, 320), MetricTier::Confirmed),
            ])
            ->create());

        $this->command->info("Clients: {$this->clients->count()}, Brands: {$this->brands->count()}, Products: {$this->products->count()}");
    }

    private function seedCreators(): void
    {
        // Weighted across all 9 RelationshipStatus values so every filter has hits.
        $statuses = [
            ...array_fill(0, 90, RelationshipStatus::Active),
            ...array_fill(0, 45, RelationshipStatus::Collaborated),
            ...array_fill(0, 45, RelationshipStatus::Prospect),
            ...array_fill(0, 35, RelationshipStatus::Contacted),
            ...array_fill(0, 30, RelationshipStatus::InConversation),
            ...array_fill(0, 20, RelationshipStatus::None),
            ...array_fill(0, 15, RelationshipStatus::Paused),
            ...array_fill(0, 12, RelationshipStatus::Declined),
            ...array_fill(0, 8, RelationshipStatus::Blocklisted),
        ];
        shuffle($statuses);

        $languages = ['de', 'de', 'de', 'en', 'en', 'fr'];

        $this->creators = Creator::factory()
            ->count(300)
            ->sequence(fn ($seq) => [
                'relationship_status' => $statuses[$seq->index],
                'primary_language' => $languages[$seq->index % count($languages)],
            ])
            ->create();

        // Contacts for ~60% of creators.
        foreach ($this->creators->random(180) as $creator) {
            Contact::factory()->count(mt_rand(1, 10) > 8 ? 2 : 1)->create([
                'creator_id' => $creator->id,
                'preferred_channel' => fake()->randomElement(['email', 'DM', 'phone']),
            ]);
        }

        // Brand preferences for ~70%; restrictions recorded to honour the guard.
        $brandNames = $this->brands->pluck('name');
        foreach ($this->creators->random(210) as $creator) {
            $preferred = $brandNames->random(mt_rand(2, 3))->values()->all();
            $restricted = mt_rand(1, 10) <= 3 ? [$brandNames->random()] : [];
            BrandPreference::factory()->create([
                'creator_id' => $creator->id,
                'preferred_brands' => $preferred,
                'restricted_brands' => $restricted,
                'notes' => $restricted !== [] ? 'Declines partnerships with '.$restricted[0].'.' : null,
            ]);
            $this->restrictedBrands[$creator->id] = array_map(mb_strtolower(...), $restricted);
        }

        $this->command->info("Creators: {$this->creators->count()}");
    }

    private function seedRoster(): void
    {
        $handleSeq = 0;

        foreach ($this->creators as $creator) {
            $platforms = [Platform::Instagram];
            if (mt_rand(1, 100) <= 50) {
                $platforms[] = Platform::TikTok;
            }
            if (mt_rand(1, 100) <= 25) {
                $platforms[] = Platform::YouTube;
            }

            // Audience size tiers: nano → macro.
            $base = fake()->randomElement([
                mt_rand(2_000, 10_000), mt_rand(10_000, 60_000),
                mt_rand(10_000, 60_000), mt_rand(60_000, 250_000), mt_rand(250_000, 1_200_000),
            ]);

            foreach ($platforms as $platform) {
                $handleSeq++;
                $account = PlatformAccount::factory()->create([
                    'creator_id' => $creator->id,
                    'platform' => $platform,
                    'handle' => Str::slug($creator->display_name, '.').'.'.$handleSeq,
                    'follower_count' => new MetricValue((float) $base, MetricTier::Public, 'followers'),
                    'provenance' => $this->provenanceFor($platform),
                ]);
                $this->accountsByCreator[$creator->id][] = $account;
            }

            $subject = MonitoredSubject::factory()->create([
                'creator_id' => $creator->id,
                'label' => $creator->display_name,
                'platforms' => $platforms,
                'active' => ! in_array($creator->relationship_status, [RelationshipStatus::Blocklisted, RelationshipStatus::Declined], true),
            ]);
            $this->subjectByCreator[$creator->id] = $subject->id;
        }

        $this->command->info('Platform accounts: '.PlatformAccount::query()->count().', Monitored subjects: '.count($this->subjectByCreator));
    }

    /** Weekly follower time series per account, 13 points — bulk inserted. */
    private function seedAccountSnapshots(): void
    {
        $rows = [];

        foreach ($this->accountsByCreator as $accounts) {
            foreach ($accounts as $account) {
                $target = $account->follower_count->amount;
                $start = $target * fake()->randomFloat(4, 0.82, 0.95);
                $provenance = json_encode($this->provenanceFor($account->platform)->toArray());

                for ($week = 0; $week < 13; $week++) {
                    $followers = round($start + ($target - $start) * ($week / 12) * fake()->randomFloat(4, 0.9, 1.1));
                    $capturedAt = $this->now->subWeeks(12 - $week)->startOfWeek()->addHours(mt_rand(6, 10));
                    $rows[] = [
                        'tenant_id' => $this->tenantId,
                        'platform_account_id' => $account->id,
                        'content_item_id' => null,
                        'captured_at' => $capturedAt,
                        'metrics' => json_encode([
                            ['amount' => $followers, 'tier' => 'PUBLIC', 'metric' => 'followers'],
                            ['amount' => mt_rand(80, 900), 'tier' => 'PUBLIC', 'metric' => 'following'],
                            ['amount' => mt_rand(40, 2200), 'tier' => 'PUBLIC', 'metric' => 'media_count'],
                        ]),
                        'provenance' => $provenance,
                        'created_at' => $capturedAt,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('metric_snapshots')->insert($chunk);
        }

        $this->command->info('Account metric snapshots: '.count($rows));
    }

    private function seedContent(): void
    {
        $eligible = $this->creators->filter(fn (Creator $c) => in_array($c->relationship_status, [
            RelationshipStatus::Active, RelationshipStatus::Collaborated,
            RelationshipStatus::Paused, RelationshipStatus::InConversation,
        ], true));

        $snapshotRows = [];
        $count = 0;

        foreach ($eligible as $creator) {
            foreach (range(1, mt_rand(3, 9)) as $i) {
                $account = fake()->randomElement($this->accountsByCreator[$creator->id]);
                $platform = $account->platform;
                $type = match ($platform) {
                    Platform::Instagram => fake()->randomElement([ContentType::ImagePost, ContentType::Carousel, ContentType::Reel, ContentType::Reel]),
                    Platform::TikTok => ContentType::Video,
                    Platform::YouTube => fake()->randomElement([ContentType::Video, ContentType::Short]),
                };
                $publishedAt = $this->now->subDays(mt_rand(0, 89))->subMinutes(mt_rand(0, 1439));
                $followers = $account->follower_count->amount;
                $views = max(150, (int) round($followers * fake()->randomFloat(4, 0.08, 0.9)));
                $likes = (int) round($views * fake()->randomFloat(4, 0.02, 0.12));
                $comments = max(1, (int) round($likes * fake()->randomFloat(4, 0.01, 0.08)));
                $shares = (int) round($views * fake()->randomFloat(4, 0.001, 0.02));

                $item = ContentItem::factory()->create([
                    'platform_account_id' => $account->id,
                    'platform' => $platform,
                    'content_type' => $type,
                    'caption' => fake()->realText(120).' #'.Str::slug(fake()->word()),
                    'published_at' => $publishedAt,
                    'public_metrics' => [
                        new MetricValue($views, MetricTier::Public, 'views'),
                        new MetricValue($likes, MetricTier::Public, 'likes'),
                        new MetricValue($comments, MetricTier::Public, 'comments'),
                        new MetricValue($shares, MetricTier::Public, 'shares'),
                    ],
                    'provenance' => $this->provenanceFor($platform, $type),
                ]);

                $this->contentByCreator[$creator->id][] = $item->id;
                $this->contentIndex[$item->id] = $item;
                $count++;

                // 2-4 growth points per item — bulk inserted below.
                $points = mt_rand(2, 4);
                $offsets = [1, 24, 72, 168]; // hours after publish
                $provenance = json_encode($this->provenanceFor($platform, $type)->toArray());
                for ($p = 0; $p < $points; $p++) {
                    $capturedAt = $publishedAt->addHours($offsets[$p]);
                    if ($capturedAt->isAfter($this->now)) {
                        break;
                    }
                    $growth = 0.45 + 0.55 * (($p + 1) / $points);
                    $snapshotRows[] = [
                        'tenant_id' => $this->tenantId,
                        'platform_account_id' => null,
                        'content_item_id' => $item->id,
                        'captured_at' => $capturedAt,
                        'metrics' => json_encode([
                            ['amount' => (int) round($views * $growth), 'tier' => 'PUBLIC', 'metric' => 'views'],
                            ['amount' => (int) round($likes * $growth), 'tier' => 'PUBLIC', 'metric' => 'likes'],
                            ['amount' => (int) round($comments * $growth), 'tier' => 'PUBLIC', 'metric' => 'comments'],
                            ['amount' => (int) round($shares * $growth), 'tier' => 'PUBLIC', 'metric' => 'shares'],
                            ['amount' => (int) round($views * $growth * 0.01), 'tier' => 'PUBLIC', 'metric' => 'saves'],
                        ]),
                        'provenance' => $provenance,
                        'created_at' => $capturedAt,
                    ];
                }
            }
        }

        foreach (array_chunk($snapshotRows, 500) as $chunk) {
            DB::table('metric_snapshots')->insert($chunk);
        }

        $this->command->info("Content items: {$count}, content snapshots: ".count($snapshotRows));
    }

    private function seedStories(): void
    {
        $igAccounts = collect($this->accountsByCreator)
            ->flatten()
            ->filter(fn (PlatformAccount $a) => $a->platform === Platform::Instagram)
            ->values();

        $mediaDisk = config('qds.ingestion.media_disk', 'media');
        $mediaWritable = rescue(fn () => Storage::disk($mediaDisk)->put('stories/.probe', 'x'), false, false) !== false;
        $withMedia = 0;

        foreach ($igAccounts->random(min(150, $igAccounts->count()))->values() as $i => $account) {
            $active = $i < 30;
            $capturedAt = $active
                ? $this->now->subHours(mt_rand(1, 20))
                : $this->now->subDays(mt_rand(2, 45))->subHours(mt_rand(0, 23));

            $mediaUrl = 'stories/'.$account->id.'/'.Str::random(24).'.png';
            if ($mediaWritable && $withMedia < 12) {
                Storage::disk($mediaDisk)->put($mediaUrl, base64_decode(self::PNG_1PX));
                $withMedia++;
            }

            Story::factory()->create([
                'platform_account_id' => $account->id,
                'captured_at' => $capturedAt,
                'expires_at' => $capturedAt->addHours(24),
                'media_url' => $mediaUrl,
                'public_metrics' => [
                    new MetricValue(mt_rand(150, 40_000), MetricTier::Public, 'views'),
                ],
            ]);
        }

        $this->command->info('Stories: '.Story::query()->count()." ({$withMedia} with real media files)");
    }

    private function seedHashtags(): void
    {
        foreach (['#ad', '#werbung', '#unboxing'] as $tag) {
            HashtagList::factory()->agency()->hashtag($tag)->create(['created_by' => $this->staff->first()->id]);
        }
        foreach ($this->brands as $brand) {
            HashtagList::factory()->hashtag('#'.Str::slug($brand->name, ''))->create(['brand_id' => $brand->id]);
        }

        $pool = ['#ootd', '#skincare', '#fitness', '#foodie', '#travelgram', '#homedecor', '#tech', '#gaming', '#wellness', '#style'];
        $rows = [];
        $ambiguous = 0;

        foreach ($this->contentIndex as $id => $item) {
            if (mt_rand(1, 100) > 50) {
                continue;
            }
            foreach ((array) array_rand(array_flip($pool), mt_rand(1, 3)) as $tag) {
                $isAmbiguous = $ambiguous < 25 && mt_rand(1, 100) <= 5;
                $ambiguous += $isAmbiguous ? 1 : 0;
                $rows[] = [
                    'tenant_id' => $this->tenantId,
                    'content_item_id' => $id,
                    'original' => $tag,
                    'normalized' => HashtagNormalizer::normalize($tag),
                    'first_position' => mt_rand(0, 180),
                    'occurrences' => 1,
                    'matches' => json_encode($isAmbiguous ? [['scope' => 'BRAND'], ['scope' => 'CAMPAIGN']] : []),
                    'is_ambiguous' => $isAmbiguous,
                    'resolved_at' => null,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('content_hashtags')->insert($chunk);
        }

        $this->command->info('Hashtag lists: '.HashtagList::query()->count().', content hashtags: '.count($rows)." ({$ambiguous} ambiguous → review)");
    }

    private function seedSentiment(): void
    {
        $labels = [
            ...array_fill(0, 45, SentimentLabel::Positive),
            ...array_fill(0, 30, SentimentLabel::Neutral),
            ...array_fill(0, 15, SentimentLabel::Negative),
            ...array_fill(0, 5, SentimentLabel::Mixed),
            ...array_fill(0, 5, SentimentLabel::Unknown),
        ];
        $count = 0;

        foreach ($this->contentIndex as $id => $item) {
            if (mt_rand(1, 100) > 70) {
                continue;
            }
            $label = $labels[array_rand($labels)];
            $roll = mt_rand(1, 100);
            [$level, $status] = match (true) {
                $roll <= 10 => [ConfidenceLevel::Low, VerificationStatus::AiAssessed],      // review queue
                $roll <= 25 => [ConfidenceLevel::High, VerificationStatus::HumanReviewed],
                default => [fake()->randomElement([ConfidenceLevel::Medium, ConfidenceLevel::High]), VerificationStatus::AiAssessed],
            };

            SentimentAnalysis::factory()->create([
                'content_item_id' => $id,
                'label' => $label,
                'context_summary' => fake()->sentence(10),
                'assessment' => new ConfidenceAssessment($label->value, $level, ['caption-tone'], $status),
            ]);
            $count++;
        }

        $this->command->info("Sentiment analyses: {$count}");
    }

    private function seedRecognition(): void
    {
        $count = 0;

        foreach ($this->contentIndex as $id => $item) {
            if (mt_rand(1, 100) > 30) {
                continue;
            }
            $brand = $this->brands->random();
            $type = fake()->randomElement(RecognitionType::cases());
            $factory = RecognitionDetection::factory();
            if (mt_rand(1, 100) <= 15) {
                $factory = $factory->lowConfidence(); // review queue
            }
            $factory->create([
                'content_item_id' => $id,
                'recognition_type' => $type,
                'detected_text' => $type === RecognitionType::ImageTextOcr ? $brand->name.' — neu bei mir!' : null,
                'detected_brand' => $brand->name,
                'provider_label' => $brand->name,
                'provenance' => new Provenance(
                    $type === RecognitionType::SpokenBrand ? SourceRegistry::GOOGLE_SPEECH_TO_TEXT : SourceRegistry::GOOGLE_CLOUD_VISION,
                    $this->now,
                    self::FIXTURE_VERSION,
                ),
            ]);
            $count++;
        }

        foreach (Story::query()->inRandomOrder()->limit(20)->get() as $story) {
            RecognitionDetection::factory()->inStory()->create([
                'story_id' => $story->id,
                'detected_brand' => $this->brands->random()->name,
            ]);
            $count += 1;
        }

        $this->command->info("Recognition detections: {$count}");
    }

    private function seedComments(): void
    {
        $showcase = array_slice(array_keys($this->contentIndex), 0, 60);
        $count = 0;
        foreach ($showcase as $contentId) {
            foreach (range(1, mt_rand(2, 5)) as $i) {
                Comment::factory()->create([
                    'content_item_id' => $contentId,
                    'posted_at' => $this->now->subHours(mt_rand(1, 400)),
                ]);
                $count++;
            }
        }
        $this->command->info("Comments: {$count}");
    }

    private function seedEmv(): void
    {
        $admin = $this->staff->firstWhere('email', 'admin@qds.test') ?? $this->staff->first();

        $active = EmvConfiguration::factory()->active()->create([
            'name' => 'QDS Standard EMV 2026',
            'formula_version' => 'formula-2026.1',
            'rate_card_version' => 'rates-2026.1',
            'created_by' => $admin->id,
            'activated_by' => $admin->id,
            'effective_from' => $this->now->subMonths(3)->toDateString(),
        ]);
        EmvConfiguration::factory()->create(['name' => 'Draft: Q4 rate proposal', 'status' => EmvConfigurationStatus::Draft, 'created_by' => $admin->id]);
        EmvConfiguration::factory()->create(['name' => 'Legacy 2025 model', 'status' => EmvConfigurationStatus::Archived, 'created_by' => $admin->id]);
        EmvConfiguration::factory()->create(['name' => 'Paused pilot model', 'status' => EmvConfigurationStatus::Inactive, 'created_by' => $admin->id]);

        $rows = [];
        foreach ($this->contentIndex as $id => $item) {
            if (mt_rand(1, 100) > 60) {
                continue;
            }
            $metrics = collect($item->public_metrics)->keyBy(fn (MetricValue $m) => $m->metric);
            $views = $metrics->get('views')?->amount ?? 0;
            $likes = $metrics->get('likes')?->amount ?? 0;
            $comments = $metrics->get('comments')?->amount ?? 0;
            $emv = round($views * 0.01 + $likes * 0.05 + $comments * 0.2, 2);
            $calculatedAt = CarbonImmutable::parse($item->published_at)->addDays(2);

            $rows[] = [
                'tenant_id' => $this->tenantId,
                'content_item_id' => $id,
                'emv_configuration_id' => $active->id,
                'formula_version' => $active->formula_version,
                'rate_card_version' => $active->rate_card_version,
                'currency' => 'EUR',
                'value' => json_encode(['amount' => $emv, 'tier' => 'ESTIMATED', 'metric' => 'emv']),
                'inputs' => json_encode(['views' => $views, 'likes' => $likes, 'comments' => $comments]),
                'assumptions' => null,
                'calculated_at' => $calculatedAt,
                'created_at' => $calculatedAt,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('emv_results')->insert($chunk);
        }

        $this->command->info('EMV configurations: 4, EMV results: '.count($rows));
    }

    /** Mirrors seedEmv(): an ACTIVE reach configuration + DRAFT/ARCHIVED samples, then real ReachResult rows via ReachCalculator (REQ-M1-006, ADR-0022). */
    private function seedReach(): void
    {
        $admin = $this->staff->firstWhere('email', 'admin@qds.test') ?? $this->staff->first();

        $active = ReachConfiguration::factory()->active()->create([
            'name' => 'QDS Estimated Reach 2026',
            'method' => 'qds-estimated-reach',
            'formula_version' => 'reach-2026.1',
            'params' => ['view_weight' => 0.7, 'follower_weight' => 0.1],
            'effective_from' => $this->now->subMonths(3)->toDateString(),
            'created_by' => $admin->id,
            'activated_by' => $admin->id,
        ]);
        ReachConfiguration::factory()->create(['name' => 'Draft: Q4 reach proposal', 'status' => ReachConfigurationStatus::Draft, 'created_by' => $admin->id]);
        ReachConfiguration::factory()->create(['name' => 'Legacy 2025 reach model', 'status' => ReachConfigurationStatus::Archived, 'created_by' => $admin->id]);

        // Eager-load platformAccount (Model::shouldBeStrict forbids lazy
        // loading outside production) before handing content to the real
        // calculator, so seeded reach_results use the disclosed formula.
        $calculator = app(ReachCalculator::class);
        $content = ContentItem::query()->with('platformAccount')->whereIn('id', array_keys($this->contentIndex))->get();

        $resultCount = 0;
        foreach ($content as $item) {
            if ($calculator->calculate($item) !== null) {
                $resultCount++;
            }
        }

        $this->command->info("Reach configurations: 3 (1 active: {$active->formula_version}), reach results: {$resultCount}");
    }

    private function seedCampaigns(): void
    {
        $plan = [
            [CampaignStatus::Draft, 2], [CampaignStatus::Planned, 3], [CampaignStatus::Active, 6],
            [CampaignStatus::Paused, 2], [CampaignStatus::Completed, 4], [CampaignStatus::Cancelled, 1],
        ];
        $themes = ['Summer Launch', 'Herbst Kollektion', 'Glow Up', 'Creator Week', 'Product Drop', 'Brand Refresh', 'Winter Push', 'Awareness Sprint'];

        $this->campaigns = collect();
        foreach ($plan as [$status, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $brand = $this->brands->random();
                [$start, $end, $spend] = match ($status) {
                    CampaignStatus::Draft => [null, null, null],
                    CampaignStatus::Planned => [$this->now->addDays(mt_rand(10, 40)), $this->now->addDays(mt_rand(50, 90)), null],
                    CampaignStatus::Active => [$this->now->subDays(mt_rand(10, 45)), $this->now->addDays(mt_rand(10, 45)), mt_rand(0, 1) ? mt_rand(3_000, 25_000) : null],
                    CampaignStatus::Paused => [$this->now->subDays(mt_rand(20, 50)), $this->now->addDays(mt_rand(5, 30)), mt_rand(2_000, 12_000)],
                    CampaignStatus::Completed => [$this->now->subDays(mt_rand(80, 140)), $this->now->subDays(mt_rand(5, 30)), mt_rand(5_000, 40_000)],
                    CampaignStatus::Cancelled => [$this->now->subDays(60), null, null],
                };

                $campaign = Campaign::factory()->create([
                    'name' => $brand->name.' '.fake()->randomElement($themes).' '.$this->now->year,
                    'brand_id' => $brand->id,
                    'status' => $status,
                    'start_at' => $start,
                    'end_at' => $end,
                    'spend' => $spend !== null ? new MetricValue((float) $spend, MetricTier::Confirmed, 'spend') : null,
                ]);
                $this->campaigns->push($campaign);

                if ($status !== CampaignStatus::Draft) {
                    $eligible = $this->creators
                        ->reject(fn (Creator $c) => $c->relationship_status === RelationshipStatus::Blocklisted)
                        ->reject(fn (Creator $c) => in_array(mb_strtolower($brand->name), $this->restrictedBrands[$c->id] ?? [], true))
                        ->random(mt_rand(5, 30));
                    $campaign->creators()->attach($eligible->pluck('id')->all());
                }
            }
        }

        $this->command->info('Campaigns: '.$this->campaigns->count().', pivot rows: '.DB::table('campaign_creator')->count());
    }

    private function seedCommunicationLogs(): void
    {
        $count = 0;

        foreach ($this->campaigns->whereIn('status', [CampaignStatus::Active, CampaignStatus::Completed]) as $campaign) {
            $attached = $campaign->creators()->limit(8)->get();
            foreach ($attached as $creator) {
                foreach (range(1, mt_rand(2, 5)) as $i) {
                    CommunicationLog::factory()->create([
                        'creator_id' => $creator->id,
                        'campaign_id' => mt_rand(0, 1) ? $campaign->id : null,
                        'channel' => fake()->randomElement(['email', 'DM', 'call', 'phone']),
                        'direction' => fake()->randomElement(['inbound', 'outbound']),
                        'summary' => fake()->realText(90),
                        'occurred_at' => $this->now->subDays(mt_rand(0, 90))->subHours(mt_rand(0, 23)),
                    ]);
                    $count++;
                }
            }
        }

        foreach ($this->creators->random(40) as $creator) {
            CommunicationLog::factory()->create([
                'creator_id' => $creator->id,
                'campaign_id' => null,
                'direction' => fake()->randomElement(['inbound', 'outbound']),
                'occurred_at' => $this->now->subDays(mt_rand(0, 60)),
            ]);
            $count++;
        }

        $this->command->info("Communication logs: {$count}");
    }

    private function seedSeedingAndShipments(): void
    {
        $plan = [
            [SeedingCampaignStatus::Draft, 1], [SeedingCampaignStatus::Planned, 2],
            [SeedingCampaignStatus::Active, 4], [SeedingCampaignStatus::Shipping, 2],
            [SeedingCampaignStatus::Completed, 2], [SeedingCampaignStatus::Cancelled, 1],
        ];
        $types = SeedingType::cases();
        $this->seedingCampaigns = collect();
        $shipmentCount = 0;
        $linkCount = 0;
        $typeIx = 0;

        foreach ($plan as [$status, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $brand = $this->brands->random();
                $product = $this->products->firstWhere('brand_id', $brand->id) ?? $this->products->random();
                $parent = $this->campaigns
                    ->where('brand_id', $brand->id)
                    ->whereNotIn('status', [CampaignStatus::Draft])
                    ->first();

                $seeding = SeedingCampaign::factory()->create([
                    'name' => $brand->name.' Seeding '.fake()->randomElement(['Frühling', 'Sommer', 'Herbst', 'Winter']).' '.$this->now->year,
                    'seeding_type' => $types[$typeIx++ % count($types)],
                    'brand_id' => $brand->id,
                    'product_id' => $product->id,
                    'campaign_id' => (mt_rand(1, 100) <= 60 && $parent !== null) ? $parent->id : null,
                    'status' => $status,
                    'spend' => in_array($status, [SeedingCampaignStatus::Active, SeedingCampaignStatus::Completed], true) && mt_rand(0, 1)
                        ? new MetricValue((float) mt_rand(1_500, 12_000), MetricTier::Confirmed, 'spend')
                        : null,
                ]);
                $this->seedingCampaigns->push($seeding);

                if (in_array($status, [SeedingCampaignStatus::Draft, SeedingCampaignStatus::Planned], true)) {
                    continue;
                }

                $recipients = $this->creators
                    ->whereIn('relationship_status', [RelationshipStatus::Active, RelationshipStatus::Collaborated])
                    ->reject(fn (Creator $c) => in_array(mb_strtolower($brand->name), $this->restrictedBrands[$c->id] ?? [], true))
                    ->random(mt_rand(8, 20));

                foreach ($recipients as $creator) {
                    $roll = mt_rand(1, 100);
                    $shipStatus = match (true) {
                        $roll <= 15 => ShipmentStatus::Pending,
                        $roll <= 25 => ShipmentStatus::Preparing,
                        $roll <= 45 => ShipmentStatus::Shipped,
                        $roll <= 55 => ShipmentStatus::InTransit,
                        $roll <= 90 => ShipmentStatus::Delivered,
                        $roll <= 95 => ShipmentStatus::Returned,
                        default => ShipmentStatus::Failed,
                    };

                    $shippedAt = in_array($shipStatus, [ShipmentStatus::Pending, ShipmentStatus::Preparing], true)
                        ? null
                        : $this->now->subDays(mt_rand(5, 60))->subHours(mt_rand(0, 23));
                    $deliveredAt = $shipStatus === ShipmentStatus::Delivered ? $shippedAt?->addDays(mt_rand(2, 5)) : null;
                    $postingRequired = mt_rand(1, 100) <= 60;
                    $posted = $shipStatus === ShipmentStatus::Delivered && $postingRequired && mt_rand(1, 100) <= 70;
                    $postedAt = $posted ? $deliveredAt?->addDays(mt_rand(1, 10)) : null;

                    $shipment = Shipment::factory()->create([
                        'seeding_campaign_id' => $seeding->id,
                        'creator_id' => $creator->id,
                        'status' => $shipStatus,
                        'tracking_number' => $shippedAt !== null ? strtoupper(fake()->bothify('TRK-########')) : null,
                        'shipped_at' => $shippedAt,
                        'delivered_at' => $deliveredAt,
                        'product_id' => $product->id,
                        'quantity' => mt_rand(1, 3),
                        'product_value_at_ship' => new MetricValue($product->unit_value?->amount ?? 49.0, MetricTier::Confirmed),
                        'posting_required' => $postingRequired,
                        'posted' => $posted,
                        'posted_at' => $postedAt,
                    ]);
                    $shipmentCount++;

                    // Posted shipments produce resulting content + a SEEDED mention.
                    if ($posted && ! empty($this->contentByCreator[$creator->id])) {
                        $contentIds = fake()->randomElements(
                            $this->contentByCreator[$creator->id],
                            min(mt_rand(1, 2), count($this->contentByCreator[$creator->id])),
                        );
                        foreach ($contentIds as $contentId) {
                            DB::table('shipment_resulting_content')->insertOrIgnore([
                                'tenant_id' => $this->tenantId,
                                'shipment_id' => $shipment->id,
                                'content_item_id' => $contentId,
                                'created_at' => $postedAt,
                                'updated_at' => $postedAt,
                            ]);
                            $linkCount++;

                            if (! isset($this->mentionedContent[$contentId])) {
                                Mention::factory()->seeded()->create([
                                    'monitored_subject_id' => $this->subjectByCreator[$creator->id],
                                    'content_item_id' => $contentId,
                                    'campaign_id' => $seeding->campaign_id,
                                    'classification' => new ConfidenceAssessment(
                                        MentionType::Seeded->value,
                                        ConfidenceLevel::High,
                                        ['shipment-record:'.$shipment->id],
                                        VerificationStatus::AiAssessed,
                                    ),
                                ]);
                                $this->mentionedContent[$contentId] = true;
                            }
                        }
                    }
                }
            }
        }

        $this->command->info("Seeding campaigns: {$this->seedingCampaigns->count()}, shipments: {$shipmentCount}, resulting-content links: {$linkCount}");
    }

    private function seedOrganicMentions(): void
    {
        $count = 0;

        foreach ($this->contentByCreator as $creatorId => $contentIds) {
            foreach ($contentIds as $contentId) {
                if (isset($this->mentionedContent[$contentId]) || mt_rand(1, 100) > 40) {
                    continue;
                }

                $roll = mt_rand(1, 100);
                $factory = Mention::factory();
                $attrs = [
                    'monitored_subject_id' => $this->subjectByCreator[$creatorId],
                    'content_item_id' => $contentId,
                ];

                if ($roll <= 12) {
                    $factory = $factory->paid();
                    $attrs['campaign_id'] = $this->campaigns->whereNotIn('status', [CampaignStatus::Draft])->random()->id;
                } elseif ($roll <= 24) {
                    $factory = $factory->lowConfidence(); // review queue
                }

                $factory->create($attrs);
                $this->mentionedContent[$contentId] = true;
                $count++;
            }
        }

        // Story mentions.
        foreach (Story::query()->with('platformAccount')->inRandomOrder()->limit(30)->get() as $story) {
            $creatorId = $story->platformAccount?->creator_id;
            if ($creatorId === null || ! isset($this->subjectByCreator[$creatorId])) {
                continue;
            }
            Mention::factory()->inStory()->create([
                'monitored_subject_id' => $this->subjectByCreator[$creatorId],
                'story_id' => $story->id,
                'content_item_id' => null,
            ]);
            $count++;
        }

        $this->command->info("Organic/paid/story mentions: {$count}");
    }

    private function seedTasks(): void
    {
        $mkAnchor = fn (): array => match (mt_rand(1, 10)) {
            1, 2, 3, 4, 5 => ['creator_id' => $this->creators->random()->id, 'campaign_id' => null],
            6, 7, 8 => ['creator_id' => null, 'campaign_id' => $this->campaigns->random()->id],
            default => ['creator_id' => null, 'campaign_id' => null],
        };
        $assignee = fn (): ?int => mt_rand(1, 100) <= 70 ? $this->staff->random()->id : null;
        $openStatus = fn () => fake()->randomElement([TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Blocked]);
        $titles = ['Follow up with creator', 'Draft briefing', 'Review content draft', 'Negotiate rate', 'Prepare shipment list', 'Approve invoice', 'Schedule call', 'Update tracking sheet'];
        $count = 0;

        $make = function (array $attrs) use (&$count, $mkAnchor, $assignee, $titles): void {
            Task::factory()->create([
                'title' => fake()->randomElement($titles),
                'assignee_user_id' => $assignee(),
                ...$mkAnchor(),
                ...$attrs,
            ]);
            $count++;
        };

        for ($i = 0; $i < 10; $i++) { // overdue
            $make(['status' => $openStatus(), 'due_at' => $this->now->subDays(mt_rand(1, 10)), 'reminder_sent_at' => $this->now->subDays(mt_rand(1, 10))]);
        }
        for ($i = 0; $i < 10; $i++) { // due soon; half unstamped so qds:send-task-reminders fires
            $make(['status' => $openStatus(), 'due_at' => $this->now->addHours(mt_rand(1, 47)), 'reminder_sent_at' => $i % 2 === 0 ? null : $this->now->subHours(2)]);
        }
        for ($i = 0; $i < 25; $i++) { // future
            $make(['status' => $openStatus(), 'due_at' => $this->now->addDays(mt_rand(3, 30)), 'reminder_sent_at' => null]);
        }
        for ($i = 0; $i < 15; $i++) {
            $make(['status' => TaskStatus::Done, 'due_at' => $this->now->subDays(mt_rand(1, 30)), 'reminder_sent_at' => null]);
        }
        for ($i = 0; $i < 5; $i++) {
            $make(['status' => TaskStatus::Cancelled, 'due_at' => $this->now->subDays(mt_rand(1, 30)), 'reminder_sent_at' => null]);
        }
        for ($i = 0; $i < 8; $i++) { // no due date
            $make(['status' => $openStatus(), 'due_at' => null, 'reminder_sent_at' => null]);
        }

        $this->command->info("Tasks: {$count}");
    }

    private function seedDocuments(): void
    {
        $disk = config('qds.documents.disk', 'local');
        $labels = ['Vertrag', 'Briefing', 'Rechnung', 'Media-Kit', 'Rate-Card', 'NDA'];
        $count = 0;

        $anchors = [
            ...array_map(fn () => ['creator_id' => $this->creators->random()->id], range(1, 40)),
            ...array_map(fn () => ['campaign_id' => $this->campaigns->random()->id], range(1, 30)),
            ...array_map(fn () => ['seeding_campaign_id' => $this->seedingCampaigns->random()->id], range(1, 20)),
        ];

        foreach ($anchors as $anchor) {
            $path = 'documents/'.$this->now->format('Y/m').'/'.Str::random(40).'.pdf';
            Storage::disk($disk)->put($path, self::PDF_STUB);

            DocumentAttachment::factory()->create([
                'creator_id' => null,
                'campaign_id' => null,
                'seeding_campaign_id' => null,
                ...$anchor,
                'file_name' => fake()->randomElement($labels).' '.fake()->numerify('##-###').'.pdf',
                'storage_url' => $path,
                'uploaded_at' => $this->now->subDays(mt_rand(0, 120)),
            ]);
            $count++;
        }

        $this->command->info("Documents: {$count} (real files on disk '{$disk}')");
    }

    private function seedExports(): void
    {
        $admin = User::query()->where('email', 'admin@qds.test')->first();
        $disk = config('qds.exports.disk', 'exports');
        $csv = "period,mentions,views,emv\n2026-06,124,1830400,10422.50\n2026-07,98,1411200,8120.75\n";

        foreach ([ExportFormat::Csv, ExportFormat::Excel] as $i => $format) {
            $path = 'exports/demo/'.Str::uuid().'.'.strtolower($format->value);
            Storage::disk($disk)->put($path, $csv);
            ExportJob::factory()->completed()->create([
                'user_id' => $admin->id,
                'format' => $format,
                'disk' => $disk,
                'file_path' => $path,
                'file_size' => strlen($csv),
                'completed_at' => $this->now->subHours(2 + $i),
                'expires_at' => $this->now->addHours(22),
            ]);
        }

        // Distinct filters per live job — export_jobs_live_unique forbids two
        // live rows with the same (user, report, format, filters_hash).
        $weekly = ReportFilters::validate(['grain' => 'week']);
        $quarterly = ReportFilters::validate(['grain' => 'quarter']);

        ExportJob::factory()->create(['user_id' => $admin->id, 'status' => ExportJobStatus::Pending]);
        ExportJob::factory()->create([
            'user_id' => $admin->id,
            'status' => ExportJobStatus::Running,
            'filters' => $weekly->toArray(),
            'filters_hash' => $weekly->hash(),
        ]);
        ExportJob::factory()->create([
            'user_id' => $admin->id,
            'status' => ExportJobStatus::Failed,
            'filters' => $quarterly->toArray(),
            'filters_hash' => $quarterly->hash(),
            'error' => 'Demo: upstream rollup temporarily unavailable.',
            'failed_at' => $this->now->subDay(),
        ]);
        ExportJob::factory()->create([
            'user_id' => $admin->id,
            'status' => ExportJobStatus::Expired,
            'format' => ExportFormat::Pdf,
            'completed_at' => $this->now->subDays(3),
            'expires_at' => $this->now->subDays(2),
        ]);

        $this->command->info('Export jobs: 6 (2 downloadable)');
    }

    private function seedOperations(): void
    {
        $sources = [
            SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER,
            SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER,
            SourceRegistry::APIFY_INSTAGRAM_REEL_SCRAPER,
            SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS,
            SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER,
            SourceRegistry::YOUTUBE_DATA_API_V3,
            SourceRegistry::GOOGLE_CLOUD_VISION,
            SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
        ];

        // Provider health: mostly healthy, one degraded, one failing.
        foreach ($sources as $i => $source) {
            [$status, $failures, $errorCat] = match ($i) {
                4 => ['DEGRADED', 2, 'TIMEOUT'],
                7 => ['FAILING', 5, 'RATE_LIMITED'],
                default => ['HEALTHY', 0, null],
            };
            DB::table('provider_health_states')->updateOrInsert(['source' => $source], [
                'status' => $status,
                'last_success_at' => $this->now->subMinutes(mt_rand(10, 300)),
                'last_failure_at' => $failures > 0 ? $this->now->subMinutes(mt_rand(5, 60)) : null,
                'consecutive_failures' => $failures,
                'last_error_category' => $errorCat,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }

        // Two weeks of ingestion cycles; latest one still RUNNING.
        $cycles = [];
        for ($d = 14; $d >= 1; $d--) {
            foreach ([false, true] as $storiesOnly) {
                $started = $this->now->subDays($d)->setTime($storiesOnly ? 14 : 6, mt_rand(0, 30));
                $status = match (true) {
                    $d === 3 && ! $storiesOnly => 'PARTIAL',
                    $d === 7 && $storiesOnly => 'FAILED',
                    default => 'COMPLETED',
                };
                $expected = $storiesOnly ? mt_rand(80, 140) : mt_rand(400, 560);
                $failed = $status === 'COMPLETED' ? 0 : mt_rand(3, 25);
                $cycles[] = [
                    'correlation_id' => (string) Str::uuid(),
                    'status' => $status,
                    'stories_only' => $storiesOnly,
                    'full_depth' => ! $storiesOnly && $d % 7 === 0,
                    'creator_id' => null,
                    'accounts_count' => $expected,
                    'jobs_expected' => $expected,
                    'jobs_pending' => 0,
                    'jobs_failed' => $failed,
                    'started_at' => $started,
                    'finished_at' => $started->addMinutes(mt_rand(8, 45)),
                    'created_at' => $started,
                    'updated_at' => $started,
                ];
            }
        }
        $running = $this->now->subMinutes(12);
        $cycles[] = [
            'correlation_id' => (string) Str::uuid(),
            'status' => 'RUNNING',
            'stories_only' => false,
            'full_depth' => false,
            'creator_id' => null,
            'accounts_count' => 520,
            'jobs_expected' => 520,
            'jobs_pending' => 208,
            'jobs_failed' => 1,
            'started_at' => $running,
            'finished_at' => null,
            'created_at' => $running,
            'updated_at' => $running,
        ];
        DB::table('ingestion_cycles')->insert($cycles);

        // Provider calls referencing the recent cycles.
        $recentCycles = array_slice($cycles, -8);
        $callRows = [];
        for ($i = 0; $i < 160; $i++) {
            $cycle = $recentCycles[array_rand($recentCycles)];
            $source = $sources[array_rand($sources)];
            $ok = mt_rand(1, 100) <= 85;
            $started = CarbonImmutable::parse($cycle['started_at'])->addSeconds(mt_rand(10, 1800));
            $duration = mt_rand(180, 4200);
            $results = $ok ? mt_rand(1, 40) : 0;
            $callRows[] = [
                'source' => $source,
                'operation' => str_contains($source, 'story') ? 'fetch-stories' : 'fetch-profile',
                'correlation_id' => $cycle['correlation_id'],
                'job_id' => (string) Str::uuid(),
                'platform_account_id' => null,
                'started_at' => $started,
                'finished_at' => $started->addMilliseconds($duration),
                'duration_ms' => $duration,
                'http_status' => $ok ? 200 : fake()->randomElement([429, 500, 503]),
                'outcome' => $ok ? 'SUCCESS' : (mt_rand(0, 1) ? 'PARTIAL' : 'FAILURE'),
                'error_category' => $ok ? null : fake()->randomElement(['TIMEOUT', 'RATE_LIMITED', 'UPSTREAM_ERROR', 'MALFORMED_RESPONSE']),
                'error_message' => $ok ? null : 'Demo: synthetic provider error.',
                'retry_count' => $ok ? 0 : mt_rand(1, 3),
                'response_bytes' => $ok ? mt_rand(2_000, 400_000) : null,
                'result_count' => $results,
                'accepted_count' => $results,
                'rejected_count' => 0,
                'duplicate_count' => $ok ? mt_rand(0, 5) : 0,
                'quarantined_count' => 0,
                'rate_limit' => $ok ? null : json_encode(['remaining' => 0, 'reset_in_s' => 60]),
                'timings' => null,
                'created_at' => $started,
            ];
        }
        foreach (array_chunk($callRows, 200) as $chunk) {
            DB::table('provider_calls')->insert($chunk);
        }

        // Alerts: a mix of open and resolved across types.
        $alertDefs = [
            ['REPEATED_FAILURES', SourceRegistry::GOOGLE_SPEECH_TO_TEXT, 'critical', null],
            ['RATE_LIMIT_RISK', SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER, 'warning', null],
            ['STALE_DATA', SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS, 'warning', null],
            ['STORY_POLLING_RISK', SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS, 'warning', null],
            ['JOB_FAILED', SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER, 'warning', 2],
            ['SCHEMA_DRIFT', SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER, 'critical', 5],
            ['ABNORMAL_DURATION', SourceRegistry::YOUTUBE_DATA_API_V3, 'warning', 1],
            ['EXCESSIVE_RETRIES', SourceRegistry::GOOGLE_CLOUD_VISION, 'warning', 4],
        ];
        foreach ($alertDefs as [$type, $source, $severity, $resolvedDaysAgo]) {
            $first = $this->now->subDays(mt_rand(2, 10));
            DB::table('ingestion_alerts')->insert([
                'alert_type' => $type,
                'source' => $source,
                'fingerprint' => substr(sha1($type.$source), 0, 40),
                'severity' => $severity,
                'message' => 'Demo: '.str_replace('_', ' ', strtolower($type)).' observed for '.$source.'.',
                'count' => mt_rand(1, 6),
                'first_occurred_at' => $first,
                'last_occurred_at' => $first->addHours(mt_rand(1, 48)),
                'resolved_at' => $resolvedDaysAgo !== null ? $this->now->subDays($resolvedDaysAgo) : null,
                'created_at' => $first,
                'updated_at' => $first,
            ]);
        }

        // Quarantine + response samples.
        $quarantine = [];
        for ($i = 0; $i < 10; $i++) {
            $quarantine[] = [
                'source' => $sources[array_rand($sources)],
                'operation' => 'fetch-profile',
                'correlation_id' => (string) Str::uuid(),
                'external_hint' => 'post-'.mt_rand(10_000_000, 99_999_999),
                'reason_category' => fake()->randomElement(['MISSING_REQUIRED_FIELDS', 'INVALID_FIELD_TYPES', 'SCHEMA_DRIFT', 'NORMALIZATION_FAILED']),
                'reason' => 'Demo: payload rejected by the normalization contract.',
                'payload' => json_encode(['raw' => ['id' => null, 'caption' => 'demo-quarantined']]),
                'expires_at' => $this->now->addDays(mt_rand(3, 14)),
                'created_at' => $this->now->subDays(mt_rand(0, 6)),
            ];
        }
        DB::table('quarantined_records')->insert($quarantine);

        $samples = [];
        for ($i = 0; $i < 5; $i++) {
            $sampledAt = $this->now->subDays(mt_rand(0, 6));
            $samples[] = [
                'source' => $sources[array_rand($sources)],
                'operation' => 'fetch-profile',
                'correlation_id' => (string) Str::uuid(),
                'payload' => json_encode(['sample' => true, 'fields' => ['id', 'caption', 'metrics']]),
                'sampled_at' => $sampledAt,
                'expires_at' => $sampledAt->addDays(14),
                'created_at' => $sampledAt,
            ];
        }
        DB::table('provider_response_samples')->insert($samples);

        $this->command->info('Operations: 8 health states, '.count($cycles).' cycles, '.count($callRows).' provider calls, 8 alerts, 10 quarantined, 5 samples');
    }

    private function provenanceFor(Platform $platform, ?ContentType $type = null): Provenance
    {
        $source = match ($platform) {
            Platform::Instagram => $type === ContentType::Reel
                ? SourceRegistry::APIFY_INSTAGRAM_REEL_SCRAPER
                : ($type !== null ? SourceRegistry::APIFY_INSTAGRAM_POST_SCRAPER : SourceRegistry::APIFY_INSTAGRAM_PROFILE_SCRAPER),
            Platform::TikTok => SourceRegistry::CLOCKWORKS_TIKTOK_SCRAPER,
            Platform::YouTube => SourceRegistry::YOUTUBE_DATA_API_V3,
        };

        return new Provenance($source, $this->now, self::FIXTURE_VERSION);
    }
}
