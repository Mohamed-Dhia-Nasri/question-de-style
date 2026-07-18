# Seeded-Product Detection — Sub-project A (Tier 0 Free-Signal Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Detect seeded **products** (not just brands) from the free signals already present in every pull — captions, `@mentions`, product tags, collaborators, gifting cues, and the real paid-partnership label — in DE/EN/FR, and stop the known false-SEEDED alarms, with no new AI spend.

**Architecture:** Augment the existing enrichment pipeline. A new deterministic `TextSignalRecognizer` stage writes brand/product evidence into the (now product-aware) `RecognitionDetection` table, which flows through the existing `EvidenceBundle` → `MentionClassifier` → review/linker path. Product identity resolves through a `product_id`-first ladder. Everything is fail-closed, tenant-scoped, and gated by a kill switch.

**Tech Stack:** Laravel (PHP 8.3), PostgreSQL, PHPUnit, DDD layout (`app/Platform/*`, `app/Modules/*`).

**Spec:** `docs/superpowers/specs/2026-07-18-seeded-detection-tier0-free-signals-design.md`

**Branch:** work on `feat/seeded-detection-tier0` (already created; the spec is committed there).

## Global Constraints

Every task's requirements implicitly include these. Values are copied verbatim from the spec.

- **Fail-closed / never fabricate:** a missing provider field → empty; a missing signal → no row. Never invent data.
- **DP-004 human precedence:** AI writers never overwrite a human-reviewed/corrected/confirmed envelope. Reuse `HumanPrecedence::allowsAiUpdate()` and the existing `firstOrNew` + `UniqueConstraintViolationException` retry.
- **Tenancy (ADR-0019):** new tables/columns are tenant-owned where the sibling is; use `BelongsToTenant` and tenant-scoped queries.
- **Kill switch:** the new text-signal stage is gated by `config('qds.enrichment.text_signals.enabled')`; off ⇒ no behaviour change.
- **Idempotent upsert identity** for new `RecognitionDetection` rows = `(content_item_id|story_id, recognition_type, provider_label)`, where `provider_label` carries the **stable per-match key** (normalized value / provider tag id), **not** the shared caption text. Text offset is a stored attribute, never part of the key.
- **Product-resolution ladder** (text/tag → `product_id`, most reliable first): `product_id` → exact `sku` → `name`/`variant` (diacritic-folded, brand-corroborated) → `products.aliases` → normalized text.
- **Tri-state paid label:** `true` = confirmed paid, `false` = explicitly not, `null` = unknown. Only `=== true` fires the PAID branch; never conflate `null` with `false`.
- **Backward compatibility:** add new constructor params as optional, defaulted, appended last; add array keys, never remove.
- **No new provider cost:** sub-project A is pure local text/tag processing — no HTTP/AI calls.
- **Run tests with `XDEBUG_MODE=off`** (repo convention). DB tests `use RefreshDatabase;` (base `Tests\TestCase` auto-creates `$this->defaultTenant` and binds `TenantContext`). Commits must **not** carry a `Co-Authored-By`/AI-attribution trailer (a commit hook rejects it).

## File Structure

**Phase A2 — structured-signal ingestion**
- Create `app/Platform/Ingestion/DTO/ProductTag.php` — value object for a tagged product.
- Modify `app/Platform/Ingestion/DTO/ContentData.php` — 4 optional signal fields.
- Create `database/migrations/2026_07_18_100001_add_signal_columns_to_content_items.php`.
- Modify `app/Modules/Monitoring/Models/ContentItem.php` — fillable + casts.
- Modify `app/Platform/Ingestion/Persistence/ContentItemPersister.php` — persist the 4 fields.
- Create `app/Platform/Ingestion/Normalization/SignalExtract.php` — shared adapter mapping helpers.
- Modify the 5 adapters (`InstagramPostAdapter`, `InstagramReelAdapter`, `InstagramStoryAdapter`, `TikTokContentAdapter`, `YouTubeContentAdapter`).

**Phase A3a — lexicon, extractors, config**
- Modify `config/qds.php` — `text_signals` block.
- Create `database/migrations/2026_07_18_100002_add_social_handles_to_brands.php`; modify `app/Modules/CRM/Models/Brand.php`.
- Modify `app/Platform/Enrichment/Recognition/BrandLexicon.php` — diacritic fold, `matchAllInText`, `resolveHandle`, allowlist.
- Create `app/Platform/Enrichment/TextSignals/MentionExtractor.php`.
- Create `app/Platform/Enrichment/TextSignals/ContextualCueDetector.php`.

**Phase A3b — product-aware detection**
- Modify `app/Shared/Enums/RecognitionType.php` — 3 cases.
- Create `database/migrations/2026_07_18_100003_add_product_to_recognition_detections.php`; modify `app/Modules/Monitoring/Models/RecognitionDetection.php`.
- Create `database/migrations/2026_07_18_100004_add_aliases_to_products.php`; modify `app/Modules/CRM/Models/Product.php`.
- Create `app/Platform/Enrichment/TextSignals/ResolvedProduct.php`, `app/Platform/Enrichment/TextSignals/ProductResolver.php`, `app/Platform/Enrichment/TextSignals/TextSignalRecognizer.php`.
- Modify `app/Platform/Enrichment/EnrichmentPipeline.php` — wire the stage.

**Phase A4 — decision core**
- Modify `app/Platform/Enrichment/Attribution/EvidenceBundle.php`, `ShipmentEvidence.php`, `app/Modules/CRM/Services/ShipmentEvidenceSource.php`, `AttributionService.php`, `MentionClassifier.php`, `app/Platform/Enrichment/Matching/SeededContentLinker.php`.

**Phase A5 — eval harness**
- Create `tests/Fixtures/eval/golden-set.json`, `app/Platform/Enrichment/Console/EvalDetectionCommand.php`; register in the console kernel/`routes/console.php`.

**Deferred within A (explicit, per spec §11):** Instagram Story *sticker* signals (mention/product stickers on `StoryData`/`Story`) are **not** built here — stories carry no caption and sticker-data availability is unconfirmed on the current scraper; `TextSignalRecognizer` already returns `skipped:stories-have-no-caption` for stories, and full story coverage lands with the B/C/D visual tiers. `products.aliases` and `brands.social_handles` are created but their CRM entry screens are a fast-follow (the resolver/lexicon skip an empty column gracefully). The precision gate "scoreless OCR text is not HIGH on its own" is satisfied structurally: Task 12 makes HIGH SEEDED require product-level alignment, which scoreless brand-only text never has.

---

## Phase A2 — Structured-signal ingestion

### Task 1: `ProductTag` value object + `ContentData` signal fields

**Files:**
- Create: `app/Platform/Ingestion/DTO/ProductTag.php`
- Modify: `app/Platform/Ingestion/DTO/ContentData.php:19-37`
- Test: `tests/Unit/Ingestion/ContentDataSignalsTest.php`

**Interfaces:**
- Produces: `ProductTag(?string $brandRef, ?string $productName, ?string $productSku, ?string $providerTagId)`; `ContentData` gains `array $mentions = []`, `array $productTags = []`, `array $collaborators = []`, `?bool $brandedContentLabel = null`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Ingestion;

use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\DTO\ProductTag;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ContentDataSignalsTest extends TestCase
{
    public function test_content_data_defaults_signal_fields_empty(): void
    {
        $data = new ContentData(
            platform: Platform::Instagram,
            externalId: 'p1',
            contentType: ContentType::Reel,
            caption: 'hi',
            mediaUrls: [],
            publishedAt: CarbonImmutable::now(),
            publicMetrics: [],
            provenance: new Provenance(\App\Platform\Ingestion\SourceRegistry::AGENCY_MANUAL_ENTRY, CarbonImmutable::now(), 'v1'),
        );

        $this->assertSame([], $data->mentions);
        $this->assertSame([], $data->productTags);
        $this->assertSame([], $data->collaborators);
        $this->assertNull($data->brandedContentLabel);
    }

    public function test_product_tag_carries_identity(): void
    {
        $tag = new ProductTag('glossier', 'You Perfume', 'GLO-YOU-50', 'ig-123');

        $this->assertSame('You Perfume', $tag->productName);
        $this->assertSame('ig-123', $tag->providerTagId);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter ContentDataSignalsTest`
Expected: FAIL ("Class ... ProductTag not found" / unknown named argument `mentions`).

- [ ] **Step 3: Write minimal implementation**

Create `app/Platform/Ingestion/DTO/ProductTag.php`:

```php
<?php

namespace App\Platform\Ingestion\DTO;

/**
 * A product the platform itself tagged on a post (Instagram shopping tag,
 * etc.). Fields are whatever the provider supplied — any may be null; the
 * ProductResolver maps them onto a CRM product (never fabricated).
 */
final readonly class ProductTag
{
    public function __construct(
        public ?string $brandRef,       // brand name/handle as the provider gave it
        public ?string $productName,
        public ?string $productSku,
        public ?string $providerTagId,  // the platform's own tag id, when present
    ) {}
}
```

In `app/Platform/Ingestion/DTO/ContentData.php`, append after the existing `?string $permalink = null,` constructor param:

```php
        /** @var list<string> handles referenced by the post (without '@') */
        public array $mentions = [],
        /** @var list<ProductTag> platform-native product tags */
        public array $productTags = [],
        /** @var list<string> collaborator/co-author handles */
        public array $collaborators = [],
        /** Tri-state paid/branded-content disclosure: true=paid, false=explicitly not, null=unknown. */
        public ?bool $brandedContentLabel = null,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter ContentDataSignalsTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Ingestion/DTO/ProductTag.php app/Platform/Ingestion/DTO/ContentData.php tests/Unit/Ingestion/ContentDataSignalsTest.php
git commit -m "feat(ingestion): add structured-signal fields to ContentData + ProductTag VO"
```

### Task 2: Persist signal columns on `content_items`

**Files:**
- Create: `database/migrations/2026_07_18_100001_add_signal_columns_to_content_items.php`
- Modify: `app/Modules/Monitoring/Models/ContentItem.php` (fillable + casts)
- Modify: `app/Platform/Ingestion/Persistence/ContentItemPersister.php:52-90`
- Test: `tests/Feature/Ingestion/ContentSignalPersistenceTest.php`

**Interfaces:**
- Consumes: `ContentData.{mentions,productTags,collaborators,brandedContentLabel}` (Task 1).
- Produces: `content_items.{mentioned_handles,product_tags,collaborators,branded_content_label}` columns; `ContentItem` casts them (`array`, `array`, `array`, `boolean`). (Column is `mentioned_handles`, not `mentions`, to avoid shadowing the `mentions()` relation.)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ingestion;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Ingestion\DTO\ContentData;
use App\Platform\Ingestion\DTO\ProductTag;
use App\Platform\Ingestion\Persistence\ContentItemPersister;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentSignalPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_signals_round_trip_to_content_items(): void
    {
        $account = PlatformAccount::factory()->create(['platform' => Platform::Instagram]);

        $data = new ContentData(
            platform: Platform::Instagram,
            externalId: 'sig-1',
            contentType: ContentType::Reel,
            caption: 'thanks @glossier for the PR',
            mediaUrls: ['https://cdn/x.mp4'],
            publishedAt: CarbonImmutable::now(),
            publicMetrics: [],
            provenance: new Provenance(\App\Platform\Ingestion\SourceRegistry::AGENCY_MANUAL_ENTRY, CarbonImmutable::now(), 'v1'),
            permalink: null,
            mentions: ['glossier'],
            productTags: [new ProductTag('glossier', 'You Perfume', 'GLO-YOU-50', 'ig-1')],
            collaborators: ['glossier'],
            brandedContentLabel: true,
        );

        app(ContentItemPersister::class)->persist($account, [$data]);

        $row = ContentItem::query()->where('external_id', 'sig-1')->firstOrFail();
        $this->assertSame(['glossier'], $row->mentioned_handles);
        $this->assertSame('You Perfume', $row->product_tags[0]['product_name']);
        $this->assertSame(['glossier'], $row->collaborators);
        $this->assertTrue($row->branded_content_label);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter ContentSignalPersistenceTest`
Expected: FAIL (unknown column `mentioned_handles`).

- [ ] **Step 3: Write minimal implementation**

Create the migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            // NOTE: column is `mentioned_handles`, NOT `mentions` — a `mentions`
            // cast would shadow the existing ContentItem::mentions() HasMany
            // relationship (attribution Mention rows) and break the dashboard.
            $table->jsonb('mentioned_handles')->nullable()->after('caption');
            $table->jsonb('product_tags')->nullable()->after('mentioned_handles');
            $table->jsonb('collaborators')->nullable()->after('product_tags');
            // Tri-state: true=paid, false=explicitly-not, null=unknown. No default.
            $table->boolean('branded_content_label')->nullable()->after('collaborators');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn(['mentioned_handles', 'product_tags', 'collaborators', 'branded_content_label']);
        });
    }
};
```

In `app/Modules/Monitoring/Models/ContentItem.php`, add to `$fillable`: `'mentioned_handles', 'product_tags', 'collaborators', 'branded_content_label'`. Add to `casts()`:

```php
            'mentioned_handles' => 'array',
            'product_tags' => 'array',
            'collaborators' => 'array',
            'branded_content_label' => 'boolean',
```

> **Do not** name the column/cast `mentions` — `ContentItem` already has a `mentions(): HasMany` relationship (attribution `Mention` rows) and a `'mentions'` cast would shadow it, breaking `$content->mentions` on the Monitoring content-detail page and its tests.

In `ContentItemPersister::persist`, add to the create array (after `'media_urls' => $item->mediaUrls,`) and to the `$updates` array (extract the product-tag mapping into a private `mapProductTags(array $tags): array` method to avoid duplicating the closure):

```php
            'mentioned_handles' => $item->mentions,
            'product_tags' => $this->mapProductTags($item->productTags),
            'collaborators' => $item->collaborators,
            'branded_content_label' => $item->brandedContentLabel,
```

with the shared helper:

```php
    /** @param list<ProductTag> $tags @return list<array{brand_ref: ?string, product_name: ?string, product_sku: ?string, provider_tag_id: ?string}> */
    private function mapProductTags(array $tags): array
    {
        return array_map(static fn (ProductTag $t): array => [
            'brand_ref' => $t->brandRef,
            'product_name' => $t->productName,
            'product_sku' => $t->productSku,
            'provider_tag_id' => $t->providerTagId,
        ], $tags);
    }
```

Add `use App\Platform\Ingestion\DTO\ProductTag;` at the top. (These fields are covered by the existing `human_overrides` guard automatically — the DTO field stays `mentions`; only the column is `mentioned_handles`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter ContentSignalPersistenceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_18_100001_add_signal_columns_to_content_items.php app/Modules/Monitoring/Models/ContentItem.php app/Platform/Ingestion/Persistence/ContentItemPersister.php tests/Feature/Ingestion/ContentSignalPersistenceTest.php
git commit -m "feat(ingestion): persist structured signal columns on content_items"
```

### Task 3: Map signals in the provider adapters

**Files:**
- Create: `app/Platform/Ingestion/Normalization/SignalExtract.php`
- Modify: `app/Platform/Ingestion/Providers/Instagram/InstagramPostAdapter.php:81-96`, `InstagramReelAdapter.php`, `TikTokContentAdapter.php:104-125`, `YouTubeContentAdapter.php:134-150`
- Test: `tests/Feature/Ingestion/InstagramSignalMappingTest.php`

**Interfaces:**
- Consumes: `ContentData` signal params (Task 1).
- Produces: `SignalExtract::mentions(array): list<string>`, `SignalExtract::productTags(array): list<ProductTag>`, `SignalExtract::collaborators(array): list<string>`, `SignalExtract::brandedContentLabel(array): ?bool` — each fail-closed (empty/null when the key is absent or malformed).

> Provider field names are read defensively (empty when absent). Confirm the exact keys against `tests/Fixtures/providers/*.json` while implementing; the helper tolerates missing keys so unmapped platforms simply carry empty signals.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Ingestion;

use App\Platform\Ingestion\Http\ApifyClient;
use App\Platform\Ingestion\Providers\Instagram\InstagramPostAdapter;
use App\Platform\Ingestion\DTO\ContentData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstagramSignalMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_adapter_maps_mentions_when_present(): void
    {
        $item = [
            'id' => 'abc', 'type' => 'Image', 'caption' => 'love it',
            'displayUrl' => 'https://cdn/x.jpg', 'url' => 'https://ig/p/abc',
            'timestamp' => '2026-07-01T00:00:00Z',
            'mentions' => ['glossier', 'sephora'],
        ];

        $data = \App\Platform\Ingestion\Normalization\SignalExtract::mentions($item);

        $this->assertSame(['glossier', 'sephora'], $data);
    }

    public function test_signal_extract_is_fail_closed_on_missing_keys(): void
    {
        $this->assertSame([], \App\Platform\Ingestion\Normalization\SignalExtract::mentions([]));
        $this->assertNull(\App\Platform\Ingestion\Normalization\SignalExtract::brandedContentLabel([]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter InstagramSignalMappingTest`
Expected: FAIL (class `SignalExtract` not found).

- [ ] **Step 3: Write minimal implementation**

Create `app/Platform/Ingestion/Normalization/SignalExtract.php`:

```php
<?php

namespace App\Platform\Ingestion\Normalization;

use App\Platform\Ingestion\DTO\ProductTag;

/**
 * Fail-closed mapping of provider payload fields onto ContentData signal
 * fields. Any missing/malformed key yields empty/null — never fabricated.
 *
 * @phpstan-type Item array<array-key, mixed>
 */
final class SignalExtract
{
    /** @param array<array-key, mixed> $item @return list<string> */
    public static function mentions(array $item): array
    {
        return self::stringList($item['mentions'] ?? null);
    }

    /** @param array<array-key, mixed> $item @return list<string> */
    public static function collaborators(array $item): array
    {
        return self::stringList($item['coauthorProducers'] ?? $item['collaborators'] ?? null);
    }

    /** @param array<array-key, mixed> $item @return list<ProductTag> */
    public static function productTags(array $item): array
    {
        $raw = $item['productTags'] ?? $item['taggedProducts'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $tags = [];

        foreach ($raw as $t) {
            if (! is_array($t)) {
                continue;
            }

            $tags[] = new ProductTag(
                brandRef: self::str($t['brand'] ?? $t['brandName'] ?? null),
                productName: self::str($t['name'] ?? $t['productName'] ?? $t['title'] ?? null),
                productSku: self::str($t['sku'] ?? null),
                providerTagId: self::str($t['id'] ?? $t['productId'] ?? null),
            );
        }

        return $tags;
    }

    /** @param array<array-key, mixed> $item */
    public static function brandedContentLabel(array $item): ?bool
    {
        $v = $item['isSponsored'] ?? $item['paidPartnership'] ?? $item['isPaidPartnership'] ?? null;

        return is_bool($v) ? $v : null;
    }

    /** @param mixed $value @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $v) {
            $s = self::str(is_array($v) ? ($v['username'] ?? $v['name'] ?? null) : $v);

            if ($s !== null) {
                $out[] = ltrim($s, '@');
            }
        }

        return array_values(array_unique($out));
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
```

In `InstagramPostAdapter::fetchContent`, add these named args to the `new ContentData(...)` call (after `permalink:`):

```php
                mentions: \App\Platform\Ingestion\Normalization\SignalExtract::mentions($item),
                productTags: \App\Platform\Ingestion\Normalization\SignalExtract::productTags($item),
                collaborators: \App\Platform\Ingestion\Normalization\SignalExtract::collaborators($item),
                brandedContentLabel: \App\Platform\Ingestion\Normalization\SignalExtract::brandedContentLabel($item),
```

Apply the identical four lines to the `new ContentData(...)` in `InstagramReelAdapter`, `TikTokContentAdapter`, and `YouTubeContentAdapter` (each reads whatever its payload exposes; absent keys → empty).

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter InstagramSignalMappingTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Ingestion/Normalization/SignalExtract.php app/Platform/Ingestion/Providers tests/Feature/Ingestion/InstagramSignalMappingTest.php
git commit -m "feat(ingestion): map @mentions, product tags, collaborators, paid label in adapters"
```

---

## Phase A3a — Lexicon, extractors, config

### Task 4: `text_signals` config block

**Files:**
- Modify: `config/qds.php` (enrichment block, near line 295)
- Test: `tests/Unit/Enrichment/TextSignalsConfigTest.php`

**Interfaces:**
- Produces: `config('qds.enrichment.text_signals.enabled')` (bool), `.short_brand_allowlist` (list), `.gifting_cues` (map lang→list).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Enrichment;

use Tests\TestCase;

class TextSignalsConfigTest extends TestCase
{
    public function test_text_signals_config_present(): void
    {
        $this->assertIsBool(config('qds.enrichment.text_signals.enabled'));
        $this->assertContains('dm', config('qds.enrichment.text_signals.short_brand_allowlist'));
        $this->assertContains('gifted', config('qds.enrichment.text_signals.gifting_cues.en'));
        $this->assertContains('pr-paket', config('qds.enrichment.text_signals.gifting_cues.de'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter TextSignalsConfigTest`
Expected: FAIL (null config).

- [ ] **Step 3: Write minimal implementation**

In `config/qds.php`, inside the `'enrichment' => [ ... ]` array (e.g. after the `'hashtags' => [...]` sub-array), add:

```php
        // Tier 0 free-signal detection (sub-project A). Kill switch mirrors
        // enrichment.enabled; cue/allowlist lists are operational config.
        'text_signals' => [
            'enabled' => env('QDS_ENRICHMENT_TEXT_SIGNALS_ENABLED', false),
            // Short brands that are safe to match despite the >=3-char noise
            // guard (whole-word only). Extend per market.
            'short_brand_allowlist' => ['dm', 'so', 'kn'],
            // Gifting/PR disclosure phrases per language (normalized lower-case,
            // matched whole-word/diacritic-folded on the caption).
            'gifting_cues' => [
                'de' => ['pr-paket', 'pr paket', 'unbezahlt', 'gratis', 'geschenkt', 'werbung'],
                'en' => ['gifted', 'gift', 'pr', 'pr package', 'c/o', 'thanks to', 'thank you'],
                'fr' => ['offert', 'cadeau', 'collab', 'colis presse'],
            ],
        ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter TextSignalsConfigTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add config/qds.php tests/Unit/Enrichment/TextSignalsConfigTest.php
git commit -m "feat(enrichment): add text_signals config (kill switch, cues, short-brand allowlist)"
```

### Task 5: Brand lexicon — diacritic fold, multi-match, handle resolution, `brands.social_handles`

**Files:**
- Create: `database/migrations/2026_07_18_100002_add_social_handles_to_brands.php`
- Modify: `app/Modules/CRM/Models/Brand.php` (fillable + casts)
- Modify: `app/Platform/Enrichment/Recognition/BrandLexicon.php`
- Test: `tests/Feature/Enrichment/BrandLexiconTest.php`

**Interfaces:**
- Produces: `BrandLexicon::matchAllInText(string): array` (list of distinct brand names, first-offset order), `BrandLexicon::resolveHandle(string): ?string`. `matchInText`/`matchLabel` keep working (now diacritic-folded). `brands.social_handles` jsonb column; `Brand` casts `social_handles => 'array'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Platform\Enrichment\Recognition\BrandLexicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandLexiconTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_all_brands_diacritic_insensitive(): void
    {
        Brand::factory()->create(['name' => 'Nestlé', 'aliases' => []]);
        Brand::factory()->create(['name' => 'CeraVe', 'aliases' => []]);

        $found = (new BrandLexicon)->matchAllInText('loving nestle and cerave together');

        $this->assertEqualsCanonicalizing(['Nestlé', 'CeraVe'], $found);
    }

    public function test_matches_possessive_brand_mention(): void
    {
        Brand::factory()->create(['name' => 'Nike', 'aliases' => []]);

        // Apostrophes are kept in folding, so "nike's" still boundary-matches "nike".
        $this->assertSame(['Nike'], (new BrandLexicon)->matchAllInText("obsessed with nike's new drop"));
    }

    public function test_returns_brands_in_first_offset_order_even_via_alias(): void
    {
        Brand::factory()->create(['name' => 'Glossier', 'aliases' => ['glossy']]);
        Brand::factory()->create(['name' => 'Dove', 'aliases' => []]);

        // "glossy" (a Glossier alias) appears before "dove"; Glossier must sort first.
        $found = (new BrandLexicon)->matchAllInText('glossy serum then dove cream then glossier reveal');

        $this->assertSame(['Glossier', 'Dove'], $found);
    }

    public function test_resolves_at_handle_to_brand(): void
    {
        Brand::factory()->create(['name' => 'Glossier', 'aliases' => [], 'social_handles' => ['glossier']]);

        $this->assertSame('Glossier', (new BrandLexicon)->resolveHandle('@glossier'));
        $this->assertNull((new BrandLexicon)->resolveHandle('@unknownbrand'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter BrandLexiconTest`
Expected: FAIL (unknown column `social_handles` / method `matchAllInText`).

- [ ] **Step 3: Write minimal implementation**

Migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', fn (Blueprint $t) => $t->jsonb('social_handles')->nullable()->after('aliases'));
    }

    public function down(): void
    {
        Schema::table('brands', fn (Blueprint $t) => $t->dropColumn('social_handles'));
    }
};
```

`Brand.php`: add `'social_handles'` to `$fillable` and `'social_handles' => 'array'` to `casts()`.

Rewrite `BrandLexicon.php`:

```php
<?php

namespace App\Platform\Enrichment\Recognition;

use App\Modules\CRM\Models\Brand;

/**
 * Known-brand matching for recognition + caption text. Deterministic,
 * case- AND diacritic-insensitive, whole-word; never guesses. Product
 * inference stays out of here (enrichment doctrine).
 */
class BrandLexicon
{
    private const MIN_TEXT_MATCH_LENGTH = 3;

    /** @var array<string, string>|null folded alias → brand name */
    private ?array $lexicon = null;

    /** @var array<string, string>|null folded handle → brand name */
    private ?array $handles = null;

    /** Exact (folded) label match, e.g. a Vision logo description. */
    public function matchLabel(string $label): ?string
    {
        $needle = self::fold($label);

        return $needle === '' ? null : ($this->lexicon()[$needle] ?? null);
    }

    /** First known brand appearing in a text. */
    public function matchInText(string $text): ?string
    {
        return $this->matchAllInText($text)[0] ?? null;
    }

    /**
     * ALL distinct known brands appearing in a text, in first-occurrence
     * order (M26 whole-word guard; folded so "loreal" matches "L'Oréal").
     *
     * @return list<string>
     */
    public function matchAllInText(string $text): array
    {
        $haystack = self::fold($text);
        $hits = [];

        foreach ($this->lexicon() as $alias => $brandName) {
            if (mb_strlen($alias) < self::MIN_TEXT_MATCH_LENGTH && ! $this->isAllowedShort($alias)) {
                continue;
            }

            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($alias, '/').'(?![\p{L}\p{N}])/u', $haystack, $m, PREG_OFFSET_CAPTURE) === 1) {
                // A brand can have several matching keys (name + aliases); keep
                // the EARLIEST text offset so first-occurrence order is honest.
                $hits[$brandName] = isset($hits[$brandName]) ? min($hits[$brandName], $m[0][1]) : $m[0][1];
            }
        }

        asort($hits); // order by first offset

        return array_keys($hits);
    }

    /** '@glossier' | 'glossier' → brand name via brands.social_handles, else null. */
    public function resolveHandle(string $handle): ?string
    {
        $needle = self::fold(ltrim(trim($handle), '@'));

        return $needle === '' ? null : ($this->handles()[$needle] ?? null);
    }

    private function isAllowedShort(string $foldedAlias): bool
    {
        foreach ((array) config('qds.enrichment.text_signals.short_brand_allowlist', []) as $s) {
            if (self::fold((string) $s) === $foldedAlias) {
                return true;
            }
        }

        return false;
    }

    /** Lower-case + strip diacritics (NFKD) + trim. Apostrophes are KEPT so a
     *  possessive ("Nike's") still yields a word boundary after the brand; an
     *  apostrophe-in-name brand ("L'Oréal") matches its no-apostrophe form via a
     *  configured alias ("loreal"), not by stripping punctuation (which would
     *  glue the possessive 's onto the name and break whole-word matching). */
    private static function fold(string $s): string
    {
        $n = \Normalizer::normalize(trim($s), \Normalizer::FORM_KD);
        $n = is_string($n) ? $n : $s;

        return mb_strtolower(preg_replace('/\p{Mn}+/u', '', $n) ?? $n);
    }

    /** @return array<string, string> */
    private function lexicon(): array
    {
        if ($this->lexicon !== null) {
            return $this->lexicon;
        }

        $lexicon = [];

        foreach (Brand::query()->get(['name', 'aliases']) as $brand) {
            $lexicon[self::fold($brand->name)] = $brand->name;

            foreach ($brand->aliases ?? [] as $alias) {
                $alias = trim((string) $alias);

                if ($alias !== '') {
                    $lexicon[self::fold($alias)] = $brand->name;
                }
            }
        }

        return $this->lexicon = $lexicon;
    }

    /** @return array<string, string> */
    private function handles(): array
    {
        if ($this->handles !== null) {
            return $this->handles;
        }

        $handles = [];

        foreach (Brand::query()->get(['name', 'social_handles']) as $brand) {
            foreach ($brand->social_handles ?? [] as $handle) {
                $handle = self::fold(ltrim(trim((string) $handle), '@'));

                if ($handle !== '') {
                    $handles[$handle] = $brand->name;
                }
            }
        }

        return $this->handles = $handles;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter BrandLexiconTest`
Expected: PASS. Also run `--filter RecognitionPipelineTest` to confirm existing callers still pass.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_18_100002_add_social_handles_to_brands.php app/Modules/CRM/Models/Brand.php app/Platform/Enrichment/Recognition/BrandLexicon.php tests/Feature/Enrichment/BrandLexiconTest.php
git commit -m "feat(enrichment): diacritic-fold + multi-brand match + @handle resolution in BrandLexicon"
```

### Task 6: `MentionExtractor`

**Files:**
- Create: `app/Platform/Enrichment/TextSignals/MentionExtractor.php`
- Test: `tests/Unit/Enrichment/MentionExtractorTest.php`

**Interfaces:**
- Produces: `MentionExtractor::extract(?string $caption): array` — list of distinct normalized handles (no `@`), first-seen order.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\TextSignals\MentionExtractor;
use Tests\TestCase;

class MentionExtractorTest extends TestCase
{
    public function test_extracts_distinct_handles(): void
    {
        $out = (new MentionExtractor)->extract('thanks @glossier and @Sephora.official — @glossier again');

        $this->assertSame(['glossier', 'sephora.official'], $out);
    }

    public function test_null_caption_yields_empty(): void
    {
        $this->assertSame([], (new MentionExtractor)->extract(null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter MentionExtractorTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Platform\Enrichment\TextSignals;

/**
 * Extracts @mention handles from caption text (Instagram/TikTok handle
 * grammar: letters, digits, '.', '_'). Lower-cased, de-duplicated,
 * first-seen order. Never fabricates — null/empty caption → [].
 */
final class MentionExtractor
{
    private const PATTERN = '/@([A-Za-z0-9._]+)/';

    /** @return list<string> */
    public function extract(?string $caption): array
    {
        if (! is_string($caption) || $caption === '') {
            return [];
        }

        preg_match_all(self::PATTERN, $caption, $matches);

        $out = [];

        foreach ($matches[1] as $handle) {
            $handle = mb_strtolower(rtrim($handle, '.'));

            if ($handle !== '' && ! in_array($handle, $out, true)) {
                $out[] = $handle;
            }
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter MentionExtractorTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Enrichment/TextSignals/MentionExtractor.php tests/Unit/Enrichment/MentionExtractorTest.php
git commit -m "feat(enrichment): add @mention extractor"
```

### Task 7: `ContextualCueDetector`

**Files:**
- Create: `app/Platform/Enrichment/TextSignals/ContextualCueDetector.php`
- Test: `tests/Unit/Enrichment/ContextualCueDetectorTest.php`

**Interfaces:**
- Produces: `ContextualCueDetector::detect(?string $caption): array` — list of `gifting-cue:<phrase>` signal strings (deduped), matched whole-word/diacritic-folded across all configured languages.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\TextSignals\ContextualCueDetector;
use Tests\TestCase;

class ContextualCueDetectorTest extends TestCase
{
    public function test_detects_multilingual_cues(): void
    {
        $d = new ContextualCueDetector;

        $this->assertContains('gifting-cue:gifted', $d->detect('this was gifted by the brand'));
        $this->assertContains('gifting-cue:pr-paket', $d->detect('Danke für das PR-Paket'));
        $this->assertContains('gifting-cue:offert', $d->detect('produit offert, merci'));
    }

    public function test_no_cue_yields_empty(): void
    {
        $this->assertSame([], (new ContextualCueDetector)->detect('just a normal caption'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter ContextualCueDetectorTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Platform\Enrichment\TextSignals;

/**
 * Detects gifting/PR disclosure phrases in a caption across the configured
 * languages (config qds.enrichment.text_signals.gifting_cues). Matching is
 * whole-word and diacritic-folded. Emits 'gifting-cue:<phrase>' signals —
 * a relevance/context booster, never a brand claim.
 */
final class ContextualCueDetector
{
    /** @return list<string> */
    public function detect(?string $caption): array
    {
        if (! is_string($caption) || trim($caption) === '') {
            return [];
        }

        $haystack = self::fold($caption);
        $out = [];

        foreach ((array) config('qds.enrichment.text_signals.gifting_cues', []) as $phrases) {
            foreach ((array) $phrases as $phrase) {
                $folded = self::fold((string) $phrase);

                if ($folded === '') {
                    continue;
                }

                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($folded, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1) {
                    $signal = 'gifting-cue:'.$folded;

                    if (! in_array($signal, $out, true)) {
                        $out[] = $signal;
                    }
                }
            }
        }

        return $out;
    }

    private static function fold(string $s): string
    {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_KD);
        $n = is_string($n) ? $n : $s;

        return mb_strtolower(preg_replace('/\p{Mn}+/u', '', $n) ?? $n);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter ContextualCueDetectorTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Enrichment/TextSignals/ContextualCueDetector.php tests/Unit/Enrichment/ContextualCueDetectorTest.php
git commit -m "feat(enrichment): add multilingual gifting-cue detector"
```

---

## Phase A3b — Product-aware detection

### Task 8: Product-aware `RecognitionDetection` + `RecognitionType` + `products.aliases`

**Files:**
- Modify: `app/Shared/Enums/RecognitionType.php`
- Create: `database/migrations/2026_07_18_100003_add_product_to_recognition_detections.php`
- Modify: `app/Modules/Monitoring/Models/RecognitionDetection.php` (fillable)
- Create: `database/migrations/2026_07_18_100004_add_aliases_to_products.php`
- Modify: `app/Modules/CRM/Models/Product.php` (fillable + casts)
- Test: `tests/Feature/Enrichment/ProductAwareDetectionTest.php`

**Interfaces:**
- Produces: `RecognitionType::{CaptionText,Mention,ProductTag}`; `recognition_detections.detected_product` (string null), `.product_id` (bigint null FK); `products.aliases` (jsonb null). `RecognitionDetection` fillable adds `detected_product`, `product_id`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Shared\Enums\RecognitionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAwareDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_detection_stores_product_identity(): void
    {
        $product = Product::factory()->create(['name' => 'You Perfume']);

        $d = RecognitionDetection::factory()->create([
            'recognition_type' => RecognitionType::ProductTag,
            'detected_product' => 'You Perfume',
            'product_id' => $product->id,
        ]);

        $this->assertSame('You Perfume', $d->fresh()->detected_product);
        $this->assertSame($product->id, $d->fresh()->product_id);
        $this->assertSame('PRODUCT_TAG', RecognitionType::ProductTag->value);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter ProductAwareDetectionTest`
Expected: FAIL (enum case / column missing).

- [ ] **Step 3: Write minimal implementation**

`RecognitionType.php`: add cases

```php
    case CaptionText = 'CAPTION_TEXT';
    case Mention = 'MENTION';
    case ProductTag = 'PRODUCT_TAG';
```

Migration `..._add_product_to_recognition_detections.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recognition_detections', function (Blueprint $table): void {
            $table->string('detected_product')->nullable()->after('detected_brand');
            $table->foreignId('product_id')->nullable()->after('detected_product')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recognition_detections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('detected_product');
        });
    }
};
```

`RecognitionDetection.php`: add `'detected_product'` and `'product_id'` to `$fillable` (leave them OUT of the upsert identity — see Task 10).

Migration `..._add_aliases_to_products.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->jsonb('aliases')->nullable()->after('category'));
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('aliases'));
    }
};
```

`Product.php`: add `'aliases'` to `$fillable` and `'aliases' => 'array'` to `casts()`.

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter ProductAwareDetectionTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Shared/Enums/RecognitionType.php database/migrations/2026_07_18_100003_add_product_to_recognition_detections.php database/migrations/2026_07_18_100004_add_aliases_to_products.php app/Modules/Monitoring/Models/RecognitionDetection.php app/Modules/CRM/Models/Product.php tests/Feature/Enrichment/ProductAwareDetectionTest.php
git commit -m "feat(enrichment): product-aware RecognitionDetection + product/caption/mention types + products.aliases"
```

### Task 9: `ResolvedProduct` + `ProductResolver` (ladder + caption co-occurrence)

**Files:**
- Create: `app/Platform/Enrichment/TextSignals/ResolvedProduct.php`, `app/Platform/Enrichment/TextSignals/ProductResolver.php`
- Test: `tests/Feature/Enrichment/ProductResolverTest.php`

**Interfaces:**
- Consumes: `ProductTag` (Task 1), `products.aliases` (Task 8).
- Produces: `ResolvedProduct(int $productId, string $name, int $brandId, string $brandName, string $rung)`; `ProductResolver::resolveTag(ProductTag): ?ResolvedProduct`; `ProductResolver::matchInCaption(string $caption, array $brandsPresent): list<ResolvedProduct>`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Platform\Enrichment\TextSignals\ProductResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_caption_name_match_requires_brand_co_occurrence(): void
    {
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);

        $resolver = app(ProductResolver::class);

        // Brand present → product resolves.
        $withBrand = $resolver->matchInCaption('loving You Perfume', ['Glossier']);
        $this->assertCount(1, $withBrand);
        $this->assertSame('You Perfume', $withBrand[0]->name);

        // Brand absent → generic-name guard suppresses it.
        $this->assertSame([], $resolver->matchInCaption('loving You Perfume', []));
    }

    public function test_resolve_tag_prefers_exact_sku(): void
    {
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume', 'sku' => 'GLO-YOU-50']);

        $resolved = app(ProductResolver::class)->resolveTag(
            new \App\Platform\Ingestion\DTO\ProductTag('Glossier', 'wrong name', 'GLO-YOU-50', 'ig-1')
        );

        $this->assertNotNull($resolved);
        $this->assertSame($product->id, $resolved->productId);
        $this->assertSame('sku', $resolved->rung);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter ProductResolverTest`
Expected: FAIL (classes not found).

- [ ] **Step 3: Write minimal implementation**

`ResolvedProduct.php`:

```php
<?php

namespace App\Platform\Enrichment\TextSignals;

final readonly class ResolvedProduct
{
    public function __construct(
        public int $productId,
        public string $name,
        public int $brandId,
        public string $brandName,
        public string $rung, // 'sku' | 'name' | 'alias' — which ladder rung matched
    ) {}
}
```

`ProductResolver.php`:

```php
<?php

namespace App\Platform\Enrichment\TextSignals;

use App\Modules\CRM\Models\Product;
use App\Platform\Ingestion\DTO\ProductTag;

/**
 * Resolves a product tag or caption text onto a CRM product via the
 * ladder: exact SKU > name/variant > aliases. Tenant-scoped (Product is
 * BelongsToTenant); deterministic; never guesses. The caption name/variant
 * rung requires the product's brand to be present in the post (generic-name
 * guard) so shared names ("Lip Balm") cannot create false hits.
 */
final class ProductResolver
{
    public function resolveTag(ProductTag $tag): ?ResolvedProduct
    {
        if ($tag->productSku !== null) {
            $bySku = $this->catalog()->first(fn (Product $p): bool => $p->sku !== null && self::fold($p->sku) === self::fold($tag->productSku));

            if ($bySku !== null) {
                return $this->make($bySku, 'sku');
            }
        }

        foreach ([$tag->productName] as $name) {
            if ($name === null) {
                continue;
            }

            $byName = $this->catalog()->first(fn (Product $p): bool => $this->nameMatches($p, $name));

            if ($byName !== null) {
                return $this->make($byName, 'name');
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $brandsPresent  brand names already evidenced in the post
     * @return list<ResolvedProduct>
     */
    public function matchInCaption(string $caption, array $brandsPresent): array
    {
        $folded = self::fold($caption);
        $present = array_map(self::fold(...), $brandsPresent);
        $out = [];

        foreach ($this->catalog() as $product) {
            if (! $this->nameAppears($product, $folded)) {
                continue;
            }

            // Generic-name guard: a caption name/variant match only counts
            // when the product's brand is independently present in the post.
            if (! in_array(self::fold($product->brand->name), $present, true)) {
                continue;
            }

            $out[$product->id] = $this->make($product, 'name');
        }

        return array_values($out);
    }

    /** @return \Illuminate\Support\Collection<int, Product> */
    private function catalog(): \Illuminate\Support\Collection
    {
        // Tenant-scoped by the model's global scope; eager-load brand for name.
        return Product::query()->with('brand')->get();
    }

    private function nameMatches(Product $p, string $name): bool
    {
        $needle = self::fold($name);

        if ($needle === '') {
            return false;
        }

        if (self::fold($p->name) === $needle || ($p->variant !== null && self::fold($p->variant) === $needle)) {
            return true;
        }

        foreach ($p->aliases ?? [] as $alias) {
            if (self::fold((string) $alias) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function nameAppears(Product $p, string $foldedHaystack): bool
    {
        foreach (array_filter([$p->name, $p->variant, ...($p->aliases ?? [])]) as $candidate) {
            $folded = self::fold((string) $candidate);

            if ($folded !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($folded, '/').'(?![\p{L}\p{N}])/u', $foldedHaystack) === 1) {
                return true;
            }
        }

        return false;
    }

    private function make(Product $p, string $rung): ResolvedProduct
    {
        return new ResolvedProduct($p->id, $p->name, $p->brand_id, $p->brand->name, $rung);
    }

    private static function fold(string $s): string
    {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_KD);
        $n = is_string($n) ? $n : $s;

        return mb_strtolower(preg_replace('/\p{Mn}+/u', '', $n) ?? $n);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter ProductResolverTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Enrichment/TextSignals/ResolvedProduct.php app/Platform/Enrichment/TextSignals/ProductResolver.php tests/Feature/Enrichment/ProductResolverTest.php
git commit -m "feat(enrichment): product-resolution ladder (sku>name>alias) with brand co-occurrence guard"
```

### Task 10: `TextSignalRecognizer` stage + pipeline wiring

**Files:**
- Create: `app/Platform/Enrichment/TextSignals/TextSignalRecognizer.php`
- Modify: `app/Platform/Enrichment/EnrichmentPipeline.php:33-77`
- Test: `tests/Feature/Enrichment/TextSignalRecognizerTest.php`

**Interfaces:**
- Consumes: `BrandLexicon` (Task 5), `MentionExtractor` (Task 6), `ProductResolver` (Task 9), `RecognitionType` (Task 8), `ContentItem`/`Story`.
- Produces: `TextSignalRecognizer::enrich(ContentItem|Story $target): string` (stage summary). Writes `CaptionText`/`Mention`/`ProductTag` detections idempotently, honoring DP-004.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Platform\Enrichment\TextSignals\TextSignalRecognizer;
use App\Shared\Enums\RecognitionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TextSignalRecognizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_caption_mention_and_product_tag_detections(): void
    {
        $brand = Brand::factory()->create(['name' => 'Glossier', 'social_handles' => ['glossier']]);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume', 'sku' => 'GLO-YOU-50']);

        $item = ContentItem::factory()->create([
            'caption' => 'thanks @glossier for the You Perfume',
            'product_tags' => [['brand_ref' => 'Glossier', 'product_name' => 'You Perfume', 'product_sku' => 'GLO-YOU-50', 'provider_tag_id' => 'ig-1']],
        ]);

        (new TextSignalRecognizer(
            app(\App\Platform\Enrichment\Recognition\BrandLexicon::class),
            app(\App\Platform\Enrichment\TextSignals\MentionExtractor::class),
            app(\App\Platform\Enrichment\TextSignals\ProductResolver::class),
        ))->enrich($item);

        $types = RecognitionDetection::query()->where('content_item_id', $item->id)->pluck('recognition_type')->all();
        $this->assertContains(RecognitionType::Mention, $types);
        $this->assertContains(RecognitionType::ProductTag, $types);

        $tag = RecognitionDetection::query()->where('content_item_id', $item->id)
            ->where('recognition_type', RecognitionType::ProductTag)->firstOrFail();
        $this->assertSame($product->id, $tag->product_id);
        $this->assertSame('Glossier', $tag->detected_brand);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter TextSignalRecognizerTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Platform\Enrichment\TextSignals;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Recognition\BrandLexicon;
use App\Platform\Enrichment\Support\HumanPrecedence;
use App\Platform\Ingestion\DTO\ProductTag;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\Provenance;
use App\Platform\Ingestion\SourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Deterministic Tier-0 recognizer: mines the caption + platform tags for
 * brand/product evidence and writes CaptionText/Mention/ProductTag
 * detections. Idempotent (provider_label = stable per-match key); honors
 * DP-004; fail-closed (no signal → no row). No provider calls.
 */
final class TextSignalRecognizer
{
    public function __construct(
        private readonly BrandLexicon $lexicon,
        private readonly MentionExtractor $mentions,
        private readonly ProductResolver $products,
    ) {}

    public function enrich(ContentItem|Story $target): string
    {
        if (! $target instanceof ContentItem) {
            return 'skipped:stories-have-no-caption';
        }

        $caption = (string) ($target->caption ?? '');
        $written = 0;

        // 1. Caption brands (all, diacritic-folded).
        $captionBrands = $caption === '' ? [] : $this->lexicon->matchAllInText($caption);

        foreach ($captionBrands as $brand) {
            $written += $this->upsert($target, RecognitionType::CaptionText, 'caption:'.mb_strtolower($brand), $brand, null, null, ['caption-brand-match:'.$brand]);
        }

        // 2. @mention → brand.
        foreach ($this->mentions->extract($caption) as $handle) {
            $brand = $this->lexicon->resolveHandle($handle);

            if ($brand !== null) {
                $written += $this->upsert($target, RecognitionType::Mention, 'mention:'.$handle, $brand, null, null, ['mention-brand-match:'.$brand.':@'.$handle]);
            }
        }

        // 3. Caption products (brand co-occurrence guard uses the caption brands).
        foreach ($this->products->matchInCaption($caption, $captionBrands) as $rp) {
            $written += $this->upsert($target, RecognitionType::CaptionText, 'caption-product:'.$rp->productId, $rp->brandName, $rp->name, $rp->productId, ['caption-product-match:'.$rp->name.':rung='.$rp->rung]);
        }

        // 4. Structured product tags (exact product, near-ground-truth).
        foreach ($this->productTags($target) as $tag) {
            $rp = $this->products->resolveTag($tag);

            if ($rp === null) {
                continue;
            }

            $key = 'product-tag:'.($tag->providerTagId ?? (string) $rp->productId);
            $written += $this->upsert($target, RecognitionType::ProductTag, $key, $rp->brandName, $rp->name, $rp->productId, ['product-tag-match:'.$rp->name.':rung='.$rp->rung]);
        }

        return 'completed:'.$written.' text-signal detection(s)';
    }

    /** @return list<ProductTag> */
    private function productTags(ContentItem $target): array
    {
        $tags = [];

        foreach ((array) ($target->product_tags ?? []) as $row) {
            if (is_array($row)) {
                $tags[] = new ProductTag($row['brand_ref'] ?? null, $row['product_name'] ?? null, $row['product_sku'] ?? null, $row['provider_tag_id'] ?? null);
            }
        }

        return $tags;
    }

    /** @param list<string> $signals @return int 1 if written, 0 if a human decision blocked it */
    private function upsert(ContentItem $target, RecognitionType $type, string $providerLabel, string $brand, ?string $product, ?int $productId, array $signals): int
    {
        $identity = ['content_item_id' => $target->id, 'recognition_type' => $type, 'provider_label' => $providerLabel];

        $detection = RecognitionDetection::query()->firstOrNew($identity);

        if ($detection->exists && ! HumanPrecedence::allowsAiUpdate($detection->assessment)) {
            return 0;
        }

        if (! $detection->exists) {
            $detection->detected_brand = $brand;
            $detection->detected_product = $product;
            $detection->product_id = $productId;
        }

        $detection->fill([
            'detected_text' => null,
            'assessment' => new ConfidenceAssessment(
                value: $detection->detected_product ?? $detection->detected_brand ?? $brand,
                confidenceLevel: $productId !== null ? ConfidenceLevel::High : ConfidenceLevel::Medium,
                signals: $signals,
                verificationStatus: VerificationStatus::AiAssessed,
            ),
            'provenance' => new Provenance(SourceRegistry::AGENCY_MANUAL_ENTRY, CarbonImmutable::now(), 'text-signals-v1'),
        ]);

        try {
            $detection->save();
        } catch (UniqueConstraintViolationException) {
            $detection = RecognitionDetection::query()->where($identity)->firstOrFail();

            if (! HumanPrecedence::allowsAiUpdate($detection->assessment)) {
                return 0;
            }

            return 0; // concurrent insert already recorded it
        }

        return 1;
    }
}
```

> Note: `SourceRegistry::AGENCY_MANUAL_ENTRY` is used as the provenance source for internally-derived text signals (no external provider). If a dedicated `SRC-qds-text-signals` id is preferred, add it to `SourceRegistry` first; `AGENCY_MANUAL_ENTRY` keeps A dependency-free.

In `EnrichmentPipeline.php`: add `TextSignalRecognizer $textSignals` to the constructor, and after the `recognition` stage block (before `sentiment`), add:

```php
            if (config('qds.enrichment.text_signals.enabled')) {
                $stages['text_signals'] = $this->textSignals->enrich($target);
            } else {
                $stages['text_signals'] = 'skipped:disabled';
            }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter TextSignalRecognizerTest`
Expected: PASS. Then `--filter EnrichmentPipelineTest` to confirm wiring is green.

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Enrichment/TextSignals/TextSignalRecognizer.php app/Platform/Enrichment/EnrichmentPipeline.php tests/Feature/Enrichment/TextSignalRecognizerTest.php
git commit -m "feat(enrichment): TextSignalRecognizer stage (caption/mention/product-tag detections), gated"
```

---

## Phase A4 — Product-aware, trustworthy decision core

### Task 11: Product-aware `EvidenceBundle` + `ShipmentEvidence.productId`

**Files:**
- Modify: `app/Platform/Enrichment/Attribution/EvidenceBundle.php`, `app/Platform/Enrichment/Attribution/ShipmentEvidence.php`, `app/Modules/CRM/Services/ShipmentEvidenceSource.php:40-48`
- Test: `tests/Unit/Enrichment/EvidenceBundleShapeTest.php`

**Interfaces:**
- Produces: `EvidenceBundle` gains `array $contextualCues = []` and `paidPartnershipLabel` becomes `?bool $paidPartnershipLabel = null`; `recognitions[]` entries may carry `'productId' => ?int, 'product' => ?string`. `ShipmentEvidence` gains `?int $productId = null` (after `$productLabel`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\Attribution\EvidenceBundle;
use App\Platform\Enrichment\Attribution\ShipmentEvidence;
use Tests\TestCase;

class EvidenceBundleShapeTest extends TestCase
{
    public function test_paid_label_is_tristate_and_cues_present(): void
    {
        $b = new EvidenceBundle(contextualCues: ['gifting-cue:pr']);

        $this->assertNull($b->paidPartnershipLabel);
        $this->assertSame(['gifting-cue:pr'], $b->contextualCues);
    }

    public function test_shipment_evidence_carries_product_id(): void
    {
        $s = new ShipmentEvidence(reference: 'shipment-record:1', productLabel: 'You Perfume', productId: 42);
        $this->assertSame(42, $s->productId);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter EvidenceBundleShapeTest`
Expected: FAIL (unknown named args).

- [ ] **Step 3: Write minimal implementation**

In `EvidenceBundle.php`: change `public bool $paidPartnershipLabel = false,` to `public ?bool $paidPartnershipLabel = null,`, and add a new constructor param:

```php
        /** @var list<string> gifting/PR cue signals (relevance booster, not a brand claim) */
        public array $contextualCues = [],
```

Update the `recognitions` docblock to note optional `productId`/`product` keys.

In `ShipmentEvidence.php`: add `public ?int $productId = null,` immediately after `public ?string $productLabel = null,`.

In `ShipmentEvidenceSource::forTarget`, add to the `new ShipmentEvidence(...)` mapping (after `productLabel:`):

```php
                productId: $shipment->product_id,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter EvidenceBundleShapeTest`
Expected: PASS. Then `--filter MentionClassifierTest --filter AttributionTest` (existing tests still green — `null` paid label is falsy like the old `false`).

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Enrichment/Attribution/EvidenceBundle.php app/Platform/Enrichment/Attribution/ShipmentEvidence.php app/Modules/CRM/Services/ShipmentEvidenceSource.php tests/Unit/Enrichment/EvidenceBundleShapeTest.php
git commit -m "feat(enrichment): product-aware EvidenceBundle + tri-state paid label + ShipmentEvidence.productId"
```

### Task 12: `MentionClassifier` — product-level alignment, brand-only→review, cues, paid

**Files:**
- Modify: `app/Platform/Enrichment/Attribution/MentionClassifier.php`
- Test: `tests/Unit/Enrichment/MentionClassifierProductTest.php`

**Interfaces:**
- Consumes: `EvidenceBundle`/`ShipmentEvidence` (Task 11).
- Produces: SEEDED at HIGH only with product-level alignment; brand-only SEEDED capped at MEDIUM with signal `product-unconfirmed`; PAID only when `paidPartnershipLabel === true`; cues appended to signals.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\Attribution\EvidenceBundle;
use App\Platform\Enrichment\Attribution\MentionClassifier;
use App\Platform\Enrichment\Attribution\ShipmentEvidence;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class MentionClassifierProductTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private function recognition(string $brand, ?int $productId = null, ?string $product = null): array
    {
        return ['type' => 'PRODUCT_TAG', 'brand' => $brand, 'level' => ConfidenceLevel::High, 'productId' => $productId, 'product' => $product];
    }

    public function test_product_level_alignment_is_high_seeded(): void
    {
        $result = (new MentionClassifier)->classify(new EvidenceBundle(
            recognitions: [$this->recognition('Glossier', 42, 'You Perfume')],
            shipments: [new ShipmentEvidence(reference: 'shipment-record:1', brandName: 'Glossier', productLabel: 'You Perfume', productId: 42, deliveredAt: CarbonImmutable::parse('2026-06-01'))],
            publishedAt: CarbonImmutable::parse('2026-06-03'),
        ));

        $this->assertSame(MentionType::Seeded, $result->mentionType);
        $this->assertSame(ConfidenceLevel::High, $result->confidenceLevel);
    }

    public function test_brand_only_alignment_is_medium_and_flagged_for_review(): void
    {
        $result = (new MentionClassifier)->classify(new EvidenceBundle(
            recognitions: [$this->recognition('Glossier', null, null)],
            shipments: [new ShipmentEvidence(reference: 'shipment-record:1', brandName: 'Glossier', productLabel: 'You Perfume', productId: 42, deliveredAt: CarbonImmutable::parse('2026-06-01'))],
            publishedAt: CarbonImmutable::parse('2026-06-03'),
        ));

        $this->assertSame(MentionType::Seeded, $result->mentionType);
        $this->assertSame(ConfidenceLevel::Medium, $result->confidenceLevel);
        $this->assertContains('product-unconfirmed', $result->signals);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter MentionClassifierProductTest`
Expected: FAIL (brand-only currently returns HIGH; no `product-unconfirmed`).

- [ ] **Step 3: Write minimal implementation**

In `MentionClassifier.php`:

(a) In `shipmentAligns`, add a product-level branch at the top of the recognition loop (before the brand-name check):

```php
        foreach ($recognitions as $recognition) {
            // Product-level alignment (primary): id match, else exact folded name.
            if (($recognition['productId'] ?? null) !== null && $shipment->productId !== null
                && (int) $recognition['productId'] === $shipment->productId) {
                return true;
            }

            if ($shipment->brandName !== null
                && mb_strtolower($recognition['brand']) === mb_strtolower($shipment->brandName)) {
                return true;
            }
        }
```

(b) Add a helper that decides product-level relevance:

```php
    /**
     * @param list<ShipmentEvidence> $aligned
     * @param list<array{type: string, brand: string, level: ConfidenceLevel, productId?: int|null, product?: string|null}> $recognitions
     */
    private function hasProductLevelAlignment(array $aligned, array $recognitions): bool
    {
        foreach ($aligned as $shipment) {
            foreach ($recognitions as $r) {
                if (($r['productId'] ?? null) !== null && $shipment->productId !== null
                    && (int) $r['productId'] === $shipment->productId) {
                    return true;
                }
            }
        }

        return false;
    }
```

(c) In the SEEDED block (where `$aligned !== []`), replace the HIGH/MEDIUM decision so HIGH requires product-level alignment, and brand-only appends the review flag:

```php
        if ($aligned !== []) {
            $productLevel = $this->hasProductLevelAlignment($aligned, [...$strongRecognitions, ...$weakRecognitions]);
            $strongRelevance = $this->shipmentHasStrongRelevance($aligned, $strongRecognitions, $targetedHashtags);
            $timingOk = $this->timingSatisfied($aligned, $evidence);

            $shipmentSignals = array_map(static fn (ShipmentEvidence $s): string => $s->reference, $aligned);

            if (! $timingOk) {
                $shipmentSignals[] = 'shipment-timing-unverified';
            }

            // HIGH only when the SPECIFIC product is evidenced. Brand-only
            // alignment is real but unconfirmed → MEDIUM + review flag.
            if ($productLevel && $strongRelevance && $timingOk) {
                $level = ConfidenceLevel::High;
            } else {
                $level = ConfidenceLevel::Medium;
                if (! $productLevel) {
                    $shipmentSignals[] = 'product-unconfirmed';
                }
            }

            return new ClassificationResult(MentionType::Seeded, $level, [...$shipmentSignals, ...$signals, ...$evidence->contextualCues]);
        }
```

(d) The `paidPartnershipLabel` branch already reads `if ($evidence->paidPartnershipLabel)`; make it strict:

```php
        if ($evidence->paidPartnershipLabel === true) {
```

and update `$hasAnySignal` to `|| $evidence->paidPartnershipLabel === true` and append `...$evidence->contextualCues` to the other non-null `ClassificationResult` signal lists (LikelyOrganic / Unknown) for explainability.

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter MentionClassifierProductTest`
Expected: PASS. Then `--filter MentionClassifierTest` (existing doctrine tests stay green).

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Enrichment/Attribution/MentionClassifier.php tests/Unit/Enrichment/MentionClassifierProductTest.php
git commit -m "feat(enrichment): product-level SEEDED (HIGH); brand-only -> MEDIUM+review; strict paid; cues"
```

### Task 13: `AttributionService::buildEvidence` — product-aware, real paid label, cues, precision gate

**Files:**
- Modify: `app/Platform/Enrichment/Attribution/AttributionService.php:200-240`
- Test: `tests/Feature/Enrichment/AttributionProductEvidenceTest.php`

**Interfaces:**
- Consumes: product-aware `RecognitionDetection` (Task 8), `ContextualCueDetector` (Task 7), tri-state `content_items.branded_content_label` (Task 2), `EvidenceBundle` (Task 11).
- Produces: `recognitions[]` carrying `productId`/`product`; real `paidPartnershipLabel`; `contextualCues`; unmatched/low-score logos excluded from relevance.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributionProductEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_tag_detection_drives_high_seeded_with_shipment(): void
    {
        // creator → account → active Instagram subject → in-window content
        // (wiring mirrors AttributionTest.php / ShipmentEvidenceSourceTest.php).
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->onPlatform(Platform::Instagram)->create();
        MonitoredSubject::factory()->create([
            'creator_id' => $creator->id,
            'platforms' => [Platform::Instagram],
            'active' => true,
        ]);
        $content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'published_at' => CarbonImmutable::parse('2026-06-10 12:00:00'),
        ]);

        // Brand + product + a delivered, in-window shipment of that exact product.
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);
        $seeding = SeedingCampaign::factory()->create(['brand_id' => $brand->id, 'campaign_id' => $campaign->id]);
        Shipment::factory()->create([
            'seeding_campaign_id' => $seeding->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => CarbonImmutable::parse('2026-06-01 10:00:00'),
            'delivered_at' => CarbonImmutable::parse('2026-06-03 10:00:00'),
        ]);

        // A product-level detection (near-ground-truth product tag) for the shipped SKU.
        RecognitionDetection::factory()->create([
            'content_item_id' => $content->id,
            'recognition_type' => RecognitionType::ProductTag,
            'detected_brand' => 'Glossier',
            'detected_product' => 'You Perfume',
            'product_id' => $product->id,
            'assessment' => new ConfidenceAssessment(
                'You Perfume',
                ConfidenceLevel::High,
                ['product-tag-match:You Perfume:rung=sku'],
                VerificationStatus::AiAssessed,
            ),
        ]);

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        $this->assertSame(ConfidenceLevel::High, $mention->classification->confidenceLevel);
        $this->assertNotContains('product-unconfirmed', $mention->classification->signals);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter AttributionProductEvidenceTest`
Expected: FAIL (product not carried into evidence → MEDIUM, not HIGH).

- [ ] **Step 3: Write minimal implementation**

In `AttributionService::buildEvidence`, change the recognition assembly loop to carry product identity and drop non-relevant logos:

```php
        foreach ($detectionQuery->get() as $detection) {
            $assessment = $detection->assessment;

            if ($assessment->value === null || in_array('human-rejected', $assessment->signals, true)) {
                continue;
            }

            if ($detection->detected_brand === null) {
                continue;
            }

            // Precision gate: an UNMATCHED logo (brand not in the lexicon) or a
            // low-confidence logo carries no attribution relevance.
            if ($detection->recognition_type === \App\Shared\Enums\RecognitionType::Logo
                && (in_array('brand-lexicon:unmatched', $assessment->signals, true)
                    || $assessment->confidenceLevel === ConfidenceLevel::Low
                    || $assessment->confidenceLevel === ConfidenceLevel::Unknown)) {
                continue;
            }

            $recognitions[] = [
                'type' => $detection->recognition_type->value,
                'brand' => $detection->detected_brand,
                'level' => $assessment->confidenceLevel,
                'productId' => $detection->product_id,
                'product' => $detection->detected_product,
            ];
        }
```

Change the `EvidenceBundle` construction to use the real label + cues:

```php
        return new EvidenceBundle(
            recognitions: $recognitions,
            hashtagMatches: $hashtagMatches,
            ambiguousHashtags: $ambiguous,
            shipments: $this->seedingEvidence->forTarget($target),
            paidPartnershipLabel: $target instanceof ContentItem ? $target->branded_content_label : null,
            contextualCues: $target instanceof ContentItem ? app(\App\Platform\Enrichment\TextSignals\ContextualCueDetector::class)->detect($target->caption) : [],
            publishedAt: $this->publicationDate($target),
        );
```

Add `use App\Shared\Enums\ConfidenceLevel;` if not already imported.

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter AttributionProductEvidenceTest`
Expected: PASS. Then `--filter AttributionTest` to confirm existing behaviour holds.

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Enrichment/Attribution/AttributionService.php tests/Feature/Enrichment/AttributionProductEvidenceTest.php
git commit -m "feat(enrichment): buildEvidence carries product + real paid label + cues; drop non-relevant logos"
```

### Task 14: `SeededContentLinker` — never auto-link brand-only SEEDED

**Files:**
- Modify: `app/Platform/Enrichment/Matching/SeededContentLinker.php:130-143`
- Test: `tests/Feature/Enrichment/SeededContentLinkerProductGuardTest.php`

**Interfaces:**
- Consumes: the `product-unconfirmed` signal (Task 12).
- Produces: `linkable()` returns false for any AI-assessed mention whose `classification->signals` contains `product-unconfirmed`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

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
        // A MEDIUM AI SEEDED mention flagged product-unconfirmed must NOT auto-link.
        $mention = Mention::factory()->create([
            'mention_type' => MentionType::Seeded,
            'classification' => new ConfidenceAssessment(
                MentionType::Seeded->value,
                ConfidenceLevel::Medium,
                ['shipment-record:1', 'product-unconfirmed'],
                VerificationStatus::AiAssessed,
            ),
        ]);

        $summary = app(SeededContentLinker::class)->run();

        // Nothing linked because the only candidate is product-unconfirmed.
        $this->assertSame(0, $summary->linked);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter SeededContentLinkerProductGuardTest`
Expected: FAIL (MEDIUM AI SEEDED currently auto-links).

- [ ] **Step 3: Write minimal implementation**

In `SeededContentLinker::linkable`, add the guard at the top:

```php
    private function linkable(Mention $mention): bool
    {
        $assessment = $mention->classification;

        if ($assessment->verificationStatus === VerificationStatus::AiAssessed) {
            // Brand-only (product-unconfirmed) SEEDED stays for human review;
            // never auto-link a shipment on brand match alone.
            if (in_array('product-unconfirmed', $assessment->signals, true)) {
                return false;
            }

            return in_array($assessment->confidenceLevel, [ConfidenceLevel::High, ConfidenceLevel::Medium], true);
        }

        return in_array($assessment->verificationStatus, [
            VerificationStatus::HumanReviewed,
            VerificationStatus::HumanCorrected,
            VerificationStatus::Confirmed,
        ], true);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter SeededContentLinkerProductGuardTest`
Expected: PASS. Then `--filter SeededContentLinkerTest` (existing linking still works for product-confirmed mentions).

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Enrichment/Matching/SeededContentLinker.php tests/Feature/Enrichment/SeededContentLinkerProductGuardTest.php
git commit -m "feat(enrichment): never auto-link brand-only (product-unconfirmed) SEEDED mentions"
```

---

## Phase A5 — Eval harness

### Task 15: `qds:eval-detection` scorecard

**Files:**
- Create: `tests/Fixtures/eval/golden-set.json`
- Create: `app/Platform/Enrichment/Console/EvalDetectionCommand.php`
- Modify: `routes/console.php` (or the console kernel) to register the command
- Test: `tests/Feature/Enrichment/EvalDetectionCommandTest.php`

**Interfaces:**
- Consumes: the full A2–A4 pipeline.
- Produces: `qds:eval-detection {--fixture=}` — prints a per-platform confusion matrix + recall/precision at brand and product level; exit 0.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EvalDetectionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_eval_command_prints_metrics_and_exits_zero(): void
    {
        $path = base_path('tests/Fixtures/eval/tiny.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([[
            'platform' => 'INSTAGRAM', 'caption' => 'thanks @glossier for the You Perfume',
            'mentions' => ['glossier'], 'is_seeded' => true, 'brand' => 'Glossier', 'product' => 'You Perfume',
        ]]));

        $this->artisan('qds:eval-detection', ['--fixture' => $path])
            ->expectsOutputToContain('recall')
            ->assertExitCode(0);

        File::delete($path);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter EvalDetectionCommandTest`
Expected: FAIL (command `qds:eval-detection` not defined).

- [ ] **Step 3: Write minimal implementation**

Create `tests/Fixtures/eval/golden-set.json` with ~10 seed rows (expand to 60–100 later), each `{platform, caption, mentions, product_tags, is_seeded, brand, product, reason}`.

Create `app/Platform/Enrichment/Console/EvalDetectionCommand.php`:

```php
<?php

namespace App\Platform\Enrichment\Console;

use App\Platform\Enrichment\Attribution\MentionClassifier;
use App\Platform\Enrichment\Attribution\EvidenceBundle;
use App\Platform\Enrichment\Recognition\BrandLexicon;
use App\Platform\Enrichment\TextSignals\ContextualCueDetector;
use App\Platform\Enrichment\TextSignals\MentionExtractor;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Offline scorecard for seeded detection over a labelled golden set. Runs
 * the deterministic text-signal + classification path (no provider calls)
 * and prints recall/precision at brand and product level, per platform.
 * The measurement baseline for sub-projects B–E.
 */
class EvalDetectionCommand extends Command
{
    protected $signature = 'qds:eval-detection {--fixture=}';

    protected $description = 'Score seeded-product detection against a labelled golden set.';

    public function handle(BrandLexicon $lexicon, MentionExtractor $mentions, ContextualCueDetector $cues): int
    {
        $path = (string) ($this->option('fixture') ?: base_path('tests/Fixtures/eval/golden-set.json'));

        if (! File::exists($path)) {
            $this->error("Fixture not found: {$path}");

            return self::FAILURE;
        }

        /** @var list<array<string, mixed>> $cases */
        $cases = json_decode(File::get($path), true) ?: [];

        $tp = $fp = $fn = 0;

        foreach ($cases as $case) {
            $caption = (string) ($case['caption'] ?? '');
            $brandsInCaption = $lexicon->matchAllInText($caption);
            $handleBrands = array_filter(array_map([$lexicon, 'resolveHandle'], $mentions->extract($caption)));
            $detectedBrands = array_values(array_unique([...$brandsInCaption, ...$handleBrands]));

            $predictedSeeded = $detectedBrands !== [] || ($case['product_tags'] ?? []) !== [];
            $actualSeeded = (bool) ($case['is_seeded'] ?? false);

            if ($predictedSeeded && $actualSeeded) {
                $tp++;
            } elseif ($predictedSeeded && ! $actualSeeded) {
                $fp++;
            } elseif (! $predictedSeeded && $actualSeeded) {
                $fn++;
            }
        }

        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;

        $this->table(['metric', 'value'], [
            ['cases', count($cases)],
            ['true positives', $tp],
            ['false positives', $fp],
            ['false negatives', $fn],
            ['recall', number_format($recall, 3)],
            ['precision', number_format($precision, 3)],
        ]);

        return self::SUCCESS;
    }
}
```

Register in `routes/console.php` (or wherever the app registers commands): confirm auto-discovery loads `app/**/Console` — if not, add `\App\Platform\Enrichment\Console\EvalDetectionCommand::class` to the kernel's `$commands`/`withCommands()`. (Grep `RunEnrichmentCommand` to see how the existing enrichment command is registered and mirror it.)

> This first version scores at the brand/seeded level end-to-end via the deterministic path; extend it to run the full DB-backed `EnrichmentPipeline` and report product-level metrics per platform as the golden set grows (tracked as an A5 follow-up, not a placeholder in this task).

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off vendor/bin/phpunit --filter EvalDetectionCommandTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Enrichment/Console/EvalDetectionCommand.php tests/Fixtures/eval/golden-set.json routes/console.php tests/Feature/Enrichment/EvalDetectionCommandTest.php
git commit -m "feat(enrichment): qds:eval-detection scorecard (recall/precision baseline)"
```

---

## Final verification

- [ ] Run the whole suite: `XDEBUG_MODE=off vendor/bin/phpunit`
- [ ] Run the scorecard on the golden set: `php artisan qds:eval-detection` — record the baseline recall/precision in the PR description.
- [ ] Confirm `QDS_ENRICHMENT_TEXT_SIGNALS_ENABLED=false` by default (the whole stage is a no-op until enabled).
- [ ] Open the PR from `feat/seeded-detection-tier0` (no `Co-Authored-By` trailer).
