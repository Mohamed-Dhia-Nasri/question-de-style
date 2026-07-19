# Sub-project B — Media Resolution + Keyframe Sampling — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve real media on TikTok/YouTube, sample persisted keyframes for every platform, and hand tiers C/D a stable `KeyframeSet` contract — per `docs/superpowers/specs/2026-07-18-seeded-detection-media-resolution-design.md`.

**Architecture:** Ingestion adapters store real media/thumbnail URLs; a lazy per-target `MediaWorkspace` downloads each asset once (streaming, size-guarded, SSRF-pinned) and feeds recognition + keyframe persistence; an ffmpeg `KeyframeSampler` extracts deterministic even-interval frames persisted as polymorphic `keyframes` rows; YouTube gains a transcript provider feeding the existing SPOKEN_BRAND path. All new stages are kill-switched; retention + GDPR erase mirror story media.

**Tech Stack:** Laravel 12, PostgreSQL, PHPUnit, ffmpeg/ffprobe via `Illuminate\Support\Facades\Process`, Apify actors via the existing `ApifyClient`.

## Global Constraints

- Run tests with `XDEBUG_MODE=off vendor/bin/phpunit` (repo convention; `--filter` for single tests).
- NEVER add a `Co-Authored-By` or any AI-attribution trailer to commits (a commit hook rejects it).
- Base test class `Tests\TestCase`; DB tests `use RefreshDatabase;` (auto-creates + binds a default tenant).
- Fail-closed, never fabricate: missing media/signal → explainable skip marker, no row.
- Tenant-scoped: new tables use `App\Shared\Tenancy\BelongsToTenant`; commands iterating tenants use explicit `tenant_id` predicates + `withoutGlobalScopes()`.
- DP-004 human precedence and DP-005 (inline media to providers, no URL leaves the platform, `AiPayloadGuard` untouched) are preserved.
- Kill switches: `qds.enrichment.keyframes.enabled` and `qds.ingestion.youtube_transcript.enabled` (both default `true`); OFF = no behavioural change vs today.
- Backward compatible: existing Instagram recognition behaviour and the full suite (~1,300 tests) stay green. The former `MediaFetcher::MAX_BYTES = 20_000_000` becomes config `qds.enrichment.recognition.inline_max_bytes` with the same default.
- The branch is `feat/seeded-detection-media` (already exists, rebased on post-A main). Commit after every task.
- Sub-project A landed on main: `EnrichmentPipeline` already has a `text_signals` stage; `BrandLexicon` has `matchAllInText`/`resolveHandle`; adapters already pass `mentions/productTags/collaborators/brandedContentLabel` to `ContentData`. Do not touch those.

---

### Task 1: Config keys, SourceRegistry id, Apify actor entry

**Files:**
- Modify: `app/Platform/Ingestion/SourceRegistry.php`
- Modify: `config/qds.php`
- Modify: `config/services.php`
- Test: `tests/Unit/Ingestion/SourceRegistryTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT = 'SRC-apify-youtube-transcript'`; config keys `qds.enrichment.recognition.inline_max_bytes`, `qds.enrichment.keyframes.{enabled,interval_seconds,min_frames,max_frames,max_width,jpeg_quality,download_max_bytes,retention_days,ffmpeg_path,ffprobe_path}`, `qds.ingestion.youtube_transcript.enabled`, `services.apify.actors.youtube_transcript`. Every later task reads these names verbatim.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Ingestion;

use App\Platform\Ingestion\SourceRegistry;
use PHPUnit\Framework\TestCase;

class SourceRegistryTest extends TestCase
{
    public function test_youtube_transcript_source_is_registered(): void
    {
        $this->assertSame('SRC-apify-youtube-transcript', SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT);
        $this->assertTrue(SourceRegistry::isRegistered(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Unit/Ingestion/SourceRegistryTest.php`
Expected: ERROR — `Undefined constant ... APIFY_YOUTUBE_TRANSCRIPT`.

- [ ] **Step 3: Implement**

In `app/Platform/Ingestion/SourceRegistry.php`, after the `YOUTUBE_DATA_API_V3` const:

```php
    /**
     * YouTube captions/transcript TEXT only (ADR-0028 amendment to the
     * ADR-0001 freeze): the pintostudio transcript actor. Never video or
     * audio bytes — YouTube media files stay out of reach in v1 (ToS).
     */
    public const APIFY_YOUTUBE_TRANSCRIPT = 'SRC-apify-youtube-transcript';
```

Add `self::APIFY_YOUTUBE_TRANSCRIPT,` to the `all()` array right after `self::YOUTUBE_DATA_API_V3,`.

In `config/services.php`, inside `'apify' => ['actors' => [...]]`, after the `instagram_direct` entry:

```php
            // SRC-apify-youtube-transcript (ADR-0028): YouTube captions text
            // for SPOKEN_BRAND — the only in-freeze YouTube spoken signal.
            'youtube_transcript' => env('APIFY_ACTOR_YOUTUBE_TRANSCRIPT', 'pintostudio~youtube-transcript-scraper'),
```

In `config/qds.php`, inside the `'ingestion'` array (after the `'campaign_refresh'` block):

```php
        // YouTube transcript fetch (ADR-0028, sub-project B): one actor run
        // per NEW YouTube video, invoked lazily from enrichment recognition.
        // Kill switch: off = no actor call, SPOKEN_BRAND stays unavailable.
        'youtube_transcript' => [
            'enabled' => (bool) env('QDS_INGESTION_YOUTUBE_TRANSCRIPT_ENABLED', true),
        ],
```

In `config/qds.php`, inside the `'enrichment'` array (after the `'audio'` block):

```php
        // Inline-payload ceiling for the Google recognition providers
        // (formerly MediaFetcher::MAX_BYTES). Video over this cap skips the
        // whole-video Video Intelligence pass (distinct marker) — keyframes
        // still cover it.
        'recognition' => [
            'inline_max_bytes' => (int) env('QDS_ENRICHMENT_INLINE_MAX_BYTES', 20_000_000),
        ],

        // Keyframe sampling (sub-project B): deterministic even-interval
        // frames for ALL platforms — the artifact tiers C/D consume.
        // N = clamp(ceil(duration/interval), min, max). Persisted on the
        // private media disk with story-media-equivalent retention.
        'keyframes' => [
            'enabled' => (bool) env('QDS_ENRICHMENT_KEYFRAMES_ENABLED', true),
            'interval_seconds' => (int) env('QDS_ENRICHMENT_KEYFRAME_INTERVAL_SECONDS', 6),
            'min_frames' => (int) env('QDS_ENRICHMENT_KEYFRAME_MIN', 3),
            'max_frames' => (int) env('QDS_ENRICHMENT_KEYFRAME_MAX', 12),
            'max_width' => (int) env('QDS_ENRICHMENT_KEYFRAME_MAX_WIDTH', 1280),
            'jpeg_quality' => (int) env('QDS_ENRICHMENT_KEYFRAME_JPEG_QUALITY', 3),
            'download_max_bytes' => (int) env('QDS_ENRICHMENT_KEYFRAME_DOWNLOAD_MAX_BYTES', 200_000_000),
            'retention_days' => (int) env('QDS_ENRICHMENT_KEYFRAME_RETENTION_DAYS', 180),
            'ffmpeg_path' => env('QDS_ENRICHMENT_FFMPEG_PATH', 'ffmpeg'),
            'ffprobe_path' => env('QDS_ENRICHMENT_FFPROBE_PATH', 'ffprobe'),
        ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Unit/Ingestion/SourceRegistryTest.php`
Expected: PASS (2 assertions).

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Ingestion/SourceRegistry.php config/qds.php config/services.php tests/Unit/Ingestion/SourceRegistryTest.php
git commit -m "feat(enrichment): add keyframe/transcript config + SRC-apify-youtube-transcript id"
```

---

### Task 2: TikTok adapter stores the real download URL

**Files:**
- Modify: `app/Platform/Ingestion/Providers/TikTok/TikTokContentAdapter.php` (the `mediaUrls:` argument, currently `array_filter([Extract::string($item, 'webVideoUrl')])`)
- Modify: `tests/Fixtures/providers/tiktok-items.json`
- Test: `tests/Feature/Ingestion/ProviderNormalizationTest.php` (existing file — add one test, update any assertion expecting `media_urls` to contain the watch URL)

**Interfaces:**
- Consumes: `Extract::string(array, ...keys): ?string`.
- Produces: TikTok `ContentData::mediaUrls` = `[<real CDN download URL>]` (falls back to `webVideoUrl` when the actor omits it); `permalink` unchanged (`webVideoUrl`).

- [ ] **Step 1: Update the fixture**

In `tests/Fixtures/providers/tiktok-items.json`: to item 1 (id `7301234567890123456`) add, after `"webVideoUrl"`:

```json
    "mediaUrls": ["https://cdn.tiktok.example/video/7301234567890123456.mp4"],
```

To item 2 (id `7301234567890123457`) change `"videoMeta": {"duration": 184}` to:

```json
    "videoMeta": {"duration": 184, "downloadAddr": "https://cdn.tiktok.example/download/7301234567890123457.mp4"},
```

Item 3 (the broken marker item) stays unchanged.

- [ ] **Step 2: Write the failing test**

Add to `tests/Feature/Ingestion/ProviderNormalizationTest.php` (match the file's existing setup helpers for running the TikTok adapter over the fixture):

```php
    public function test_tiktok_media_urls_carry_the_real_download_url_not_the_watch_page(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.tiktok'), $this->fixture('tiktok-items'));

        $batch = app(\App\Platform\Ingestion\Providers\TikTok\TikTokContentAdapter::class)->fetchContent('styleicon');
        $items = array_values(array_filter($batch->items, fn ($i) => $i instanceof \App\Platform\Ingestion\DTO\ContentData));

        // Item 1: the actor's mediaUrls list wins.
        $this->assertSame(['https://cdn.tiktok.example/video/7301234567890123456.mp4'], $items[0]->mediaUrls);
        $this->assertSame('https://www.tiktok.com/@styleicon/video/7301234567890123456', $items[0]->permalink);

        // Item 2: videoMeta.downloadAddr is the fallback.
        $this->assertSame(['https://cdn.tiktok.example/download/7301234567890123457.mp4'], $items[1]->mediaUrls);
    }
```

- [ ] **Step 3: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Ingestion/ProviderNormalizationTest.php --filter test_tiktok_media_urls_carry_the_real_download_url`
Expected: FAIL — `media_urls` still holds the watch-page URL.

- [ ] **Step 4: Implement**

In `TikTokContentAdapter.php`, change the `mediaUrls:` argument to:

```php
                mediaUrls: array_values(array_filter([
                    $this->downloadUrl($item) ?? Extract::string($item, 'webVideoUrl'),
                ])),
```

and add this private method after `captureProfile()`:

```php
    /**
     * The actor's direct CDN media URL — the REAL video file (sub-project B).
     * Falls back to null when absent; the caller then keeps webVideoUrl so
     * media_urls never goes empty (downstream degrades to media:fetch-failed
     * exactly as before, never silently).
     *
     * @param  array<array-key, mixed>  $item
     */
    private function downloadUrl(array $item): ?string
    {
        $urls = $item['mediaUrls'] ?? null;

        if (is_array($urls) && is_string($urls[0] ?? null) && $urls[0] !== '') {
            return $urls[0];
        }

        $videoMeta = is_array($item['videoMeta'] ?? null) ? $item['videoMeta'] : [];

        return Extract::string($videoMeta, 'downloadAddr');
    }
```

- [ ] **Step 5: Run the full ingestion test file; fix stale assertions**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Ingestion/ProviderNormalizationTest.php`
Expected: the new test PASSES. If any pre-existing assertion expects TikTok `media_urls` to equal the watch URL (grep the file for `webVideoUrl`), update it to the new fixture CDN URL — `permalink` assertions stay on the watch URL.

- [ ] **Step 6: Commit**

```bash
git add app/Platform/Ingestion/Providers/TikTok/TikTokContentAdapter.php tests/Fixtures/providers/tiktok-items.json tests/Feature/Ingestion/ProviderNormalizationTest.php
git commit -m "feat(ingestion): TikTok media_urls carry the actor's real download URL"
```

---

### Task 3: YouTube adapter stores the max-res thumbnail + permalink

**Files:**
- Modify: `app/Platform/Ingestion/Providers/YouTube/YouTubeContentAdapter.php` (the `mediaUrls:` argument, currently `["https://www.youtube.com/watch?v={$externalId}"]`)
- Modify: `tests/Fixtures/providers/youtube-videos.json`
- Test: `tests/Feature/Ingestion/ProviderNormalizationTest.php`

**Interfaces:**
- Consumes: `Extract::string`.
- Produces: YouTube `ContentData::mediaUrls` = `[<best thumbnail URL>]` (maxres → standard → high → medium → default; `[]` when none), `permalink` = the watch URL (new — feeds nothing else yet, but keeps the canonical page URL).

- [ ] **Step 1: Update the fixture**

In `tests/Fixtures/providers/youtube-videos.json`: to item 1 (`vid00000001`) extend `snippet` with:

```json
      "snippet": {"title": "Lookbook Sommer 2026", "publishedAt": "2026-06-28T14:00:00Z", "thumbnails": {"maxres": {"url": "https://i.ytimg.example/vi/vid00000001/maxresdefault.jpg"}, "high": {"url": "https://i.ytimg.example/vi/vid00000001/hqdefault.jpg"}}},
```

To item 2 (`vid00000002`) extend `snippet` with only a `high` thumbnail:

```json
      "snippet": {"title": "Quick Tipp #shorts", "publishedAt": "2026-07-01T08:00:00Z", "thumbnails": {"high": {"url": "https://i.ytimg.example/vi/vid00000002/hqdefault.jpg"}}},
```

- [ ] **Step 2: Write the failing test**

Add to `ProviderNormalizationTest.php`:

```php
    public function test_youtube_media_urls_carry_the_best_thumbnail_and_permalink_the_watch_url(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeYouTubeApi();

        $batch = app(\App\Platform\Ingestion\Providers\YouTube\YouTubeContentAdapter::class)->fetchContent('stylechannel');
        $items = array_values(array_filter($batch->items, fn ($i) => $i instanceof \App\Platform\Ingestion\DTO\ContentData));

        $this->assertSame(['https://i.ytimg.example/vi/vid00000001/maxresdefault.jpg'], $items[0]->mediaUrls);
        $this->assertSame('https://www.youtube.com/watch?v=vid00000001', $items[0]->permalink);
        // Item 2 has no maxres → the ladder falls back to high.
        $this->assertSame(['https://i.ytimg.example/vi/vid00000002/hqdefault.jpg'], $items[1]->mediaUrls);
    }
```

- [ ] **Step 3: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Ingestion/ProviderNormalizationTest.php --filter test_youtube_media_urls_carry_the_best_thumbnail`
Expected: FAIL — `media_urls` still holds the watch URL.

- [ ] **Step 4: Implement**

In `YouTubeContentAdapter.php`, change the `mediaUrls:` argument and add `permalink:` after `publicMetrics:`:

```php
                mediaUrls: array_values(array_filter([$this->thumbnailUrl($snippet)])),
```

```php
                permalink: "https://www.youtube.com/watch?v={$externalId}",
```

Add this private method after `durationSeconds()`:

```php
    /**
     * Highest-resolution thumbnail the Data API exposes — the only visual
     * the frozen YouTube provider legally hands us (sub-project B); the
     * video file itself is deliberately NOT downloadable (ToS, ADR-0028).
     *
     * @param  array<array-key, mixed>  $snippet
     */
    private function thumbnailUrl(array $snippet): ?string
    {
        $thumbnails = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [];

        foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $size) {
            $entry = is_array($thumbnails[$size] ?? null) ? $thumbnails[$size] : [];

            if (($url = Extract::string($entry, 'url')) !== null) {
                return $url;
            }
        }

        return null;
    }
```

- [ ] **Step 5: Run the file; fix stale assertions**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Ingestion/ProviderNormalizationTest.php`
Expected: new test PASSES; update any pre-existing assertion expecting YouTube `media_urls` to contain `watch?v=` (grep the file) to the thumbnail URLs above.

- [ ] **Step 6: Commit**

```bash
git add app/Platform/Ingestion/Providers/YouTube/YouTubeContentAdapter.php tests/Fixtures/providers/youtube-videos.json tests/Feature/Ingestion/ProviderNormalizationTest.php
git commit -m "feat(ingestion): YouTube media_urls carry the max-res thumbnail; permalink = watch URL"
```

---

### Task 4: `content_transcripts` table + `ContentTranscript` model

**Files:**
- Create: `database/migrations/2026_07_19_100001_create_content_transcripts_table.php`
- Create: `app/Modules/Monitoring/Models/ContentTranscript.php`
- Modify: `app/Modules/Monitoring/Models/ContentItem.php` (add `transcripts()` HasMany)
- Test: `tests/Feature/Enrichment/ContentTranscriptTest.php` (create)

**Interfaces:**
- Consumes: `BelongsToTenant`, `AsValueObject`, `Provenance`.
- Produces: `ContentTranscript` model — fillable `content_item_id, language, text, segments, provider, provenance, checksum, fetched_at`; casts `segments => array`, `provenance => AsValueObject:Provenance`, `fetched_at => immutable_datetime`; unique `(content_item_id, language, provider)`; `ContentItem::transcripts(): HasMany`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentTranscriptTest extends TestCase
{
    use RefreshDatabase;

    private function makeContentItem(): ContentItem
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create();

        return ContentItem::factory()->for($account, 'platformAccount')->create();
    }

    private function attributes(ContentItem $item): array
    {
        return [
            'content_item_id' => $item->id,
            'language' => 'und',
            'text' => 'danke an Glossier für das PR Paket',
            'segments' => [['start' => '0.0', 'dur' => '4.2', 'text' => 'danke an Glossier für das PR Paket']],
            'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
            'provenance' => new Provenance(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, CarbonImmutable::now(), 'youtube-transcript-v1'),
            'checksum' => hash('sha256', 'danke an Glossier für das PR Paket'),
            'fetched_at' => CarbonImmutable::now(),
        ];
    }

    public function test_transcript_row_is_tenant_stamped_and_reachable_from_content(): void
    {
        $item = $this->makeContentItem();
        $row = ContentTranscript::query()->create($this->attributes($item));

        $this->assertNotNull($row->tenant_id);
        $this->assertSame($item->tenant_id, $row->tenant_id);
        $this->assertTrue($item->transcripts()->whereKey($row->id)->exists());
        $this->assertSame('und', $row->language);
    }

    public function test_one_transcript_per_item_language_provider(): void
    {
        $item = $this->makeContentItem();
        ContentTranscript::query()->create($this->attributes($item));

        $this->expectException(UniqueConstraintViolationException::class);
        ContentTranscript::query()->create($this->attributes($item));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/ContentTranscriptTest.php`
Expected: ERROR — class `ContentTranscript` not found.

- [ ] **Step 3: Implement the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_transcripts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            // 'und' (BCP-47 undetermined) when the provider names no language —
            // NOT NULL so the unique key below has no NULL-duplicate hole.
            $table->string('language', 20);
            $table->text('text');
            $table->jsonb('segments')->nullable();
            $table->string('provider', 100);
            $table->jsonb('provenance');
            $table->char('checksum', 64);
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['content_item_id', 'language', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_transcripts');
    }
};
```

- [ ] **Step 4: Implement the model**

```php
<?php

namespace App\Modules\Monitoring\Models;

use App\Shared\Casts\AsValueObject;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider-derived transcript of one ContentItem (sub-project B) —
 * refreshable, multi-language, segment-ready. v1 source: the YouTube
 * transcript actor (SRC-apify-youtube-transcript, ADR-0028); tier D's
 * multilingual speech will add rows under other providers/languages.
 *
 * Tenant-owned (ADR-0019); erased with the creator's content (GDPR).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $content_item_id
 * @property string $language BCP-47; 'und' when the provider names none
 * @property string $text
 * @property array<int, array<string, string>>|null $segments timestamped cues
 * @property string $provider SRC-* id
 * @property Provenance $provenance
 * @property string $checksum sha256 of text
 * @property CarbonImmutable $fetched_at
 */
class ContentTranscript extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'content_item_id',
        'language',
        'text',
        'segments',
        'provider',
        'provenance',
        'checksum',
        'fetched_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'segments' => 'array',
            'provenance' => AsValueObject::class.':'.Provenance::class,
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }
}
```

In `ContentItem.php`, add after `enrichmentRuns()`:

```php
    /** @return HasMany<ContentTranscript, $this> */
    public function transcripts(): HasMany
    {
        return $this->hasMany(ContentTranscript::class);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/ContentTranscriptTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_19_100001_create_content_transcripts_table.php app/Modules/Monitoring/Models/ContentTranscript.php app/Modules/Monitoring/Models/ContentItem.php tests/Feature/Enrichment/ContentTranscriptTest.php
git commit -m "feat(enrichment): content_transcripts table + model (refreshable, multi-language)"
```

---

### Task 5: `YouTubeTranscriptFetcher` (actor call + persistence + telemetry)

**Files:**
- Create: `app/Platform/Enrichment/Transcripts/YouTubeTranscriptFetcher.php`
- Create: `tests/Fixtures/providers/youtube-transcript.json`
- Test: `tests/Feature/Enrichment/YouTubeTranscriptFetcherTest.php` (create)

**Interfaces:**
- Consumes: `ApifyClient::runActor(string $sourceId, string $actorId, array $input): ProviderResponse`; `ProviderCallRecorder::{start,recordFailure,recordCompletion}`; `ContentTranscript` (Task 4); `SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT` (Task 1).
- Produces: `YouTubeTranscriptFetcher::ensureFor(ContentItem $item, string $correlationId, int $retryCount): ?ContentTranscript` — returns the existing row without re-fetching; fetches + persists otherwise; `null` = unavailable (normal outcome, never throws). Task 6 consumes this.

- [ ] **Step 1: Create the fixture**

`tests/Fixtures/providers/youtube-transcript.json`:

```json
[
  {
    "data": [
      {"start": "0.00", "dur": "4.20", "text": "danke an Glossier für das PR Paket"},
      {"start": "4.20", "dur": "3.10", "text": "der You Perfume Duft ist unglaublich"}
    ]
  }
]
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Platform\Enrichment\Transcripts\YouTubeTranscriptFetcher;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

class YouTubeTranscriptFetcherTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    private function makeYouTubeItem(): ContentItem
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => Platform::YouTube]);

        return ContentItem::factory()->for($account, 'platformAccount')->create([
            'platform' => Platform::YouTube,
            'content_type' => ContentType::Video,
            'external_id' => 'vid00000001',
        ]);
    }

    public function test_fetches_persists_and_records_telemetry(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.youtube_transcript'), $this->fixture('youtube-transcript'));
        $item = $this->makeYouTubeItem();

        $row = app(YouTubeTranscriptFetcher::class)->ensureFor($item, 'corr-1', 0);

        $this->assertNotNull($row);
        $this->assertSame('danke an Glossier für das PR Paket der You Perfume Duft ist unglaublich', $row->text);
        $this->assertSame('und', $row->language);
        $this->assertSame(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, $row->provider);
        $this->assertCount(2, $row->segments);
        $this->assertSame(1, ProviderCall::query()->where('source', SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT)->where('operation', 'transcript.fetch')->count());
    }

    public function test_existing_row_short_circuits_without_actor_call(): void
    {
        $this->fakeProviderCredentials();
        Http::fake(); // any HTTP call would be recorded
        $item = $this->makeYouTubeItem();
        ContentTranscript::query()->create([
            'content_item_id' => $item->id, 'language' => 'und', 'text' => 'schon da',
            'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
            'provenance' => new \App\Shared\ValueObjects\Provenance(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, \Carbon\CarbonImmutable::now(), 'youtube-transcript-v1'),
            'checksum' => hash('sha256', 'schon da'), 'fetched_at' => \Carbon\CarbonImmutable::now(),
        ]);

        $row = app(YouTubeTranscriptFetcher::class)->ensureFor($item, 'corr-2', 0);

        $this->assertSame('schon da', $row?->text);
        Http::assertNothingSent();
    }

    public function test_no_captions_yields_null_not_an_exception(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.youtube_transcript'), [['data' => []]]);
        $item = $this->makeYouTubeItem();

        $this->assertNull(app(YouTubeTranscriptFetcher::class)->ensureFor($item, 'corr-3', 0));
        $this->assertSame(0, ContentTranscript::query()->count());
    }

    public function test_provider_failure_yields_null_and_records_failure(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.youtube_transcript'), [], 500);
        $item = $this->makeYouTubeItem();

        $this->assertNull(app(YouTubeTranscriptFetcher::class)->ensureFor($item, 'corr-4', 0));
        $this->assertSame(1, ProviderCall::query()->where('source', SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT)->where('outcome', 'FAILURE')->count());
    }
}
```

Note: if `ProviderCall::outcome` is stored differently (check `app/Platform/Ingestion/Support/CallOutcome.php` enum values), assert with `CallOutcome::Failure->value` instead of the literal.

- [ ] **Step 3: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/YouTubeTranscriptFetcherTest.php`
Expected: ERROR — class `YouTubeTranscriptFetcher` not found.

- [ ] **Step 4: Implement**

```php
<?php

namespace App\Platform\Enrichment\Transcripts;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Http\ApifyClient;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Persistence\PersistenceResult;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;

/**
 * Lazily fetches one YouTube video's transcript via the ADR-0028 actor
 * (SRC-apify-youtube-transcript) and persists it as a ContentTranscript.
 *
 * - An existing row short-circuits (one billed run per video, ever).
 * - No captions / provider failure → null: transcript stays unavailable,
 *   the enrichment run does NOT fail (mirrors the speech:provider-error
 *   swallow — a transcript is an optional signal, never fabricated).
 * - Every actor call is recorded in External API Monitoring.
 */
class YouTubeTranscriptFetcher
{
    public function __construct(
        private readonly ApifyClient $client,
        private readonly ProviderCallRecorder $recorder,
    ) {}

    public function ensureFor(ContentItem $item, string $correlationId, int $retryCount): ?ContentTranscript
    {
        $existing = ContentTranscript::query()
            ->where('content_item_id', $item->id)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if (! is_string($item->external_id) || $item->external_id === '') {
            return null;
        }

        $context = $this->recorder->start(
            SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
            'transcript.fetch',
            $correlationId,
            null,
            $item->platform_account_id,
            $retryCount,
        );

        try {
            $response = $this->client->runActor(
                SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
                (string) config('services.apify.actors.youtube_transcript'),
                ['videoUrl' => "https://www.youtube.com/watch?v={$item->external_id}"],
            );
        } catch (ProviderCallException $e) {
            $this->recorder->recordFailure($context, $e);

            return null;
        }

        $segments = $this->segmentsFrom($response->items);
        $text = trim(implode(' ', array_column($segments, 'text')));

        $row = null;

        if ($text !== '') {
            $row = ContentTranscript::query()->updateOrCreate(
                [
                    'content_item_id' => $item->id,
                    'language' => 'und',
                    'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
                ],
                [
                    'text' => $text,
                    'segments' => $segments,
                    'checksum' => hash('sha256', $text),
                    'fetched_at' => CarbonImmutable::now(),
                    'provenance' => new Provenance(
                        SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
                        CarbonImmutable::now(),
                        'youtube-transcript-v1',
                    ),
                ],
            );
        }

        $this->recorder->recordCompletion(
            $context,
            new NormalizedBatch(items: [], rejected: [], response: $response, validationMs: 0.0, normalizationMs: 0.0),
            new PersistenceResult(created: $row !== null ? 1 : 0, duplicates: 0, persistenceMs: 0.0),
        );

        return $row;
    }

    /**
     * Tolerant parse of the actor's dataset items — each item may carry a
     * `data` list of {start, dur, text} caption cues. Anything malformed is
     * skipped (never fabricated).
     *
     * @param  list<mixed>  $items
     * @return list<array{start: string, dur: string, text: string}>
     */
    private function segmentsFrom(array $items): array
    {
        $segments = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach ((array) ($item['data'] ?? []) as $segment) {
                if (is_array($segment) && is_string($segment['text'] ?? null) && trim($segment['text']) !== '') {
                    $segments[] = [
                        'start' => (string) ($segment['start'] ?? ''),
                        'dur' => (string) ($segment['dur'] ?? ''),
                        'text' => trim($segment['text']),
                    ];
                }
            }
        }

        return $segments;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/YouTubeTranscriptFetcherTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Platform/Enrichment/Transcripts/YouTubeTranscriptFetcher.php tests/Fixtures/providers/youtube-transcript.json tests/Feature/Enrichment/YouTubeTranscriptFetcherTest.php
git commit -m "feat(enrichment): YouTubeTranscriptFetcher — one telemetered actor run per video"
```

---

### Task 6: Transcript → SPOKEN_BRAND seam (`transcriptBatch` + RecognitionService YouTube branch)

**Files:**
- Modify: `app/Platform/Enrichment/Recognition/RecognitionNormalizer.php` (add `transcriptBatch()`)
- Modify: `app/Platform/Enrichment/Recognition/RecognitionService.php` (constructor + a YouTube branch at the top of `enrich()`)
- Test: `tests/Feature/Enrichment/TranscriptRecognitionTest.php` (create)

**Interfaces:**
- Consumes: `YouTubeTranscriptFetcher::ensureFor` (Task 5); `BrandLexicon::matchInText`; `RecognitionService::persist()` (existing private upsert — reused, NOT duplicated).
- Produces: `RecognitionNormalizer::transcriptBatch(string $transcript): NormalizedBatch` (SPOKEN_BRAND candidates, synthetic `ProviderResponse` with `sourceVersion: 'youtube-transcript-v1'`); skip markers `youtube-transcript:disabled` / `youtube-transcript:unavailable`. The branch runs BEFORE the Google-providers-configured early return (a transcript needs no Google key).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Platform\Enrichment\Recognition\RecognitionService;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RecognitionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

class TranscriptRecognitionTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    private function makeYouTubeItem(): ContentItem
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => Platform::YouTube]);

        return ContentItem::factory()->for($account, 'platformAccount')->create([
            'platform' => Platform::YouTube,
            'content_type' => ContentType::Video,
            'external_id' => 'vid00000001',
            'media_urls' => [],
        ]);
    }

    public function test_transcript_yields_spoken_brand_detection_with_actor_provenance_and_no_google_call(): void
    {
        Brand::factory()->create(['name' => 'Glossier']);
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.youtube_transcript'), $this->fixture('youtube-transcript'));
        // NO Google provider keys configured — the transcript path must still run.
        $item = $this->makeYouTubeItem();

        $result = app(RecognitionService::class)->enrich($item, 'corr-t1');

        $this->assertSame('completed', $result['status']);
        $detection = RecognitionDetection::query()
            ->where('content_item_id', $item->id)
            ->where('recognition_type', RecognitionType::SpokenBrand)
            ->firstOrFail();
        $this->assertSame('Glossier', $detection->detected_brand);
        $this->assertSame(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, $detection->provenance->source);
    }

    public function test_kill_switch_off_reports_disabled_and_makes_no_actor_call(): void
    {
        config(['qds.ingestion.youtube_transcript.enabled' => false]);
        \Illuminate\Support\Facades\Http::fake();
        $item = $this->makeYouTubeItem();

        $result = app(RecognitionService::class)->enrich($item, 'corr-t2');

        $this->assertContains('youtube-transcript:disabled', $result['skipped']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_no_captions_reports_unavailable(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.youtube_transcript'), [['data' => []]]);
        $item = $this->makeYouTubeItem();

        $result = app(RecognitionService::class)->enrich($item, 'corr-t3');

        $this->assertContains('youtube-transcript:unavailable', $result['skipped']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/TranscriptRecognitionTest.php`
Expected: FAIL — no SPOKEN_BRAND detection is created (the branch does not exist yet).

- [ ] **Step 3: Add `transcriptBatch` to `RecognitionNormalizer`**

Add after `speechBatch()` (imports: `App\Platform\Ingestion\DTO\ProviderResponse` is already imported):

```php
    /**
     * A stored provider transcript → SPOKEN_BRAND candidates (sub-project B:
     * YouTube rides the transcript actor since its audio is not downloadable
     * in-freeze). Same lexicon gate as speechBatch — free text with no known
     * brand is not a recognition hit.
     */
    public function transcriptBatch(string $transcript): NormalizedBatch
    {
        $start = microtime(true);

        $items = [];
        $candidate = $this->textCandidate(RecognitionType::SpokenBrand, $transcript, null, 'spoken-brand-transcript-match');

        if ($candidate !== null) {
            $items[] = $candidate;
        }

        return new NormalizedBatch(
            items: $items,
            rejected: [],
            response: new ProviderResponse(
                items: [],
                httpStatus: 200,
                responseBytes: 0,
                requestMs: 0.0,
                sourceVersion: 'youtube-transcript-v1',
            ),
            validationMs: 0.0,
            normalizationMs: (microtime(true) - $start) * 1000,
        );
    }
```

- [ ] **Step 4: Add the YouTube branch to `RecognitionService`**

Constructor: add `private readonly YouTubeTranscriptFetcher $transcripts,` after the `AudioExtractor $audio` parameter (import `App\Platform\Enrichment\Transcripts\YouTubeTranscriptFetcher` and `App\Shared\Enums\Platform`).

In `enrich()`, insert at the very top (before the no-provider-configured early return), and note the early return must now RETURN the accumulated `$created`/`$updated` instead of hard-coded zeros:

```php
        // YouTube SPOKEN_BRAND rides the stored transcript (ADR-0028): its
        // audio is not downloadable in-freeze, and mining a transcript needs
        // no Google key — so this runs before the provider-config gate.
        if ($target instanceof ContentItem && $target->platform === Platform::YouTube) {
            if (! (bool) config('qds.ingestion.youtube_transcript.enabled')) {
                $skipped[] = 'youtube-transcript:disabled';
            } else {
                $transcript = $this->transcripts->ensureFor($target, $correlationId, $retryCount);

                if ($transcript === null) {
                    $skipped[] = 'youtube-transcript:unavailable';
                } else {
                    [$c, $u] = $this->persist($target, SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, $this->normalizer->transcriptBatch($transcript->text));
                    $created += $c;
                    $updated += $u;
                }
            }
        }
```

Change the not-configured early return from `return ['status' => 'completed-empty', 'created' => 0, 'updated' => 0, 'skipped' => $skipped];` to:

```php
            return [
                'status' => $created + $updated > 0 ? 'completed' : 'completed-empty',
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ];
```

- [ ] **Step 5: Run test + the existing recognition suite**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/TranscriptRecognitionTest.php tests/Feature/Enrichment/RecognitionPipelineTest.php`
Expected: all PASS (the branch only fires for YouTube ContentItems; existing tests use Instagram/stories).

- [ ] **Step 6: Commit**

```bash
git add app/Platform/Enrichment/Recognition/RecognitionNormalizer.php app/Platform/Enrichment/Recognition/RecognitionService.php tests/Feature/Enrichment/TranscriptRecognitionTest.php
git commit -m "feat(enrichment): YouTube SPOKEN_BRAND from the stored transcript (no Google call)"
```

---

### Task 7: `MediaFetcher::streamToFile` (streaming, size-guarded, SSRF-pinned)

**Files:**
- Create: `app/Platform/Enrichment/Media/StreamStatus.php`
- Create: `app/Platform/Enrichment/Media/StreamResult.php`
- Modify: `app/Platform/Enrichment/Recognition/MediaFetcher.php`
- Test: `tests/Feature/Enrichment/MediaStreamingTest.php` (create)

**Interfaces:**
- Consumes: the existing private SSRF helpers (`resolvePublicIp`, `resolveRedirectTarget`) — reused, not duplicated.
- Produces: `MediaFetcher::streamToFile(string $url, string $sinkPath, int $maxBytes): StreamResult` where `StreamResult{ StreamStatus $status; ?string $contentType }` and `enum StreamStatus: string { Ok, TooLarge, Gone, Failed }`. Task 8 consumes this.

- [ ] **Step 1: Create the enum + VO**

`app/Platform/Enrichment/Media/StreamStatus.php`:

```php
<?php

namespace App\Platform\Enrichment\Media;

/** Outcome of one streamed media download (sub-project B). */
enum StreamStatus: string
{
    case Ok = 'ok';
    /** Over the byte cap — maps to the media:too-large marker. */
    case TooLarge = 'too-large';
    /** 403/404/410 — an expired scraped URL; maps to media:too-old. */
    case Gone = 'gone';
    case Failed = 'failed';
}
```

`app/Platform/Enrichment/Media/StreamResult.php`:

```php
<?php

namespace App\Platform\Enrichment\Media;

final readonly class StreamResult
{
    public function __construct(
        public StreamStatus $status,
        public ?string $contentType = null,
    ) {}
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\Media\StreamStatus;
use App\Platform\Enrichment\Recognition\MediaFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediaStreamingTest extends TestCase
{
    private function sink(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qds-test-sink-');
        $this->assertIsString($path);

        return $path;
    }

    public function test_ok_download_writes_the_sink_and_reports_content_type(): void
    {
        // Literal public IP: the SSRF guard passes without DNS resolution.
        Http::fake(['93.184.216.34/*' => Http::response('VIDEOBYTES', 200, ['Content-Type' => 'video/mp4'])]);
        $sink = $this->sink();

        $result = app(MediaFetcher::class)->streamToFile('https://93.184.216.34/clip.mp4', $sink, 1_000_000);

        $this->assertSame(StreamStatus::Ok, $result->status);
        $this->assertSame('video/mp4', $result->contentType);
        $this->assertSame('VIDEOBYTES', file_get_contents($sink));
        @unlink($sink);
    }

    public function test_gone_status_for_expired_source_urls(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('', 403)]);
        $sink = $this->sink();

        $this->assertSame(StreamStatus::Gone, app(MediaFetcher::class)->streamToFile('https://93.184.216.34/old.mp4', $sink, 1_000_000)->status);
        @unlink($sink);
    }

    public function test_content_length_over_cap_is_too_large(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('x', 200, ['Content-Type' => 'video/mp4', 'Content-Length' => '9999999'])]);
        $sink = $this->sink();

        $this->assertSame(StreamStatus::TooLarge, app(MediaFetcher::class)->streamToFile('https://93.184.216.34/big.mp4', $sink, 1_000)->status);
        @unlink($sink);
    }

    public function test_oversized_body_is_too_large_even_without_content_length(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response(str_repeat('x', 2_000), 200, ['Content-Type' => 'video/mp4'])]);
        $sink = $this->sink();

        $this->assertSame(StreamStatus::TooLarge, app(MediaFetcher::class)->streamToFile('https://93.184.216.34/big.mp4', $sink, 1_000)->status);
        @unlink($sink);
    }

    public function test_html_watch_page_is_refused(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html'])]);
        $sink = $this->sink();

        $this->assertSame(StreamStatus::Failed, app(MediaFetcher::class)->streamToFile('https://93.184.216.34/watch', $sink, 1_000_000)->status);
        @unlink($sink);
    }

    public function test_private_host_is_refused_by_the_ssrf_guard(): void
    {
        Http::fake();
        $sink = $this->sink();

        $this->assertSame(StreamStatus::Failed, app(MediaFetcher::class)->streamToFile('https://169.254.169.254/latest', $sink, 1_000)->status);
        Http::assertNothingSent();
        @unlink($sink);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/MediaStreamingTest.php`
Expected: ERROR — method `streamToFile` does not exist.

- [ ] **Step 4: Implement in `MediaFetcher`**

Add imports `App\Platform\Enrichment\Media\StreamResult;` and `App\Platform\Enrichment\Media\StreamStatus;`. Add after `fromPublicUrl()`:

```php
    /**
     * Stream a (possibly large) media file to $sinkPath under a hard byte
     * cap, with the same SSRF doctrine as fromPublicUrl: resolve once,
     * refuse non-public, pin the connection, re-validate every redirect.
     * The caller owns $sinkPath's lifecycle (MediaWorkspace cleans up).
     */
    public function streamToFile(string $url, string $sinkPath, int $maxBytes): StreamResult
    {
        return $this->streamFollowingRedirects($url, $sinkPath, $maxBytes, self::MAX_REDIRECTS);
    }

    private function streamFollowingRedirects(string $url, string $sinkPath, int $maxBytes, int $redirectsLeft): StreamResult
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return new StreamResult(StreamStatus::Failed);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['https', 'http'], true) || $host === '') {
            return new StreamResult(StreamStatus::Failed);
        }

        $ip = $this->resolvePublicIp($host);

        if ($ip === null) {
            return new StreamResult(StreamStatus::Failed);
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $tooLarge = false;

        try {
            $response = $this->pinnedGetToFile($url, $host, $port, $ip, $sinkPath, $maxBytes, $tooLarge);
        } catch (Throwable) {
            // The mid-stream guard aborts by throwing; $tooLarge tells the
            // cap-abort apart from a genuine transport failure.
            return new StreamResult($tooLarge ? StreamStatus::TooLarge : StreamStatus::Failed);
        }

        if ($response->redirect()) {
            if ($redirectsLeft <= 0) {
                return new StreamResult(StreamStatus::Failed);
            }

            $next = $this->resolveRedirectTarget($url, (string) $response->header('Location'));

            return $next === null
                ? new StreamResult(StreamStatus::Failed)
                : $this->streamFollowingRedirects($next, $sinkPath, $maxBytes, $redirectsLeft - 1);
        }

        if (in_array($response->status(), [403, 404, 410], true)) {
            // Expired scraped CDN URL (the TikTok case) — media:too-old.
            return new StreamResult(StreamStatus::Gone);
        }

        if (! $response->successful()) {
            return new StreamResult(StreamStatus::Failed);
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        if ($contentType !== ''
            && ! str_starts_with($contentType, 'image/')
            && ! str_starts_with($contentType, 'video/')
            && ! str_starts_with($contentType, 'application/octet-stream')) {
            return new StreamResult(StreamStatus::Failed);
        }

        $declared = $response->header('Content-Length');

        if (is_numeric($declared) && (int) $declared > $maxBytes) {
            return new StreamResult(StreamStatus::TooLarge);
        }

        // Http::fake() bypasses curl's sink — materialize the body so tests
        // and production share one code path; real transfers already wrote
        // the sink and body() just re-reads that same stream.
        if ((int) @filesize($sinkPath) === 0) {
            $body = $response->body();

            if ($body !== '') {
                file_put_contents($sinkPath, $body);
            }
        }

        clearstatcache(true, $sinkPath);

        if ((int) @filesize($sinkPath) > $maxBytes) {
            return new StreamResult(StreamStatus::TooLarge);
        }

        if ((int) @filesize($sinkPath) === 0) {
            return new StreamResult(StreamStatus::Failed);
        }

        return new StreamResult(StreamStatus::Ok, $contentType !== '' ? $contentType : null);
    }

    /**
     * One pinned GET streamed to a file sink. The Guzzle progress hook
     * aborts mid-stream once the cap is exceeded (defense-in-depth over the
     * Content-Length and post-transfer checks) by throwing; the caller maps
     * the $tooLarge flag to the right status.
     */
    protected function pinnedGetToFile(string $url, string $host, int $port, string $ip, string $sinkPath, int $maxBytes, bool &$tooLarge): Response
    {
        return Http::withOptions([
            'allow_redirects' => false,
            'sink' => $sinkPath,
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
            'progress' => function ($downloadTotal, $downloadedBytes) use (&$tooLarge, $maxBytes): void {
                if ($downloadedBytes > $maxBytes || ($downloadTotal > 0 && $downloadTotal > $maxBytes)) {
                    $tooLarge = true;

                    throw new \RuntimeException('media byte cap exceeded');
                }
            },
        ])->timeout(120)->connectTimeout(10)->get($url);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/MediaStreamingTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Platform/Enrichment/Media/StreamStatus.php app/Platform/Enrichment/Media/StreamResult.php app/Platform/Enrichment/Recognition/MediaFetcher.php tests/Feature/Enrichment/MediaStreamingTest.php
git commit -m "feat(enrichment): MediaFetcher::streamToFile — streaming, size-guarded, SSRF-pinned"
```

---

### Task 8: `MediaWorkspace` + `LocalMediaAsset` + `MediaWorkspaceFactory` (single lazy download per target)

**Files:**
- Create: `app/Platform/Enrichment/Media/LocalMediaAsset.php`
- Create: `app/Platform/Enrichment/Media/MediaWorkspace.php`
- Create: `app/Platform/Enrichment/Media/MediaWorkspaceFactory.php`
- Test: `tests/Feature/Enrichment/MediaWorkspaceTest.php` (create)

**Interfaces:**
- Consumes: `MediaFetcher::streamToFile` (Task 7); config `qds.enrichment.recognition.inline_max_bytes`, `qds.enrichment.keyframes.download_max_bytes`, `qds.ingestion.media_disk`.
- Produces (consumed by Tasks 10 and 13):
  - `LocalMediaAsset{ string $tempPath; int $byteSize; ?string $contentType; string $sha256; ?string $sourceUrl }` + `bytes(): string`.
  - `MediaWorkspace::images(): list<LocalMediaAsset>`, `video(): ?LocalMediaAsset`, `markers(): list<string>`, `close(): void`. Acquisition is LAZY — no download until first access ("don't download media for nobody").
  - `MediaWorkspaceFactory::forTarget(ContentItem|Story $target): MediaWorkspace`. Routing: ImagePost/Carousel → first 3 image URLs at the inline cap; video types → first URL at the download cap; Story → the archived private-disk file (no HTTP). Markers: `media:none`, `media:fetch-failed`, `media:too-large`, `media:too-old`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Media\MediaWorkspaceFactory;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(ContentType $type, array $mediaUrls): ContentItem
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => Platform::Instagram]);

        return ContentItem::factory()->for($account, 'platformAccount')->create([
            'platform' => Platform::Instagram,
            'content_type' => $type,
            'media_urls' => $mediaUrls,
        ]);
    }

    public function test_acquisition_is_lazy_and_carousel_images_download_once_each(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('IMAGEBYTES', 200, ['Content-Type' => 'image/jpeg'])]);
        $item = $this->makeItem(ContentType::Carousel, [
            'https://93.184.216.34/a.jpg', 'https://93.184.216.34/b.jpg',
            'https://93.184.216.34/c.jpg', 'https://93.184.216.34/d.jpg',
        ]);

        $ws = app(MediaWorkspaceFactory::class)->forTarget($item);
        Http::assertNothingSent(); // lazy until first access

        $images = $ws->images();

        $this->assertCount(3, $images); // first-3 limit preserved
        Http::assertSentCount(3);
        $this->assertSame('IMAGEBYTES', $images[0]->bytes());
        $this->assertSame(hash('sha256', 'IMAGEBYTES'), $images[0]->sha256);
        $this->assertNull($ws->video());

        $paths = array_map(fn ($a) => $a->tempPath, $images);
        $ws->close();
        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_video_target_downloads_the_first_url_and_expired_url_marks_too_old(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('', 410)]);
        $item = $this->makeItem(ContentType::Reel, ['https://93.184.216.34/gone.mp4']);

        $ws = app(MediaWorkspaceFactory::class)->forTarget($item);

        $this->assertNull($ws->video());
        $this->assertContains('media:too-old', $ws->markers());
        $ws->close();
    }

    public function test_no_media_urls_marks_media_none(): void
    {
        $item = $this->makeItem(ContentType::Reel, []);
        $ws = app(MediaWorkspaceFactory::class)->forTarget($item);

        $this->assertNull($ws->video());
        $this->assertContains('media:none', $ws->markers());
        $ws->close();
    }

    public function test_story_reads_the_archived_private_disk_file_without_http(): void
    {
        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');
        Storage::disk('media')->put('tenants/1/stories/instagram/1/story-1.mp4', 'STORYVIDEO');
        Http::fake();

        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => Platform::Instagram]);
        $story = Story::factory()->for($account, 'platformAccount')->create([
            'platform' => Platform::Instagram,
            'media_url' => 'tenants/1/stories/instagram/1/story-1.mp4',
        ]);

        $ws = app(MediaWorkspaceFactory::class)->forTarget($story);

        $this->assertNotNull($ws->video());
        $this->assertSame('STORYVIDEO', $ws->video()->bytes());
        Http::assertNothingSent();
        $ws->close();
    }
}
```

Note: if `Story::factory()` requires other attributes (check `database/factories` for the Story factory's required fields), satisfy them minimally; the essential inputs are `media_url` and the platform account.

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/MediaWorkspaceTest.php`
Expected: ERROR — class `MediaWorkspaceFactory` not found.

- [ ] **Step 3: Implement `LocalMediaAsset`**

```php
<?php

namespace App\Platform\Enrichment\Media;

/** One downloaded (or disk-materialized) media file inside a MediaWorkspace. */
final readonly class LocalMediaAsset
{
    public function __construct(
        public string $tempPath,
        public int $byteSize,
        public ?string $contentType,
        public string $sha256,
        /** Null when materialized from the private story archive. */
        public ?string $sourceUrl,
    ) {}

    /** Inline payload for the Google providers (small assets only). */
    public function bytes(): string
    {
        return (string) file_get_contents($this->tempPath);
    }
}
```

- [ ] **Step 4: Implement `MediaWorkspace`**

```php
<?php

namespace App\Platform\Enrichment\Media;

use Closure;

/**
 * The per-target owner of all local media temp files for one enrichment
 * run (sub-project B): each asset is downloaded ONCE and shared by every
 * consumer (Vision/Video-Intelligence/Speech inline sends + keyframe
 * persistence). Acquisition is LAZY — nothing is downloaded until a
 * consumer asks — and close() is the single cleanup point (scraped bytes
 * are untrusted; they never outlive the run).
 */
class MediaWorkspace
{
    /** @var list<LocalMediaAsset> */
    private array $images = [];

    private ?LocalMediaAsset $video = null;

    /** @var list<string> acquisition skip markers (media:none, media:fetch-failed, media:too-large, media:too-old) */
    private array $markers = [];

    /** @var list<string> */
    private array $tempPaths = [];

    private bool $acquired = false;

    private ?Closure $acquirer;

    public function __construct(Closure $acquirer)
    {
        $this->acquirer = $acquirer;
    }

    /** @return list<LocalMediaAsset> */
    public function images(): array
    {
        $this->acquire();

        return $this->images;
    }

    public function video(): ?LocalMediaAsset
    {
        $this->acquire();

        return $this->video;
    }

    /** @return list<string> */
    public function markers(): array
    {
        $this->acquire();

        return $this->markers;
    }

    public function addImage(LocalMediaAsset $asset): void
    {
        $this->images[] = $asset;
    }

    public function setVideo(LocalMediaAsset $asset): void
    {
        $this->video = $asset;
    }

    public function addMarker(string $marker): void
    {
        $this->markers[] = $marker;
    }

    /** A workspace-owned temp path (cleaned up by close()), or null. */
    public function newTempPath(): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'qds-media-');

        if (is_string($path)) {
            $this->tempPaths[] = $path;

            return $path;
        }

        return null;
    }

    public function close(): void
    {
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }

        $this->tempPaths = [];
        $this->images = [];
        $this->video = null;
    }

    private function acquire(): void
    {
        if ($this->acquired) {
            return;
        }

        $this->acquired = true;
        $acquirer = $this->acquirer;
        $this->acquirer = null;

        if ($acquirer !== null) {
            $acquirer($this);
        }
    }
}
```

- [ ] **Step 5: Implement `MediaWorkspaceFactory`**

```php
<?php

namespace App\Platform\Enrichment\Media;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Recognition\MediaFetcher;
use App\Shared\Enums\ContentType;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds the lazy MediaWorkspace for one enrichment target. Routing
 * mirrors the pre-B RecognitionService::mediaFor: image-typed content →
 * first 3 image URLs (inline cap); video-typed content → first URL
 * (streaming download cap); Story → the archived private-disk file.
 */
class MediaWorkspaceFactory
{
    private const IMAGE_LIMIT_PER_TARGET = 3;

    public function __construct(private readonly MediaFetcher $fetcher) {}

    public function forTarget(ContentItem|Story $target): MediaWorkspace
    {
        return new MediaWorkspace(function (MediaWorkspace $workspace) use ($target): void {
            $target instanceof Story
                ? $this->acquireStory($target, $workspace)
                : $this->acquireContent($target, $workspace);
        });
    }

    private function acquireContent(ContentItem $item, MediaWorkspace $workspace): void
    {
        $urls = array_values(array_filter((array) ($item->media_urls ?? []), 'is_string'));

        if ($urls === []) {
            $workspace->addMarker('media:none');

            return;
        }

        if (in_array($item->content_type, [ContentType::ImagePost, ContentType::Carousel], true)) {
            $inlineMax = (int) config('qds.enrichment.recognition.inline_max_bytes');

            foreach (array_slice($urls, 0, self::IMAGE_LIMIT_PER_TARGET) as $url) {
                $asset = $this->stream($workspace, $url, $inlineMax);

                if ($asset !== null) {
                    $workspace->addImage($asset);
                }
            }

            return;
        }

        // Video-typed content: single deep pass over the first media asset.
        $asset = $this->stream($workspace, $urls[0], (int) config('qds.enrichment.keyframes.download_max_bytes'));

        if ($asset !== null) {
            $workspace->setVideo($asset);
        }
    }

    private function stream(MediaWorkspace $workspace, string $url, int $maxBytes): ?LocalMediaAsset
    {
        $sink = $workspace->newTempPath();

        if ($sink === null) {
            $workspace->addMarker('media:fetch-failed');

            return null;
        }

        $result = $this->fetcher->streamToFile($url, $sink, $maxBytes);

        if ($result->status !== StreamStatus::Ok) {
            $workspace->addMarker(match ($result->status) {
                StreamStatus::TooLarge => 'media:too-large',
                StreamStatus::Gone => 'media:too-old',
                default => 'media:fetch-failed',
            });

            return null;
        }

        clearstatcache(true, $sink);
        $size = (int) @filesize($sink);

        if ($size === 0) {
            $workspace->addMarker('media:fetch-failed');

            return null;
        }

        return new LocalMediaAsset($sink, $size, $result->contentType, (string) hash_file('sha256', $sink), $url);
    }

    /** Archived story media lives on the private disk — no HTTP, no SSRF surface. */
    private function acquireStory(Story $story, MediaWorkspace $workspace): void
    {
        $mediaUrl = (string) ($story->media_url ?? '');

        if ($mediaUrl === '') {
            $workspace->addMarker('media:none');

            return;
        }

        $sink = $workspace->newTempPath();

        if ($sink === null) {
            $workspace->addMarker('media:fetch-failed');

            return;
        }

        $isVideo = $this->looksLikeVideo($mediaUrl);
        $maxBytes = $isVideo
            ? (int) config('qds.enrichment.keyframes.download_max_bytes')
            : (int) config('qds.enrichment.recognition.inline_max_bytes');

        try {
            $stream = Storage::disk((string) config('qds.ingestion.media_disk'))->readStream($mediaUrl);
            $out = fopen($sink, 'wb');

            if (! is_resource($stream) || ! is_resource($out)) {
                $workspace->addMarker('media:fetch-failed');

                return;
            }

            // Copy one byte past the cap so an over-cap file is detectable.
            stream_copy_to_stream($stream, $out, $maxBytes + 1);
            fclose($stream);
            fclose($out);
        } catch (Throwable) {
            $workspace->addMarker('media:fetch-failed');

            return;
        }

        clearstatcache(true, $sink);
        $size = (int) @filesize($sink);

        if ($size === 0) {
            $workspace->addMarker('media:fetch-failed');

            return;
        }

        if ($size > $maxBytes) {
            $workspace->addMarker('media:too-large');

            return;
        }

        $asset = new LocalMediaAsset($sink, $size, null, (string) hash_file('sha256', $sink), null);
        $isVideo ? $workspace->setVideo($asset) : $workspace->addImage($asset);
    }

    private function looksLikeVideo(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'mov', 'webm', 'm4v'], true);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/MediaWorkspaceTest.php`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Platform/Enrichment/Media/ tests/Feature/Enrichment/MediaWorkspaceTest.php
git commit -m "feat(enrichment): lazy single-download MediaWorkspace + factory"
```

---

### Task 9: `AudioExtractor::extractFromFile` (reuse the workspace's file)

**Files:**
- Modify: `app/Platform/Enrichment/Recognition/AudioExtractor.php`
- Test: `tests/Feature/Enrichment/AudioExtractorTest.php` (existing — add one test)

**Interfaces:**
- Consumes: nothing new.
- Produces: `AudioExtractor::extractFromFile(string $videoPath): ?string` — same contract as `extract()` but reads an existing file instead of writing bytes to a fresh temp file. `extract(string $videoBytes)` keeps its exact behaviour (delegates). Task 10 calls `extractFromFile`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Enrichment/AudioExtractorTest.php` (reuse its existing synthetic-video helper and ffmpeg-or-skip gate):

```php
    public function test_extract_from_file_produces_the_same_flac_as_extract(): void
    {
        $videoPath = $this->makeVideoWithAudio(); // the file's existing helper that renders a testsrc+sine MP4 and returns its path (adapt the name to the helper actually present)
        $extractor = app(\App\Platform\Enrichment\Recognition\AudioExtractor::class);

        $fromFile = $extractor->extractFromFile($videoPath);
        $fromBytes = $extractor->extract((string) file_get_contents($videoPath));

        $this->assertNotNull($fromFile);
        $this->assertSame($fromBytes, $fromFile);
    }
```

If the helper returns bytes instead of a path, write them to a temp file first inside the test.

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/AudioExtractorTest.php --filter test_extract_from_file`
Expected: ERROR — method `extractFromFile` does not exist (or SKIPPED when ffmpeg is absent — then rely on CI/dev with ffmpeg).

- [ ] **Step 3: Implement**

In `AudioExtractor.php`, replace the body of `extract()` and add `extractFromFile()`:

```php
    /** FLAC bytes of the first ≤60s of audio, or null when none can be derived. */
    public function extract(string $videoBytes): ?string
    {
        if ($videoBytes === '') {
            return null;
        }

        $in = tempnam(sys_get_temp_dir(), 'qds-audio-in-');

        if ($in === false) {
            return null;
        }

        try {
            if (file_put_contents($in, $videoBytes) === false) {
                return null;
            }

            return $this->extractFromFile($in);
        } finally {
            @unlink($in);
        }
    }

    /**
     * Same contract as extract(), reading an EXISTING video file — the
     * MediaWorkspace already materialized the bytes once (sub-project B);
     * writing them to a second temp file would double the disk footprint.
     * The caller keeps ownership of $videoPath.
     */
    public function extractFromFile(string $videoPath): ?string
    {
        if (! is_file($videoPath) || (int) @filesize($videoPath) === 0) {
            return null;
        }

        $out = tempnam(sys_get_temp_dir(), 'qds-audio-out-');

        if ($out === false) {
            return null;
        }

        try {
            $result = Process::timeout(self::FFMPEG_TIMEOUT_SECONDS)->run([
                $this->ffmpegPath(),
                '-nostdin',
                '-v', 'error',
                '-i', $videoPath,
                '-vn', // drop the video stream
                '-ac', '1', // mono
                '-ar', '16000', // 16 kHz
                '-t', (string) $this->maxSeconds(),
                '-f', 'flac',
                '-y', $out,
            ]);

            if (! $result->successful()) {
                // Includes the muted-video case: no audio stream → ffmpeg
                // refuses to write an empty FLAC and exits non-zero.
                return null;
            }

            $audio = file_get_contents($out);

            return is_string($audio) && $audio !== '' && strlen($audio) <= self::MAX_AUDIO_BYTES
                ? $audio
                : null;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($out);
        }
    }
```

(The old `extract()` body's dual-tempnam cleanup comment becomes obsolete — the split leaves each method owning exactly one temp file.)

- [ ] **Step 4: Run the whole file to verify no regression**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/AudioExtractorTest.php`
Expected: all PASS (or SKIPPED without ffmpeg).

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Enrichment/Recognition/AudioExtractor.php tests/Feature/Enrichment/AudioExtractorTest.php
git commit -m "refactor(enrichment): AudioExtractor::extractFromFile — reuse the workspace file"
```

---

### Task 10: RecognitionService + EnrichmentPipeline consume the workspace (the marker split lands here)

**Files:**
- Modify: `app/Platform/Enrichment/Recognition/RecognitionService.php`
- Modify: `app/Platform/Enrichment/EnrichmentPipeline.php`
- Modify: `tests/Feature/Enrichment/RecognitionPipelineTest.php` (line ~116: the direct `enrich()` call site)
- Test: extend `tests/Feature/Enrichment/RecognitionPipelineTest.php` with the too-large marker test

**Interfaces:**
- Consumes: `MediaWorkspace`/`MediaWorkspaceFactory` (Task 8), `AudioExtractor::extractFromFile` (Task 9), config `qds.enrichment.recognition.inline_max_bytes`.
- Produces: `RecognitionService::enrich(ContentItem|Story $target, string $correlationId, int $retryCount = 0, ?MediaWorkspace $workspace = null): array` — same return shape. A `null` workspace builds its own (keeps any other callers working); the pipeline passes the shared one. New marker: `recognition:whole-video-skipped-too-large`. `EnrichmentPipeline` builds ONE workspace per run and `close()`s it in `finally`; Task 13 adds the keyframes stage next to it.
- Behaviour preserved: in-cap images/video produce byte-identical provider calls; `MediaFetcher` stays injected only via the factory.

- [ ] **Step 1: Write the failing marker test**

Add to `RecognitionPipelineTest.php` (mirror its existing fake helpers — Vision/VI fakes on `videointelligence.googleapis.com/*` and media on the literal-IP host):

```php
    public function test_video_over_the_inline_cap_skips_whole_video_vi_with_the_distinct_marker(): void
    {
        config([
            'services.google_video_intelligence.api_key' => 'test-vi-key',
            'qds.enrichment.recognition.inline_max_bytes' => 10, // force the split
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '93.184.216.34/*' => \Illuminate\Support\Facades\Http::response(str_repeat('V', 100), 200, ['Content-Type' => 'video/mp4']),
        ]);
        $content = $this->makeVideoContent(['https://93.184.216.34/big.mp4']); // adapt to the file's existing content-item helper

        $result = $this->enrichViaService($content); // the file's helper around RecognitionService::enrich

        $this->assertContains('recognition:whole-video-skipped-too-large', $result['skipped']);
        \Illuminate\Support\Facades\Http::assertNotSent(fn ($request) => str_contains($request->url(), 'videointelligence'));
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/RecognitionPipelineTest.php --filter whole_video_skipped`
Expected: FAIL (marker absent — the old code drops the video with `media:fetch-failed`).

- [ ] **Step 3: Refactor `RecognitionService`**

Constructor: REMOVE `private readonly MediaFetcher $media,` and ADD `private readonly MediaWorkspaceFactory $workspaces,` (import `App\Platform\Enrichment\Media\{LocalMediaAsset, MediaWorkspace, MediaWorkspaceFactory}`; drop the now-unused `MediaFetcher` import).

`enrich()` signature: `public function enrich(ContentItem|Story $target, string $correlationId, int $retryCount = 0, ?MediaWorkspace $workspace = null): array`.

Replace the media section — everything from `[$imageBytes, $videoBytes] = $this->mediaFor($target, $skipped);` through the end of the video block — with:

```php
        $ownWorkspace = $workspace === null;
        $workspace ??= $this->workspaces->forTarget($target);

        try {
            $images = $workspace->images();
            $video = $workspace->video();

            foreach ($workspace->markers() as $marker) {
                $skipped[] = $marker;
            }

            if ($images !== []) {
                if (! $this->vision->isConfigured()) {
                    $skipped[] = 'vision:not-configured';
                } else {
                    foreach ($images as $image) {
                        [$c, $u] = $this->annotate(
                            $target,
                            SourceRegistry::GOOGLE_CLOUD_VISION,
                            'vision.annotate',
                            $correlationId,
                            $retryCount,
                            fn (): NormalizedBatch => $this->normalizer->visionBatch($this->vision->annotateImage($image->bytes())),
                        );

                        $created += $c;
                        $updated += $u;
                    }
                }
            }

            if ($video !== null) {
                $inlineMax = (int) config('qds.enrichment.recognition.inline_max_bytes');

                if (! $this->videoIntelligence->isConfigured()) {
                    // OPTIONAL provider (data-source matrix) — absence is normal.
                    $skipped[] = 'video-intelligence:not-configured';
                } elseif ($video->byteSize > $inlineMax) {
                    // The whole-video inline pass is skipped — NOT the media:
                    // keyframes still cover this video (sub-project B split).
                    $skipped[] = 'recognition:whole-video-skipped-too-large';
                } else {
                    [$c, $u] = $this->annotate(
                        $target,
                        SourceRegistry::GOOGLE_VIDEO_INTELLIGENCE,
                        'video.annotate',
                        $correlationId,
                        $retryCount,
                        fn (): NormalizedBatch => $this->normalizer->videoBatch($this->videoIntelligence->annotateVideo($video->bytes())),
                    );

                    $created += $c;
                    $updated += $u;
                }

                // SPOKEN_BRAND: derive a ≤60s audio track locally, then
                // transcribe. Each gate records its own skip marker so a
                // missing detection is always explainable. Runs for ANY
                // downloaded video size — the cap above is inline-only.
                if (! $this->speech->isConfigured()) {
                    $skipped[] = 'speech:not-configured';
                } elseif (! $this->audio->isAvailable()) {
                    $skipped[] = 'speech:ffmpeg-unavailable';
                } else {
                    $audioBytes = $this->audio->extractFromFile($video->tempPath);

                    if ($audioBytes === null) {
                        // Muted/undecodable media — unavailable, never fabricated.
                        $skipped[] = 'speech:audio-extraction-failed';
                    } else {
                        try {
                            [$c, $u] = $this->annotate(
                                $target,
                                SourceRegistry::GOOGLE_SPEECH_TO_TEXT,
                                'speech.recognize',
                                $correlationId,
                                $retryCount,
                                fn (): NormalizedBatch => $this->normalizer->speechBatch($this->speech->recognize($audioBytes)),
                            );

                            $created += $c;
                            $updated += $u;
                        } catch (ProviderCallException $e) {
                            // A transient speech failure must NOT fail the whole
                            // run and re-bill the already-succeeded stages.
                            $skipped[] = 'speech:provider-error';
                        }
                    }
                }
            }
        } finally {
            if ($ownWorkspace) {
                $workspace->close();
            }
        }
```

Delete the now-unused `mediaFor()`, `looksLikeVideo()`, and `IMAGE_LIMIT_PER_TARGET` (the factory owns them).

- [ ] **Step 4: Wire the pipeline**

In `EnrichmentPipeline`: constructor adds `private readonly MediaWorkspaceFactory $workspaces,` (import it). In `run()`, before the `try`:

```php
        $workspace = $this->workspaces->forTarget($target);
```

Change the recognition call to `$this->recognition->enrich($target, $correlationId, $retryCount, $workspace);` and append after the existing `catch` block:

```php
        } finally {
            $workspace->close();
        }
```

- [ ] **Step 5: Update the direct call site**

`RecognitionPipelineTest.php:116` — `app(RecognitionService::class)->enrich($content, $correlationId)` needs no change (the nullable workspace builds its own). Verify no other caller passes positional args beyond `$retryCount`: `grep -rn "->enrich(" app tests | grep -i recognition`.

- [ ] **Step 6: Run the enrichment suite, then the FULL suite**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/`
Expected: all PASS — this is the behaviour-preservation gate. One legacy delta is legitimate: an over-20MB image/video now yields `media:too-large`/`recognition:whole-video-skipped-too-large` instead of `media:fetch-failed`; update any test asserting the old marker for oversized media (grep `fetch-failed` in tests/Feature/Enrichment).
Then run: `XDEBUG_MODE=off vendor/bin/phpunit`
Expected: full suite green.

- [ ] **Step 7: Commit**

```bash
git add app/Platform/Enrichment/Recognition/RecognitionService.php app/Platform/Enrichment/EnrichmentPipeline.php tests/Feature/Enrichment/RecognitionPipelineTest.php
git commit -m "refactor(enrichment): recognition consumes the shared MediaWorkspace; too-large marker split"
```

---

### Task 11: `keyframes` table, `Keyframe` model, `KeyframeKind`, `KeyframeSet` + repository

**Files:**
- Create: `database/migrations/2026_07_19_100002_create_keyframes_table.php`
- Create: `app/Shared/Enums/KeyframeKind.php`
- Create: `app/Modules/Monitoring/Models/Keyframe.php`
- Create: `app/Platform/Enrichment/Keyframes/KeyframeSet.php`
- Create: `app/Platform/Enrichment/Keyframes/KeyframeRepository.php`
- Modify: `app/Modules/Monitoring/Models/ContentItem.php` and `app/Modules/Monitoring/Models/Story.php` (add `keyframes()` MorphMany)
- Test: `tests/Feature/Enrichment/KeyframeModelTest.php` (create)

**Interfaces:**
- Consumes: `BelongsToTenant`, `AsValueObject`, `Provenance`.
- Produces (the C/D seam — tier C will FK `embedding.keyframe_id` → `keyframes.id`):
  - `enum KeyframeKind: string { VideoSample = 'video_sample'; Thumbnail = 'thumbnail'; SourceImage = 'source_image'; }`
  - `Keyframe` model — fillable `owner_type, owner_id, ordinal, timestamp_ms, storage_disk, storage_path, width, height, kind, checksum, provenance`; casts `kind => KeyframeKind`, `provenance => AsValueObject:Provenance`; `owner(): MorphTo`; unique `(owner_type, owner_id, ordinal)`.
  - `KeyframeSet{ list<Keyframe> $frames; string $status }` with `isEmpty(): bool`; `KeyframeRepository::forOwner(ContentItem|Story $owner): KeyframeSet` (frames ordered by `ordinal`; status `extracted` | `empty`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Enrichment\Keyframes\KeyframeRepository;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeyframeModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeContentItem(): ContentItem
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create();

        return ContentItem::factory()->for($account, 'platformAccount')->create();
    }

    private function frameAttributes(ContentItem $item, int $ordinal): array
    {
        return [
            'owner_type' => $item->getMorphClass(),
            'owner_id' => $item->id,
            'ordinal' => $ordinal,
            'timestamp_ms' => $ordinal * 3000,
            'storage_disk' => 'media',
            'storage_path' => "tenants/1/keyframes/instagram/1/content-x/{$ordinal}.jpg",
            'width' => 1280,
            'height' => 720,
            'kind' => KeyframeKind::VideoSample,
            'checksum' => str_repeat('a', 64),
            'provenance' => new Provenance('SRC-apify-instagram-reel-scraper', CarbonImmutable::now(), 'keyframes-v1'),
        ];
    }

    public function test_frames_are_tenant_stamped_and_owner_reachable(): void
    {
        $item = $this->makeContentItem();
        $frame = Keyframe::query()->create($this->frameAttributes($item, 0));

        $this->assertSame($item->tenant_id, $frame->tenant_id);
        $this->assertTrue($frame->owner()->is($item));
        $this->assertTrue($item->keyframes()->whereKey($frame->id)->exists());
        $this->assertSame(KeyframeKind::VideoSample, $frame->kind);
    }

    public function test_owner_plus_ordinal_is_unique(): void
    {
        $item = $this->makeContentItem();
        Keyframe::query()->create($this->frameAttributes($item, 0));

        $this->expectException(UniqueConstraintViolationException::class);
        Keyframe::query()->create($this->frameAttributes($item, 0));
    }

    public function test_repository_returns_frames_ordered_by_ordinal(): void
    {
        $item = $this->makeContentItem();
        Keyframe::query()->create($this->frameAttributes($item, 2));
        Keyframe::query()->create($this->frameAttributes($item, 0));
        Keyframe::query()->create($this->frameAttributes($item, 1));

        $set = app(KeyframeRepository::class)->forOwner($item);

        $this->assertSame('extracted', $set->status);
        $this->assertFalse($set->isEmpty());
        $this->assertSame([0, 1, 2], array_map(fn ($f) => $f->ordinal, $set->frames));
    }

    public function test_repository_reports_empty_for_frameless_owner(): void
    {
        $set = app(KeyframeRepository::class)->forOwner($this->makeContentItem());

        $this->assertSame('empty', $set->status);
        $this->assertTrue($set->isEmpty());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/KeyframeModelTest.php`
Expected: ERROR — class `Keyframe` not found.

- [ ] **Step 3: Implement migration, enum, model, VO, repository**

Migration `2026_07_19_100002_create_keyframes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyframes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // Polymorphic owner: ContentItem or Story (and future media roots).
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedInteger('ordinal');
            // Position in the source video; null for thumbnails/source images.
            $table->unsignedInteger('timestamp_ms')->nullable();
            $table->string('storage_disk', 50);
            $table->string('storage_path', 500);
            // Best-effort image metadata (getimagesize; null when undecodable).
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('kind', 20);
            $table->char('checksum', 64);
            $table->jsonb('provenance');
            $table->timestamps();

            // Extract-once identity: a re-run may never duplicate or renumber
            // frames (tier C FKs embeddings to keyframes.id).
            $table->unique(['owner_type', 'owner_id', 'ordinal']);
            $table->index(['owner_type', 'owner_id']);
        });

        DB::statement("ALTER TABLE keyframes ADD CONSTRAINT keyframes_kind_check CHECK (kind IN ('video_sample', 'thumbnail', 'source_image'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('keyframes');
    }
};
```

`app/Shared/Enums/KeyframeKind.php`:

```php
<?php

namespace App\Shared\Enums;

/** How a keyframe was derived (sub-project B). */
enum KeyframeKind: string
{
    /** Even-interval ffmpeg sample from a downloaded video. */
    case VideoSample = 'video_sample';
    /** The platform's poster image (YouTube — the only in-freeze visual). */
    case Thumbnail = 'thumbnail';
    /** A post/carousel/story image — the image IS the frame. */
    case SourceImage = 'source_image';
}
```

`app/Modules/Monitoring/Models/Keyframe.php`:

```php
<?php

namespace App\Modules\Monitoring\Models;

use App\Shared\Casts\AsValueObject;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\Provenance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One persisted representative frame of an owner's media (sub-project B) —
 * the stable, FK-able unit tiers C (one embedding per frame) and D (Gemini
 * grounding) consume. Files live on the private media disk under
 * tenants/{id}/keyframes/… with story-media-equivalent retention + GDPR
 * erase; the row and its file live and die together.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $owner_type
 * @property int $owner_id
 * @property int $ordinal
 * @property int|null $timestamp_ms
 * @property string $storage_disk
 * @property string $storage_path
 * @property int|null $width
 * @property int|null $height
 * @property KeyframeKind $kind
 * @property string $checksum sha256 of the stored file
 * @property Provenance $provenance
 */
class Keyframe extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'ordinal',
        'timestamp_ms',
        'storage_disk',
        'storage_path',
        'width',
        'height',
        'kind',
        'checksum',
        'provenance',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => KeyframeKind::class,
            'provenance' => AsValueObject::class.':'.Provenance::class,
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
```

In `ContentItem.php` add after `transcripts()` (import `Illuminate\Database\Eloquent\Relations\MorphMany`):

```php
    /** @return MorphMany<Keyframe, $this> */
    public function keyframes(): MorphMany
    {
        return $this->morphMany(Keyframe::class, 'owner');
    }
```

Add the identical method to `Story.php` (same import).

`app/Platform/Enrichment/Keyframes/KeyframeSet.php`:

```php
<?php

namespace App\Platform\Enrichment\Keyframes;

use App\Modules\Monitoring\Models\Keyframe;

/**
 * The frame set tiers C/D consume for one owner — the swappable contract:
 * neither tier touches the keyframes table directly.
 */
final readonly class KeyframeSet
{
    public function __construct(
        /** @var list<Keyframe> ordered by ordinal */
        public array $frames,
        /** 'extracted' | 'empty' (run-level skip detail lives on EnrichmentRun.stages) */
        public string $status,
    ) {}

    public function isEmpty(): bool
    {
        return $this->frames === [];
    }
}
```

`app/Platform/Enrichment/Keyframes/KeyframeRepository.php`:

```php
<?php

namespace App\Platform\Enrichment\Keyframes;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\Story;

class KeyframeRepository
{
    public function forOwner(ContentItem|Story $owner): KeyframeSet
    {
        $frames = Keyframe::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->id)
            ->orderBy('ordinal')
            ->get()
            ->all();

        return new KeyframeSet($frames, $frames === [] ? 'empty' : 'extracted');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/KeyframeModelTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_19_100002_create_keyframes_table.php app/Shared/Enums/KeyframeKind.php app/Modules/Monitoring/Models/Keyframe.php app/Modules/Monitoring/Models/ContentItem.php app/Modules/Monitoring/Models/Story.php app/Platform/Enrichment/Keyframes/ tests/Feature/Enrichment/KeyframeModelTest.php
git commit -m "feat(enrichment): keyframes table (polymorphic owner) + KeyframeSet contract for C/D"
```

---

### Task 12: `KeyframeSampler` (deterministic even-interval ffmpeg extraction)

**Files:**
- Create: `app/Platform/Enrichment/Keyframes/SampledFrame.php`
- Create: `app/Platform/Enrichment/Keyframes/KeyframeSampler.php`
- Test: `tests/Feature/Enrichment/KeyframeSamplerTest.php` (create)

**Interfaces:**
- Consumes: config `qds.enrichment.keyframes.{interval_seconds,min_frames,max_frames,max_width,jpeg_quality,ffmpeg_path,ffprobe_path}`.
- Produces (Task 13 consumes): `SampledFrame{ string $tempPath; int $timestampMs; int $ordinal }`; `KeyframeSampler::isAvailable(): bool`; `sample(string $videoPath): ?list<SampledFrame>` — null on any failure (fail-closed); the CALLER unlinks the frame temp files.

- [ ] **Step 1: Write the failing test**

Mirror `AudioExtractorTest`'s real-ffmpeg-or-skip pattern (synthetic `testsrc` clips, no fixture files):

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\Keyframes\KeyframeSampler;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class KeyframeSamplerTest extends TestCase
{
    private KeyframeSampler $sampler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sampler = app(KeyframeSampler::class);

        if (! $this->sampler->isAvailable()) {
            $this->markTestSkipped('ffmpeg/ffprobe not installed.');
        }
    }

    /** Render a silent synthetic clip of $seconds and return its path. */
    private function makeVideo(int $seconds): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qds-test-video-');
        Process::timeout(60)->run([
            (string) config('qds.enrichment.keyframes.ffmpeg_path'),
            '-nostdin', '-v', 'error',
            '-f', 'lavfi', '-i', "testsrc=duration={$seconds}:size=320x240:rate=10",
            '-pix_fmt', 'yuv420p', '-f', 'mp4', '-y', $path,
        ])->throw();

        return $path;
    }

    private function discard(?array $frames, string ...$videos): void
    {
        foreach ($frames ?? [] as $frame) {
            @unlink($frame->tempPath);
        }
        foreach ($videos as $video) {
            @unlink($video);
        }
    }

    public function test_frame_count_is_clamped_and_timestamps_ascend(): void
    {
        config(['qds.enrichment.keyframes.interval_seconds' => 2, 'qds.enrichment.keyframes.min_frames' => 2, 'qds.enrichment.keyframes.max_frames' => 4]);
        $video = $this->makeVideo(20); // ceil(20/2)=10 → clamped to 4

        $frames = $this->sampler->sample($video);

        $this->assertNotNull($frames);
        $this->assertCount(4, $frames);
        $this->assertSame([0, 1, 2, 3], array_map(fn ($f) => $f->ordinal, $frames));
        $timestamps = array_map(fn ($f) => $f->timestampMs, $frames);
        $this->assertSame($timestamps, array_values(array_unique($timestamps)));
        $sorted = $timestamps;
        sort($sorted);
        $this->assertSame($sorted, $timestamps);
        foreach ($frames as $frame) {
            $this->assertFileExists($frame->tempPath);
            $this->assertGreaterThan(0, (int) filesize($frame->tempPath));
        }
        $this->discard($frames, $video);
    }

    public function test_short_video_gets_the_minimum_and_sampling_is_deterministic(): void
    {
        config(['qds.enrichment.keyframes.interval_seconds' => 6, 'qds.enrichment.keyframes.min_frames' => 3, 'qds.enrichment.keyframes.max_frames' => 12]);
        $video = $this->makeVideo(4); // ceil(4/6)=1 → clamped up to 3

        $first = $this->sampler->sample($video);
        $second = $this->sampler->sample($video);

        $this->assertNotNull($first);
        $this->assertCount(3, $first);
        $this->assertSame(
            array_map(fn ($f) => $f->timestampMs, $first),
            array_map(fn ($f) => $f->timestampMs, $second ?? []),
        );
        $this->assertSame(
            array_map(fn ($f) => hash_file('sha256', $f->tempPath), $first),
            array_map(fn ($f) => hash_file('sha256', $f->tempPath), $second ?? []),
        );
        $this->discard($first, $video);
        $this->discard($second);
    }

    public function test_frames_are_downscaled_to_max_width(): void
    {
        config(['qds.enrichment.keyframes.max_width' => 160, 'qds.enrichment.keyframes.min_frames' => 1, 'qds.enrichment.keyframes.max_frames' => 1]);
        $video = $this->makeVideo(3); // source is 320px wide

        $frames = $this->sampler->sample($video);

        $this->assertNotNull($frames);
        [$width] = (array) getimagesize($frames[0]->tempPath);
        $this->assertSame(160, $width);
        $this->discard($frames, $video);
    }

    public function test_undecodable_input_yields_null(): void
    {
        $garbage = (string) tempnam(sys_get_temp_dir(), 'qds-test-garbage-');
        file_put_contents($garbage, 'not a video at all');

        $this->assertNull($this->sampler->sample($garbage));
        @unlink($garbage);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/KeyframeSamplerTest.php`
Expected: ERROR — class `KeyframeSampler` not found (or all SKIPPED without ffmpeg — then install ffmpeg locally; the suite treats it like AudioExtractorTest).

- [ ] **Step 3: Implement**

`app/Platform/Enrichment/Keyframes/SampledFrame.php`:

```php
<?php

namespace App\Platform\Enrichment\Keyframes;

/** One extracted frame temp file, before persistence (caller owns cleanup). */
final readonly class SampledFrame
{
    public function __construct(
        public string $tempPath,
        public int $timestampMs,
        public int $ordinal,
    ) {}
}
```

`app/Platform/Enrichment/Keyframes/KeyframeSampler.php`:

```php
<?php

namespace App\Platform\Enrichment\Keyframes;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Deterministic even-interval keyframe extraction (sub-project B): given a
 * downloaded video file, derive N = clamp(ceil(duration/interval), min, max)
 * JPEG frames at the midpoint of each of N equal spans — identical input
 * bytes + config always yield identical frames (repo determinism doctrine;
 * scene-change selection is a documented future mode, ADR-0028).
 *
 * Mirrors AudioExtractor's untrusted-input hardening: fixed argument
 * vector, -nostdin, hard timeouts; any failure yields null (fail-closed,
 * never fabricated), and partial output is discarded.
 */
class KeyframeSampler
{
    private const FFMPEG_TIMEOUT_SECONDS = 120;

    private const FFPROBE_TIMEOUT_SECONDS = 30;

    private ?bool $available = null;

    /** True when both configured binaries answer -version. */
    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            return $this->available = Process::timeout(10)->run([$this->ffmpegPath(), '-version'])->successful()
                && Process::timeout(10)->run([$this->ffprobePath(), '-version'])->successful();
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    /** @return list<SampledFrame>|null null when nothing can be derived */
    public function sample(string $videoPath): ?array
    {
        $duration = $this->probeDurationSeconds($videoPath);

        if ($duration === null || $duration <= 0.0) {
            return null;
        }

        $interval = max(1, (int) config('qds.enrichment.keyframes.interval_seconds'));
        $min = max(1, (int) config('qds.enrichment.keyframes.min_frames'));
        $max = max($min, (int) config('qds.enrichment.keyframes.max_frames'));
        $count = (int) min($max, max($min, (int) ceil($duration / $interval)));

        $maxWidth = max(16, (int) config('qds.enrichment.keyframes.max_width'));
        $quality = min(31, max(2, (int) config('qds.enrichment.keyframes.jpeg_quality')));

        $frames = [];

        try {
            for ($i = 0; $i < $count; $i++) {
                // Midpoint of span i — deterministic, never the (often black)
                // first/last frame.
                $timestamp = $duration * ($i + 0.5) / $count;
                $out = tempnam(sys_get_temp_dir(), 'qds-frame-');

                if ($out === false) {
                    $this->discard($frames);

                    return null;
                }

                $result = Process::timeout(self::FFMPEG_TIMEOUT_SECONDS)->run([
                    $this->ffmpegPath(),
                    '-nostdin',
                    '-v', 'error',
                    '-ss', sprintf('%.3F', $timestamp),
                    '-i', $videoPath,
                    '-frames:v', '1',
                    '-vf', sprintf('scale=min(%d\\,iw):-2', $maxWidth),
                    '-q:v', (string) $quality,
                    '-f', 'image2',
                    '-y', $out,
                ]);

                clearstatcache(true, $out);

                if (! $result->successful() || (int) @filesize($out) === 0) {
                    @unlink($out);
                    $this->discard($frames);

                    return null;
                }

                $frames[] = new SampledFrame($out, (int) round($timestamp * 1000), $i);
            }
        } catch (Throwable) {
            $this->discard($frames);

            return null;
        }

        return $frames;
    }

    private function probeDurationSeconds(string $videoPath): ?float
    {
        try {
            $result = Process::timeout(self::FFPROBE_TIMEOUT_SECONDS)->run([
                $this->ffprobePath(),
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $videoPath,
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $output = trim($result->output());

        return is_numeric($output) ? (float) $output : null;
    }

    /** @param list<SampledFrame> $frames */
    private function discard(array $frames): void
    {
        foreach ($frames as $frame) {
            @unlink($frame->tempPath);
        }
    }

    private function ffmpegPath(): string
    {
        return (string) config('qds.enrichment.keyframes.ffmpeg_path', 'ffmpeg');
    }

    private function ffprobePath(): string
    {
        return (string) config('qds.enrichment.keyframes.ffprobe_path', 'ffprobe');
    }
}
```

Note the scale filter: `scale=min(1280\,iw):-2` — the escaped comma keeps ffmpeg from reading it as a filter separator; `-2` keeps the height even. No shell is involved (fixed arg vector), so no quoting beyond that backslash.

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/KeyframeSamplerTest.php`
Expected: PASS (4 tests, or SKIPPED without ffmpeg).

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Enrichment/Keyframes/SampledFrame.php app/Platform/Enrichment/Keyframes/KeyframeSampler.php tests/Feature/Enrichment/KeyframeSamplerTest.php
git commit -m "feat(enrichment): deterministic even-interval KeyframeSampler (ffmpeg)"
```

---

### Task 13: `KeyframeWriter` + `KeyframeExtractor` stage wired into the pipeline

**Files:**
- Create: `app/Platform/Enrichment/Keyframes/KeyframeWriter.php`
- Create: `app/Platform/Enrichment/Keyframes/KeyframeExtractor.php`
- Modify: `app/Platform/Enrichment/EnrichmentPipeline.php` (keyframes stage after recognition)
- Test: `tests/Feature/Enrichment/KeyframePipelineTest.php` (create)

**Interfaces:**
- Consumes: `KeyframeSampler`/`SampledFrame` (Task 12), `Keyframe`/`KeyframeKind` (Task 11), `MediaWorkspace`/`LocalMediaAsset` (Task 8), config `qds.enrichment.keyframes.enabled`, `qds.ingestion.media_disk`.
- Produces:
  - `KeyframeWriter::persist(ContentItem|Story $owner, list<array{tempPath: string, timestampMs: int|null, kind: KeyframeKind, extension: string}> $frames): int` — stores files under `tenants/{tenant_id}/keyframes/{platform}/{platform_account_id}/{content|story}-{external_id}/{ordinal}.{ext}` and inserts rows.
  - `KeyframeExtractor::enrich(ContentItem|Story $target, MediaWorkspace $workspace): string` — the stage summary written to `EnrichmentRun.stages['keyframes']`: `completed:N frame(s)` | `skipped:already-extracted` | `skipped:ffmpeg-unavailable` | `skipped:extraction-failed` | `skipped:<acquisition marker>` | `skipped:no-media`.
  - Pipeline stage value `skipped:disabled` when the kill switch is off.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Enrichment\EnrichmentPipeline;
use App\Platform\Enrichment\Keyframes\KeyframeSampler;
use App\Platform\Enrichment\Keyframes\SampledFrame;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KeyframePipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');
    }

    /** Container-stub the sampler (the AudioExtractor test pattern) — pipeline tests never shell out. */
    private function stubSampler(int $frameCount): void
    {
        $this->app->instance(KeyframeSampler::class, new class($frameCount) extends KeyframeSampler
        {
            public function __construct(private readonly int $frameCount) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function sample(string $videoPath): ?array
            {
                $frames = [];

                for ($i = 0; $i < $this->frameCount; $i++) {
                    $path = (string) tempnam(sys_get_temp_dir(), 'qds-stub-frame-');
                    file_put_contents($path, "FRAME-{$i}");
                    $frames[] = new SampledFrame($path, $i * 3000, $i);
                }

                return $frames;
            }
        });
    }

    private function makeReel(): ContentItem
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => Platform::Instagram]);

        return ContentItem::factory()->for($account, 'platformAccount')->create([
            'platform' => Platform::Instagram,
            'content_type' => ContentType::Reel,
            'external_id' => 'reel-1',
            'media_urls' => ['https://93.184.216.34/reel.mp4'],
        ]);
    }

    public function test_video_frames_are_persisted_with_paths_checksums_and_stage_summary(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('REELBYTES', 200, ['Content-Type' => 'video/mp4'])]);
        $this->stubSampler(3);
        $reel = $this->makeReel();

        $run = app(EnrichmentPipeline::class)->run($reel, 'corr-k1');

        $this->assertSame('completed:3 frame(s)', $run->stages['keyframes']);
        $frames = Keyframe::query()->where('owner_id', $reel->id)->orderBy('ordinal')->get();
        $this->assertCount(3, $frames);
        $this->assertSame(KeyframeKind::VideoSample, $frames[0]->kind);
        $this->assertSame($reel->tenant_id, $frames[0]->tenant_id);
        $this->assertSame(hash('sha256', 'FRAME-0'), $frames[0]->checksum);
        $expectedPath = "tenants/{$reel->tenant_id}/keyframes/instagram/{$reel->platform_account_id}/content-reel-1/0.jpg";
        $this->assertSame($expectedPath, $frames[0]->storage_path);
        Storage::disk('media')->assertExists($expectedPath);
    }

    public function test_extract_once_a_second_run_skips_and_never_renumbers(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('REELBYTES', 200, ['Content-Type' => 'video/mp4'])]);
        $this->stubSampler(2);
        $reel = $this->makeReel();

        app(EnrichmentPipeline::class)->run($reel, 'corr-k2');
        $firstIds = Keyframe::query()->where('owner_id', $reel->id)->pluck('id')->all();

        $run = app(EnrichmentPipeline::class)->run($reel, 'corr-k3');

        $this->assertSame('skipped:already-extracted', $run->stages['keyframes']);
        $this->assertSame($firstIds, Keyframe::query()->where('owner_id', $reel->id)->pluck('id')->all());
    }

    public function test_kill_switch_off_reports_disabled_and_writes_nothing(): void
    {
        config(['qds.enrichment.keyframes.enabled' => false]);
        Http::fake(['93.184.216.34/*' => Http::response('REELBYTES', 200, ['Content-Type' => 'video/mp4'])]);
        $this->stubSampler(2);
        $reel = $this->makeReel();

        $run = app(EnrichmentPipeline::class)->run($reel, 'corr-k4');

        $this->assertSame('skipped:disabled', $run->stages['keyframes']);
        $this->assertSame(0, Keyframe::query()->count());
    }

    public function test_carousel_images_persist_as_source_image_frames(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('IMAGEBYTES', 200, ['Content-Type' => 'image/jpeg'])]);
        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => Platform::Instagram]);
        $carousel = ContentItem::factory()->for($account, 'platformAccount')->create([
            'platform' => Platform::Instagram,
            'content_type' => ContentType::Carousel,
            'external_id' => 'car-1',
            'media_urls' => ['https://93.184.216.34/a.jpg', 'https://93.184.216.34/b.jpg'],
        ]);

        $run = app(EnrichmentPipeline::class)->run($carousel, 'corr-k5');

        $this->assertSame('completed:2 frame(s)', $run->stages['keyframes']);
        $frames = Keyframe::query()->where('owner_id', $carousel->id)->orderBy('ordinal')->get();
        $this->assertSame([KeyframeKind::SourceImage, KeyframeKind::SourceImage], $frames->pluck('kind')->all());
        $this->assertNull($frames[0]->timestamp_ms);
        // Same bytes Vision would see — one download, one checksum.
        $this->assertSame(hash('sha256', 'IMAGEBYTES'), $frames[0]->checksum);
    }

    public function test_ffmpeg_unavailable_reports_the_marker(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('REELBYTES', 200, ['Content-Type' => 'video/mp4'])]);
        $this->app->instance(KeyframeSampler::class, new class extends KeyframeSampler
        {
            public function isAvailable(): bool
            {
                return false;
            }
        });
        $reel = $this->makeReel();

        $run = app(EnrichmentPipeline::class)->run($reel, 'corr-k6');

        $this->assertSame('skipped:ffmpeg-unavailable', $run->stages['keyframes']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/KeyframePipelineTest.php`
Expected: ERROR — `stages['keyframes']` missing / classes not found.

- [ ] **Step 3: Implement `KeyframeWriter`**

```php
<?php

namespace App\Platform\Enrichment\Keyframes;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\Story;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;

/**
 * Persists extracted frames to the private media disk (the story-media
 * path convention) and inserts their rows. Idempotency is the DB's
 * (owner, ordinal) unique key — a concurrent duplicate insert loses
 * quietly and the winner's rows stand (extract-once; tier C embeddings
 * FK these ids and must never be orphaned by a re-run).
 */
class KeyframeWriter
{
    /**
     * @param  list<array{tempPath: string, timestampMs: int|null, kind: KeyframeKind, extension: string}>  $frames
     * @return int rows written
     */
    public function persist(ContentItem|Story $owner, array $frames): int
    {
        $disk = (string) config('qds.ingestion.media_disk');
        $written = 0;

        foreach (array_values($frames) as $ordinal => $frame) {
            $path = $this->pathFor($owner, $ordinal, $frame['extension']);
            $stream = @fopen($frame['tempPath'], 'rb');

            if ($stream === false) {
                continue;
            }

            try {
                Storage::disk($disk)->put($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            [$width, $height] = $this->dimensions($frame['tempPath']);

            try {
                Keyframe::query()->create([
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => $owner->id,
                    'ordinal' => $ordinal,
                    'timestamp_ms' => $frame['timestampMs'],
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'width' => $width,
                    'height' => $height,
                    'kind' => $frame['kind'],
                    'checksum' => (string) hash_file('sha256', $frame['tempPath']),
                    'provenance' => new Provenance($owner->provenance->source, CarbonImmutable::now(), 'keyframes-v1'),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue; // a concurrent pass already wrote this ordinal
            }

            $written++;
        }

        return $written;
    }

    /** tenants/{tenant}/keyframes/{platform}/{account}/{content|story}-{external}/{ordinal}.{ext} */
    private function pathFor(ContentItem|Story $owner, int $ordinal, string $extension): string
    {
        $ownerSegment = ($owner instanceof ContentItem ? 'content-' : 'story-').$owner->external_id;

        return sprintf(
            'tenants/%d/keyframes/%s/%d/%s/%d.%s',
            $owner->tenant_id,
            strtolower($owner->platform->value),
            $owner->platform_account_id,
            $ownerSegment,
            $ordinal,
            $extension,
        );
    }

    /** @return array{0: int|null, 1: int|null} best-effort (null when undecodable, e.g. HEIC) */
    private function dimensions(string $path): array
    {
        $info = @getimagesize($path);

        return is_array($info) ? [(int) $info[0], (int) $info[1]] : [null, null];
    }
}
```

- [ ] **Step 4: Implement `KeyframeExtractor`**

```php
<?php

namespace App\Platform\Enrichment\Keyframes;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Media\LocalMediaAsset;
use App\Platform\Enrichment\Media\MediaWorkspace;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Enums\Platform;

/**
 * The keyframes pipeline stage (sub-project B): turns the workspace's
 * media into persisted frames. Extract-once: an owner that already has
 * frames is never re-sampled (a forced re-extract is a future operator
 * command, not a pipeline behaviour). The returned string is the
 * EnrichmentRun stage summary — every skip is explainable.
 */
class KeyframeExtractor
{
    public function __construct(
        private readonly KeyframeSampler $sampler,
        private readonly KeyframeWriter $writer,
    ) {}

    public function enrich(ContentItem|Story $target, MediaWorkspace $workspace): string
    {
        $hasFrames = Keyframe::query()
            ->where('owner_type', $target->getMorphClass())
            ->where('owner_id', $target->id)
            ->exists();

        if ($hasFrames) {
            return 'skipped:already-extracted';
        }

        $video = $workspace->video();

        if ($video !== null) {
            if (! $this->sampler->isAvailable()) {
                return 'skipped:ffmpeg-unavailable';
            }

            $frames = $this->sampler->sample($video->tempPath);

            if ($frames === null || $frames === []) {
                return 'skipped:extraction-failed';
            }

            try {
                $written = $this->writer->persist($target, array_map(
                    static fn (SampledFrame $frame): array => [
                        'tempPath' => $frame->tempPath,
                        'timestampMs' => $frame->timestampMs,
                        'kind' => KeyframeKind::VideoSample,
                        'extension' => 'jpg',
                    ],
                    $frames,
                ));
            } finally {
                foreach ($frames as $frame) {
                    @unlink($frame->tempPath);
                }
            }

            return "completed:{$written} frame(s)";
        }

        $images = $workspace->images();

        if ($images !== []) {
            // A YouTube ContentItem's single image is the platform poster;
            // any other image (post/carousel/story) IS the frame.
            $kind = $target instanceof ContentItem && $target->platform === Platform::YouTube
                ? KeyframeKind::Thumbnail
                : KeyframeKind::SourceImage;

            $written = $this->writer->persist($target, array_map(
                fn (LocalMediaAsset $asset): array => [
                    'tempPath' => $asset->tempPath,
                    'timestampMs' => null,
                    'kind' => $kind,
                    'extension' => $this->extensionFor($asset->contentType),
                ],
                $images,
            ));

            return "completed:{$written} frame(s)";
        }

        return 'skipped:'.($workspace->markers()[0] ?? 'no-media');
    }

    /** Mirrors ArchiveStoryMediaJob::extensionFor's image branch. */
    private function extensionFor(?string $contentType): string
    {
        return match (true) {
            $contentType === null => 'jpg',
            str_contains($contentType, 'image/png') => 'png',
            str_contains($contentType, 'image/webp') => 'webp',
            str_contains($contentType, 'image/heic') => 'heic',
            default => 'jpg',
        };
    }
}
```

- [ ] **Step 5: Wire the pipeline stage**

In `EnrichmentPipeline` (Task 10 already added the workspace + `finally`): constructor adds `private readonly KeyframeExtractor $keyframes,` (import it). Insert directly after the recognition stage block:

```php
            if ((bool) config('qds.enrichment.keyframes.enabled')) {
                // Runs even with no Google provider configured — frames are
                // for tiers C/D, independent of the recognition providers.
                $stages['keyframes'] = $this->keyframes->enrich($target, $workspace);
            } else {
                $stages['keyframes'] = 'skipped:disabled';
            }
```

Update the pipeline docblock's stage list line to `hashtags → recognition → keyframes → text signals → sentiment → seeded attribution → EMV → reach`.

- [ ] **Step 6: Run test to verify it passes, then the enrichment suite**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/KeyframePipelineTest.php`
Expected: PASS (5 tests).
Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/`
Expected: green. Existing pipeline tests that assert the full `stages` array now see the extra `keyframes` key — update those assertions to include it (grep `stages` in tests/Feature/Enrichment).

- [ ] **Step 7: Commit**

```bash
git add app/Platform/Enrichment/Keyframes/KeyframeWriter.php app/Platform/Enrichment/Keyframes/KeyframeExtractor.php app/Platform/Enrichment/EnrichmentPipeline.php tests/Feature/Enrichment/KeyframePipelineTest.php
git commit -m "feat(enrichment): keyframes pipeline stage — persisted frames for every platform"
```

---

### Task 14: End-to-end flow tests (the spec §7 worked examples)

**Files:**
- Test: `tests/Feature/Enrichment/MediaResolutionFlowsTest.php` (create; no production code — this task locks the spec's promised end-states)

**Interfaces:**
- Consumes: everything above via `EnrichmentPipeline::run`.
- Produces: regression coverage for the four flagship flows.

- [ ] **Step 1: Write the tests**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\EnrichmentPipeline;
use App\Platform\Enrichment\Keyframes\KeyframeSampler;
use App\Platform\Enrichment\Keyframes\SampledFrame;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RecognitionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

class MediaResolutionFlowsTest extends TestCase
{
    use FakesProviderResponses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');
        $this->app->instance(KeyframeSampler::class, new class extends KeyframeSampler
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function sample(string $videoPath): ?array
            {
                $path = (string) tempnam(sys_get_temp_dir(), 'qds-stub-frame-');
                file_put_contents($path, 'FRAME');

                return [new SampledFrame($path, 500, 0)];
            }
        });
    }

    private function makeAccount(Platform $platform): PlatformAccount
    {
        return PlatformAccount::factory()->for(Creator::factory())->create(['platform' => $platform]);
    }

    public function test_tiktok_video_yields_downloaded_media_and_video_sample_keyframes(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('TIKTOKVIDEO', 200, ['Content-Type' => 'video/mp4'])]);
        $item = ContentItem::factory()->for($this->makeAccount(Platform::TikTok), 'platformAccount')->create([
            'platform' => Platform::TikTok,
            'content_type' => ContentType::Short,
            'external_id' => 'tt-1',
            'media_urls' => ['https://93.184.216.34/tt-1.mp4'],
        ]);

        $run = app(EnrichmentPipeline::class)->run($item, 'corr-f1');

        $this->assertSame('completed:1 frame(s)', $run->stages['keyframes']);
        $this->assertSame(KeyframeKind::VideoSample, Keyframe::query()->where('owner_id', $item->id)->firstOrFail()->kind);
    }

    public function test_youtube_yields_thumbnail_keyframe_and_transcript_spoken_brand(): void
    {
        Brand::factory()->create(['name' => 'Glossier']);
        $this->fakeProviderCredentials();
        Http::fake([
            'api.apify.com/v2/acts/*/run-sync-get-dataset-items*' => Http::response($this->fixture('youtube-transcript')),
            '93.184.216.34/*' => Http::response('THUMBNAIL', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $item = ContentItem::factory()->for($this->makeAccount(Platform::YouTube), 'platformAccount')->create([
            'platform' => Platform::YouTube,
            'content_type' => ContentType::Video,
            'external_id' => 'vid00000001',
            'media_urls' => ['https://93.184.216.34/maxres.jpg'],
        ]);

        $run = app(EnrichmentPipeline::class)->run($item, 'corr-f2');

        $this->assertSame('completed:1 frame(s)', $run->stages['keyframes']);
        $frame = Keyframe::query()->where('owner_id', $item->id)->firstOrFail();
        $this->assertSame(KeyframeKind::Thumbnail, $frame->kind);
        $this->assertSame(1, ContentTranscript::query()->where('content_item_id', $item->id)->count());
        $this->assertTrue(
            RecognitionDetection::query()
                ->where('content_item_id', $item->id)
                ->where('recognition_type', RecognitionType::SpokenBrand)
                ->where('detected_brand', 'Glossier')
                ->exists(),
        );
    }

    public function test_large_video_still_gets_keyframes_with_the_split_marker(): void
    {
        config([
            'services.google_video_intelligence.api_key' => 'test-vi-key',
            'qds.enrichment.recognition.inline_max_bytes' => 5,
        ]);
        Http::fake(['93.184.216.34/*' => Http::response('LARGEREELBYTES', 200, ['Content-Type' => 'video/mp4'])]);
        $item = ContentItem::factory()->for($this->makeAccount(Platform::Instagram), 'platformAccount')->create([
            'platform' => Platform::Instagram,
            'content_type' => ContentType::Reel,
            'external_id' => 'reel-big',
            'media_urls' => ['https://93.184.216.34/big.mp4'],
        ]);

        $run = app(EnrichmentPipeline::class)->run($item, 'corr-f3');

        $this->assertStringContainsString('recognition:whole-video-skipped-too-large', $run->stages['recognition']);
        $this->assertSame('completed:1 frame(s)', $run->stages['keyframes']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'videointelligence'));
    }

    public function test_story_video_samples_frames_from_the_archived_file(): void
    {
        Storage::disk('media')->put('tenants/1/stories/instagram/9/st-1.mp4', 'STORYVIDEO');
        $story = Story::factory()->for($this->makeAccount(Platform::Instagram), 'platformAccount')->create([
            'platform' => Platform::Instagram,
            'external_id' => 'st-1',
            'media_url' => 'tenants/1/stories/instagram/9/st-1.mp4',
        ]);

        $run = app(EnrichmentPipeline::class)->run($story, 'corr-f4');

        $this->assertSame('completed:1 frame(s)', $run->stages['keyframes']);
        $frame = Keyframe::query()->where('owner_type', $story->getMorphClass())->where('owner_id', $story->id)->firstOrFail();
        $this->assertSame(KeyframeKind::VideoSample, $frame->kind);
    }
}
```

(Adjust `Story::factory()` attributes to the factory's required fields, as in Task 8.)

- [ ] **Step 2: Run the tests**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/MediaResolutionFlowsTest.php`
Expected: PASS (4 tests) — everything was built in Tasks 1–13; failures here are integration bugs to fix now, not new features.

- [ ] **Step 3: Run the FULL suite**

Run: `XDEBUG_MODE=off vendor/bin/phpunit`
Expected: green.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Enrichment/MediaResolutionFlowsTest.php
git commit -m "test(enrichment): end-to-end media-resolution + keyframe flows (spec §7)"
```

---

### Task 15: Keyframe retention — settings column, resolver, `qds:prune-keyframes`, schedule

**Files:**
- Create: `database/migrations/2026_07_19_100003_add_keyframe_retention_to_monitoring_settings.php`
- Modify: `app/Modules/Monitoring/Models/MonitoringSetting.php` (fillable + casts: `keyframe_retention_days`)
- Modify: `app/Shared/Settings/MonitoringSettingsResolver.php` (add `keyframeRetentionDaysFor`)
- Create: `app/Platform/Enrichment/Console/PruneKeyframesCommand.php`
- Modify: `app/Platform/PlatformServiceProvider.php` (register the command)
- Modify: `routes/console.php` (daily schedule)
- Test: `tests/Feature/Enrichment/KeyframeRetentionTest.php` (create)

**Interfaces:**
- Consumes: `Keyframe` (Task 11), `MonitoringSettingsResolver::rowFor` (existing private memoized lookup), config `qds.enrichment.keyframes.retention_days`.
- Produces: `MonitoringSettingsResolver::keyframeRetentionDaysFor(int $tenantId): int` (0 = keep forever); command `qds:prune-keyframes` — per tenant, file-then-row, confirm-blob-gone-before-delete (the M31 pattern). No settings UI in B (the nullable column falls back to config until a later settings-page change exposes it).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Settings\MonitoringSettingsResolver;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KeyframeRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');
    }

    private function makeFrame(int $ageDays, string $path = 'tenants/1/keyframes/instagram/1/content-x/0.jpg'): Keyframe
    {
        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create();
        Storage::disk('media')->put($path, 'FRAME');

        $frame = Keyframe::query()->create([
            'owner_type' => $item->getMorphClass(),
            'owner_id' => $item->id,
            'ordinal' => 0,
            'timestamp_ms' => 0,
            'storage_disk' => 'media',
            'storage_path' => $path,
            'width' => 100,
            'height' => 100,
            'kind' => KeyframeKind::VideoSample,
            'checksum' => str_repeat('a', 64),
            'provenance' => new Provenance('SRC-apify-instagram-reel-scraper', CarbonImmutable::now(), 'keyframes-v1'),
        ]);
        $frame->timestamps = false;
        $frame->forceFill(['created_at' => CarbonImmutable::now()->subDays($ageDays)])->save();

        return $frame->refresh();
    }

    public function test_resolver_prefers_the_tenant_row_and_falls_back_to_config(): void
    {
        config(['qds.enrichment.keyframes.retention_days' => 180]);
        $tenantId = (int) $this->defaultTenant->id;

        $resolver = app(MonitoringSettingsResolver::class);
        $this->assertSame(180, $resolver->keyframeRetentionDaysFor($tenantId));

        MonitoringSetting::query()->create([
            'shipment_window_days' => 60,
            'engagement_trend_window_days' => 30,
            'story_retention_days' => 180,
            'communication_retention_days' => 0,
            'keyframe_retention_days' => 7,
        ]);

        $this->assertSame(7, app(MonitoringSettingsResolver::class)->keyframeRetentionDaysFor($tenantId));
    }

    public function test_expired_frames_lose_file_and_row_fresh_frames_survive(): void
    {
        config(['qds.enrichment.keyframes.retention_days' => 30]);
        $expired = $this->makeFrame(40);
        $fresh = $this->makeFrame(5, 'tenants/1/keyframes/instagram/1/content-y/0.jpg');

        $this->artisan('qds:prune-keyframes')->assertSuccessful();

        $this->assertDatabaseMissing('keyframes', ['id' => $expired->id]);
        Storage::disk('media')->assertMissing($expired->storage_path);
        $this->assertDatabaseHas('keyframes', ['id' => $fresh->id]);
        Storage::disk('media')->assertExists($fresh->storage_path);
    }

    public function test_zero_retention_keeps_everything(): void
    {
        config(['qds.enrichment.keyframes.retention_days' => 0]);
        $old = $this->makeFrame(400);

        $this->artisan('qds:prune-keyframes')->assertSuccessful();

        $this->assertDatabaseHas('keyframes', ['id' => $old->id]);
    }
}
```

Note: `MonitoringSetting::create` runs in the default tenant context (BelongsToTenant stamps it). If the model requires `updated_by` or other fields, satisfy them per its factory/fillable. If `Tests\TestCase` exposes the default tenant under a different property name than `$this->defaultTenant` (check `tests/TestCase.php`), use that name.

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/KeyframeRetentionTest.php`
Expected: ERROR — unknown column / method / command.

- [ ] **Step 3: Implement**

Migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table): void {
            // Null = tenant never chose → config default applies (ADR-0025
            // pattern); 0 = keep forever. No settings UI yet (sub-project B).
            $table->unsignedSmallInteger('keyframe_retention_days')->nullable()->after('story_retention_days');
        });

        DB::statement('ALTER TABLE monitoring_settings ADD CONSTRAINT monitoring_settings_keyframe_retention_check CHECK (keyframe_retention_days IS NULL OR keyframe_retention_days BETWEEN 0 AND 3650)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE monitoring_settings DROP CONSTRAINT IF EXISTS monitoring_settings_keyframe_retention_check');
        Schema::table('monitoring_settings', function (Blueprint $table): void {
            $table->dropColumn('keyframe_retention_days');
        });
    }
};
```

`MonitoringSetting.php`: add `'keyframe_retention_days',` to `$fillable` (after `'story_retention_days',`) and `'keyframe_retention_days' => 'integer',` to `casts()`.

`MonitoringSettingsResolver.php`: add after `storyRetentionDaysFor()`:

```php
    /** Keyframe retention for ONE tenant; 0 = keep forever (sub-project B). */
    public function keyframeRetentionDaysFor(int $tenantId): int
    {
        $row = $this->rowFor($tenantId);

        return max(0, $row->keyframe_retention_days ?? (int) config('qds.enrichment.keyframes.retention_days'));
    }
```

`app/Platform/Enrichment/Console/PruneKeyframesCommand.php`:

```php
<?php

namespace App\Platform\Enrichment\Console;

use App\Models\Tenant;
use App\Modules\Monitoring\Models\Keyframe;
use App\Shared\Settings\MonitoringSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Derived-media lifecycle (sub-project B, DP-005 retention limits):
 * persisted keyframes older than the per-tenant keep-time are deleted —
 * file first, row only once the blob is confirmed gone (the story-media
 * M31 pattern). Unlike stories, the ROW goes too: a keyframe row without
 * its file is meaningless to tiers C/D.
 */
class PruneKeyframesCommand extends Command
{
    protected $signature = 'qds:prune-keyframes';

    protected $description = 'Delete persisted keyframes past the retention window (DP-005)';

    public function handle(MonitoringSettingsResolver $settings): int
    {
        $pruned = 0;

        // ADR-0025: retention is per tenant. The scheduler runs tenant-less
        // (TenantScope is a no-op), so ownership is an EXPLICIT predicate.
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $retentionDays = $settings->keyframeRetentionDaysFor((int) $tenantId);

            if ($retentionDays <= 0) {
                continue; // this workspace keeps keyframes forever
            }

            $cutoff = CarbonImmutable::now()->subDays($retentionDays);

            Keyframe::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('created_at', '<', $cutoff)
                ->chunkById(200, function ($keyframes) use (&$pruned): void {
                    foreach ($keyframes as $keyframe) {
                        $disk = Storage::disk((string) $keyframe->storage_disk);
                        $path = (string) $keyframe->storage_path;

                        try {
                            $deleted = $disk->delete($path);
                        } catch (\Throwable) {
                            // Some disks throw instead of returning false.
                            $deleted = false;
                        }

                        // Row goes only once the blob is confirmed gone (M31).
                        if (! $deleted && $disk->exists($path)) {
                            continue;
                        }

                        $keyframe->delete();
                        $pruned++;
                    }
                });
        }

        $this->info("Pruned {$pruned} keyframes past their workspace's keep-time.");

        return self::SUCCESS;
    }
}
```

`PlatformServiceProvider.php`: add `PruneKeyframesCommand::class,` to the `$this->commands([...])` array (import `App\Platform\Enrichment\Console\PruneKeyframesCommand`).

`routes/console.php`: after the `qds:prune-story-media` schedule block:

```php
// Derived-media lifecycle (sub-project B): persisted keyframes past the
// per-tenant retention window are deleted — file first, then row (DP-005).
Schedule::command('qds:prune-keyframes')->daily();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/KeyframeRetentionTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_19_100003_add_keyframe_retention_to_monitoring_settings.php app/Modules/Monitoring/Models/MonitoringSetting.php app/Shared/Settings/MonitoringSettingsResolver.php app/Platform/Enrichment/Console/PruneKeyframesCommand.php app/Platform/PlatformServiceProvider.php routes/console.php tests/Feature/Enrichment/KeyframeRetentionTest.php
git commit -m "feat(enrichment): per-tenant keyframe retention + qds:prune-keyframes"
```

---

### Task 16: GDPR erasure covers keyframes + transcripts

**Files:**
- Modify: `app/Modules/CRM/Services/Gdpr/CreatorEraser.php`
- Test: `tests/Feature/Enrichment/KeyframeErasureTest.php` (create)

**Interfaces:**
- Consumes: `CreatorEraser::erase(Creator)` (existing), `Keyframe`, `ContentTranscript`.
- Produces: erasure counts gain `keyframes`, `content_transcripts`, `keyframe_files`; frame blobs are deleted AFTER commit (never before rows are durably gone).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Services\Gdpr\CreatorEraser;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KeyframeErasureTest extends TestCase
{
    use RefreshDatabase;

    public function test_erasure_removes_keyframe_rows_files_and_transcripts(): void
    {
        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->for($creator)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create();

        $path = "tenants/{$item->tenant_id}/keyframes/instagram/{$account->id}/content-x/0.jpg";
        Storage::disk('media')->put($path, 'FRAME');
        Keyframe::query()->create([
            'owner_type' => $item->getMorphClass(),
            'owner_id' => $item->id,
            'ordinal' => 0,
            'timestamp_ms' => 0,
            'storage_disk' => 'media',
            'storage_path' => $path,
            'width' => 100,
            'height' => 100,
            'kind' => KeyframeKind::VideoSample,
            'checksum' => str_repeat('a', 64),
            'provenance' => new Provenance('SRC-apify-instagram-reel-scraper', CarbonImmutable::now(), 'keyframes-v1'),
        ]);
        ContentTranscript::query()->create([
            'content_item_id' => $item->id,
            'language' => 'und',
            'text' => 'hello',
            'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
            'provenance' => new Provenance(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, CarbonImmutable::now(), 'youtube-transcript-v1'),
            'checksum' => hash('sha256', 'hello'),
            'fetched_at' => CarbonImmutable::now(),
        ]);

        $counts = app(CreatorEraser::class)->erase($creator);

        $this->assertSame(1, $counts['keyframes']);
        $this->assertSame(1, $counts['content_transcripts']);
        $this->assertSame(1, $counts['keyframe_files']);
        $this->assertSame(0, Keyframe::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ContentTranscript::query()->withoutGlobalScopes()->count());
        Storage::disk('media')->assertMissing($path);
    }
}
```

Note: `CreatorEraser::erase` refreshes materialized rollup views at the end; if the test DB lacks them under `RefreshDatabase`, mirror however the existing eraser tests handle that (find them with `grep -rln CreatorEraser tests/` and copy their setup).

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/KeyframeErasureTest.php`
Expected: FAIL — undefined array keys `keyframes` / `content_transcripts` / `keyframe_files`.

- [ ] **Step 3: Implement**

In `CreatorEraser.php` (imports: add `App\Modules\Monitoring\Models\ContentItem;` and `App\Modules\Monitoring\Models\Story;`):

1. Add a collector variable next to `$mediaPaths = [];`:

```php
        $keyframePaths = [];
```

and include it in the transaction closure's `use` list (`&$keyframePaths`).

2. Inside the transaction, right after the `$recognitionIds` collection block, gather the keyframe rows (paths BEFORE the rows go — files are deleted after commit):

```php
            $keyframeRows = ($contentIds === [] && $storyIds === []) ? [] : DB::table('keyframes')
                ->where(function ($q) use ($contentIds, $storyIds): void {
                    $q->where(function ($qq) use ($contentIds): void {
                        $qq->where('owner_type', (new ContentItem)->getMorphClass())->whereIn('owner_id', $contentIds);
                    })->orWhere(function ($qq) use ($storyIds): void {
                        $qq->where('owner_type', (new Story)->getMorphClass())->whereIn('owner_id', $storyIds);
                    });
                })
                ->get(['id', 'storage_path'])->all();
            $keyframePaths = array_column($keyframeRows, 'storage_path');
```

3. In the deletes section, directly after the `$counts['recognition_detections']` line:

```php
            $counts['keyframes'] = $this->deleteByIds('keyframes', array_map('intval', array_column($keyframeRows, 'id')));
            $counts['content_transcripts'] = $this->deleteWhereIn('content_transcripts', 'content_item_id', $contentIds);
```

4. After commit, next to the existing `media_files` line:

```php
        $counts['keyframe_files'] = $this->deleteFiles((string) config('qds.ingestion.media_disk'), $keyframePaths);
```

Also update the class docblock's inventory sentence to mention "keyframes + transcripts" among the erased monitoring artifacts.

- [ ] **Step 4: Run the test + the existing eraser tests**

Run: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Enrichment/KeyframeErasureTest.php` then `grep -rln CreatorEraser tests/ | xargs -I{} env XDEBUG_MODE=off vendor/bin/phpunit {}`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/CRM/Services/Gdpr/CreatorEraser.php tests/Feature/Enrichment/KeyframeErasureTest.php
git commit -m "feat(gdpr): erase keyframes + transcripts (rows in-transaction, blobs after commit)"
```

---

### Task 17: ADR-0028, data-source matrix amendment, deferred register

**Files:**
- Modify: `docs/05-decisions/decision-log.md` (append ADR-0028)
- Modify: `docs/40-integrations/00-data-source-matrix.md` (add the transcript provider row + TikTok field note, matching the file's existing table format)
- Modify: `docs/20-cross-cutting/01-deferred-register.md` (three entries, matching its existing format)

**Interfaces:** documentation only — no code, no tests.

- [ ] **Step 1: Append ADR-0028 to the decision log** (match the numbering/format of ADR-0027's entry):

```markdown
## ADR-0028 — Seeded-detection media resolution & keyframe sampling (sub-project B)

- **Status:** Accepted (2026-07-19)
- **Context:** Recognition was inert on TikTok/YouTube (watch-page URLs in `media_urls`), silently dropped video over the 20 MB inline cap, and analyzed no representative frames — blocking the visual tiers (C embeddings, D Gemini) of the seeded-detection modernization. Spec: `docs/superpowers/specs/2026-07-18-seeded-detection-media-resolution-design.md`.
- **Decision:**
  1. **TikTok** media resolves from the download-URL field the frozen `SRC-clockworks-tiktok-scraper` payload already carries (`mediaUrls[0]`, fallback `videoMeta.downloadAddr`). No provider change — a matrix clarification of which fields we read (same class as ADR-0017's input-only changes).
  2. **The provider set is amended with exactly one source:** `SRC-apify-youtube-transcript` (the `pintostudio~youtube-transcript-scraper` actor) supplying YouTube **captions text only** — never video or audio bytes. Kill-switched (`qds.ingestion.youtube_transcript.enabled`), cost-metered through the standard `ApifyClient`/ProviderCall telemetry, one run per new YouTube video. This supersedes ADR-0001/DP-006 for this single addition; everything else stays frozen. YouTube's visual signal is the Data-API max-res thumbnail — the only in-freeze visual; downloading YouTube video files (yt-dlp or downloader actors) is REJECTED for v1 (ToS) and recorded as deferred.
  3. **Keyframes are a persisted derived-media class:** deterministic even-interval ffmpeg samples (`N = clamp(ceil(duration/interval), min, max)`), stored on the private media disk under `tenants/{id}/keyframes/…` as polymorphic `keyframes` rows — the FK-able contract tiers C/D consume. They carry story-media-equivalent lifecycle: per-tenant retention (`keyframe_retention_days`, `qds:prune-keyframes`) and GDPR erasure (extends ADR-0013/ADR-0025).
  4. **No Google Cloud Storage in v1.** Video over the inline cap skips only the whole-video Video-Intelligence pass (`recognition:whole-video-skipped-too-large`) — keyframes still cover it; over the streaming download cap the media is skipped explainably (`media:too-large`). Moving Video Intelligence to a `gs://` input would reverse DP-005's inline-only doctrine and stand up a second storage backend — deferred.
- **Consequences:** TikTok gains full-video visual coverage at zero extra provider cost; YouTube gains a thumbnail frame + transcript-driven SPOKEN_BRAND; every platform yields a `KeyframeSet` for C/D; large video is explainable, never silently dropped. New deferred items: real YouTube video download, GCS whole-video Video Intelligence, scene-change sampling.
```

- [ ] **Step 2: Amend the data-source matrix.** In `docs/40-integrations/00-data-source-matrix.md`, add `SRC-apify-youtube-transcript` to the provider table (§3) in the file's existing row format — capability "YouTube captions/transcript text (SPOKEN_BRAND input)", authority ADR-0028 — and a note on the TikTok row that `media_urls` is populated from the actor's download-URL field (ADR-0028). Update the §6 extension-rule sentence to reference ADR-0028 as the amendment precedent if the file lists amendments.

- [ ] **Step 3: Add deferred-register entries.** In `docs/20-cross-cutting/01-deferred-register.md`, matching its existing entry format, add: (1) real YouTube video-file download (frame-level YouTube visual; ToS decision + ADR required); (2) GCS-URI whole-video Video Intelligence for over-cap video (first GCS bucket + DP-005 doctrine change); (3) scene-change keyframe sampling as an alternative `KeyframeSampler` mode.

- [ ] **Step 4: Commit**

```bash
git add docs/05-decisions/decision-log.md docs/40-integrations/00-data-source-matrix.md docs/20-cross-cutting/01-deferred-register.md
git commit -m "docs: ADR-0028 media resolution + keyframe sampling; matrix + deferred register"
```

---

## Final verification (after Task 17)

- [ ] Run the FULL suite: `XDEBUG_MODE=off vendor/bin/phpunit` — green, no skips beyond the usual ffmpeg-absent skips.
- [ ] `git log --oneline main..feat/seeded-detection-media` — one spec commit + one plan commit + the task commits above, no attribution trailers.
- [ ] Spot-check the kill switches: with `QDS_ENRICHMENT_KEYFRAMES_ENABLED=false` and `QDS_INGESTION_YOUTUBE_TRANSCRIPT_ENABLED=false`, an enrichment run's stages show `keyframes → skipped:disabled` and recognition shows `youtube-transcript:disabled`, with zero new HTTP calls and zero new rows — the true no-op guarantee.
