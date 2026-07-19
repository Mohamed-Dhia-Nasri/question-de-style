# Visual Product Matching (Sub-project C) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give seeded-product detection "eyes": match sub-project B's keyframes against per-tenant product reference photos with Gemini Embedding 2 vectors in pgvector, emitting product-level `VISUAL_PRODUCT` detections through the existing evidence → classifier path, governed by a new AI budget subsystem.

**Architecture:** A new `visual_match` pipeline stage (between `keyframes` and `text_signals`) prepares frames locally (format/quality/near-dup), embeds them via an `EmbeddingProvider` abstraction (Vertex-platform EU multi-region, service-account auth), exact-scans candidate photo embeddings in pgvector, maps similarities onto AUTO/REVIEW/REJECT bands, and persists three layers: append-only `visual_match_runs` + `visual_match_candidates` (audit + D's escalation surface) and `recognition_detections` only for accepted bands. A platform-level `AiBudgetGuard` enforces per-post/daily/monthly budgets with priority tiers, read-only mode, and threshold alerts.

**Tech Stack:** Laravel 12, PostgreSQL 17 + pgvector (0.8.0 semantics; `pgvector/pgvector:pg17-bookworm` locally, Neon in prod), Gemini Embedding 2 (`gemini-embedding-2`, `:embedContent`, 3072 dims, EU multi-region `aiplatform.eu.rep.googleapis.com`), Livewire 4, PHPUnit.

**Authoritative spec:** `docs/superpowers/specs/2026-07-19-visual-product-matching-design.md` (approved; §18 = verified external facts with citations). On any conflict, the spec wins.

## Global Constraints

- Kill switch `qds.enrichment.visual_match.enabled` = `QDS_ENRICHMENT_VISUAL_MATCH_ENABLED`, default **false** — OFF must be a true no-op (evidence byte-identical, zero provider calls).
- Model `gemini-embedding-2` (GA), dimensions **3072**, ONE `:embedContent` call per image (multi-image fuses — never batch frames), price **$0.00012/image = 120 micro-USD** (`QDS_AI_EMBEDDING_PRICE_MICRO_USD`), auth = service-account Bearer token ONLY (API keys cannot call `embedContent`), location **`eu`**.
- pgvector: exact scan only, similarity = `1 - (a <=> b)`, no ANN index; pin **0.8.0 semantics** (Neon's PG17 version); vectors stored as text literals via `VectorLiteral` (no composer dependency).
- Tests: PHPUnit, base `Tests\TestCase`, `RefreshDatabase` where DB is touched, factories, run `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit [--filter …]` against real Postgres (needs the pgvector image from Task 1).
- Tenant tables: `BelongsToTenant`, NOT NULL `tenant_id`, composite `(fk, tenant_id)` FKs (reach_results pattern). `Model::shouldBeStrict` is ON in tests — never lazy-load relations.
- DP-004 everywhere: `HumanPrecedence::allowsAiUpdate` before every AI write to an existing envelope; `provider_label` immutable.
- Provider calls: `AiPayloadGuard::assertSafe` on every outbound payload; `ProviderCallRecorder` telemetry in the CALLERS (embedders — they hold the correlationId), never in the provider.
- Commits: conventional messages, **NEVER any Co-Authored-By / AI-attribution trailer** (a hook rejects it). Commit after every green task step that says so.
- Execute tasks strictly in order (1 → 24); each task ends green with a commit.

## Cross-task conventions (assembler notes — read before starting any task)

- `SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS` is added ONCE in **Task 6** (Tasks 7/9/13/18/21 consume it).
- The `config/qds.php` `visual_match` block grows across Tasks **7 → 9 → 12 → 19**; every config step is written create-or-verify — add only missing keys, never duplicate the block. Task 19's step states the §12 end-state.
- Enums `VisualMatchOutcome` / `VisualMatchBand` are created in **Task 14**; `PhotoViewLabel` in **Task 4**; consume, never re-create.
- `Tests\Support\FakeEmbeddingProvider` (3072-wide vectors) is created in **Task 9** and reused by Tasks 13/19/22.
- All factories live flat in `database/factories/` (`AppServiceProvider::guessFactoryNamesUsing`).
- Deferred-register ids: Task 24 claims **DEF-012…DEF-020**; renumber contiguously if another branch lands entries first.
- Budget semantics: per-post ceiling applies to Medium priority only; High stops only at global hard caps, read-only mode, or the circuit breaker (breaker consult lives in Task 19's matcher).
- Ops dashboard (Task 21) shows own-tenant itemization + anonymous platform totals only (ADR-0019 posture) — reflected in Task 24's ADR text.

---

### Task 1: pgvector foundation — docker image, extension migration, VectorLiteral

**Files:**
- Create: `database/migrations/2026_07_20_100001_enable_pgvector_extension.php`
- Create: `app/Platform/Enrichment/VisualMatch/Support/VectorLiteral.php`
- Modify: `docker-compose.yml` (line 16 `image: postgres:17`; header comment lines 3–6)
- Modify: `README.md` (Quickstart comment line 43; Testing section lines 100–104)
- Test: `tests/Unit/Enrichment/VectorLiteralTest.php` (create)
- Test: `tests/Feature/Enrichment/PgvectorRoundTripTest.php` (create)

**Interfaces:**
- Consumes: nothing (first task of the branch).
- Produces: `VectorLiteral::fromArray(array $vector): string` and `VectorLiteral::toArray(string $literal): array` (frozen contract signatures — Task 5's models, Task 9/13's embedders and Task 16's scorer consume them verbatim); the `vector` extension present in every migrated database (dev `qds`, test `qds_test`, Neon) — prerequisite for Task 5's `vector(3072)` columns; the verified similarity spelling `1 - (a <=> b)` proven against known geometry (Task 16 copies it).

- [ ] **Step 1: Swap the local Postgres image to pgvector (infra prerequisite — no test yet)**

`postgres:17` cannot install pgvector (verified in the spec's pre-design inspection); `pgvector/pgvector:pg17-bookworm` is built `FROM postgres:17-bookworm`, so entrypoint/PGDATA/env are identical and the existing `qds-pgdata` volume (with both `qds` and `qds_test`) keeps working.

In `docker-compose.yml` change line 4:

```yaml
# NOT containerized; this compose file provides a plain PostgreSQL 17 that is
```

to:

```yaml
# NOT containerized; this compose file provides a PostgreSQL 17 + pgvector that is
```

and change the service image (line 16) from `image: postgres:17` to:

```yaml
    # pgvector/pgvector:pg17-bookworm is built FROM postgres:17-bookworm —
    # identical entrypoint/PGDATA/env, so the existing qds-pgdata volume keeps
    # working. Adds the pgvector extension for visual product matching
    # (ADR-0029). Neon ships pgvector 0.8.0 on PG17: all vector code pins
    # itself to 0.8.0 semantics (exact scan, `<=>` cosine distance).
    image: pgvector/pgvector:pg17-bookworm
```

Recreate the container and verify the extension is available:

```bash
docker compose pull postgres
docker compose up -d --force-recreate --wait postgres
docker exec qds-postgres psql -U qds -c "SELECT default_version FROM pg_available_extensions WHERE name = 'vector';"
docker exec qds-postgres psql -U qds -lqt | grep qds_test
```

Expected: the first psql prints one version row (`0.8.x`); the second still lists `qds_test` (the volume survived). If `qds_test` were ever missing, recreate it per README: `docker exec qds-postgres psql -U qds -c "CREATE DATABASE qds_test;"`.

- [ ] **Step 2: Write the failing VectorLiteral unit test**

Create `tests/Unit/Enrichment/VectorLiteralTest.php`:

```php
<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class VectorLiteralTest extends TestCase
{
    public function test_formats_a_float_list_as_a_pgvector_literal(): void
    {
        $this->assertSame('[0.1,0.2,0.3]', VectorLiteral::fromArray([0.1, 0.2, 0.3]));
        $this->assertSame('[1,-2.5,0]', VectorLiteral::fromArray([1.0, -2.5, 0.0]));
    }

    public function test_parses_a_literal_back_to_floats(): void
    {
        $this->assertSame([0.1, 0.2, 0.3], VectorLiteral::toArray('[0.1,0.2,0.3]'));
        $this->assertSame([1.0, -2.5, 0.0], VectorLiteral::toArray(' [1, -2.5, 0] '));
    }

    public function test_round_trip_preserves_values(): void
    {
        $vector = [0.123456789012345, -1.0, 2.5e-8];

        $this->assertSame($vector, VectorLiteral::toArray(VectorLiteral::fromArray($vector)));
    }

    public function test_rejects_nan_components(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VectorLiteral::fromArray([0.1, NAN]);
    }

    public function test_rejects_infinite_components(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VectorLiteral::fromArray([INF]);
    }

    public function test_rejects_empty_vectors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VectorLiteral::fromArray([]);
    }

    public function test_rejects_malformed_literals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VectorLiteral::toArray('0.1,0.2');
    }

    public function test_rejects_non_numeric_components(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VectorLiteral::toArray('[0.1,abc]');
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VectorLiteralTest`
Expected: ERROR — `Class "App\Platform\Enrichment\VisualMatch\Support\VectorLiteral" not found`.

- [ ] **Step 4: Implement VectorLiteral**

Create `app/Platform/Enrichment/VisualMatch/Support/VectorLiteral.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Support;

use InvalidArgumentException;

/**
 * pgvector text-literal formatting/parsing — the ONLY vector serialization
 * in the codebase (no composer dependency, ADR-0029). pgvector accepts
 * `'[0.1,0.2,...]'::vector` as input and prints the same shape on `::text`
 * output; PHP 8's locale-independent shortest-round-trip float casting
 * keeps the conversion lossless at the precision similarity scoring needs.
 */
final class VectorLiteral
{
    /** @param list<float> $vector */
    public static function fromArray(array $vector): string
    {
        if ($vector === []) {
            throw new InvalidArgumentException('A pgvector literal needs at least one dimension.');
        }

        $parts = [];

        foreach ($vector as $component) {
            $component = (float) $component;

            if (! is_finite($component)) {
                throw new InvalidArgumentException('Vector components must be finite (NAN/INF rejected).');
            }

            $parts[] = (string) $component;
        }

        return '['.implode(',', $parts).']';
    }

    /** @return list<float> */
    public static function toArray(string $literal): array
    {
        $trimmed = trim($literal);

        if (! str_starts_with($trimmed, '[') || ! str_ends_with($trimmed, ']')) {
            throw new InvalidArgumentException("Not a pgvector literal: [{$literal}]");
        }

        $body = substr($trimmed, 1, -1);

        if (trim($body) === '') {
            throw new InvalidArgumentException('A pgvector literal needs at least one dimension.');
        }

        $components = [];

        foreach (explode(',', $body) as $component) {
            $component = trim($component);

            if ($component === '' || ! is_numeric($component)) {
                throw new InvalidArgumentException("Malformed vector component [{$component}].");
            }

            $components[] = (float) $component;
        }

        return $components;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VectorLiteralTest`
Expected: PASS (8 tests).

- [ ] **Step 6: Write the failing pgvector round-trip feature test**

Create `tests/Feature/Enrichment/PgvectorRoundTripTest.php` (real Postgres `qds_test`, `RefreshDatabase` — each test's probe table rolls back with its transaction):

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PgvectorRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_vector_extension_is_installed(): void
    {
        $row = DB::selectOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'");

        $this->assertNotNull(
            $row,
            'pgvector extension missing — recreate the local container from pgvector/pgvector:pg17-bookworm (README#testing)'
        );
        // Neon ships 0.8.0 on PG17; local may be newer. The code pins itself
        // to 0.8.0 semantics, so 0.8.0+ is the only requirement here.
        $this->assertTrue(version_compare($row->extversion, '0.8.0', '>='));
    }

    public function test_vectors_round_trip_through_a_vector_column(): void
    {
        DB::statement('CREATE TABLE vector_probe (id bigserial PRIMARY KEY, embedding vector(3) NOT NULL)');

        // Exactly float32-representable components: pgvector stores
        // single-precision, so these survive the round trip bit-exact.
        $vector = [0.25, -1.5, 0.5];
        DB::insert('INSERT INTO vector_probe (embedding) VALUES (?::vector)', [VectorLiteral::fromArray($vector)]);

        $row = DB::selectOne('SELECT embedding::text AS embedding FROM vector_probe');

        $this->assertSame($vector, VectorLiteral::toArray($row->embedding));
    }

    public function test_cosine_distance_operator_matches_known_geometry(): void
    {
        DB::statement('CREATE TABLE vector_probe (id bigserial PRIMARY KEY, embedding vector(3) NOT NULL)');
        DB::insert('INSERT INTO vector_probe (embedding) VALUES (?::vector), (?::vector)', [
            VectorLiteral::fromArray([1.0, 0.0, 0.0]),
            VectorLiteral::fromArray([0.0, 1.0, 0.0]),
        ]);

        // The pgvector-prescribed similarity spelling: `<=>` is cosine
        // DISTANCE, similarity = 1 - (a <=> b); ORDER BY <=> is nearest-first.
        // Same direction (scale-invariant) => 1.0; orthogonal => 0.0.
        $rows = DB::select(
            'SELECT 1 - (embedding <=> ?::vector) AS similarity FROM vector_probe ORDER BY embedding <=> ?::vector',
            [VectorLiteral::fromArray([2.0, 0.0, 0.0]), VectorLiteral::fromArray([2.0, 0.0, 0.0])]
        );

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(1.0, (float) $rows[0]->similarity, 1e-6);
        $this->assertEqualsWithDelta(0.0, (float) $rows[1]->similarity, 1e-6);
    }
}
```

- [ ] **Step 7: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter PgvectorRoundTripTest`
Expected: FAIL/ERROR — `test_the_vector_extension_is_installed` fails its `assertNotNull` (no migration created the extension yet) and the other two error with `SQLSTATE[42704]: Undefined object: 7 ERROR: type "vector" does not exist`.

- [ ] **Step 8: Write the extension migration**

Create `database/migrations/2026_07_20_100001_enable_pgvector_extension.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * pgvector (ADR-0029, sub-project C): vector similarity for visual
     * product matching. CREATE EXTENSION is per DATABASE, not per cluster,
     * so this one migration covers local dev (qds), the test database
     * (qds_test) and Neon alike; it installs into `public` — the hard-coded
     * search_path (config/database.php). On Neon no special role is needed.
     * Local docker must run pgvector/pgvector:pg17-bookworm (drop-in
     * postgres:17 replacement — see docker-compose.yml and README#testing).
     * Neon ships pgvector 0.8.0 on PG17: all vector code pins itself to
     * 0.8.0 semantics (exact scan, `<=>` cosine distance; no post-0.8.0
     * features).
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        // No CASCADE on purpose: if any vector column still exists (a later
        // migration not yet rolled back), this fails loudly instead of
        // silently dropping embedding data.
        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
```

- [ ] **Step 9: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter PgvectorRoundTripTest`
Expected: PASS (3 tests). (`RefreshDatabase` runs `migrate:fresh` against `qds_test`, which now executes the extension migration on the pgvector-capable image.)

- [ ] **Step 10: README note**

In `README.md`, change the Quickstart comment (line 43) from:

```
# 1. Local services (PostgreSQL 17 on 127.0.0.1:5433; --wait blocks until healthy)
```

to:

```
# 1. Local services (PostgreSQL 17 + pgvector on 127.0.0.1:5433; --wait blocks until healthy)
```

In the Testing section, after the paragraph ending `Tests never call external providers.` (line 104), append a new paragraph:

```markdown
The suite needs the `pgvector` extension in `qds_test`: the compose file's
`pgvector/pgvector:pg17-bookworm` image ships it, and the
`enable_pgvector_extension` migration runs `CREATE EXTENSION IF NOT EXISTS`
per database — so `qds_test` is covered automatically on `migrate:fresh`.
If your local container predates the image switch, recreate it once with
`docker compose pull postgres && docker compose up -d --force-recreate --wait postgres`
(the `qds-pgdata` volume and both databases survive — the image is a drop-in
`postgres:17`-bookworm derivative).
```

- [ ] **Step 11: Full-suite run**

The image swap + new migration touch every DB-backed test, so gate on the whole suite once.

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit`
Expected: all green — ≈1356 pre-existing tests + 11 new, 0 failures, 0 errors.

- [ ] **Step 12: Commit**

```bash
git add docker-compose.yml README.md database/migrations/2026_07_20_100001_enable_pgvector_extension.php app/Platform/Enrichment/VisualMatch/Support/VectorLiteral.php tests/Unit/Enrichment/VectorLiteralTest.php tests/Feature/Enrichment/PgvectorRoundTripTest.php
git commit -m "feat(platform): pgvector foundation — docker image, extension migration, VectorLiteral"
```

---

---

### Task 2: RecognitionType::VisualProduct + widened DB CHECK

**Files:**
- Create: `database/migrations/2026_07_20_100002_add_visual_product_to_recognition_type_check.php`
- Modify: `app/Shared/Enums/RecognitionType.php` (enum body, lines 9–18)
- Test: `tests/Feature/Enrichment/VisualProductRecognitionTypeTest.php` (create)

**Interfaces:**
- Consumes: nothing new (the CHECK-widening precedent is `database/migrations/2026_07_18_100003_add_product_to_recognition_detections.php`, whose current constraint holds exactly `IMAGE_TEXT_OCR, LOGO, SPOKEN_BRAND, ON_SCREEN_TEXT, CAPTION_TEXT, MENTION, PRODUCT_TAG`).
- Produces: `App\Shared\Enums\RecognitionType::VisualProduct = 'VISUAL_PRODUCT'` (frozen contract case — Tasks 18/19/20 consume it) and a `recognition_detections` CHECK that accepts it. `RecognitionDetection` needs no model change: `recognition_type` is already enum-cast and `detected_product`/`product_id` are already fillable (verified, `app/Modules/Monitoring/Models/RecognitionDetection.php` lines 47–76). Note for Task 24's docs: `DemoDataSeeder` (line 581) randomizes over `RecognitionType::cases()` and will now fabricate VISUAL_PRODUCT demo rows — accepted, documented there; no test exercises the seeder (verified).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Enrichment/VisualProductRecognitionTypeTest.php`:

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Shared\Enums\RecognitionType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VisualProductRecognitionTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_visual_product_case_exists_with_the_canonical_value(): void
    {
        $this->assertSame('VISUAL_PRODUCT', RecognitionType::VisualProduct->value);
        $this->assertSame(RecognitionType::VisualProduct, RecognitionType::from('VISUAL_PRODUCT'));
    }

    public function test_a_visual_product_detection_row_persists_and_reloads(): void
    {
        $product = Product::factory()->create();

        $detection = RecognitionDetection::factory()->create([
            'recognition_type' => RecognitionType::VisualProduct,
            // The stable DP-004 upsert identity Task 18's writer will use.
            'provider_label' => 'visual-product:'.$product->id,
            'detected_product' => $product->name,
            'product_id' => $product->id,
        ]);

        $reloaded = RecognitionDetection::query()->findOrFail($detection->id);

        $this->assertSame(RecognitionType::VisualProduct, $reloaded->recognition_type);
        $this->assertSame('visual-product:'.$product->id, $reloaded->provider_label);
        $this->assertSame($product->id, $reloaded->product_id);
    }

    public function test_the_widened_check_still_rejects_unknown_types(): void
    {
        $detection = RecognitionDetection::factory()->create();

        $this->expectException(QueryException::class);
        DB::statement(
            'UPDATE recognition_detections SET recognition_type = ? WHERE id = ?',
            ['HOLOGRAM', $detection->id]
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualProductRecognitionTypeTest`
Expected: ERROR ×2 — `Error: Undefined constant App\Shared\Enums\RecognitionType::VisualProduct` (the closed-set test passes already against the old CHECK).

- [ ] **Step 3: Add the enum case**

In `app/Shared/Enums/RecognitionType.php`, after `case ProductTag = 'PRODUCT_TAG';`:

```php
    /**
     * Sub-project C (ADR-0029): the seeded PRODUCT itself, recognized
     * visually — keyframe embeddings matched against the tenant's product
     * reference photos. Carries product_id; written by VisualMatchWriter
     * with provider_label 'visual-product:<productId>'.
     */
    case VisualProduct = 'VISUAL_PRODUCT';
```

- [ ] **Step 4: Run test to verify the DB CHECK now fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualProductRecognitionTypeTest`
Expected: 2 pass, 1 ERROR — `test_a_visual_product_detection_row_persists_and_reloads` throws `SQLSTATE[23514] … violates check constraint "recognition_detections_recognition_type_check"` (the PHP enum is ahead of the DB closed set — exactly what the widening migration fixes).

- [ ] **Step 5: Write the CHECK-widening migration**

Create `database/migrations/2026_07_20_100002_add_visual_product_to_recognition_type_check.php` (mirrors the `2026_07_18_100003` precedent):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ENUM-RecognitionType grows VISUAL_PRODUCT (sub-project C, ADR-0029):
     * a confident keyframe↔reference-photo embedding match, carrying
     * product_id. Widen the closed-set CHECK to match the PHP enum.
     *
     * Glossary amendment (docs/00-meta/03-glossary.md#enum-recognitiontype),
     * landing with sub-project C's docs task: VISUAL_PRODUCT — "the seeded
     * product itself was recognized visually in the post's keyframes via
     * embedding similarity against the tenant's reference photos".
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE recognition_detections DROP CONSTRAINT recognition_detections_recognition_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE recognition_detections ADD CONSTRAINT recognition_detections_recognition_type_check
                CHECK (recognition_type IN (
                    'IMAGE_TEXT_OCR','LOGO','SPOKEN_BRAND','ON_SCREEN_TEXT',
                    'CAPTION_TEXT','MENTION','PRODUCT_TAG','VISUAL_PRODUCT'
                ))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recognition_detections DROP CONSTRAINT recognition_detections_recognition_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE recognition_detections ADD CONSTRAINT recognition_detections_recognition_type_check
                CHECK (recognition_type IN (
                    'IMAGE_TEXT_OCR','LOGO','SPOKEN_BRAND','ON_SCREEN_TEXT',
                    'CAPTION_TEXT','MENTION','PRODUCT_TAG'
                ))
        SQL);
    }
};
```

- [ ] **Step 6: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualProductRecognitionTypeTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Guard run over the recognition/review surface**

The enum gained a case and the DB constraint changed — run every suite that touches recognition types, detections, and the review queue.

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter 'Recognition|ProductAwareDetection|ReviewWorkflow|Attribution|SeededContentLinker'`
Expected: all green, 0 failures (the classifier does no enum counting — verified spec fact §2.2 — so no existing test may break; if one does, STOP and re-read it rather than patching it).

- [ ] **Step 8: Commit**

```bash
git add app/Shared/Enums/RecognitionType.php database/migrations/2026_07_20_100002_add_visual_product_to_recognition_type_check.php tests/Feature/Enrichment/VisualProductRecognitionTypeTest.php
git commit -m "feat(enrichment): RecognitionType VISUAL_PRODUCT case + widened recognition_type check"
```

---

---

### Task 3: keyframes (id, tenant_id) unique + KeyframeFactory

**Files:**
- Create: `database/migrations/2026_07_20_100003_add_id_tenant_unique_to_keyframes.php`
- Create: `database/factories/KeyframeFactory.php`
- Modify: `app/Modules/Monitoring/Models/Keyframe.php` (imports lines 3–10; trait block lines 34–37)
- Test: `tests/Feature/Enrichment/KeyframeFactoryTest.php` (create)

**Interfaces:**
- Consumes: `Database\Factories\Concerns\ResolvesTenant::defaultTenantId(): int`; `Factory::guessFactoryNamesUsing` (app/Providers/AppServiceProvider.php:31) maps `Keyframe` → `Database\Factories\KeyframeFactory` (flat factories dir, house rule); no morph map exists, so `owner_type` stores FQCNs (`ContentItem::class`); `KeyframeKind::{VideoSample, Thumbnail, SourceImage}`; `SourceRegistry::APIFY_INSTAGRAM_REEL_SCRAPER` (already used as keyframe provenance in `KeyframeModelTest`).
- Produces: the `keyframes_id_tenant_id_unique` unique — the composite-FK anchor Task 5's `keyframe_embeddings (keyframe_id, tenant_id) REFERENCES keyframes (id, tenant_id)` requires; `Keyframe::factory()` with states `forOwner(Model $owner): static`, `thumbnail(): static`, `sourceImage(): static` — shared by Tasks 5, 12, 13, 14 tests (frames default to a fresh ContentItem owner, distinct ordinals per faker-unique, null `timestamp_ms` on the thumbnail/sourceImage states for the null-timestamp support-counting tests).

- [ ] **Step 1: Write the failing factory test**

Create `tests/Feature/Enrichment/KeyframeFactoryTest.php`:

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Shared\Enums\KeyframeKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeyframeFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_valid_tenant_stamped_frame(): void
    {
        $frame = Keyframe::factory()->create();

        $this->assertNotNull($frame->tenant_id);
        // No morph map — owner_type stores the FQCN (B's seam audit 4).
        $this->assertSame(ContentItem::class, $frame->owner_type);
        $this->assertSame(KeyframeKind::VideoSample, $frame->kind);
        $this->assertSame(64, strlen($frame->checksum));
        $this->assertSame(64, strlen($frame->source_checksum));

        $owner = ContentItem::query()->findOrFail($frame->owner_id);
        $this->assertSame($frame->tenant_id, $owner->tenant_id);
    }

    public function test_for_owner_attaches_multiple_frames_with_distinct_ordinals(): void
    {
        $item = ContentItem::factory()->create();

        $frames = Keyframe::factory()->count(3)->forOwner($item)->create();

        $this->assertSame([$item->id, $item->id, $item->id], $frames->pluck('owner_id')->all());
        // Distinct ordinals — the (owner_type, owner_id, ordinal) unique holds.
        $this->assertCount(3, array_unique($frames->pluck('ordinal')->all()));
        $this->assertTrue($frames->first()->owner()->is($item));
    }

    public function test_thumbnail_and_source_image_states_have_no_timeline_position(): void
    {
        $thumbnail = Keyframe::factory()->thumbnail()->create();
        $sourceImage = Keyframe::factory()->sourceImage()->create();

        $this->assertSame(KeyframeKind::Thumbnail, $thumbnail->kind);
        $this->assertNull($thumbnail->timestamp_ms);
        $this->assertSame(KeyframeKind::SourceImage, $sourceImage->kind);
        $this->assertNull($sourceImage->timestamp_ms);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter KeyframeFactoryTest`
Expected: ERROR ×3 — `BadMethodCallException: Call to undefined method App\Modules\Monitoring\Models\Keyframe::factory()` (the model lacks `HasFactory`).

- [ ] **Step 3: Implement — HasFactory on Keyframe + the factory**

In `app/Modules/Monitoring/Models/Keyframe.php`, add two imports (alphabetical order, after `use App\Shared\ValueObjects\Provenance;`):

```php
use Database\Factories\KeyframeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
```

and change the trait block:

```php
class Keyframe extends Model
{
    use BelongsToTenant;
```

to:

```php
class Keyframe extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<KeyframeFactory> */
    use HasFactory;
```

Create `database/factories/KeyframeFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Synthetic keyframe rows (sub-project B's artifact; DP-005). Default owner
 * is a fresh ContentItem; no morph map exists, so owner_type stores the
 * FQCN. Ordinals are faker-unique so multiple frames for ONE owner never
 * collide on the (owner_type, owner_id, ordinal) unique; pass an explicit
 * ordinal (and timestamp_ms) when a test needs deterministic positions.
 *
 * @extends Factory<Keyframe>
 */
class KeyframeFactory extends Factory
{
    use ResolvesTenant;

    protected $model = Keyframe::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ordinal = fake()->unique()->numberBetween(0, 9_999);

        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'owner_type' => ContentItem::class,
            'owner_id' => ContentItem::factory(),
            'ordinal' => $ordinal,
            'timestamp_ms' => $ordinal * 3_000,
            'storage_disk' => 'media',
            'storage_path' => 'tenants/test/keyframes/'.fake()->uuid().'.jpg',
            'width' => 1280,
            'height' => 720,
            'kind' => KeyframeKind::VideoSample,
            'checksum' => hash('sha256', fake()->uuid()),
            'source_checksum' => hash('sha256', fake()->uuid()),
            'provenance' => new Provenance(
                SourceRegistry::APIFY_INSTAGRAM_REEL_SCRAPER,
                CarbonImmutable::now(),
                'keyframes-v1',
            ),
        ];
    }

    /** Attach the frame to an existing owner (ContentItem or Story). */
    public function forOwner(Model $owner): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }

    /** A platform poster image — no position in a video timeline. */
    public function thumbnail(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => KeyframeKind::Thumbnail,
            'timestamp_ms' => null,
        ]);
    }

    /** A post/carousel/story image — the image IS the frame. */
    public function sourceImage(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => KeyframeKind::SourceImage,
            'timestamp_ms' => null,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter KeyframeFactoryTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Add the failing composite-FK-anchor test**

Append to `tests/Feature/Enrichment/KeyframeFactoryTest.php` (and add `use Illuminate\Support\Facades\DB;` to its imports):

```php
    public function test_keyframes_carry_the_id_tenant_unique_composite_fk_anchor(): void
    {
        // Task 5's keyframe_embeddings composite FK
        // (keyframe_id, tenant_id) → keyframes (id, tenant_id) needs this
        // unique (reach_results tenant-FK pattern, ADR-0019/0020).
        $this->assertNotNull(DB::selectOne(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'keyframes' AND indexname = 'keyframes_id_tenant_id_unique'"
        ));
    }
```

- [ ] **Step 6: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter KeyframeFactoryTest`
Expected: 3 pass, 1 FAIL — `test_keyframes_carry_the_id_tenant_unique_composite_fk_anchor` fails `assertNotNull` (the unique does not exist yet — verified against `2026_07_19_100002_create_keyframes_table.php`, which only creates the `(owner_type, owner_id, ordinal)` unique).

- [ ] **Step 7: Write the migration**

Create `database/migrations/2026_07_20_100003_add_id_tenant_unique_to_keyframes.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * (id, tenant_id) unique on keyframes — the composite-FK anchor the
     * keyframe_embeddings table (sub-project C, ADR-0029) needs: children
     * FK (keyframe_id, tenant_id) → keyframes (id, tenant_id) per the
     * reach_results tenant-FK pattern (ADR-0019/0020). Redundant with the
     * PK for lookups; it exists solely so Postgres accepts the composite
     * FK — the pattern every other tenant-owned parent already follows.
     */
    public function up(): void
    {
        Schema::table('keyframes', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::table('keyframes', function (Blueprint $table): void {
            $table->dropUnique(['id', 'tenant_id']);
        });
    }
};
```

- [ ] **Step 8: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter KeyframeFactoryTest`
Expected: PASS (4 tests).

- [ ] **Step 9: Guard run over the whole keyframe surface**

The model gained a trait and the table a unique — run every keyframe suite (model, pipeline, erasure, retention, sampler + the new factory test).

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter Keyframe`
Expected: all green, 0 failures (B's suites are untouched behaviourally — the new unique cannot conflict because `id` alone is already unique).

- [ ] **Step 10: Commit**

```bash
git add app/Modules/Monitoring/Models/Keyframe.php database/factories/KeyframeFactory.php database/migrations/2026_07_20_100003_add_id_tenant_unique_to_keyframes.php tests/Feature/Enrichment/KeyframeFactoryTest.php
git commit -m "feat(enrichment): keyframes (id, tenant_id) unique + KeyframeFactory"
```

---

### Task 4: Product reference photos — migration, model, factory, `PhotoViewLabel` enum

**Files:**
- Create: `database/migrations/2026_07_20_100004_create_product_reference_photos_table.php`
- Create: `app/Shared/Enums/PhotoViewLabel.php`
- Create: `app/Modules/CRM/Models/ProductReferencePhoto.php`
- Create: `database/factories/ProductReferencePhotoFactory.php`
- Modify: `app/Modules/CRM/Models/Product.php` (insert `referencePhotos()` after `shipments()`, lines 75–80; `HasMany` is already imported at line 13)
- Test: `tests/Feature/Crm/ProductReferencePhotoTest.php` (create)

**Interfaces:**
- Consumes: `BelongsToTenant` trait, `ResolvesTenant` factory concern, `Product` model + `ProductFactory` (existing); pre-existing `(id, tenant_id)` uniques on `products` and `users` (from `2026_07_11_100002_add_tenant_ownership_to_business_tables.php`, `products` and `users` are both in `COMPOSITE_PARENTS`).
- Produces (Tasks 5, 9, 10, 11, 15 rely on these):
  - `enum PhotoViewLabel: string { Front='front'; Back='back'; Side='side'; Packaging='packaging'; InUse='in_use'; Other='other'; }` (contract-frozen)
  - `ProductReferencePhoto` model — fillable `product_id, storage_disk, storage_path, view_label, checksum, width, height, uploaded_by`; cast `view_label => PhotoViewLabel`; `product(): BelongsTo<Product>`; `uploadedBy(): BelongsTo<User>`
  - `Product::referencePhotos(): HasMany<ProductReferencePhoto>` (photo-count badge in T10, blob collection in T11)
  - `ProductReferencePhotoFactory` (blob-less rows; `storage_disk` `'media'`, unique `storage_path`/`checksum`)
  - `product_reference_photos_id_tenant_unique UNIQUE (id, tenant_id)` — the composite-FK target Task 5 needs
  - DB guarantees: product delete cascades photo rows; composite FK `product_reference_photos_product_id_tenant_fk` rejects cross-tenant rows; CHECK `product_reference_photos_view_label_check` (NULL allowed)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Shared\Enums\PhotoViewLabel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Schema + model guarantees for tenant-uploaded product reference photos
 * (visual matching sub-project C, spec §4.1): tenant stamping, enum-backed
 * view labels with a DB CHECK backstop, product-delete row cascade, and the
 * composite tenant FK that keeps every photo inside its product's workspace.
 */
class ProductReferencePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_photos_are_tenant_stamped_with_reachable_relations(): void
    {
        $photo = ProductReferencePhoto::factory()->create(['view_label' => PhotoViewLabel::Packaging]);
        $product = Product::query()->findOrFail($photo->product_id);

        $this->assertSame($this->defaultTenant->id, $photo->tenant_id);
        $this->assertSame(PhotoViewLabel::Packaging, $photo->refresh()->view_label);
        $this->assertTrue($photo->product()->is($product));
        $this->assertTrue($product->referencePhotos()->whereKey($photo->id)->exists());
    }

    public function test_view_label_may_be_null(): void
    {
        $photo = ProductReferencePhoto::factory()->create(['view_label' => null]);

        $this->assertNull($photo->refresh()->view_label);
    }

    public function test_view_label_check_rejects_unknown_labels(): void
    {
        $product = Product::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('product_reference_photos')->insert([
            'tenant_id' => $this->defaultTenant->id,
            'product_id' => $product->id,
            'storage_disk' => 'media',
            'storage_path' => 'tenants/'.$this->defaultTenant->id.'/product-photos/'.$product->id.'/x.jpg',
            'view_label' => 'glamour',
            'checksum' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deleting_the_product_cascades_photo_rows(): void
    {
        $photo = ProductReferencePhoto::factory()->create();
        $survivor = ProductReferencePhoto::factory()->create();

        Product::query()->findOrFail($photo->product_id)->delete();

        $this->assertDatabaseMissing('product_reference_photos', ['id' => $photo->id]);
        $this->assertDatabaseHas('product_reference_photos', ['id' => $survivor->id]);
    }

    public function test_cross_tenant_photos_violate_the_composite_product_fk(): void
    {
        $product = Product::factory()->create();       // default tenant
        $other = $this->makeTenant('Other Workspace'); // context stays on default

        try {
            DB::table('product_reference_photos')->insert([
                'tenant_id' => $other->id,
                'product_id' => $product->id,
                'storage_disk' => 'media',
                'storage_path' => 'tenants/'.$other->id.'/product-photos/'.$product->id.'/x.jpg',
                'view_label' => 'front',
                'checksum' => str_repeat('b', 64),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail("A photo row pointing at another tenant's product must violate the composite FK.");
        } catch (QueryException $e) {
            $this->assertStringContainsString('product_reference_photos_product_id_tenant_fk', $e->getMessage());
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ProductReferencePhotoTest`
Expected: ERROR (5 tests) — `Error: Class "App\Modules\CRM\Models\ProductReferencePhoto" not found`.

- [ ] **Step 3: Implement migration, enum, model, factory, Product relation**

`database/migrations/2026_07_20_100004_create_product_reference_photos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-uploaded product reference photos (sub-project C, spec §4.1) —
     * the catalog side of visual product matching. Tenant-owned (ADR-0019)
     * per the reach_results composite-FK pattern; UNIQUE (id, tenant_id) so
     * product_photo_embeddings can compose-FK to it. Blob deletion is
     * app-managed (collect paths in-transaction, rows cascade, files after
     * commit — house order); cap/mime/size rules live in the upload
     * component, not the DDL.
     */
    public function up(): void
    {
        Schema::create('product_reference_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('storage_disk', 50);
            // tenants/{tenant}/product-photos/{product}/{uuid}.{ext} on the private media disk.
            $table->string('storage_path', 500);
            $table->string('view_label', 20)->nullable();
            // sha256 of the stored bytes.
            $table->char('checksum', 64);
            // Best-effort image metadata (getimagesize; null when undecodable).
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Per-product listing + the T10 photo-count badge.
            $table->index('product_id');
        });

        // Closed label set mirroring PhotoViewLabel; NULL passes (label optional).
        DB::statement("ALTER TABLE product_reference_photos ADD CONSTRAINT product_reference_photos_view_label_check CHECK (view_label IN ('front', 'back', 'side', 'packaging', 'in_use', 'other'))");

        // Composite-parent target so product_photo_embeddings can FK (photo_id, tenant_id).
        DB::statement('ALTER TABLE product_reference_photos ADD CONSTRAINT product_reference_photos_id_tenant_unique UNIQUE (id, tenant_id)');

        // The photo must belong to the same tenant as its product; CASCADE on
        // the composite too so a product delete is order-independent across
        // both FKs. Uploader stamp follows the reach_configurations audit
        // pattern (plain FK null-on-delete + composite tenant check).
        DB::statement('ALTER TABLE product_reference_photos ADD CONSTRAINT product_reference_photos_product_id_tenant_fk FOREIGN KEY (product_id, tenant_id) REFERENCES products (id, tenant_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE product_reference_photos ADD CONSTRAINT product_reference_photos_uploaded_by_tenant_fk FOREIGN KEY (uploaded_by, tenant_id) REFERENCES users (id, tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reference_photos');
    }
};
```

`app/Shared/Enums/PhotoViewLabel.php`:

```php
<?php

namespace App\Shared\Enums;

/**
 * Which product view a reference photo shows (visual matching, sub-project
 * C). Optional metadata for the management UI's diverse-views guidance —
 * matching itself treats all views equally (best photo per frame wins).
 */
enum PhotoViewLabel: string
{
    case Front = 'front';
    case Back = 'back';
    case Side = 'side';
    case Packaging = 'packaging';
    case InUse = 'in_use';
    case Other = 'other';
}
```

`app/Modules/CRM/Models/ProductReferencePhoto.php`:

```php
<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Shared\Enums\PhotoViewLabel;
use App\Shared\Tenancy\BelongsToTenant;
use Database\Factories\ProductReferencePhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ENT-ProductReferencePhoto — a tenant-uploaded catalog photo of a product
 * (visual matching sub-project C, spec §4.1). Min 1 to be matchable,
 * recommended 3–5 diverse views, hard cap 8 (enforced in the upload
 * component). Files live on the private media disk under
 * tenants/{tenant}/product-photos/{product}/…; blob deletion is app-managed
 * (rows cascade at the DB, files after commit). Tenant CATALOG data:
 * creator-GDPR erase never touches it; no retention sweep applies.
 *
 * Write-owner: Module 3 CRM (ownership matrix). Derived embeddings live in
 * Monitoring (ProductPhotoEmbedding) and FK here — this model deliberately
 * has no reverse relation so CRM never depends on Monitoring.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $product_id
 * @property string $storage_disk
 * @property string $storage_path
 * @property PhotoViewLabel|null $view_label
 * @property string $checksum sha256 of the stored bytes
 * @property int|null $width
 * @property int|null $height
 * @property int|null $uploaded_by
 */
class ProductReferencePhoto extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProductReferencePhotoFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'storage_disk',
        'storage_path',
        'view_label',
        'checksum',
        'width',
        'height',
        'uploaded_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'view_label' => PhotoViewLabel::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
```

`database/factories/ProductReferencePhotoFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Shared\Enums\PhotoViewLabel;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Rows are blob-less by default: tests that
 * need real bytes fake the media disk, put a file, and override
 * storage_path/checksum.
 *
 * @extends Factory<ProductReferencePhoto>
 */
class ProductReferencePhotoFactory extends Factory
{
    use ResolvesTenant;

    protected $model = ProductReferencePhoto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'product_id' => Product::factory(),
            'storage_disk' => 'media',
            'storage_path' => 'tenants/1/product-photos/1/'.fake()->unique()->uuid().'.jpg',
            'view_label' => PhotoViewLabel::Front,
            'checksum' => fake()->unique()->sha256(),
            'width' => 1024,
            'height' => 1024,
            'uploaded_by' => null,
        ];
    }
}
```

In `app/Modules/CRM/Models/Product.php`, insert after the `shipments()` method (lines 75–79; `HasMany` is already imported):

```php
    /** @return HasMany<ProductReferencePhoto, $this> */
    public function referencePhotos(): HasMany
    {
        return $this->hasMany(ProductReferencePhoto::class);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ProductReferencePhotoTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the surrounding suites to catch regressions**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Crm tests/Feature/Tenancy`
Expected: PASS — all green, no regressions (the new migration and the `Product` relation touch nothing existing).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_20_100004_create_product_reference_photos_table.php app/Shared/Enums/PhotoViewLabel.php app/Modules/CRM/Models/ProductReferencePhoto.php app/Modules/CRM/Models/Product.php database/factories/ProductReferencePhotoFactory.php tests/Feature/Crm/ProductReferencePhotoTest.php
git commit -m "feat(crm): product reference photos table + model (composite tenant FKs, view-label check)"
```

---

---

### Task 5: Embedding tables — `vector(3072)` storage with DB-level cascades

**Files:**
- Create: `database/migrations/2026_07_20_100005_create_embedding_tables.php`
- Create: `app/Modules/Monitoring/Models/ProductPhotoEmbedding.php`
- Create: `app/Modules/Monitoring/Models/KeyframeEmbedding.php`
- Create: `database/factories/ProductPhotoEmbeddingFactory.php`
- Create: `database/factories/KeyframeEmbeddingFactory.php`
- Test: `tests/Feature/Enrichment/EmbeddingTablesTest.php` (create)

**Interfaces:**
- Consumes:
  - `VectorLiteral::fromArray(array $vector): string` / `VectorLiteral::toArray(string $literal): array` (Task 1, contract-frozen) and the pgvector extension migration `2026_07_20_100001_enable_pgvector_extension` (Task 1)
  - `(id, tenant_id)` unique on `keyframes` (Task 2/3 map: migration `2026_07_20_100003_add_id_tenant_unique_to_keyframes`) and `Keyframe::factory()` (Task 3)
  - `ProductReferencePhoto` + `ProductReferencePhotoFactory` + `product_reference_photos_id_tenant_unique` (Task 4)
  - Existing: `Keyframe` model, `qds:prune-keyframes` (`PruneKeyframesCommand`, blob-first delete), config fallback `qds.enrichment.keyframes.retention_days`
- Produces (Tasks 9, 11, 13, 14, 15, 16 rely on these):
  - `ProductPhotoEmbedding` model — fillable `product_reference_photo_id, model_version, embedding`; `UPDATED_AT = null`; `photo(): BelongsTo<ProductReferencePhoto>`; `embedding` is a plain string holding the pgvector text literal (no cast — write via `VectorLiteral::fromArray`, read via `toArray`, compare in SQL with `1 - (embedding <=> ?)`)
  - `KeyframeEmbedding` model — fillable `keyframe_id, model_version, embedding`; `UPDATED_AT = null`; `keyframe(): BelongsTo<Keyframe>`
  - `ProductPhotoEmbeddingFactory` / `KeyframeEmbeddingFactory` — default `model_version` `'gemini-embedding-2'`, default embedding = 3072-dim unit basis vector (never zero: cosine distance to a zero vector is NaN)
  - DDL guarantees: unique `(product_reference_photo_id, model_version)` (T9's idempotency key) named `product_photo_embeddings_photo_model_unique`; unique `(keyframe_id, model_version)` (T13's cache key) named `keyframe_embeddings_keyframe_model_unique`; ON DELETE CASCADE from photo and keyframe on both plain and composite FKs — `CreatorEraser` bulk deletes (T14's test extension), `qds:prune-keyframes`, and the T11 product-delete path all stay correct with zero code changes

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\KeyframeEmbedding;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Schema + lifecycle guarantees for the two vector(3072) tables (spec
 * §4.2/§4.3): text-literal round trip through pgvector, immutability keys
 * (one row per parent per model_version), and the DB-level cascades that
 * keep both existing deleters (CreatorEraser, qds:prune-keyframes) and the
 * product-delete path correct with zero code changes.
 */
class EmbeddingTablesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    /** A 3072-dim pgvector literal: zeros with distinctive spot values. */
    private function vector(array $spots = [0 => 1.0]): string
    {
        $vector = array_fill(0, 3072, 0.0);
        foreach ($spots as $index => $value) {
            $vector[$index] = $value;
        }

        return VectorLiteral::fromArray($vector);
    }

    /** Hand-built keyframe (KeyframeRetentionTest pattern) so age + blob path are exact. */
    private function makeFrame(int $ageDays = 0, string $path = 'tenants/1/keyframes/instagram/1/content-x/0.jpg'): Keyframe
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
            'source_checksum' => str_repeat('b', 64),
            'provenance' => new Provenance('SRC-apify-instagram-reel-scraper', CarbonImmutable::now(), 'keyframes-v1'),
        ]);
        $frame->timestamps = false;
        $frame->forceFill(['created_at' => CarbonImmutable::now()->subDays($ageDays)])->save();

        return $frame->refresh();
    }

    private function embedFrame(Keyframe $frame, string $modelVersion = 'gemini-embedding-2'): KeyframeEmbedding
    {
        return KeyframeEmbedding::query()->create([
            'keyframe_id' => $frame->id,
            'model_version' => $modelVersion,
            'embedding' => $this->vector(),
        ]);
    }

    public function test_factories_build_tenant_stamped_rows_with_reachable_parents(): void
    {
        $photoEmbedding = ProductPhotoEmbedding::factory()->create();
        $frameEmbedding = KeyframeEmbedding::factory()->create();

        $this->assertSame($this->defaultTenant->id, $photoEmbedding->tenant_id);
        $this->assertSame($this->defaultTenant->id, $frameEmbedding->tenant_id);
        $this->assertSame('gemini-embedding-2', $photoEmbedding->model_version);
        $this->assertNotNull($photoEmbedding->created_at);
        $this->assertTrue($photoEmbedding->photo()->exists());
        $this->assertTrue($frameEmbedding->keyframe()->exists());
    }

    public function test_photo_embedding_round_trips_a_3072_dimension_vector(): void
    {
        $embedding = ProductPhotoEmbedding::factory()->create([
            'embedding' => $this->vector([0 => 1.0, 1 => 0.5, 2 => 0.25]),
        ]);

        // Refresh so we read pgvector's own text output, not our input string.
        $values = VectorLiteral::toArray($embedding->refresh()->embedding);

        $this->assertCount(3072, $values);
        // 1, 0.5, 0.25 are exactly representable in float4 — exact round trip.
        $this->assertSame(1.0, $values[0]);
        $this->assertSame(0.5, $values[1]);
        $this->assertSame(0.25, $values[2]);
        $this->assertSame(0.0, $values[3071]);
    }

    public function test_photo_embedding_is_unique_per_photo_and_model_version(): void
    {
        $photo = ProductReferencePhoto::factory()->create();
        ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $photo->id]);
        // Another model_version for the SAME photo is legal (backfill path).
        ProductPhotoEmbedding::factory()->create([
            'product_reference_photo_id' => $photo->id,
            'model_version' => 'gemini-embedding-3',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $photo->id]);
    }

    public function test_keyframe_embedding_is_unique_per_keyframe_and_model_version(): void
    {
        $frame = $this->makeFrame();
        $this->embedFrame($frame);
        $this->embedFrame($frame, 'gemini-embedding-3'); // legal: model upgrade

        $this->expectException(UniqueConstraintViolationException::class);
        $this->embedFrame($frame);
    }

    public function test_deleting_a_keyframe_cascades_its_embeddings(): void
    {
        $doomed = $this->makeFrame(0, 'tenants/1/keyframes/instagram/1/content-a/0.jpg');
        $survivor = $this->makeFrame(0, 'tenants/1/keyframes/instagram/1/content-b/0.jpg');
        $doomedEmbedding = $this->embedFrame($doomed);
        $doomedUpgrade = $this->embedFrame($doomed, 'gemini-embedding-3');
        $survivorEmbedding = $this->embedFrame($survivor);

        $doomed->delete();

        $this->assertDatabaseMissing('keyframe_embeddings', ['id' => $doomedEmbedding->id]);
        $this->assertDatabaseMissing('keyframe_embeddings', ['id' => $doomedUpgrade->id]);
        $this->assertDatabaseHas('keyframe_embeddings', ['id' => $survivorEmbedding->id]);
    }

    public function test_prune_command_cascades_embeddings_of_expired_keyframes(): void
    {
        config(['qds.enrichment.keyframes.retention_days' => 30]);
        $expired = $this->makeFrame(40, 'tenants/1/keyframes/instagram/1/content-old/0.jpg');
        $fresh = $this->makeFrame(5, 'tenants/1/keyframes/instagram/1/content-new/0.jpg');
        $expiredEmbedding = $this->embedFrame($expired);
        $freshEmbedding = $this->embedFrame($fresh);

        $this->artisan('qds:prune-keyframes')->assertSuccessful();

        $this->assertDatabaseMissing('keyframes', ['id' => $expired->id]);
        $this->assertDatabaseMissing('keyframe_embeddings', ['id' => $expiredEmbedding->id]);
        $this->assertDatabaseHas('keyframes', ['id' => $fresh->id]);
        $this->assertDatabaseHas('keyframe_embeddings', ['id' => $freshEmbedding->id]);
    }

    public function test_deleting_a_photo_cascades_its_embeddings(): void
    {
        $photo = ProductReferencePhoto::factory()->create();
        $survivorPhoto = ProductReferencePhoto::factory()->create();
        $doomed = ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $photo->id]);
        $survivor = ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $survivorPhoto->id]);

        $photo->delete();

        $this->assertDatabaseMissing('product_photo_embeddings', ['id' => $doomed->id]);
        $this->assertDatabaseHas('product_photo_embeddings', ['id' => $survivor->id]);
    }

    public function test_deleting_a_product_cascades_photos_and_their_embeddings(): void
    {
        $photo = ProductReferencePhoto::factory()->create();
        $embedding = ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $photo->id]);

        Product::query()->findOrFail($photo->product_id)->delete();

        $this->assertDatabaseMissing('product_reference_photos', ['id' => $photo->id]);
        $this->assertDatabaseMissing('product_photo_embeddings', ['id' => $embedding->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter EmbeddingTablesTest`
Expected: ERROR (8 tests) — `Error: Class "App\Modules\Monitoring\Models\ProductPhotoEmbedding" not found`.

- [ ] **Step 3: Implement migration, models, factories**

`database/migrations/2026_07_20_100005_create_embedding_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Derived vector storage for visual matching (sub-project C, spec
     * §4.2/§4.3). Immutable per (parent, model_version): a replaced photo is
     * a NEW photo row; a model upgrade backfills NEW rows — never in-place
     * mutation. vector(3072) is deliberate DDL: request dimensionality and
     * column width must agree, so a different-width model is a schema
     * migration, not a config flip. Exact scan only — no HNSW/IVFFlat
     * (3072 also exceeds pgvector's 2000-dim index limit for the vector
     * type; the sanctioned ANN paths — halfvec expression index or
     * Matryoshka truncation — are documented in the spec §4.2). The
     * DB-level ON DELETE CASCADEs keep CreatorEraser's in-transaction bulk
     * deletes and qds:prune-keyframes correct with zero code changes.
     */
    public function up(): void
    {
        Schema::create('product_photo_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_reference_photo_id')->constrained('product_reference_photos')->cascadeOnDelete();
            $table->string('model_version', 64);
            $table->timestamp('created_at');

            // T9's idempotency key; its prefix also serves per-photo lookups.
            $table->unique(['product_reference_photo_id', 'model_version'], 'product_photo_embeddings_photo_model_unique');
        });
        // Blueprint has no vector type — raw DDL. Width must match the
        // qds.enrichment.visual_match.dimensions request knob (spec §4.2).
        DB::statement('ALTER TABLE product_photo_embeddings ADD COLUMN embedding vector(3072) NOT NULL');
        // Embeddings must live in their photo's tenant; CASCADE on the
        // composite too so photo/product deletes are order-independent.
        DB::statement('ALTER TABLE product_photo_embeddings ADD CONSTRAINT product_photo_embeddings_product_reference_photo_id_tenant_fk FOREIGN KEY (product_reference_photo_id, tenant_id) REFERENCES product_reference_photos (id, tenant_id) ON DELETE CASCADE');

        Schema::create('keyframe_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('keyframe_id')->constrained('keyframes')->cascadeOnDelete();
            $table->string('model_version', 64);
            $table->timestamp('created_at');

            // T13's cache key: one embedding per frame per model_version.
            $table->unique(['keyframe_id', 'model_version'], 'keyframe_embeddings_keyframe_model_unique');
        });
        DB::statement('ALTER TABLE keyframe_embeddings ADD COLUMN embedding vector(3072) NOT NULL');
        // Composite FK target (id, tenant_id) on keyframes arrives in
        // 2026_07_20_100003 (runs earlier by filename order).
        DB::statement('ALTER TABLE keyframe_embeddings ADD CONSTRAINT keyframe_embeddings_keyframe_id_tenant_fk FOREIGN KEY (keyframe_id, tenant_id) REFERENCES keyframes (id, tenant_id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('keyframe_embeddings');
        Schema::dropIfExists('product_photo_embeddings');
    }
};
```

`app/Modules/Monitoring/Models/ProductPhotoEmbedding.php`:

```php
<?php

namespace App\Modules\Monitoring\Models;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Shared\Tenancy\BelongsToTenant;
use Database\Factories\ProductPhotoEmbeddingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One 3072-dimension Gemini embedding of one product reference photo at one
 * model_version (sub-project C, spec §4.2). Immutable per key — replaced
 * photos and upgraded models get NEW rows, never in-place mutation.
 * `embedding` is the raw pgvector text literal ('[0.1,0.2,…]'): format with
 * VectorLiteral, compare in SQL with `1 - (embedding <=> ?)` (`<=>` is
 * cosine DISTANCE). Rows die with their photo via DB-level cascade.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $product_reference_photo_id
 * @property string $model_version
 * @property string $embedding pgvector text literal '[0.1,0.2,…]'
 * @property \Carbon\CarbonImmutable $created_at
 */
class ProductPhotoEmbedding extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProductPhotoEmbeddingFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'product_reference_photo_id',
        'model_version',
        'embedding',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProductReferencePhoto, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(ProductReferencePhoto::class, 'product_reference_photo_id');
    }
}
```

`app/Modules/Monitoring/Models/KeyframeEmbedding.php`:

```php
<?php

namespace App\Modules\Monitoring\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Database\Factories\KeyframeEmbeddingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One 3072-dimension Gemini embedding of one keyframe at one model_version
 * (sub-project C, spec §4.3) — the per-frame cache that makes re-runs and
 * backfills free (cache hits are never billed). Rows die with their
 * keyframe via DB-level ON DELETE CASCADE, which keeps both existing
 * deleters (CreatorEraser's bulk deletes, qds:prune-keyframes) correct
 * with zero code changes. `embedding` is the raw pgvector text literal:
 * format with VectorLiteral, compare with `1 - (embedding <=> ?)`.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $keyframe_id
 * @property string $model_version
 * @property string $embedding pgvector text literal '[0.1,0.2,…]'
 * @property \Carbon\CarbonImmutable $created_at
 */
class KeyframeEmbedding extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<KeyframeEmbeddingFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'keyframe_id',
        'model_version',
        'embedding',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Keyframe, $this> */
    public function keyframe(): BelongsTo
    {
        return $this->belongsTo(Keyframe::class);
    }
}
```

`database/factories/ProductPhotoEmbeddingFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Defaults to a unit basis vector — NEVER the
 * zero vector, whose cosine distance is undefined (NaN) in pgvector.
 *
 * @extends Factory<ProductPhotoEmbedding>
 */
class ProductPhotoEmbeddingFactory extends Factory
{
    use ResolvesTenant;

    protected $model = ProductPhotoEmbedding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vector = array_fill(0, 3072, 0.0);
        $vector[0] = 1.0;

        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'product_reference_photo_id' => ProductReferencePhoto::factory(),
            'model_version' => 'gemini-embedding-2',
            'embedding' => VectorLiteral::fromArray($vector),
        ];
    }
}
```

`database/factories/KeyframeEmbeddingFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\KeyframeEmbedding;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Defaults to a unit basis vector — NEVER the
 * zero vector, whose cosine distance is undefined (NaN) in pgvector.
 *
 * @extends Factory<KeyframeEmbedding>
 */
class KeyframeEmbeddingFactory extends Factory
{
    use ResolvesTenant;

    protected $model = KeyframeEmbedding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vector = array_fill(0, 3072, 0.0);
        $vector[0] = 1.0;

        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'keyframe_id' => Keyframe::factory(),
            'model_version' => 'gemini-embedding-2',
            'embedding' => VectorLiteral::fromArray($vector),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter EmbeddingTablesTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Run the surrounding suite to catch regressions**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Enrichment tests/Feature/Crm`
Expected: PASS — all green (in particular `KeyframeRetentionTest` and `KeyframeErasureTest` still pass: the new cascades are additive; T14 extends the erasure test to assert embedding rows go with the frames).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_20_100005_create_embedding_tables.php app/Modules/Monitoring/Models/ProductPhotoEmbedding.php app/Modules/Monitoring/Models/KeyframeEmbedding.php database/factories/ProductPhotoEmbeddingFactory.php database/factories/KeyframeEmbeddingFactory.php tests/Feature/Enrichment/EmbeddingTablesTest.php
git commit -m "feat(enrichment): vector(3072) embedding tables for photos + keyframes with DB-level cascades"
```

---

---

### Task 6: GoogleServiceAccountTokenProvider (first service-account auth) + `services.google_embeddings` config + source id

**Files:**
- Create: `app/Platform/Enrichment/VisualMatch/Http/GoogleServiceAccountTokenProvider.php`
- Modify: `app/Platform/Ingestion/SourceRegistry.php` (add const after `GOOGLE_VIDEO_INTELLIGENCE`, line 46; add `all()` entry after line 72)
- Modify: `config/services.php` (add `google_embeddings` block after the `google_video_intelligence` block, lines 124–128, before the Stripe banner at line 130)
- Test: `tests/Unit/Ingestion/SourceRegistryTest.php` (existing, lines 1–16 — add one test)
- Test: `tests/Feature/Enrichment/GoogleServiceAccountTokenProviderTest.php` (create)

**Interfaces:**
- Consumes: `ProviderCallException::__construct(string $source, ErrorCategory $category, string $sanitizedMessage, ?int $httpStatus = null, ?int $retryAfterSeconds = null)` (app/Platform/Ingestion/Exceptions/ProviderCallException.php); `ErrorCategory::Authentication`; `SourceRegistry::isRegistered(string): bool`.
- Produces: `SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS = 'SRC-google-gemini-embeddings'` (registered in `all()`) — consumed by Tasks 7, 9, 13, 18, 21; config keys `services.google_embeddings.{credentials_path,project_id,location,base_url,timeout}` (spec §12, verbatim) — consumed by Tasks 7, 9, 19; and
```php
final class GoogleServiceAccountTokenProvider {
    public const CACHE_KEY = 'qds:google-embeddings-token';
    public function token(): string;            // cached OAuth bearer token; throws ProviderCallException(Authentication) on failure
    public function isConfigured(): bool;       // credentials_path readable + project_id set
}
```
Task 7 consumes `token()`/`isConfigured()` and pre-warms `CACHE_KEY` in its tests so the OAuth endpoint is never re-faked outside this file.

- [ ] **Step 1: Write the failing SourceRegistry test**

Add to `tests/Unit/Ingestion/SourceRegistryTest.php`, after `test_youtube_transcript_source_is_registered()`:

```php
    public function test_gemini_embeddings_source_is_registered(): void
    {
        $this->assertSame('SRC-google-gemini-embeddings', SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS);
        $this->assertTrue(SourceRegistry::isRegistered(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter SourceRegistryTest`
Expected: 1 ERROR — `Undefined constant App\Platform\Ingestion\SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS` (the pre-existing transcript test still passes).

- [ ] **Step 3: Implement the source id**

In `app/Platform/Ingestion/SourceRegistry.php`, after the `GOOGLE_VIDEO_INTELLIGENCE` const (line 46):

```php
    /**
     * Gemini multimodal embeddings for visual product matching (ADR-0029
     * amendment to the ADR-0001 freeze — sub-project C). Image bytes travel
     * INLINE (base64), never as URLs (DP-005); Bearer-token auth ONLY —
     * API keys cannot call :embedContent (verified 2026-07-19).
     */
    public const GOOGLE_GEMINI_EMBEDDINGS = 'SRC-google-gemini-embeddings';
```

Add `self::GOOGLE_GEMINI_EMBEDDINGS,` to the `all()` array right after `self::GOOGLE_VIDEO_INTELLIGENCE,` (line 72).

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter SourceRegistryTest`
Expected: PASS (2 tests, 4 assertions).

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Ingestion/SourceRegistry.php tests/Unit/Ingestion/SourceRegistryTest.php
git commit -m "feat(ingestion): register SRC-google-gemini-embeddings source id (ADR-0029)"
```

- [ ] **Step 6: Write the failing token-provider test**

Create `tests/Feature/Enrichment/GoogleServiceAccountTokenProviderTest.php`. The RSA key pair is generated in-test with `openssl_pkey_new` (throwaway, never committed); the token endpoint is `Http::fake`d; the posted JWT assertion is decoded and its claims + RS256 signature verified against the test public key.

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\VisualMatch\Http\GoogleServiceAccountTokenProvider;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * First service-account machinery in the repo (spec §5): RS256 JWT-bearer
 * exchange at Google's token endpoint. Verified flow (2026-07-19): aud =
 * https://oauth2.googleapis.com/token, scope = cloud-platform, lifetime
 * <= 1 h, grant_type = urn:ietf:params:oauth:grant-type:jwt-bearer. Key
 * material and tokens must never surface in URLs or exception messages.
 */
class GoogleServiceAccountTokenProviderTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * Throwaway RSA service-account key: generated per test, written as a
     * Google-shaped JSON key file, wired into config. Returns the file
     * path and the public key PEM for signature verification.
     *
     * @return array{path: string, public_key: string}
     */
    private function provisionServiceAccount(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key, 'openssl_pkey_new failed');

        $privatePem = '';
        $this->assertTrue(openssl_pkey_export($key, $privatePem));

        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        $path = tempnam(sys_get_temp_dir(), 'qds-test-sa-');
        $this->assertIsString($path);
        $this->cleanupPaths[] = $path;

        file_put_contents($path, (string) json_encode([
            'type' => 'service_account',
            'project_id' => 'qds-embeddings-test',
            'private_key_id' => 'test-key-1',
            'private_key' => $privatePem,
            'client_email' => 'qds-embeddings@qds-embeddings-test.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            'services.google_embeddings.credentials_path' => $path,
            'services.google_embeddings.project_id' => 'qds-embeddings-test',
        ]);

        return ['path' => $path, 'public_key' => (string) $details['key']];
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        $this->assertIsString($decoded);

        return $decoded;
    }

    public function test_is_configured_only_when_key_file_is_readable_and_project_is_set(): void
    {
        config([
            'services.google_embeddings.credentials_path' => null,
            'services.google_embeddings.project_id' => null,
        ]);
        $this->assertFalse(app(GoogleServiceAccountTokenProvider::class)->isConfigured());

        $account = $this->provisionServiceAccount();
        $this->assertTrue(app(GoogleServiceAccountTokenProvider::class)->isConfigured());

        config(['services.google_embeddings.project_id' => null]);
        $this->assertFalse(app(GoogleServiceAccountTokenProvider::class)->isConfigured());

        config([
            'services.google_embeddings.project_id' => 'qds-embeddings-test',
            'services.google_embeddings.credentials_path' => $account['path'].'-missing',
        ]);
        $this->assertFalse(app(GoogleServiceAccountTokenProvider::class)->isConfigured());
    }

    public function test_token_exchanges_a_signed_rs256_jwt_bearer_assertion(): void
    {
        $account = $this->provisionServiceAccount();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.test-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ]);

        $this->assertSame('ya29.test-token', app(GoogleServiceAccountTokenProvider::class)->token());

        Http::assertSent(function (Request $request) use ($account): bool {
            // Documented server-to-server flow: form-encoded jwt-bearer
            // grant at the token endpoint — nothing in the URL.
            $this->assertSame('https://oauth2.googleapis.com/token', $request->url());
            $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $request['grant_type']);

            $segments = explode('.', (string) $request['assertion']);
            $this->assertCount(3, $segments);
            [$header64, $claims64, $signature64] = $segments;

            $header = json_decode($this->base64UrlDecode($header64), true);
            $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);

            $claims = json_decode($this->base64UrlDecode($claims64), true);
            $this->assertIsArray($claims);
            $this->assertSame('qds-embeddings@qds-embeddings-test.iam.gserviceaccount.com', $claims['iss']);
            $this->assertSame('https://www.googleapis.com/auth/cloud-platform', $claims['scope']);
            $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
            $this->assertEqualsWithDelta(time(), $claims['iat'], 5);
            $this->assertSame(3600, $claims['exp'] - $claims['iat']);

            // The assertion really is RS256-signed by the configured key.
            $this->assertSame(1, openssl_verify(
                "{$header64}.{$claims64}",
                $this->base64UrlDecode($signature64),
                $account['public_key'],
                OPENSSL_ALGO_SHA256,
            ));

            return true;
        });
    }

    public function test_token_is_cached_and_refetched_only_near_expiry(): void
    {
        $this->provisionServiceAccount();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'token-one', 'expires_in' => 3600, 'token_type' => 'Bearer'])
                ->push(['access_token' => 'token-two', 'expires_in' => 3600, 'token_type' => 'Bearer']),
        ]);

        $provider = app(GoogleServiceAccountTokenProvider::class);

        $this->assertSame('token-one', $provider->token());
        $this->assertSame('token-one', $provider->token());
        Http::assertSentCount(1); // second call served from cache

        // Cached until 60 s before expiry (3600 - 60 = 3540 s).
        $this->travel(3541)->seconds();

        $this->assertSame('token-two', $provider->token());
        Http::assertSentCount(2);
    }

    public function test_exchange_failure_throws_a_sanitized_authentication_exception(): void
    {
        $this->provisionServiceAccount();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Invalid JWT signature.',
            ], 400),
        ]);

        try {
            app(GoogleServiceAccountTokenProvider::class)->token();
            $this->fail('Expected a ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS, $e->source);
            $this->assertSame(ErrorCategory::Authentication, $e->category);
            $this->assertSame(400, $e->httpStatus);
            // Sanitized: no key material, no raw provider body.
            $this->assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
            $this->assertStringNotContainsString('Invalid JWT signature', $e->getMessage());
        }

        // A failed exchange never poisons the cache.
        $this->assertNull(Cache::get(GoogleServiceAccountTokenProvider::CACHE_KEY));
    }

    public function test_missing_access_token_in_a_successful_response_is_a_failure(): void
    {
        $this->provisionServiceAccount();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['token_type' => 'Bearer'])]);

        try {
            app(GoogleServiceAccountTokenProvider::class)->token();
            $this->fail('Expected a ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame(ErrorCategory::Authentication, $e->category);
        }
    }

    public function test_malformed_key_file_fails_closed_without_a_network_call(): void
    {
        Http::fake();

        $path = tempnam(sys_get_temp_dir(), 'qds-test-sa-');
        $this->assertIsString($path);
        $this->cleanupPaths[] = $path;
        file_put_contents($path, 'not-json');

        config([
            'services.google_embeddings.credentials_path' => $path,
            'services.google_embeddings.project_id' => 'qds-embeddings-test',
        ]);

        try {
            app(GoogleServiceAccountTokenProvider::class)->token();
            $this->fail('Expected a ProviderCallException.');
        } catch (ProviderCallException $e) {
            $this->assertSame(ErrorCategory::Authentication, $e->category);
        }

        Http::assertNothingSent();
    }
}
```

- [ ] **Step 7: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter GoogleServiceAccountTokenProviderTest`
Expected: 6 ERRORS — `Class "App\Platform\Enrichment\VisualMatch\Http\GoogleServiceAccountTokenProvider" not found`.

- [ ] **Step 8: Add the `services.google_embeddings` config block**

In `config/services.php`, between the `google_video_intelligence` block (ends line 128) and the Stripe banner (line 130) — keys copied verbatim from spec §12:

```php
    /*
    |--------------------------------------------------------------------------
    | Google multimodal embeddings (SRC-google-gemini-embeddings — ADR-0029)
    |--------------------------------------------------------------------------
    | Sub-project C visual product matching. Model-based naming (survives
    | Google platform-brand churn). Bearer-token auth ONLY — API keys cannot
    | call :embedContent (verified 2026-07-19); credentials come ONLY from
    | environment-managed secrets. The eu multi-region endpoint is the only
    | EU location serving gemini-embedding-2 (residency: ML processing stays
    | within EU member states); the global endpoint has NO guarantee.
    */
    'google_embeddings' => [
        'credentials_path' => env('GOOGLE_EMBEDDINGS_CREDENTIALS'),   // service-account JSON key file
        'project_id' => env('GOOGLE_EMBEDDINGS_PROJECT'),
        'location' => env('GOOGLE_EMBEDDINGS_LOCATION', 'eu'), // EU multi-region — the only EU location serving this model (§5)
        'base_url' => env('GOOGLE_EMBEDDINGS_BASE_URL'),              // default derived from location
        'timeout' => (int) env('GOOGLE_EMBEDDINGS_TIMEOUT_SECONDS', 30),
    ],
```

- [ ] **Step 9: Implement `GoogleServiceAccountTokenProvider`**

Create `app/Platform/Enrichment/VisualMatch/Http/GoogleServiceAccountTokenProvider.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Http;

use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OAuth bearer tokens for SRC-google-gemini-embeddings (sub-project C,
 * ADR-0029). API keys CANNOT call :embedContent (verified 2026-07-19), so
 * this is the repo's first service-account flow: sign a self-issued RS256
 * JWT from the configured JSON key file (openssl — no new dependency) and
 * exchange it at Google's token endpoint per the documented
 * server-to-server flow, then cache the bearer token until shortly before
 * expiry.
 *
 * Security invariants (house rules): key material and tokens never appear
 * in URLs, logs, or exception messages; every failure surfaces as a
 * SANITIZED ProviderCallException(Authentication) — callers skip, never
 * crash an enrichment run. This is auth plumbing, not an AI payload: the
 * JWT assertion legitimately IS a credential, so it does not pass
 * AiPayloadGuard (which keeps credentials/personal data out of AI request
 * bodies — the embedding payload itself is guarded in
 * GeminiMultimodalEmbeddingProvider).
 */
final class GoogleServiceAccountTokenProvider
{
    /** Shared across workers; also the test seam for pre-warming a token. */
    public const CACHE_KEY = 'qds:google-embeddings-token';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /** Google's maximum assertion lifetime is one hour. */
    private const TOKEN_LIFETIME_SECONDS = 3600;

    /** Refresh this many seconds BEFORE the token would expire. */
    private const EXPIRY_SAFETY_SECONDS = 60;

    public function isConfigured(): bool
    {
        $path = (string) config('services.google_embeddings.credentials_path');

        return $path !== ''
            && is_readable($path)
            && (string) config('services.google_embeddings.project_id') !== '';
    }

    public function token(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        ['token' => $token, 'expires_in' => $expiresIn] = $this->exchange(
            $this->signAssertion($this->credentials()),
        );

        Cache::put(self::CACHE_KEY, $token, max(1, $expiresIn - self::EXPIRY_SAFETY_SECONDS));

        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string}
     */
    private function credentials(): array
    {
        $path = (string) config('services.google_embeddings.credentials_path');
        $raw = $path !== '' && is_readable($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        $clientEmail = is_array($decoded) ? ($decoded['client_email'] ?? null) : null;
        $privateKey = is_array($decoded) ? ($decoded['private_key'] ?? null) : null;

        if (! is_string($clientEmail) || $clientEmail === '' || ! is_string($privateKey) || $privateKey === '') {
            throw $this->failure('service-account key file is missing, unreadable, or malformed.');
        }

        return ['client_email' => $clientEmail, 'private_key' => $privateKey];
    }

    /**
     * Self-issued RS256 JWT per Google's server-to-server OAuth flow
     * (verified 2026-07-19): aud = the token endpoint, scope =
     * cloud-platform, lifetime <= 1 h.
     *
     * @param  array{client_email: string, private_key: string}  $credentials
     */
    private function signAssertion(array $credentials): string
    {
        $now = CarbonImmutable::now()->getTimestamp();

        $signingInput = $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'
            .$this->base64UrlEncode((string) json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_ENDPOINT,
                'iat' => $now,
                'exp' => $now + self::TOKEN_LIFETIME_SECONDS,
            ]));

        $key = openssl_pkey_get_private($credentials['private_key']);

        if ($key === false) {
            throw $this->failure('service-account private key could not be parsed.');
        }

        $signature = '';

        if (! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw $this->failure('service-account JWT signing failed.');
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * @return array{token: string, expires_in: int}
     */
    private function exchange(string $assertion): array
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('services.google_embeddings.timeout'))
                ->connectTimeout(10)
                ->post(self::TOKEN_ENDPOINT, [
                    'grant_type' => self::GRANT_TYPE,
                    'assertion' => $assertion,
                ]);
        } catch (ConnectionException) {
            throw $this->failure('token endpoint was unreachable.');
        }

        if (! $response->successful()) {
            throw $this->failure("token exchange failed (HTTP {$response->status()}).", $response->status());
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw $this->failure('token exchange returned no access token.', $response->status());
        }

        $expiresIn = $response->json('expires_in');

        return [
            'token' => $token,
            'expires_in' => is_numeric($expiresIn) ? (int) $expiresIn : self::TOKEN_LIFETIME_SECONDS,
        ];
    }

    /**
     * Every failure of this flow is an Authentication-category provider
     * failure (frozen contract) with a message safe to persist and log.
     */
    private function failure(string $sanitizedMessage, ?int $httpStatus = null): ProviderCallException
    {
        return new ProviderCallException(
            SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
            ErrorCategory::Authentication,
            SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS.' '.$sanitizedMessage,
            $httpStatus,
        );
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 10: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter GoogleServiceAccountTokenProviderTest`
Expected: PASS (6 tests).

- [ ] **Step 11: Run the relevant suites**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Ingestion tests/Feature/Enrichment`
Expected: all green (no existing test reads `services.google_embeddings`; the only shared surface is `SourceRegistry::all()`, which is additive).

- [ ] **Step 12: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/Http/GoogleServiceAccountTokenProvider.php config/services.php tests/Feature/Enrichment/GoogleServiceAccountTokenProviderTest.php
git commit -m "feat(enrichment): Google service-account bearer-token provider for gemini embeddings"
```

---

---

### Task 7: EmbeddingProvider seam + GeminiMultimodalEmbeddingProvider (`:embedContent`)

**Files:**
- Create: `app/Platform/Enrichment/VisualMatch/Contracts/EmbeddingProvider.php`
- Create: `app/Platform/Enrichment/VisualMatch/Http/GeminiMultimodalEmbeddingProvider.php`
- Modify: `config/qds.php` (seed the `visual_match` block between the `keyframes` block, lines 298–309, and the `confidence` block, lines 311–317)
- Modify: `app/Platform/PlatformServiceProvider.php` (imports after line 22; binding in `register()` after the enrichment bindings, lines 72–74)
- Test: `tests/Feature/Enrichment/GeminiMultimodalEmbeddingProviderTest.php` (create)

**Interfaces:**
- Consumes: `GoogleServiceAccountTokenProvider::token(): string` / `isConfigured(): bool` / `CACHE_KEY` (Task 6); `services.google_embeddings.*` (Task 6); `AiPayloadGuard::assertSafe(array $payload): void` (throws `InvalidArgumentException`, app/Platform/Enrichment/Support/AiPayloadGuard.php); `ProviderCallException` + `ErrorCategory` taxonomy (error-mapping precedent: app/Platform/Enrichment/Http/GoogleApiClient.php lines 89–142); `SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS` (Task 6).
- Produces (frozen contract, verbatim — Tasks 9, 13, 19 consume):
```php
interface EmbeddingProvider {
    /** @return list<float> */
    public function embedImage(string $bytes, string $mimeType): array;
    public function modelVersion(): string;
    public function isConfigured(): bool;
}
```
container binding `EmbeddingProvider::class → GeminiMultimodalEmbeddingProvider::class`; config keys `qds.enrichment.visual_match.{enabled,model_version,dimensions}` (first three lines of the spec §12 block — Tasks 12 and 19 APPEND their keys to this block, never re-create it). Telemetry division (spec §5 + frozen signatures): `embedImage` carries no correlation id, so `ProviderCallRecorder` wrapping (source `SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS`, operation `embedding.embed`) lives in the callers — Task 9's `ReferencePhotoEmbedder` and Task 13's `KeyframeEmbedder`; this provider's contribution is the classified `ProviderCallException` that `recordFailure()` consumes. The breaker consult (`ProviderCircuitBreaker::shouldSkip()` before spending) is Task 19's.

- [ ] **Step 1: Create the interface**

Create `app/Platform/Enrichment/VisualMatch/Contracts/EmbeddingProvider.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Contracts;

/**
 * The embedding seam for visual product matching (sub-project C, spec §5):
 * container-bound so the matcher, embedders, and persistence never learn
 * which provider produced a vector. A second provider (or the v2
 * cropped-region variant) is a new binding plus a new model_version —
 * zero call-site changes. YAGNI: no provider-selection config knob until
 * a second implementation exists.
 */
interface EmbeddingProvider
{
    /**
     * Embed ONE image into ONE vector. One call per image is a verified
     * model property (2026-07-19): multi-image input FUSES into a single
     * vector, which is useless for per-frame matching.
     *
     * @return list<float>
     *
     * @throws \App\Platform\Ingestion\Exceptions\ProviderCallException classified failure — never a raw provider error
     */
    public function embedImage(string $bytes, string $mimeType): array;

    /**
     * Stamped on every embedding row; changing it is a re-embed backfill,
     * never an in-place mutation (vector spaces are incompatible).
     */
    public function modelVersion(): string;

    public function isConfigured(): bool;
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Enrichment/GeminiMultimodalEmbeddingProviderTest.php`. The token flow was tested in Task 6, so these tests pre-warm the bearer-token cache (`CACHE_KEY`) and fake ONLY the embedding endpoint; `dimensions` is set to 3 so fixture vectors stay small (the knob exists precisely to keep request width and DDL in visible agreement).

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Http\GeminiMultimodalEmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Http\GoogleServiceAccountTokenProvider;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * SRC-google-gemini-embeddings over the verified :embedContent shape
 * (spec §18, 2026-07-19): one inlineData part → ONE vector at
 * embedding.values; embedContentConfig.outputDimensionality pins the
 * width; EU multi-region endpoint; Bearer-only auth. Every payload passes
 * the AiPayloadGuard BEFORE any byte (or token fetch) leaves.
 */
class GeminiMultimodalEmbeddingProviderTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * Configured provider with a pre-warmed bearer token: the OAuth flow
     * has its own test file (Task 6); embedImage never touches the token
     * endpoint here. The credentials file is a stub — it is never read
     * while the token cache is warm; it only satisfies isConfigured().
     */
    private function configureProvider(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qds-test-sa-');
        $this->assertIsString($path);
        $this->cleanupPaths[] = $path;
        file_put_contents($path, '{"client_email":"qds-embeddings@qds-embeddings-test.iam.gserviceaccount.com"}');

        config([
            'services.google_embeddings.credentials_path' => $path,
            'services.google_embeddings.project_id' => 'qds-embeddings-test',
            'qds.enrichment.visual_match.dimensions' => 3,
        ]);

        Cache::put(GoogleServiceAccountTokenProvider::CACHE_KEY, 'test-bearer-token', 3540);
    }

    private function embedExpectingFailure(): ProviderCallException
    {
        try {
            app(EmbeddingProvider::class)->embedImage('fake-frame-bytes', 'image/jpeg');
        } catch (ProviderCallException $e) {
            return $e;
        }

        $this->fail('Expected a ProviderCallException.');
    }

    public function test_the_container_binds_the_gemini_implementation(): void
    {
        $this->assertInstanceOf(GeminiMultimodalEmbeddingProvider::class, app(EmbeddingProvider::class));
    }

    public function test_embed_image_posts_one_inline_image_and_returns_the_vector(): void
    {
        $this->configureProvider();
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => Http::response(['embedding' => ['values' => [0.25, -0.5, 1.0]]]),
        ]);

        $vector = app(EmbeddingProvider::class)->embedImage('fake-frame-bytes', 'image/jpeg');

        $this->assertSame([0.25, -0.5, 1.0], $vector);

        Http::assertSent(function (Request $request): bool {
            // Verified endpoint (spec §18): EU multi-region host, v1 path,
            // :embedContent method — and NO credentials in the URL (exact
            // match proves no query string; Bearer header only).
            $this->assertSame(
                'https://aiplatform.eu.rep.googleapis.com/v1/projects/qds-embeddings-test/locations/eu/publishers/google/models/gemini-embedding-2:embedContent',
                $request->url(),
            );
            $this->assertSame('Bearer test-bearer-token', $request->header('Authorization')[0] ?? null);
            $this->assertFalse($request->hasHeader('X-Goog-Api-Key'));

            // Verified body shape: one inlineData part; width pinned via
            // embedContentConfig.outputDimensionality (top-level fields
            // are deprecated — never used).
            $this->assertSame([
                'content' => [
                    'parts' => [
                        ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode('fake-frame-bytes')]],
                    ],
                ],
                'embedContentConfig' => ['outputDimensionality' => 3],
            ], $request->data());

            return true;
        });
    }

    public function test_model_version_comes_from_config_and_is_configured_delegates_to_the_token_provider(): void
    {
        config([
            'services.google_embeddings.credentials_path' => null,
            'services.google_embeddings.project_id' => null,
        ]);
        $provider = app(EmbeddingProvider::class);

        $this->assertSame('gemini-embedding-2', $provider->modelVersion());
        $this->assertFalse($provider->isConfigured());

        $this->configureProvider();
        $this->assertTrue(app(EmbeddingProvider::class)->isConfigured());
    }

    public function test_embedding_while_unconfigured_fails_closed_without_a_network_call(): void
    {
        config([
            'services.google_embeddings.credentials_path' => null,
            'services.google_embeddings.project_id' => null,
        ]);
        Http::fake();

        $this->assertSame(ErrorCategory::Authentication, $this->embedExpectingFailure()->category);
        Http::assertNothingSent();
    }

    public function test_every_payload_passes_the_ai_payload_guard_before_any_byte_leaves(): void
    {
        $this->configureProvider();
        Http::fake();

        // A signed-URL-style mime type trips the DP-005 credential
        // pattern — proving the guard sits in FRONT of the HTTP call.
        try {
            app(EmbeddingProvider::class)->embedImage('fake-frame-bytes', 'image/jpeg?token=leaked');
            $this->fail('Expected the AiPayloadGuard to reject the payload.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('DP-005', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_rate_limiting_maps_to_rate_limited_with_retry_after(): void
    {
        $this->configureProvider();
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => Http::response(
                ['error' => ['status' => 'RESOURCE_EXHAUSTED']],
                429,
                ['Retry-After' => '7'],
            ),
        ]);

        $e = $this->embedExpectingFailure();

        $this->assertSame(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS, $e->source);
        $this->assertSame(ErrorCategory::RateLimited, $e->category);
        $this->assertSame(429, $e->httpStatus);
        $this->assertSame(7, $e->retryAfterSeconds);
    }

    public function test_denied_access_maps_to_authentication_and_never_leaks_the_token(): void
    {
        $this->configureProvider();
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403),
        ]);

        $e = $this->embedExpectingFailure();

        $this->assertSame(ErrorCategory::Authentication, $e->category);
        $this->assertStringNotContainsString('test-bearer-token', $e->getMessage());
    }

    public function test_server_errors_map_to_upstream_error(): void
    {
        $this->configureProvider();
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response('', 500)]);

        $this->assertSame(ErrorCategory::UpstreamError, $this->embedExpectingFailure()->category);
    }

    public function test_a_non_json_body_maps_to_malformed_response(): void
    {
        $this->configureProvider();
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response('embedding-but-not-json')]);

        $this->assertSame(ErrorCategory::MalformedResponse, $this->embedExpectingFailure()->category);
    }

    public function test_a_wrong_width_vector_maps_to_schema_drift(): void
    {
        $this->configureProvider();
        // 2 values against configured dimensions = 3.
        Http::fake(['aiplatform.eu.rep.googleapis.com/*' => Http::response(['embedding' => ['values' => [0.1, 0.2]]])]);

        $this->assertSame(ErrorCategory::SchemaDrift, $this->embedExpectingFailure()->category);
    }

    public function test_a_connection_timeout_maps_to_timeout(): void
    {
        $this->configureProvider();
        // The token is cached, so the ONLY outbound call is the embed —
        // this exception unambiguously exercises the embed error path.
        Http::fake([
            'aiplatform.eu.rep.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 ms'),
        ]);

        $this->assertSame(ErrorCategory::Timeout, $this->embedExpectingFailure()->category);
    }

    public function test_an_explicit_base_url_overrides_the_derived_eu_endpoint(): void
    {
        $this->configureProvider();
        config(['services.google_embeddings.base_url' => 'https://aiplatform.proxy.internal/v1']);
        Http::fake([
            'aiplatform.proxy.internal/*' => Http::response(['embedding' => ['values' => [0.1, 0.2, 0.3]]]),
        ]);

        $this->assertSame([0.1, 0.2, 0.3], app(EmbeddingProvider::class)->embedImage('fake-frame-bytes', 'image/png'));

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://aiplatform.proxy.internal/v1/projects/qds-embeddings-test/locations/eu/',
        ));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter GeminiMultimodalEmbeddingProviderTest`
Expected: 12 ERRORS — `Class "App\Platform\Enrichment\VisualMatch\Http\GeminiMultimodalEmbeddingProvider" not found` / `Target [App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider] is not instantiable`.

- [ ] **Step 4: Seed the `qds.enrichment.visual_match` config block**

In `config/qds.php`, between the `keyframes` block (ends line 309) and the confidence-bucketing comment (line 311) — the three keys are the spec §12 block's first three lines, verbatim; Tasks 12 and 19 append the rest (`quality_filter`/`dedup`, then `frame_budget`/`photo_cap`/`photo_link_ttl_minutes`/`thresholds`) to THIS block:

```php
        // Visual product matching (sub-project C, ADR-0029). Kill switch
        // default OFF = true no-op (skipped:disabled, zero provider calls).
        // model_version is stamped on every embedding row — changing it is
        // a re-embed backfill, never a mutation; dimensions keeps the
        // request width and the vector(3072) DDL visibly in agreement.
        // Later C tasks extend this block (quality filter, dedup, frame
        // budget, photo cap, thresholds).
        'visual_match' => [
            'enabled' => (bool) env('QDS_ENRICHMENT_VISUAL_MATCH_ENABLED', false), // kill switch, true no-op
            'model_version' => env('QDS_ENRICHMENT_VISUAL_MATCH_MODEL', 'gemini-embedding-2'), // pin exact versioned id at implementation
            'dimensions' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_DIMENSIONS', 3072),
        ],
```

- [ ] **Step 5: Implement `GeminiMultimodalEmbeddingProvider`**

Create `app/Platform/Enrichment/VisualMatch/Http/GeminiMultimodalEmbeddingProvider.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Http;

use App\Platform\Enrichment\Support\AiPayloadGuard;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SRC-google-gemini-embeddings (ADR-0029): Gemini Embedding 2 via the
 * verified :embedContent method on the EU multi-region endpoint (locked
 * residency decision — ML processing stays within EU member states; the
 * global endpoint offers NO guarantee and must not be used; the legacy
 * :predict/instances shape belongs to multimodalembedding@001 only).
 *
 * Verified call shape (spec §18, 2026-07-19): one inlineData part per
 * call → ONE vector at embedding.values; output width pinned via
 * embedContentConfig.outputDimensionality; Bearer-token auth ONLY (API
 * keys cannot call :embedContent). Image bytes travel INLINE base64 —
 * no URL ever reaches the provider (DP-005) — and every payload passes
 * the AiPayloadGuard BEFORE a token is fetched or a byte is sent.
 * Errors map onto the house ErrorCategory taxonomy exactly like
 * GoogleApiClient; the token never appears in URLs, logs, or exceptions.
 *
 * Telemetry division: ProviderCallRecorder wrapping (operation
 * `embedding.embed`) lives in the CALLERS (ReferencePhotoEmbedder /
 * KeyframeEmbedder) — they own the correlation id this interface
 * deliberately does not carry; this class supplies the classified
 * ProviderCallException that recordFailure() consumes. The circuit-
 * breaker consult happens in VisualProductMatcher BEFORE spending.
 */
final class GeminiMultimodalEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly GoogleServiceAccountTokenProvider $tokens,
    ) {}

    public function isConfigured(): bool
    {
        return $this->tokens->isConfigured();
    }

    public function modelVersion(): string
    {
        return (string) config('qds.enrichment.visual_match.model_version');
    }

    /** @return list<float> */
    public function embedImage(string $bytes, string $mimeType): array
    {
        if (! $this->isConfigured()) {
            throw new ProviderCallException(
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                ErrorCategory::Authentication,
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS.' is not configured.',
            );
        }

        $dimensions = (int) config('qds.enrichment.visual_match.dimensions');

        $payload = [
            'content' => [
                'parts' => [
                    ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($bytes)]],
                ],
            ],
            'embedContentConfig' => ['outputDimensionality' => $dimensions],
        ];

        // DP-005 gate FIRST — before a token is fetched or a byte leaves.
        AiPayloadGuard::assertSafe($payload);

        $token = $this->tokens->token();

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('services.google_embeddings.timeout'))
                ->connectTimeout(10)
                ->post($this->endpoint(), $payload);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'time');

            throw new ProviderCallException(
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                $timedOut ? ErrorCategory::Timeout : ErrorCategory::Network,
                $timedOut
                    ? SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS.' request timed out.'
                    : SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS.' was unreachable (network error).',
            );
        }

        $this->assertSuccessful($response);

        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderCallException(
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                ErrorCategory::MalformedResponse,
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS.' returned a non-JSON body.',
                $response->status(),
            );
        }

        // Verified response shape: the vector lives at embedding.values —
        // and its width must match the pinned outputDimensionality (a
        // mismatch is provider drift, never silently stored).
        $values = $body['embedding']['values'] ?? null;

        if (! is_array($values) || ! array_is_list($values) || count($values) !== $dimensions) {
            throw new ProviderCallException(
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                ErrorCategory::SchemaDrift,
                sprintf(
                    '%s returned %s vector values (expected %d).',
                    SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                    is_array($values) ? (string) count($values) : 'no',
                    $dimensions,
                ),
                $response->status(),
            );
        }

        $vector = [];

        foreach ($values as $value) {
            if (! is_numeric($value)) {
                throw new ProviderCallException(
                    SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                    ErrorCategory::SchemaDrift,
                    SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS.' returned a non-numeric vector component.',
                    $response->status(),
                );
            }

            $vector[] = (float) $value;
        }

        return $vector;
    }

    /**
     * {base}/projects/{project}/locations/{location}/publishers/google/
     * models/{model}:embedContent (verified v1 path).
     */
    private function endpoint(): string
    {
        $project = (string) config('services.google_embeddings.project_id');
        $location = (string) config('services.google_embeddings.location');

        return sprintf(
            '%s/projects/%s/locations/%s/publishers/google/models/%s:embedContent',
            $this->baseUrl($location),
            $project,
            $location,
            $this->modelVersion(),
        );
    }

    /**
     * Regionalized hosts carry the location subdomain (`eu` — the
     * residency guarantee); only the guarantee-free global endpoint does
     * not. Derived here so ops can still override the host via env.
     */
    private function baseUrl(string $location): string
    {
        $configured = config('services.google_embeddings.base_url');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return $location === 'global'
            ? 'https://aiplatform.googleapis.com/v1'
            : "https://aiplatform.{$location}.rep.googleapis.com/v1";
    }

    /**
     * GoogleApiClient's taxonomy, minus the API-key branches (Bearer-only
     * auth here): 429/RESOURCE_EXHAUSTED → RateLimited (+ Retry-After),
     * 401/403 → Authentication, 408 → Timeout, 5xx → UpstreamError.
     */
    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $reason = $this->errorReason($response);

        $category = match (true) {
            $status === 429 => ErrorCategory::RateLimited,
            in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'RESOURCE_EXHAUSTED'], true) => ErrorCategory::RateLimited,
            $status === 401, $status === 403 => ErrorCategory::Authentication,
            $status === 408 => ErrorCategory::Timeout,
            $status >= 500 => ErrorCategory::UpstreamError,
            default => ErrorCategory::Unknown,
        };

        $retryAfter = null;

        if ($category === ErrorCategory::RateLimited) {
            $header = $response->header('Retry-After');
            $retryAfter = is_numeric($header) ? (int) $header : null;
        }

        throw new ProviderCallException(
            SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
            $category,
            SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS." request failed (HTTP {$status}".($reason !== null ? ", {$reason}" : '').').',
            $status,
            $retryAfter,
        );
    }

    private function errorReason(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $errorStatus = $body['error']['status'] ?? null;

        if (is_string($errorStatus) && $errorStatus !== '') {
            return $errorStatus;
        }

        $reason = $body['error']['errors'][0]['reason'] ?? ($body['error']['details'][0]['reason'] ?? null);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
```

- [ ] **Step 6: Register the container binding**

In `app/Platform/PlatformServiceProvider.php`, add two imports after line 22 (`use App\Platform\Enrichment\Sentiment\UnavailableSentimentClassifier;` — keeps the list sorted):

```php
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Http\GeminiMultimodalEmbeddingProvider;
```

and in `register()`, after the `ReachEstimator` binding (line 74):

```php
        // Sub-project C (ADR-0029): the embedding seam for visual product
        // matching. Gemini Embedding 2 is the only v1 implementation; a
        // second provider is a new binding + model_version — never a
        // call-site change (no selection knob until one exists, YAGNI).
        $this->app->bind(EmbeddingProvider::class, GeminiMultimodalEmbeddingProvider::class);
```

- [ ] **Step 7: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter GeminiMultimodalEmbeddingProviderTest`
Expected: PASS (12 tests).

- [ ] **Step 8: Run the relevant suites**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Enrichment tests/Unit/Ingestion`
Expected: all green — the new config block and binding are purely additive; no existing test reads `qds.enrichment.visual_match` or resolves `EmbeddingProvider`.

- [ ] **Step 9: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/Contracts/EmbeddingProvider.php app/Platform/Enrichment/VisualMatch/Http/GeminiMultimodalEmbeddingProvider.php config/qds.php app/Platform/PlatformServiceProvider.php tests/Feature/Enrichment/GeminiMultimodalEmbeddingProviderTest.php
git commit -m "feat(enrichment): Gemini multimodal embedding provider behind the EmbeddingProvider seam"
```

---

### Task 8: AI budget subsystem (`app/Platform/AiBudget/` — C builds, D reuses)

**Files:**
- Create: `database/migrations/2026_07_20_100007_create_ai_budget_tables.php`
- Create: `app/Platform/AiBudget/Models/AiUsageCounter.php`
- Create: `app/Platform/AiBudget/Models/TenantAiQuota.php`
- Create: `app/Platform/AiBudget/Priority.php`
- Create: `app/Platform/AiBudget/BudgetDecision.php`
- Create: `app/Platform/AiBudget/TenantQuotaResolver.php`
- Create: `app/Platform/AiBudget/AiBudgetGuard.php`
- Create: `app/Platform/AiBudget/Console/AiReadOnlyCommand.php`
- Create: `app/Platform/AiBudget/Console/AiQuotaCommand.php`
- Modify: `config/qds.php` (insert a new top-level `'ai_budget'` block after the `'enrichment'` array closes at line 362, before the SVC-Analytics doc-comment at lines 364–371)
- Modify: `app/Platform/Ingestion/Support/AlertType.php` (add one case after `SnapshotGap`, line 28)
- Modify: `app/Platform/PlatformServiceProvider.php` (use-block lines 3–44; the `$this->commands([...])` list at lines 102–118)
- Test: `tests/Feature/AiBudget/AiBudgetGuardTest.php` (create)
- Test: `tests/Feature/AiBudget/AiBudgetCommandsTest.php` (create)

**Interfaces:**
- Consumes: `AlertService::raise(AlertType $type, ?string $source, string $message, string $severity = 'warning', ?int $tenantId = null): IngestionAlert` and `AlertService`'s sha1(`type|source|tenant`) dedup fingerprint (app/Platform/Ingestion/Observability/AlertService.php lines 29–77); `Tests\TestCase::$defaultTenant` / `makeTenant()`; `App\Models\Tenant`.
- Produces (frozen contract — T9, T13, T19, T21, T22 consume these verbatim):
  - `enum Priority: string { case High = 'high'; case Medium = 'medium'; }`
  - `final readonly class BudgetDecision { public function __construct(public bool $allowed, public ?string $reason = null) {} }`
  - `AiBudgetGuard::allows(string $capability, int $tenantId, int $units, Priority $priority): BudgetDecision`
  - `AiBudgetGuard::record(string $capability, int $tenantId, int $units, int $postsProcessed = 0, int $postsSkippedBudget = 0, int $postsSkippedNoCandidates = 0): void`
  - `AiBudgetGuard::readOnly(): bool` and `AiBudgetGuard::READ_ONLY_CACHE_KEY = 'qds:ai-read-only'`
  - `TenantQuotaResolver::for(int $tenantId, string $capability): array{daily: int, monthly: int}`
  - Models `App\Platform\AiBudget\Models\AiUsageCounter` / `TenantAiQuota`; tables `ai_usage_counters` (unique `(capability, tenant_id, usage_date)`) and `tenant_ai_quotas` (unique `(tenant_id, capability)`)
  - Config keys `qds.ai_budget.*` exactly as spec §12; `AlertType::AiBudgetThreshold = 'AI_BUDGET_THRESHOLD'`
  - Commands `qds:ai-read-only {mode : on|off|status}` and `qds:ai-quota {tenant} {capability} {--daily=} {--monthly=} {--clear}`
  - Deny-reason vocabulary (T19 maps any deny to `skipped:budget-exhausted` / `skipped:ai-read-only`): `read-only`, `unknown-capability`, `per-post-exceeded`, `tenant-daily-exhausted`, `tenant-monthly-exhausted`, `global-daily-exhausted`, `global-monthly-exhausted`, `global-hard-exhausted`.

- [ ] **Step 1: Write the failing schema + config test**

Create `tests/Feature/AiBudget/AiBudgetGuardTest.php`:

```php
<?php

namespace Tests\Feature\AiBudget;

use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\AiBudget\Models\AiUsageCounter;
use App\Platform\AiBudget\Models\TenantAiQuota;
use App\Platform\AiBudget\Priority;
use App\Platform\AiBudget\TenantQuotaResolver;
use App\Platform\Ingestion\Models\IngestionAlert;
use App\Platform\Ingestion\Support\AlertType;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Spec §10 — capability-keyed AI budget governance: priority-dependent
 * dimension exhaustion, per-tenant quota overrides, month rollover,
 * atomic counter increments, deduplicated threshold alerts, read-only.
 */
class AiBudgetGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Small, test-friendly budget numbers; individual tests override
     * single keys via the $overrides tree.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function configureBudget(array $overrides = []): void
    {
        config(['qds.ai_budget' => array_replace_recursive([
            'read_only' => false,
            'alert_thresholds' => [50, 80, 95, 100],
            'capabilities' => [
                'embedding' => [
                    'price_micro_usd_per_unit' => 120,
                    'per_post_units' => 12,
                    'tenant_daily_units' => 100,
                    'tenant_monthly_units' => 1000,
                    'global_daily_units' => 10000,
                    'global_daily_hard_units' => 20000,
                    'global_monthly_units' => 100000,
                    'global_monthly_hard_units' => 200000,
                ],
            ],
        ], $overrides)]);
    }

    public function test_shipped_config_defaults_match_the_spec(): void
    {
        $this->assertFalse((bool) config('qds.ai_budget.read_only'));
        $this->assertSame([50, 80, 95, 100], config('qds.ai_budget.alert_thresholds'));
        $this->assertSame(120, config('qds.ai_budget.capabilities.embedding.price_micro_usd_per_unit'));
        $this->assertSame(12, config('qds.ai_budget.capabilities.embedding.per_post_units'));
        $this->assertSame(2000, config('qds.ai_budget.capabilities.embedding.tenant_daily_units'));
        $this->assertSame(40000, config('qds.ai_budget.capabilities.embedding.tenant_monthly_units'));
        $this->assertSame(50000, config('qds.ai_budget.capabilities.embedding.global_daily_units'));
        $this->assertSame(100000, config('qds.ai_budget.capabilities.embedding.global_daily_hard_units'));
        $this->assertSame(1000000, config('qds.ai_budget.capabilities.embedding.global_monthly_units'));
        $this->assertSame(2000000, config('qds.ai_budget.capabilities.embedding.global_monthly_hard_units'));
    }

    public function test_counter_and_quota_rows_persist_with_their_unique_keys(): void
    {
        $tenantId = $this->defaultTenant->id;

        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $tenantId,
            'usage_date' => '2026-07-19',
            'units' => 3,
            'estimated_cost_micro_usd' => 360,
        ]);

        TenantAiQuota::query()->create([
            'tenant_id' => $tenantId,
            'capability' => 'embedding',
            'daily_units' => 100,
            'monthly_units' => null,
        ]);

        $this->assertDatabaseHas('ai_usage_counters', ['capability' => 'embedding', 'units' => 3]);
        $this->assertDatabaseHas('tenant_ai_quotas', ['capability' => 'embedding', 'daily_units' => 100]);

        // The atomic-upsert conflict target: ONE row per (capability, tenant, day).
        $this->expectException(UniqueConstraintViolationException::class);
        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $tenantId,
            'usage_date' => '2026-07-19',
            'units' => 1,
        ]);
    }
}
```

- [ ] **Step 2: Run it to verify failure**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter AiBudgetGuardTest`
Expected: 2 errors/failures — `test_shipped_config_defaults_match_the_spec` fails on the missing `qds.ai_budget` key (null ≠ 120); `test_counter_and_quota_rows_persist…` errors with `Class "App\Platform\AiBudget\Models\AiUsageCounter" not found`.

- [ ] **Step 3: Implement the config block, migration, and models**

In `config/qds.php`, directly after line 362 (the `],` closing `'enrichment'`) and before the SVC-Analytics doc-comment, insert (spec §12, copied verbatim):

```php
    /*
    |--------------------------------------------------------------------------
    | AI budget governance (visual matching sub-project C, spec §10 — D reuses)
    |--------------------------------------------------------------------------
    | Capability-keyed spend budgets enforced by AiBudgetGuard: per-post,
    | tenant daily/monthly (per-tenant overrides in tenant_ai_quotas, NULL
    | column → these defaults), global daily/monthly with HARD variants.
    | read_only is the emergency-stop default; the cached qds:ai-read-only
    | flag wins over it either way.
    */
    'ai_budget' => [
        'read_only' => (bool) env('QDS_AI_READ_ONLY', false),
        'alert_thresholds' => [50, 80, 95, 100],
        'capabilities' => [
            'embedding' => [
                'price_micro_usd_per_unit' => (int) env('QDS_AI_EMBEDDING_PRICE_MICRO_USD', 120), // $0.00012/image (verified 2026-07-19)
                'per_post_units' => (int) env('QDS_AI_EMBEDDING_PER_POST', 12),
                'tenant_daily_units' => (int) env('QDS_AI_EMBEDDING_TENANT_DAILY', 2000),
                'tenant_monthly_units' => (int) env('QDS_AI_EMBEDDING_TENANT_MONTHLY', 40000),
                'global_daily_units' => (int) env('QDS_AI_EMBEDDING_GLOBAL_DAILY', 50000),
                'global_daily_hard_units' => (int) env('QDS_AI_EMBEDDING_GLOBAL_DAILY_HARD', 100000),
                'global_monthly_units' => (int) env('QDS_AI_EMBEDDING_GLOBAL_MONTHLY', 1000000),
                'global_monthly_hard_units' => (int) env('QDS_AI_EMBEDDING_GLOBAL_MONTHLY_HARD', 2000000),
            ],
            // 'vlm_verification' => reserved for sub-project D
        ],
    ],
```

Create `database/migrations/2026_07_20_100007_create_ai_budget_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI budget governance (sub-project C spec §10; D reuses): capability-
     * keyed usage counters with atomic ON CONFLICT increments, and optional
     * per-tenant quota overrides (NULL column → config default).
     *
     * PLATFORM operational tables (ingestion_alerts precedent): tenant-
     * attributed via an explicit NOT NULL tenant_id but deliberately NOT
     * TenantScoped — the guard's GLOBAL budget dimensions aggregate across
     * every tenant, which a TenantScope would silently narrow inside
     * tenant-bound enrichment jobs. No children reference these tables, so
     * plain tenant FKs suffice (no composite-FK DDL needed). Monthly usage
     * = SUM over the month's daily rows; global = SUM across tenants. No
     * personal data — exempt from creator-GDPR erasure (spec §13); rows
     * age out with telemetry retention (qds:prune-ingestion-data).
     */
    public function up(): void
    {
        Schema::create('ai_usage_counters', function (Blueprint $table) {
            $table->id();
            $table->string('capability', 40);
            $table->foreignId('tenant_id')->constrained();
            $table->date('usage_date');
            $table->integer('units')->default(0);
            $table->bigInteger('estimated_cost_micro_usd')->default(0);
            $table->integer('posts_processed')->default(0);
            $table->integer('posts_skipped_budget')->default(0);
            $table->integer('posts_skipped_no_candidates')->default(0);
            $table->timestamp('updated_at');

            // The atomic upsert's conflict target.
            $table->unique(['capability', 'tenant_id', 'usage_date']);
            // Global dimension reads (SUM across tenants for a date range).
            $table->index(['capability', 'usage_date']);
        });

        Schema::create('tenant_ai_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('capability', 40);
            $table->integer('daily_units')->nullable();
            $table->integer('monthly_units')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_quotas');
        Schema::dropIfExists('ai_usage_counters');
    }
};
```

Create `app/Platform/AiBudget/Models/AiUsageCounter.php`:

```php
<?php

namespace App\Platform\AiBudget\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One day of AI usage for one (capability, tenant) pair — the ledger
 * AiBudgetGuard enforces against (spec §10). PLATFORM operational data
 * (ingestion_alerts precedent): tenant-attributed via explicit tenant_id
 * but NOT TenantScoped, because the global budget dimensions must SUM
 * across every tenant even from inside tenant-bound jobs. Increments go
 * through the guard's atomic INSERT … ON CONFLICT DO UPDATE, never this
 * model. No personal data (GDPR-exempt, spec §13).
 *
 * @property int $id
 * @property string $capability
 * @property int $tenant_id
 * @property CarbonImmutable $usage_date
 * @property int $units
 * @property int $estimated_cost_micro_usd
 * @property int $posts_processed
 * @property int $posts_skipped_budget
 * @property int $posts_skipped_no_candidates
 * @property CarbonImmutable $updated_at
 */
class AiUsageCounter extends Model
{
    public const CREATED_AT = null;

    protected $fillable = [
        'capability',
        'tenant_id',
        'usage_date',
        'units',
        'estimated_cost_micro_usd',
        'posts_processed',
        'posts_skipped_budget',
        'posts_skipped_no_candidates',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'usage_date' => 'immutable_date',
            'units' => 'integer',
            'estimated_cost_micro_usd' => 'integer',
            'posts_processed' => 'integer',
            'posts_skipped_budget' => 'integer',
            'posts_skipped_no_candidates' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
```

Create `app/Platform/AiBudget/Models/TenantAiQuota.php`:

```php
<?php

namespace App\Platform\AiBudget\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Optional per-tenant AI quota override (spec §4.6): a NULL column falls
 * back to the capability's config default. Managed via qds:ai-quota in
 * v1 — billing-plan self-serve purchase is a noted billing-module
 * follow-up. Platform table (explicit tenant_id, not TenantScoped): read
 * by the guard under any context, written by a tenant-less operator
 * command.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $capability
 * @property int|null $daily_units
 * @property int|null $monthly_units
 */
class TenantAiQuota extends Model
{
    protected $fillable = ['tenant_id', 'capability', 'daily_units', 'monthly_units'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'daily_units' => 'integer',
            'monthly_units' => 'integer',
        ];
    }
}
```

- [ ] **Step 4: Run to verify pass, then commit**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter AiBudgetGuardTest`
Expected: OK (2 tests).

```bash
git add config/qds.php database/migrations/2026_07_20_100007_create_ai_budget_tables.php app/Platform/AiBudget/Models tests/Feature/AiBudget/AiBudgetGuardTest.php
git commit -m "feat(ai-budget): usage counter + tenant quota tables with spec-default config"
```

- [ ] **Step 5: Write the failing TenantQuotaResolver test**

Append to `tests/Feature/AiBudget/AiBudgetGuardTest.php`:

```php
    public function test_quota_resolver_defaults_overrides_and_memoization(): void
    {
        $this->configureBudget();
        $tenantId = $this->defaultTenant->id;

        // No override row → config defaults.
        $this->assertSame(['daily' => 100, 'monthly' => 1000], app(TenantQuotaResolver::class)->for($tenantId, 'embedding'));

        // Override daily only; the NULL monthly column keeps the config default.
        TenantAiQuota::query()->create(['tenant_id' => $tenantId, 'capability' => 'embedding', 'daily_units' => 5, 'monthly_units' => null]);

        $resolver = app(TenantQuotaResolver::class);
        $this->assertSame(['daily' => 5, 'monthly' => 1000], $resolver->for($tenantId, 'embedding'));

        // Memoized for THIS instance's life…
        TenantAiQuota::query()->update(['daily_units' => 9]);
        $this->assertSame(['daily' => 5, 'monthly' => 1000], $resolver->for($tenantId, 'embedding'));

        // …while a fresh resolver (not a singleton) reads the new row.
        $this->assertSame(['daily' => 9, 'monthly' => 1000], app(TenantQuotaResolver::class)->for($tenantId, 'embedding'));
    }
```

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter test_quota_resolver_defaults_overrides_and_memoization`
Expected: ERROR — `Class "App\Platform\AiBudget\TenantQuotaResolver" not found`.

- [ ] **Step 6: Implement TenantQuotaResolver, run, commit**

Create `app/Platform/AiBudget/TenantQuotaResolver.php`:

```php
<?php

namespace App\Platform\AiBudget;

use App\Platform\AiBudget\Models\TenantAiQuota;

/**
 * Effective per-tenant AI quota limits (spec §10): the tenant_ai_quotas
 * override row wins column-by-column; a NULL column falls back to the
 * capability's config default. Rows are memoized per (tenant, capability)
 * for the life of THIS instance (MonitoringSettingsResolver pattern —
 * the class is NOT a singleton; each resolution starts fresh).
 */
final class TenantQuotaResolver
{
    /** @var array<string, TenantAiQuota|null> */
    private array $rows = [];

    /** @return array{daily: int, monthly: int} */
    public function for(int $tenantId, string $capability): array
    {
        $key = $tenantId.':'.$capability;

        if (! array_key_exists($key, $this->rows)) {
            $this->rows[$key] = TenantAiQuota::query()
                ->where('tenant_id', $tenantId)
                ->where('capability', $capability)
                ->first();
        }

        $override = $this->rows[$key];

        return [
            'daily' => $override?->daily_units ?? (int) config("qds.ai_budget.capabilities.{$capability}.tenant_daily_units"),
            'monthly' => $override?->monthly_units ?? (int) config("qds.ai_budget.capabilities.{$capability}.tenant_monthly_units"),
        ];
    }
}
```

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter AiBudgetGuardTest`
Expected: OK (3 tests).

```bash
git add app/Platform/AiBudget/TenantQuotaResolver.php tests/Feature/AiBudget/AiBudgetGuardTest.php
git commit -m "feat(ai-budget): TenantQuotaResolver with per-tenant overrides"
```

- [ ] **Step 7: Write the failing allows() tests (all dimensions, both priorities)**

Append to `tests/Feature/AiBudget/AiBudgetGuardTest.php` (usage is seeded directly through the model so this cycle does not depend on `record()`):

```php
    /** Seed a usage row for TODAY (or the given date) without touching record(). */
    private function seedUsage(int $tenantId, int $units, ?string $date = null): void
    {
        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $tenantId,
            'usage_date' => $date ?? CarbonImmutable::now()->toDateString(),
            'units' => $units,
        ]);
    }

    public function test_read_only_mode_blocks_every_priority_instantly(): void
    {
        $this->configureBudget();
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        Cache::forever(AiBudgetGuard::READ_ONLY_CACHE_KEY, true);

        foreach ([Priority::High, Priority::Medium] as $priority) {
            $decision = $guard->allows('embedding', $tenantId, 1, $priority);
            $this->assertFalse($decision->allowed);
            $this->assertSame('read-only', $decision->reason);
        }

        // A cached FALSE overrides even a truthy config default.
        config(['qds.ai_budget.read_only' => true]);
        Cache::forever(AiBudgetGuard::READ_ONLY_CACHE_KEY, false);
        $this->assertFalse($guard->readOnly());
        $this->assertTrue($guard->allows('embedding', $tenantId, 1, Priority::Medium)->allowed);

        // With NO cached flag the config default decides.
        Cache::forget(AiBudgetGuard::READ_ONLY_CACHE_KEY);
        $this->assertTrue($guard->readOnly());
    }

    public function test_unknown_capability_fails_closed(): void
    {
        $this->configureBudget();

        $decision = app(AiBudgetGuard::class)->allows('vlm_verification', $this->defaultTenant->id, 1, Priority::High);

        $this->assertFalse($decision->allowed);
        $this->assertSame('unknown-capability', $decision->reason);
    }

    public function test_per_post_ceiling_applies_to_medium_priority(): void
    {
        $this->configureBudget();
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        $decision = $guard->allows('embedding', $tenantId, 13, Priority::Medium);
        $this->assertFalse($decision->allowed);
        $this->assertSame('per-post-exceeded', $decision->reason);

        $this->assertTrue($guard->allows('embedding', $tenantId, 12, Priority::Medium)->allowed);
    }

    public function test_medium_priority_denies_when_the_tenant_daily_budget_is_exhausted(): void
    {
        $this->configureBudget();
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        $this->seedUsage($tenantId, 95); // 95 of 100 daily units used

        $decision = $guard->allows('embedding', $tenantId, 10, Priority::Medium);
        $this->assertFalse($decision->allowed);
        $this->assertSame('tenant-daily-exhausted', $decision->reason);

        // The remaining 5 still fit.
        $this->assertTrue($guard->allows('embedding', $tenantId, 5, Priority::Medium)->allowed);
    }

    public function test_high_priority_ignores_soft_caps_but_stops_at_the_global_hard_cap(): void
    {
        $this->configureBudget(['capabilities' => ['embedding' => ['global_daily_hard_units' => 200]]]);
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        $this->seedUsage($tenantId, 195); // tenant daily (100) long gone; hard cap at 200

        // High does not care about the exhausted tenant budget…
        $this->assertTrue($guard->allows('embedding', $tenantId, 5, Priority::High)->allowed);

        // …but the global HARD cap stops even High.
        $decision = $guard->allows('embedding', $tenantId, 6, Priority::High);
        $this->assertFalse($decision->allowed);
        $this->assertSame('global-hard-exhausted', $decision->reason);
    }

    public function test_global_soft_budget_sums_across_tenants_for_medium(): void
    {
        $this->configureBudget(['capabilities' => ['embedding' => [
            'tenant_daily_units' => 10000,
            'tenant_monthly_units' => 100000,
            'global_daily_units' => 50,
        ]]]);
        $guard = app(AiBudgetGuard::class);

        // ANOTHER tenant's spend counts toward the global dimension — this
        // is why the models are not TenantScoped.
        $this->seedUsage($this->makeTenant('Tenant B')->id, 45);

        $decision = $guard->allows('embedding', $this->defaultTenant->id, 10, Priority::Medium);
        $this->assertFalse($decision->allowed);
        $this->assertSame('global-daily-exhausted', $decision->reason);

        $this->assertTrue($guard->allows('embedding', $this->defaultTenant->id, 5, Priority::Medium)->allowed);
    }

    public function test_tenant_quota_override_beats_the_config_default(): void
    {
        $this->configureBudget();
        $tenantId = $this->defaultTenant->id;

        TenantAiQuota::query()->create(['tenant_id' => $tenantId, 'capability' => 'embedding', 'daily_units' => 5, 'monthly_units' => null]);
        $guard = app(AiBudgetGuard::class);

        $decision = $guard->allows('embedding', $tenantId, 6, Priority::Medium);
        $this->assertFalse($decision->allowed);
        $this->assertSame('tenant-daily-exhausted', $decision->reason);

        $this->assertTrue($guard->allows('embedding', $tenantId, 5, Priority::Medium)->allowed);
        // High ignores the override entirely (tenant caps are soft).
        $this->assertTrue($guard->allows('embedding', $tenantId, 6, Priority::High)->allowed);
    }

    public function test_monthly_budget_is_the_sum_of_the_months_days_and_rolls_over(): void
    {
        $this->configureBudget(['capabilities' => ['embedding' => [
            'tenant_daily_units' => 1000,
            'tenant_monthly_units' => 100,
        ]]]);
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));
        $this->seedUsage($tenantId, 60, '2026-06-01');
        $this->seedUsage($tenantId, 40, '2026-06-30');

        // June: 60 + 40 = the whole monthly budget.
        $decision = $guard->allows('embedding', $tenantId, 1, Priority::Medium);
        $this->assertFalse($decision->allowed);
        $this->assertSame('tenant-monthly-exhausted', $decision->reason);

        // July 1st: June's spend counts toward NOTHING.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 00:05:00'));
        $this->assertTrue($guard->allows('embedding', $tenantId, 1, Priority::Medium)->allowed);

        CarbonImmutable::setTestNow();
    }
```

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter AiBudgetGuardTest`
Expected: the new tests ERROR — `Class "App\Platform\AiBudget\AiBudgetGuard" not found` (the 3 earlier tests still pass).

- [ ] **Step 8: Implement Priority, BudgetDecision, and the guard's pre-spend gate**

Create `app/Platform/AiBudget/Priority.php`:

```php
<?php

namespace App\Platform\AiBudget;

/**
 * Budget priority tier (spec §7/§10): High = active-campaign work and
 * user-triggered photo embeds — ignores tenant soft caps and global soft
 * budgets, stops only at the global HARD caps, read-only mode, or the
 * provider breaker (the breaker consult lives in the matcher). Medium =
 * shipment outside an active campaign — stops at ANY exhausted budget.
 * Low never reaches the guard: an empty candidate set is already skipped.
 */
enum Priority: string
{
    case High = 'high';
    case Medium = 'medium';
}
```

Create `app/Platform/AiBudget/BudgetDecision.php`:

```php
<?php

namespace App\Platform\AiBudget;

/**
 * Pre-spend budget verdict (spec §10). $reason is set only on deny —
 * e.g. 'tenant-daily-exhausted', 'global-hard-exhausted', 'read-only'.
 */
final readonly class BudgetDecision
{
    public function __construct(public bool $allowed, public ?string $reason = null) {}
}
```

Create `app/Platform/AiBudget/AiBudgetGuard.php` (record() is added in the next cycle; write the class with allows()/readOnly() and the private helpers now):

```php
<?php

namespace App\Platform\AiBudget;

use App\Platform\AiBudget\Models\AiUsageCounter;
use App\Platform\Ingestion\Observability\AlertService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Capability-keyed AI budget governance (spec §10; C builds, D reuses).
 *
 * allows() is the PRE-SPEND gate: read-only mode, the per-post ceiling,
 * tenant daily/monthly (per-tenant overrides via TenantQuotaResolver),
 * global daily/monthly soft budgets, and the global HARD caps. Priority
 * semantics (approved): High ignores every soft cap and stops only at
 * the hard caps or read-only; Medium stops at any exhausted budget; Low
 * never reaches the guard. Unknown capabilities deny (fail-closed).
 *
 * Monthly usage = SUM of the month's daily counter rows; global usage =
 * SUM across tenants (the counter models are deliberately unscoped).
 */
final class AiBudgetGuard
{
    public const READ_ONLY_CACHE_KEY = 'qds:ai-read-only';

    public function __construct(
        private readonly TenantQuotaResolver $quotas,
        private readonly AlertService $alerts,
    ) {}

    public function allows(string $capability, int $tenantId, int $units, Priority $priority): BudgetDecision
    {
        if ($this->readOnly()) {
            return new BudgetDecision(false, 'read-only');
        }

        $config = $this->capabilityConfig($capability);

        if ($config === null) {
            return new BudgetDecision(false, 'unknown-capability');
        }

        $today = CarbonImmutable::now()->toDateString();
        $monthStart = CarbonImmutable::now()->startOfMonth()->toDateString();

        $globalDaily = $this->sum($capability, null, $today, $today);
        $globalMonthly = $this->sum($capability, null, $monthStart, $today);

        // The global HARD caps stop EVERY priority.
        if ($globalDaily + $units > (int) $config['global_daily_hard_units']
            || $globalMonthly + $units > (int) $config['global_monthly_hard_units']) {
            return new BudgetDecision(false, 'global-hard-exhausted');
        }

        if ($priority === Priority::High) {
            return new BudgetDecision(true);
        }

        if ($units > (int) $config['per_post_units']) {
            return new BudgetDecision(false, 'per-post-exceeded');
        }

        $tenantLimits = $this->quotas->for($tenantId, $capability);

        if ($this->sum($capability, $tenantId, $today, $today) + $units > $tenantLimits['daily']) {
            return new BudgetDecision(false, 'tenant-daily-exhausted');
        }

        if ($this->sum($capability, $tenantId, $monthStart, $today) + $units > $tenantLimits['monthly']) {
            return new BudgetDecision(false, 'tenant-monthly-exhausted');
        }

        if ($globalDaily + $units > (int) $config['global_daily_units']) {
            return new BudgetDecision(false, 'global-daily-exhausted');
        }

        if ($globalMonthly + $units > (int) $config['global_monthly_units']) {
            return new BudgetDecision(false, 'global-monthly-exhausted');
        }

        return new BudgetDecision(true);
    }

    /** Effective read-only state: the cached qds:ai-read-only flag wins over the config default. */
    public function readOnly(): bool
    {
        return (bool) (Cache::get(self::READ_ONLY_CACHE_KEY) ?? config('qds.ai_budget.read_only'));
    }

    /** @return array<string, mixed>|null */
    private function capabilityConfig(string $capability): ?array
    {
        $config = config("qds.ai_budget.capabilities.{$capability}");

        return is_array($config) ? $config : null;
    }

    /** Units spent in [$from, $to] — for one tenant, or globally when $tenantId is null. */
    private function sum(string $capability, ?int $tenantId, string $from, string $to): int
    {
        return (int) AiUsageCounter::query()
            ->where('capability', $capability)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereBetween('usage_date', [$from, $to])
            ->sum('units');
    }
}
```

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter AiBudgetGuardTest`
Expected: OK (11 tests).

```bash
git add app/Platform/AiBudget/Priority.php app/Platform/AiBudget/BudgetDecision.php app/Platform/AiBudget/AiBudgetGuard.php tests/Feature/AiBudget/AiBudgetGuardTest.php
git commit -m "feat(ai-budget): AiBudgetGuard pre-spend gate with priority-dependent dimensions"
```

- [ ] **Step 9: Write the failing record() + threshold-alert tests**

Append to `tests/Feature/AiBudget/AiBudgetGuardTest.php`:

```php
    public function test_record_increments_one_row_atomically_and_prices_units(): void
    {
        $this->configureBudget();
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        $guard->record('embedding', $tenantId, 8, postsProcessed: 1);
        $guard->record('embedding', $tenantId, 4, postsProcessed: 1, postsSkippedBudget: 2, postsSkippedNoCandidates: 3);

        // ONE row per (capability, tenant, day) — the second call incremented, never duplicated.
        $this->assertSame(1, AiUsageCounter::query()->count());

        $row = AiUsageCounter::query()->firstOrFail();
        $this->assertSame(12, $row->units);
        $this->assertSame(12 * 120, $row->estimated_cost_micro_usd); // units × price_micro_usd_per_unit
        $this->assertSame(2, $row->posts_processed);
        $this->assertSame(2, $row->posts_skipped_budget);
        $this->assertSame(3, $row->posts_skipped_no_candidates);
        $this->assertSame($tenantId, $row->tenant_id);
        $this->assertSame(CarbonImmutable::now()->toDateString(), $row->usage_date->toDateString());
    }

    public function test_threshold_crossings_raise_deduplicated_tenant_attributed_alerts(): void
    {
        $this->configureBudget(); // tenant daily 100 is the only dimension in alert reach
        $guard = app(AiBudgetGuard::class);
        $tenantId = $this->defaultTenant->id;

        $guard->record('embedding', $tenantId, 85); // crosses 50 and 80

        $alerts = IngestionAlert::query()->where('alert_type', AlertType::AiBudgetThreshold->value)->get();
        $this->assertCount(2, $alerts);
        $this->assertTrue($alerts->every(fn (IngestionAlert $alert): bool => $alert->tenant_id === $tenantId));
        $this->assertTrue($alerts->every(fn (IngestionAlert $alert): bool => $alert->severity === 'warning'));

        $guard->record('embedding', $tenantId, 15); // 100 of 100 — crosses 95 and 100

        $alerts = IngestionAlert::query()->where('alert_type', AlertType::AiBudgetThreshold->value)->get();
        $this->assertCount(4, $alerts);
        $this->assertSame(1, $alerts->where('severity', 'critical')->count()); // only the 100 % crossing
        $this->assertNotNull($alerts->firstWhere(
            'source', 'embedding:tenant-daily:100:'.CarbonImmutable::now()->toDateString(),
        ));

        // Spend past 100 % crosses nothing new → no alert spam.
        $guard->record('embedding', $tenantId, 5);
        $this->assertSame(4, IngestionAlert::query()->where('alert_type', AlertType::AiBudgetThreshold->value)->count());
    }
```

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter AiBudgetGuardTest`
Expected: the two new tests ERROR — `Call to undefined method App\Platform\AiBudget\AiBudgetGuard::record()`.

- [ ] **Step 10: Implement record() (atomic ON CONFLICT upsert) + threshold alerts + the AlertType case**

In `app/Platform/Ingestion/Support/AlertType.php`, after the `SnapshotGap` case (line 28) add:

```php
    /** An AI capability crossed a configured budget threshold (spec §10): warning below 100 %, critical at 100 %. */
    case AiBudgetThreshold = 'AI_BUDGET_THRESHOLD';
```

In `app/Platform/AiBudget/AiBudgetGuard.php`, add `use App\Platform\Ingestion\Support\AlertType;` and `use Illuminate\Support\Facades\DB;` to the use-block, and insert after `allows()`:

```php
    /**
     * Post-spend ledger: ONE atomic INSERT … ON CONFLICT DO UPDATE per
     * call (never read-modify-write — concurrent enrichment jobs must
     * not lose increments), cost = units × the capability list price,
     * then deduplicated threshold alerts for every budget dimension the
     * increment pushed across a configured percentage.
     */
    public function record(string $capability, int $tenantId, int $units, int $postsProcessed = 0, int $postsSkippedBudget = 0, int $postsSkippedNoCandidates = 0): void
    {
        $config = $this->capabilityConfig($capability);
        $cost = $units * (int) ($config['price_micro_usd_per_unit'] ?? 0);
        $today = CarbonImmutable::now()->toDateString();

        DB::statement(<<<'SQL'
            INSERT INTO ai_usage_counters
                (capability, tenant_id, usage_date, units, estimated_cost_micro_usd,
                 posts_processed, posts_skipped_budget, posts_skipped_no_candidates, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON CONFLICT (capability, tenant_id, usage_date) DO UPDATE SET
                units = ai_usage_counters.units + EXCLUDED.units,
                estimated_cost_micro_usd = ai_usage_counters.estimated_cost_micro_usd + EXCLUDED.estimated_cost_micro_usd,
                posts_processed = ai_usage_counters.posts_processed + EXCLUDED.posts_processed,
                posts_skipped_budget = ai_usage_counters.posts_skipped_budget + EXCLUDED.posts_skipped_budget,
                posts_skipped_no_candidates = ai_usage_counters.posts_skipped_no_candidates + EXCLUDED.posts_skipped_no_candidates,
                updated_at = NOW()
            SQL, [$capability, $tenantId, $today, $units, $cost, $postsProcessed, $postsSkippedBudget, $postsSkippedNoCandidates]);

        if ($units > 0 && $config !== null) {
            $this->raiseThresholdAlerts($capability, $tenantId, $units, $config);
        }
    }
```

and, after `sum()`:

```php
    /**
     * Raise a deduplicated AlertType::AiBudgetThreshold per (capability,
     * dimension, threshold, period) the increment crossed (before < t ≤
     * after — repeats past a threshold raise nothing). Fingerprint recipe
     * (spec §10): the source string carries capability+period+threshold+
     * date and AlertService adds the tenant. Tenant dimensions are
     * tenant-attributed (an operator sees only their own budget alerts);
     * global dimensions stay global (visible to all — no tenant data in
     * the message). Warning below 100 %, critical at 100 %.
     *
     * @param  array<string, mixed>  $config
     */
    private function raiseThresholdAlerts(string $capability, int $tenantId, int $units, array $config): void
    {
        $now = CarbonImmutable::now();
        $today = $now->toDateString();
        $monthStart = $now->startOfMonth()->toDateString();
        $monthKey = $now->format('Y-m');

        $tenantLimits = $this->quotas->for($tenantId, $capability);

        $dimensions = [
            ['period' => 'tenant-daily', 'limit' => $tenantLimits['daily'], 'after' => $this->sum($capability, $tenantId, $today, $today), 'date_key' => $today, 'tenant_id' => $tenantId],
            ['period' => 'tenant-monthly', 'limit' => $tenantLimits['monthly'], 'after' => $this->sum($capability, $tenantId, $monthStart, $today), 'date_key' => $monthKey, 'tenant_id' => $tenantId],
            ['period' => 'global-daily', 'limit' => (int) $config['global_daily_units'], 'after' => $this->sum($capability, null, $today, $today), 'date_key' => $today, 'tenant_id' => null],
            ['period' => 'global-monthly', 'limit' => (int) $config['global_monthly_units'], 'after' => $this->sum($capability, null, $monthStart, $today), 'date_key' => $monthKey, 'tenant_id' => null],
        ];

        foreach ($dimensions as $dimension) {
            $limit = (int) $dimension['limit'];

            if ($limit <= 0) {
                continue;
            }

            $after = (int) $dimension['after'];
            $before = max(0, $after - $units);

            foreach ((array) config('qds.ai_budget.alert_thresholds', [50, 80, 95, 100]) as $threshold) {
                $threshold = (int) $threshold;

                if ($before * 100 < $threshold * $limit && $after * 100 >= $threshold * $limit) {
                    $this->alerts->raise(
                        AlertType::AiBudgetThreshold,
                        "{$capability}:{$dimension['period']}:{$threshold}:{$dimension['date_key']}",
                        sprintf(
                            'AI budget %s: %s usage reached %d%% (%d of %d units) for %s.',
                            $capability, $dimension['period'], $threshold, $after, $limit, $dimension['date_key'],
                        ),
                        $threshold >= 100 ? 'critical' : 'warning',
                        $dimension['tenant_id'],
                    );
                }
            }
        }
    }
```

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter AiBudgetGuardTest`
Expected: OK (13 tests).

```bash
git add app/Platform/AiBudget/AiBudgetGuard.php app/Platform/Ingestion/Support/AlertType.php tests/Feature/AiBudget/AiBudgetGuardTest.php
git commit -m "feat(ai-budget): atomic usage recording with deduplicated threshold alerts"
```

- [ ] **Step 11: Write the failing operator-command tests**

Create `tests/Feature/AiBudget/AiBudgetCommandsTest.php` (uses the SHIPPED config defaults — no overrides — so the output assertions pin the spec numbers):

```php
<?php

namespace Tests\Feature\AiBudget;

use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\AiBudget\Models\TenantAiQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §10 operator surface: the emergency read-only flag and per-tenant
 * quota overrides, both managed from the console in v1 (self-serve quota
 * purchase lands with the billing module later).
 */
class AiBudgetCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_command_flips_and_reports_the_cache_flag(): void
    {
        $this->artisan('qds:ai-read-only', ['mode' => 'on'])
            ->expectsOutputToContain('read-only mode is ON')
            ->assertExitCode(0);
        $this->assertTrue(app(AiBudgetGuard::class)->readOnly());

        $this->artisan('qds:ai-read-only', ['mode' => 'status'])
            ->expectsOutputToContain('ON (cache flag)')
            ->assertExitCode(0);

        $this->artisan('qds:ai-read-only', ['mode' => 'off'])->assertExitCode(0);
        $this->assertFalse(app(AiBudgetGuard::class)->readOnly());

        $this->artisan('qds:ai-read-only', ['mode' => 'sideways'])->assertExitCode(1);
    }

    public function test_quota_command_sets_shows_and_clears_overrides(): void
    {
        $tenantId = $this->defaultTenant->id;

        $this->artisan('qds:ai-quota', ['tenant' => $tenantId, 'capability' => 'embedding', '--daily' => 500])
            ->expectsOutputToContain('daily 500 units (override)')
            ->assertExitCode(0);

        $row = TenantAiQuota::query()->firstOrFail();
        $this->assertSame(500, $row->daily_units);
        $this->assertNull($row->monthly_units); // untouched column stays NULL → config default

        $this->artisan('qds:ai-quota', ['tenant' => $tenantId, 'capability' => 'embedding'])
            ->expectsOutputToContain('monthly 40000 units (config default)')
            ->assertExitCode(0);

        $this->artisan('qds:ai-quota', ['tenant' => $tenantId, 'capability' => 'embedding', '--clear' => true])
            ->expectsOutputToContain('Cleared')
            ->assertExitCode(0);
        $this->assertSame(0, TenantAiQuota::query()->count());
    }

    public function test_quota_command_rejects_unknown_tenant_or_capability(): void
    {
        $this->artisan('qds:ai-quota', ['tenant' => 999999, 'capability' => 'embedding'])->assertExitCode(1);

        $this->artisan('qds:ai-quota', ['tenant' => $this->defaultTenant->id, 'capability' => 'nope'])->assertExitCode(1);

        $this->assertSame(0, TenantAiQuota::query()->count());
    }
}
```

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter AiBudgetCommandsTest`
Expected: 3 errors — `There are no commands defined in the "qds" namespace matching "ai-read-only" / "ai-quota"`.

- [ ] **Step 12: Implement both commands and register them**

Create `app/Platform/AiBudget/Console/AiReadOnlyCommand.php`:

```php
<?php

namespace App\Platform\AiBudget\Console;

use App\Platform\AiBudget\AiBudgetGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Emergency AI kill switch (spec §10): flips the cache-backed flag
 * AiBudgetGuard::readOnly() consults. ON = every allows() denies across
 * ALL capabilities — new AI spend stops instantly while everything
 * already computed stays served. The env default (QDS_AI_READ_ONLY) only
 * applies while no flag is cached; a cached value wins either way.
 */
class AiReadOnlyCommand extends Command
{
    protected $signature = 'qds:ai-read-only {mode : on|off|status}';

    protected $description = 'Toggle or inspect the emergency AI read-only mode (blocks all new AI spend)';

    public function handle(AiBudgetGuard $guard): int
    {
        $mode = strtolower((string) $this->argument('mode'));

        return match ($mode) {
            'on' => $this->set(true),
            'off' => $this->set(false),
            'status' => $this->status($guard),
            default => $this->invalid($mode),
        };
    }

    private function set(bool $enabled): int
    {
        Cache::forever(AiBudgetGuard::READ_ONLY_CACHE_KEY, $enabled);

        $this->info($enabled
            ? 'AI read-only mode is ON — all new AI spend is blocked.'
            : 'AI read-only mode is OFF — budget checks apply normally.');

        return self::SUCCESS;
    }

    private function status(AiBudgetGuard $guard): int
    {
        $source = Cache::get(AiBudgetGuard::READ_ONLY_CACHE_KEY) === null
            ? 'config default (QDS_AI_READ_ONLY)'
            : 'cache flag';

        $this->info(sprintf('AI read-only mode: %s (%s).', $guard->readOnly() ? 'ON' : 'OFF', $source));

        return self::SUCCESS;
    }

    private function invalid(string $mode): int
    {
        $this->error("Invalid mode '{$mode}' — use on, off, or status.");

        return self::FAILURE;
    }
}
```

Create `app/Platform/AiBudget/Console/AiQuotaCommand.php`:

```php
<?php

namespace App\Platform\AiBudget\Console;

use App\Models\Tenant;
use App\Platform\AiBudget\Models\TenantAiQuota;
use App\Platform\AiBudget\TenantQuotaResolver;
use Illuminate\Console\Command;

/**
 * Per-tenant AI quota overrides (spec §10, v1 operator surface): sets or
 * clears the tenant_ai_quotas row and always reports the EFFECTIVE
 * limits (override column, or config default where NULL). Billing-plan
 * self-serve purchase is a documented billing-module follow-up.
 */
class AiQuotaCommand extends Command
{
    protected $signature = 'qds:ai-quota
        {tenant : Tenant id}
        {capability : Budget capability, e.g. embedding}
        {--daily= : Tenant daily unit cap (overrides the config default)}
        {--monthly= : Tenant monthly unit cap (overrides the config default)}
        {--clear : Remove the override row (back to config defaults)}';

    protected $description = 'Show or set a per-tenant AI budget quota override (NULL column = config default)';

    public function handle(TenantQuotaResolver $resolver): int
    {
        $tenantId = (int) $this->argument('tenant');
        $capability = (string) $this->argument('capability');

        if (Tenant::query()->whereKey($tenantId)->doesntExist()) {
            $this->error("Tenant {$tenantId} does not exist.");

            return self::FAILURE;
        }

        if (! is_array(config("qds.ai_budget.capabilities.{$capability}"))) {
            $this->error(sprintf(
                "Unknown capability '%s' — configured: %s.",
                $capability,
                implode(', ', array_keys((array) config('qds.ai_budget.capabilities'))),
            ));

            return self::FAILURE;
        }

        if ((bool) $this->option('clear')) {
            TenantAiQuota::query()
                ->where('tenant_id', $tenantId)
                ->where('capability', $capability)
                ->delete();

            $this->info("Cleared the {$capability} quota override for tenant {$tenantId} — config defaults apply.");

            return self::SUCCESS;
        }

        $daily = $this->option('daily');
        $monthly = $this->option('monthly');

        if ($daily !== null || $monthly !== null) {
            $quota = TenantAiQuota::query()->firstOrNew(['tenant_id' => $tenantId, 'capability' => $capability]);

            if ($daily !== null) {
                $quota->daily_units = max(0, (int) $daily);
            }

            if ($monthly !== null) {
                $quota->monthly_units = max(0, (int) $monthly);
            }

            $quota->save();
        }

        $effective = $resolver->for($tenantId, $capability);
        $override = TenantAiQuota::query()
            ->where('tenant_id', $tenantId)
            ->where('capability', $capability)
            ->first();

        $this->info(sprintf(
            'Tenant %d / %s: daily %d units (%s), monthly %d units (%s).',
            $tenantId,
            $capability,
            $effective['daily'],
            $override?->daily_units !== null ? 'override' : 'config default',
            $effective['monthly'],
            $override?->monthly_units !== null ? 'override' : 'config default',
        ));

        return self::SUCCESS;
    }
}
```

In `app/Platform/PlatformServiceProvider.php`: add to the use-block (alphabetically, after the `App\Modules\CRM\...` imports at lines 5–7):

```php
use App\Platform\AiBudget\Console\AiQuotaCommand;
use App\Platform\AiBudget\Console\AiReadOnlyCommand;
```

and in the `$this->commands([...])` list (after `PruneKeyframesCommand::class,` at line 117):

```php
                AiReadOnlyCommand::class,
                AiQuotaCommand::class,
```

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter AiBudgetCommandsTest`
Expected: OK (3 tests).

- [ ] **Step 13: Run the AiBudget-related suite and commit**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter 'AiBudgetGuardTest|AiBudgetCommandsTest|CrossTenantAlertTest'`
Expected: OK (16 + the 5 CrossTenantAlertTest tests) — the AlertType extension changed no existing alert behaviour.

```bash
git add app/Platform/AiBudget/Console app/Platform/PlatformServiceProvider.php tests/Feature/AiBudget/AiBudgetCommandsTest.php
git commit -m "feat(ai-budget): qds:ai-read-only and qds:ai-quota operator commands"
```

---

---

### Task 9: ReferencePhotoEmbedder + EmbedProductPhotoJob + qds:embed-product-photos backfill

**Files:**
- Create: `app/Platform/Enrichment/VisualMatch/ReferencePhotoEmbedder.php`
- Create: `app/Platform/Enrichment/VisualMatch/Jobs/EmbedProductPhotoJob.php`
- Create: `app/Platform/Enrichment/VisualMatch/Console/EmbedProductPhotosCommand.php`
- Create: `tests/Support/FakeEmbeddingProvider.php`
- Modify: `config/qds.php` (guarded — the `'enrichment'` array opens at line 253; insert `visual_match` between the `'text_signals'` block, which ends at line 353, and `'metrics'` at line 355 — ONLY if Task 7 has not already added it)
- Modify: `app/Platform/PlatformServiceProvider.php` (import block lines 8–43; `$this->commands([...])` list lines 103–117)
- Test: `tests/Feature/Enrichment/ReferencePhotoEmbedderTest.php` (create)
- Test: `tests/Feature/Enrichment/EmbedProductPhotosCommandTest.php` (create)

**Interfaces:**
- Consumes:
  - `App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider` (Task 7, container-bound): `embedImage(string $bytes, string $mimeType): array` (returns `list<float>`), `modelVersion(): string`, `isConfigured(): bool`. `ProviderCallRecorder` telemetry and `AiPayloadGuard::assertSafe` live INSIDE the Task 7 implementation — every billed path in this task inherits them through the interface.
  - `App\Platform\AiBudget\AiBudgetGuard` (Task 8): `allows(string $capability, int $tenantId, int $units, Priority $priority): BudgetDecision`, `record(string $capability, int $tenantId, int $units, int $postsProcessed = 0, int $postsSkippedBudget = 0, int $postsSkippedNoCandidates = 0): void`; `App\Platform\AiBudget\Priority::High`; capability string `'embedding'`; config `qds.ai_budget.*` (read_only, capabilities.embedding.global_daily_hard_units used in tests).
  - `App\Modules\CRM\Models\ProductReferencePhoto` + `ProductReferencePhotoFactory` (Task 4); `App\Modules\Monitoring\Models\ProductPhotoEmbedding` + `ProductPhotoEmbeddingFactory` (Task 5 — factory fills `embedding` with a valid 3072-dim literal; unique `(product_reference_photo_id, model_version)`).
  - `App\Platform\Enrichment\VisualMatch\Support\VectorLiteral::fromArray(array $vector): string` (Task 1).
  - `qds.ingestion.media_disk` (existing, default `'media'`); `IngestionJobBehaviour` + `TenantContext::runAs` (existing).
- Produces (later tasks rely on these verbatim):
  - `final class ReferencePhotoEmbedder { public function embed(ProductReferencePhoto $photo): bool; }` — true when an embedding row exists for `(photo, provider->modelVersion())` after the call (fresh or cached); false on skip (budget / read-only / unconfigured / missing blob). Never fabricates.
  - `class EmbedProductPhotoJob { public function __construct(public readonly int $photoId, ?string $correlationId = null); }` — queue `enrichment`, `$tries = 4`, `ShouldBeUnique` with `uniqueId() = 'photo:{photoId}:{model_version}'` (model_version from `config('qds.enrichment.visual_match.model_version')`). Task 10 dispatches it.
  - Console command `qds:embed-product-photos` (registered in `PlatformServiceProvider`).
  - `Tests\Support\FakeEmbeddingProvider` — the container stub for the provider seam (Task 10's tests reuse it; Tasks 13/19 may too).
  - Config block `qds.enrichment.visual_match.*` guaranteed present from this task onward.

- [ ] **Step 1: Ensure the `visual_match` config block exists (guarded)**

Open `config/qds.php`. If Task 7 already added a `'visual_match'` entry inside `'enrichment'` (grep for `visual_match`), verify every key below exists with these exact defaults (spec §12 is the source of truth) and make NO edit. Otherwise insert this block verbatim inside `'enrichment'` (opens line 253), between the end of the `'text_signals'` block (line 353) and `'metrics'` (line 355):

```php
        // Visual product matching (sub-project C): keyframes vs the
        // tenant's reference-photo catalog via multimodal embeddings.
        'visual_match' => [
            'enabled' => (bool) env('QDS_ENRICHMENT_VISUAL_MATCH_ENABLED', false), // kill switch, true no-op
            'model_version' => env('QDS_ENRICHMENT_VISUAL_MATCH_MODEL', 'gemini-embedding-2'), // pin exact versioned id at implementation
            'dimensions' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_DIMENSIONS', 3072),
            'frame_budget' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_FRAME_BUDGET', 12),
            'photo_cap' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_PHOTO_CAP', 8),
            'photo_link_ttl_minutes' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_PHOTO_LINK_TTL', 10),
            'thresholds' => [
                'default' => ['auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
                // per-category overrides, keys = SectorLabel values; packaging-prone stricter:
                'BEAUTY' => ['auto' => 0.70], 'FOOD_BEVERAGE' => ['auto' => 0.70],
                // NOTE: placeholders — calibration is sub-project E's mandate (eval golden set).
            ],
            'quality_filter' => [
                'enabled' => (bool) env('QDS_ENRICHMENT_VISUAL_MATCH_QUALITY_FILTER', true),
                'min_mean_luminance' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_MIN_LUMINANCE', 10),   // 0–255
                'max_mean_luminance' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_MAX_LUMINANCE', 245),
                'min_luminance_stddev' => (float) env('QDS_ENRICHMENT_VISUAL_MATCH_MIN_STDDEV', 4.0), // flat/blank proxy
            ],
            'dedup' => [
                'enabled' => (bool) env('QDS_ENRICHMENT_VISUAL_MATCH_DEDUP', true),
                'hamming_threshold' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_DEDUP_HAMMING', 6),     // of 64 dHash bits
            ],
        ],
```

- [ ] **Step 2: Write the failing embedder test (+ the shared provider fake)**

Create `tests/Support/FakeEmbeddingProvider.php`:

```php
<?php

namespace Tests\Support;

use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;

/**
 * Deterministic container stub for the EmbeddingProvider seam: counts
 * calls, records mime types, never touches the network, and returns a
 * fixed vector at the DDL width — vector(3072) rejects any other length.
 */
final class FakeEmbeddingProvider implements EmbeddingProvider
{
    public int $calls = 0;

    /** @var list<string> mime types seen, in call order */
    public array $mimeTypes = [];

    public function __construct(
        private readonly bool $configured = true,
        private readonly string $modelVersion = 'gemini-embedding-2',
        public float $fill = 0.001,
    ) {}

    /** @return list<float> */
    public function embedImage(string $bytes, string $mimeType): array
    {
        $this->calls++;
        $this->mimeTypes[] = $mimeType;

        return array_fill(0, 3072, $this->fill);
    }

    public function modelVersion(): string
    {
        return $this->modelVersion;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }
}
```

Create `tests/Feature/Enrichment/ReferencePhotoEmbedderTest.php`:

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\ReferencePhotoEmbedder;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * Spec §6: reference photos embed through the container-bound provider,
 * idempotent per (photo, model_version), budget-guarded at priority HIGH
 * (user-triggered catalog work). Skips return false and write NOTHING —
 * never a fabricated vector, never a phantom budget unit.
 */
class ReferencePhotoEmbedderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('qds.ingestion.media_disk', 'media'));
        config()->set('qds.enrichment.visual_match.model_version', 'gemini-embedding-2');
    }

    private function bindProvider(FakeEmbeddingProvider $provider): FakeEmbeddingProvider
    {
        $this->app->instance(EmbeddingProvider::class, $provider);

        return $provider;
    }

    private function makeStoredPhoto(string $extension = 'jpg'): ProductReferencePhoto
    {
        $disk = (string) config('qds.ingestion.media_disk', 'media');
        $tenantId = app(TenantContext::class)->id() ?? $this->defaultTenant->id;
        $path = "tenants/{$tenantId}/product-photos/1/".fake()->uuid().'.'.$extension;
        Storage::disk($disk)->put($path, 'image-bytes');

        return ProductReferencePhoto::factory()->create([
            'storage_disk' => $disk,
            'storage_path' => $path,
        ]);
    }

    public function test_embed_stores_one_vector_and_records_one_budget_unit(): void
    {
        $provider = $this->bindProvider(new FakeEmbeddingProvider);
        $photo = $this->makeStoredPhoto('png');

        $this->assertTrue(app(ReferencePhotoEmbedder::class)->embed($photo));

        $this->assertSame(1, $provider->calls);
        // Mime type is derived from the stored extension, not guessed.
        $this->assertSame(['image/png'], $provider->mimeTypes);
        $this->assertDatabaseHas('product_photo_embeddings', [
            'product_reference_photo_id' => $photo->id,
            'tenant_id' => $photo->tenant_id,
            'model_version' => 'gemini-embedding-2',
        ]);
        // Post-spend accounting flowed through AiBudgetGuard::record.
        $this->assertDatabaseHas('ai_usage_counters', [
            'capability' => 'embedding',
            'tenant_id' => $photo->tenant_id,
            'units' => 1,
        ]);
    }

    public function test_embed_is_idempotent_per_photo_and_model_version(): void
    {
        $provider = $this->bindProvider(new FakeEmbeddingProvider);
        $photo = $this->makeStoredPhoto();

        $embedder = app(ReferencePhotoEmbedder::class);

        $this->assertTrue($embedder->embed($photo));
        $this->assertTrue($embedder->embed($photo)); // cached — never a second bill

        $this->assertSame(1, $provider->calls);
        $this->assertSame(1, ProductPhotoEmbedding::query()
            ->where('product_reference_photo_id', $photo->id)
            ->count());
    }

    public function test_unconfigured_provider_skips_without_writing(): void
    {
        $this->bindProvider(new FakeEmbeddingProvider(configured: false));
        $photo = $this->makeStoredPhoto();

        $this->assertFalse(app(ReferencePhotoEmbedder::class)->embed($photo));

        $this->assertDatabaseCount('product_photo_embeddings', 0);
        $this->assertDatabaseCount('ai_usage_counters', 0);
    }

    public function test_read_only_mode_skips_before_calling_the_provider(): void
    {
        config()->set('qds.ai_budget.read_only', true);
        $provider = $this->bindProvider(new FakeEmbeddingProvider);
        $photo = $this->makeStoredPhoto();

        $this->assertFalse(app(ReferencePhotoEmbedder::class)->embed($photo));

        $this->assertSame(0, $provider->calls);
        $this->assertDatabaseCount('product_photo_embeddings', 0);
    }

    public function test_exhausted_global_hard_cap_stops_even_high_priority(): void
    {
        // HIGH priority ignores tenant soft caps but MUST stop at the
        // global hard caps (§10 priority semantics).
        config()->set('qds.ai_budget.capabilities.embedding.global_daily_hard_units', 0);
        $provider = $this->bindProvider(new FakeEmbeddingProvider);
        $photo = $this->makeStoredPhoto();

        $this->assertFalse(app(ReferencePhotoEmbedder::class)->embed($photo));

        $this->assertSame(0, $provider->calls);
        $this->assertDatabaseCount('product_photo_embeddings', 0);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ReferencePhotoEmbedderTest`
Expected: ERROR ×5 — `Error: Class "App\Platform\Enrichment\VisualMatch\ReferencePhotoEmbedder" not found`.

- [ ] **Step 4: Implement `ReferencePhotoEmbedder`**

Create `app/Platform/Enrichment/VisualMatch/ReferencePhotoEmbedder.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;

/**
 * Embeds ONE product reference photo through the container-bound
 * EmbeddingProvider (spec §6). Idempotent per (photo, model_version): an
 * existing embedding row short-circuits without a provider call, so the
 * upload job, the backfill command, and model upgrades can all call this
 * blindly. Skips (unconfigured / read-only / budget / missing blob)
 * return false and write NOTHING — never a fabricated vector.
 *
 * Priority is HIGH by doctrine (§10): photo embeds are user-triggered
 * catalog work — they ignore tenant soft caps and stop only at the
 * global hard caps or read-only mode. ProviderCallRecorder telemetry and
 * AiPayloadGuard live INSIDE the provider implementation, so every path
 * into a billed call shares them by construction.
 */
final class ReferencePhotoEmbedder
{
    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly AiBudgetGuard $budget,
    ) {}

    public function embed(ProductReferencePhoto $photo): bool
    {
        $modelVersion = $this->provider->modelVersion();

        $exists = ProductPhotoEmbedding::query()
            ->withoutGlobalScopes()
            ->where('product_reference_photo_id', $photo->id)
            ->where('model_version', $modelVersion)
            ->exists();

        if ($exists) {
            return true; // already embedded — idempotency, not a skip
        }

        if (! $this->provider->isConfigured()) {
            return false;
        }

        $decision = $this->budget->allows('embedding', (int) $photo->tenant_id, 1, Priority::High);

        if (! $decision->allowed) {
            return false;
        }

        $bytes = Storage::disk((string) $photo->storage_disk)->get((string) $photo->storage_path);

        if (! is_string($bytes) || $bytes === '') {
            return false; // blob missing — unavailable ≠ false, nothing written
        }

        $vector = $this->provider->embedImage($bytes, $this->mimeType($photo));

        try {
            (new ProductPhotoEmbedding)->forceFill([
                'tenant_id' => $photo->tenant_id,
                'product_reference_photo_id' => $photo->id,
                'model_version' => $modelVersion,
                'embedding' => VectorLiteral::fromArray($vector),
                'created_at' => CarbonImmutable::now(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent embed won the (photo, model_version) insert
            // race. The row exists — the goal is met; our billed call
            // still gets counted below (it really happened).
        }

        $this->budget->record('embedding', (int) $photo->tenant_id, 1);

        return true;
    }

    /**
     * Uploads are restricted to jpg/jpeg/png/webp (spec §4.1), so the
     * stored extension is a trustworthy mime source; jpeg is the default.
     */
    private function mimeType(ProductReferencePhoto $photo): string
    {
        $extension = strtolower(pathinfo((string) $photo->storage_path, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ReferencePhotoEmbedderTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/ReferencePhotoEmbedder.php tests/Support/FakeEmbeddingProvider.php tests/Feature/Enrichment/ReferencePhotoEmbedderTest.php config/qds.php
git commit -m "feat(enrichment): budget-guarded reference-photo embedder, idempotent per photo+model"
```

- [ ] **Step 7: Write the failing job tests**

Add to `tests/Feature/Enrichment/ReferencePhotoEmbedderTest.php` (import `App\Platform\Enrichment\VisualMatch\Jobs\EmbedProductPhotoJob` at the top):

```php
    public function test_job_unique_id_keys_on_photo_and_model_version(): void
    {
        $job = new EmbedProductPhotoJob(42);

        $this->assertSame('photo:42:gemini-embedding-2', $job->uniqueId());
        $this->assertSame('enrichment', $job->queue);
        $this->assertSame(4, $job->tries);
    }

    public function test_job_embeds_under_the_photos_tenant_context(): void
    {
        $provider = $this->bindProvider(new FakeEmbeddingProvider);
        $photo = $this->makeStoredPhoto();

        // Simulate the queue worker: tenant-less context (ADR-0019).
        app(TenantContext::class)->runAs(null, function () use ($photo): void {
            (new EmbedProductPhotoJob($photo->id))->handle(app(ReferencePhotoEmbedder::class));
        });

        $this->assertSame(1, $provider->calls);
        $this->assertDatabaseHas('product_photo_embeddings', [
            'product_reference_photo_id' => $photo->id,
            'tenant_id' => $photo->tenant_id,
        ]);
    }

    public function test_job_is_a_quiet_no_op_when_the_photo_is_gone(): void
    {
        $provider = $this->bindProvider(new FakeEmbeddingProvider);

        (new EmbedProductPhotoJob(999_999))->handle(app(ReferencePhotoEmbedder::class));

        $this->assertSame(0, $provider->calls);
        $this->assertDatabaseCount('product_photo_embeddings', 0);
    }
```

- [ ] **Step 8: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ReferencePhotoEmbedderTest`
Expected: 5 PASS, 3 ERROR — `Error: Class "App\Platform\Enrichment\VisualMatch\Jobs\EmbedProductPhotoJob" not found`.

- [ ] **Step 9: Implement `EmbedProductPhotoJob`**

Create `app/Platform/Enrichment/VisualMatch/Jobs/EmbedProductPhotoJob.php` (shape mirrors `EnrichContentItemJob`):

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Jobs;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Platform\Enrichment\VisualMatch\ReferencePhotoEmbedder;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queued embedding of ONE reference photo (spec §6): dispatched on upload
 * when the capability is on and the provider is configured. ShouldBeUnique
 * on (photo, model_version) keeps duplicate uploads/backfills from racing
 * the same billed call; the embedder is idempotent besides. Carries only
 * scalar identifiers (queues doctrine); transient provider failures retry
 * with backoff, permanent ones fail fast (IngestionJobBehaviour).
 */
class EmbedProductPhotoJob implements ShouldBeUnique, ShouldQueue
{
    use IngestionJobBehaviour;
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    /** Photo embeds run outside monitoring cycles. */
    public readonly ?int $cycleId;

    public readonly string $correlationId;

    public function __construct(
        public readonly int $photoId,
        ?string $correlationId = null,
    ) {
        $this->cycleId = null;
        $this->correlationId = $correlationId ?? (string) Str::uuid();
        $this->onQueue('enrichment');
    }

    public function uniqueId(): string
    {
        return 'photo:'.$this->photoId.':'.config('qds.enrichment.visual_match.model_version');
    }

    public function uniqueFor(): int
    {
        return $this->timeout + 60;
    }

    public function handle(ReferencePhotoEmbedder $embedder): void
    {
        $this->attachLogContext();

        // Worker context is tenant-less (TenantScope no-op) — the
        // EnrichContentItemJob precedent: plain find, then runAs the row's
        // tenant so every write stamps the right owner.
        $photo = ProductReferencePhoto::query()->find($this->photoId);

        if ($photo === null) {
            return; // deleted between dispatch and run — nothing to embed
        }

        try {
            app(TenantContext::class)->runAs(
                (int) $photo->tenant_id,
                fn (): bool => $embedder->embed($photo),
            );
        } catch (Throwable $e) {
            $this->handleProviderFailure($e);
        }
    }
}
```

- [ ] **Step 10: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ReferencePhotoEmbedderTest`
Expected: PASS (8 tests).

- [ ] **Step 11: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/Jobs/EmbedProductPhotoJob.php tests/Feature/Enrichment/ReferencePhotoEmbedderTest.php
git commit -m "feat(enrichment): queued EmbedProductPhotoJob unique per photo and model version"
```

- [ ] **Step 12: Write the failing backfill-command test**

Create `tests/Feature/Enrichment/EmbedProductPhotosCommandTest.php`:

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * qds:embed-product-photos (spec §6/§14): the idempotent backfill for
 * photos uploaded while the capability was off, and the model-upgrade
 * tool — embeds every (photo, model_version) pair still missing at the
 * CURRENT model version, per-tenant, through the normal budget guard.
 */
class EmbedProductPhotosCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('qds.ingestion.media_disk', 'media'));
        config()->set('qds.enrichment.visual_match.model_version', 'gemini-embedding-2');
    }

    private function makeStoredPhoto(): ProductReferencePhoto
    {
        $disk = (string) config('qds.ingestion.media_disk', 'media');
        $tenantId = app(TenantContext::class)->id() ?? $this->defaultTenant->id;
        $path = "tenants/{$tenantId}/product-photos/1/".fake()->uuid().'.jpg';
        Storage::disk($disk)->put($path, 'jpeg-bytes');

        return ProductReferencePhoto::factory()->create([
            'storage_disk' => $disk,
            'storage_path' => $path,
        ]);
    }

    public function test_backfill_embeds_only_missing_pairs_across_tenants(): void
    {
        $provider = new FakeEmbeddingProvider;
        $this->app->instance(EmbeddingProvider::class, $provider);

        $missing = $this->makeStoredPhoto();

        $done = $this->makeStoredPhoto();
        ProductPhotoEmbedding::factory()->create([
            'product_reference_photo_id' => $done->id,
            'model_version' => 'gemini-embedding-2',
        ]);

        // A second tenant's photo is picked up too — the command iterates
        // ownership with explicit predicates (scheduler is tenant-less).
        [$tenantA] = $this->makeTenantPair();
        $foreign = $this->withTenant($tenantA, fn (): ProductReferencePhoto => $this->makeStoredPhoto());

        $this->artisan('qds:embed-product-photos')
            ->expectsOutputToContain('Embedded 2, skipped 0')
            ->assertExitCode(0);

        $this->assertSame(2, $provider->calls);

        foreach ([$missing, $foreign] as $photo) {
            $this->assertDatabaseHas('product_photo_embeddings', [
                'product_reference_photo_id' => $photo->id,
                'model_version' => 'gemini-embedding-2',
                'tenant_id' => $photo->tenant_id,
            ]);
        }
    }

    public function test_unconfigured_provider_makes_the_backfill_a_no_op(): void
    {
        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(configured: false));
        $this->makeStoredPhoto();

        $this->artisan('qds:embed-product-photos')->assertExitCode(0);

        $this->assertDatabaseCount('product_photo_embeddings', 0);
    }
}
```

- [ ] **Step 13: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter EmbedProductPhotosCommandTest`
Expected: FAIL/ERROR ×2 — `There are no commands defined in the "qds" namespace matching "embed-product-photos"` (CommandNotFoundException).

- [ ] **Step 14: Implement the command and register it**

Create `app/Platform/Enrichment/VisualMatch/Console/EmbedProductPhotosCommand.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Console;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\ReferencePhotoEmbedder;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent reference-photo embedding backfill (spec §6/§14): embeds
 * every (photo, model_version) pair still missing at the CURRENT
 * model_version — photos uploaded while the capability was off, and the
 * whole catalog after a model upgrade. Budget-guarded per photo at
 * priority HIGH, so a backfill can never blow past the global hard caps;
 * a provider error on one photo never aborts the sweep (re-runnable).
 */
class EmbedProductPhotosCommand extends Command
{
    protected $signature = 'qds:embed-product-photos';

    protected $description = 'Embed reference photos missing an embedding at the configured model version';

    public function handle(
        EmbeddingProvider $provider,
        ReferencePhotoEmbedder $embedder,
        TenantContext $tenantContext,
    ): int {
        if (! $provider->isConfigured()) {
            $this->warn('Embedding provider is not configured — nothing embedded.');

            return self::SUCCESS;
        }

        $modelVersion = $provider->modelVersion();
        $embedded = 0;
        $skipped = 0;
        $failed = 0;

        // The console runs tenant-less (TenantScope no-op): ownership is an
        // explicit predicate + per-photo runAs (the PruneKeyframes pattern).
        ProductReferencePhoto::query()
            ->withoutGlobalScopes()
            ->whereNotExists(function ($query) use ($modelVersion): void {
                $query->select(DB::raw(1))
                    ->from('product_photo_embeddings')
                    ->whereColumn('product_photo_embeddings.product_reference_photo_id', 'product_reference_photos.id')
                    ->where('product_photo_embeddings.model_version', $modelVersion);
            })
            ->orderBy('id')
            ->chunkById(100, function ($photos) use ($tenantContext, $embedder, &$embedded, &$skipped, &$failed): void {
                foreach ($photos as $photo) {
                    try {
                        $done = $tenantContext->runAs(
                            (int) $photo->tenant_id,
                            fn (): bool => $embedder->embed($photo),
                        );
                    } catch (ProviderCallException) {
                        // Transient or permanent provider trouble on ONE
                        // photo: count it and keep sweeping — the command
                        // is idempotent and safely re-runnable.
                        $failed++;

                        continue;
                    }

                    $done ? $embedded++ : $skipped++;
                }
            });

        $this->info("Embedded {$embedded}, skipped {$skipped} (budget/read-only), failed {$failed} of the missing (photo, model_version) pairs.");

        return self::SUCCESS;
    }
}
```

In `app/Platform/PlatformServiceProvider.php`: add to the import block (lines 8–43, alphabetically after `use App\Platform\Enrichment\Reach\DefaultReachEstimator;` group — exact position per the file's existing alphabetical order):

```php
use App\Platform\Enrichment\VisualMatch\Console\EmbedProductPhotosCommand;
```

and add to the `$this->commands([...])` list (lines 103–117), after `PruneKeyframesCommand::class,`:

```php
                EmbedProductPhotosCommand::class,
```

- [ ] **Step 15: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter EmbedProductPhotosCommandTest`
Expected: PASS (2 tests).

- [ ] **Step 16: Run the task's full test surface**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter 'ReferencePhotoEmbedderTest|EmbedProductPhotosCommandTest'`
Expected: PASS (10 tests).

- [ ] **Step 17: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/Console/EmbedProductPhotosCommand.php app/Platform/PlatformServiceProvider.php tests/Feature/Enrichment/EmbedProductPhotosCommandTest.php
git commit -m "feat(enrichment): qds:embed-product-photos idempotent backfill command"
```

---

---

### Task 10: ProductPhotos Livewire modal + signed thumbnail route + ProductsIndex Photos action

**Files:**
- Create: `app/Modules/CRM/Http/Controllers/ProductPhotoController.php`
- Create: `app/Modules/CRM/Livewire/Products/ProductPhotos.php`
- Create: `resources/views/livewire/crm/product-photos.blade.php`
- Modify: `app/Modules/CRM/routes.php` (after the `crm.documents.download` route block, lines 69–74; import at top, lines 1–10)
- Modify: `app/Modules/CRM/CrmServiceProvider.php` (import block lines 27–40; registration after `crm.products-index` at line 138)
- Modify: `resources/views/crm/products.blade.php` (after `@livewire('crm.products-index')`, line 8)
- Modify: `app/Modules/CRM/Models/Product.php` (guarded — add `referencePhotos()` after `shipments()`, lines 76–80)
- Modify: `app/Modules/CRM/Livewire/Products/ProductsIndex.php` (`productsQuery()` lines 72–85; imports lines 1–20)
- Modify: `resources/views/livewire/crm/products-index.blade.php` (row-actions cell, lines 80–91)
- Test: `tests/Feature/Crm/ProductPhotosTest.php` (create)

**Interfaces:**
- Consumes:
  - `EmbedProductPhotoJob::__construct(public readonly int $photoId, ?string $correlationId = null)`, queue `enrichment` (Task 9); `Tests\Support\FakeEmbeddingProvider` (Task 9).
  - `EmbeddingProvider::isConfigured(): bool` (Task 7, container-bound).
  - `App\Modules\CRM\Models\ProductReferencePhoto` (Task 4 — casts `view_label` to `PhotoViewLabel`, exposes `product(): BelongsTo`, columns per spec §4.1) + factory; `App\Shared\Enums\PhotoViewLabel` (Front/Back/Side/Packaging/InUse/Other, values front/back/side/packaging/in_use/other) (Task 4); `ProductPhotoEmbedding` + factory (Task 5, cascade from photo delete).
  - Config `qds.enrichment.visual_match.{enabled,photo_cap,photo_link_ttl_minutes}` (present since Task 9), `qds.ingestion.media_disk`.
  - Existing: `ProductPolicy` (view = crm.view, update = crm.manage), `AuditLogger::record(string $action, ?Model $subject, array $context)`, `TenantContext::idOrFail()`, `SetTenantContext` middleware (tenant-scoped route binding), Blade components `x-ui.modal`/`x-ui.confirm-modal`/`x-ui.button`/`x-form.*`/`x-states.empty`.
- Produces (later tasks rely on):
  - Livewire component `crm.product-photos` (`ProductPhotos`) opened by browser event `open-product-photos` (`{ productId }`); emits `product-photos-changed` after mutations.
  - Named route `crm.products.photo` (GET `/crm/products/photos/{productReferencePhoto}`, middleware `['web','auth','signed','subscribed']`).
  - `Product::referencePhotos(): HasMany` (Task 11's cleanup queries the table directly; Task 15 may reuse the relation).
  - Audit actions `product.photo_added` / `product.photo_removed`.

- [ ] **Step 1: Write the failing signed-thumbnail-route tests**

Create `tests/Feature/Crm/ProductPhotosTest.php`:

```php
<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Products\ProductPhotos;
use App\Modules\CRM\Livewire\Products\ProductsIndex;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Jobs\EmbedProductPhotoJob;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * Reference-photo management (spec §6): private-disk blobs behind
 * short-TTL signed thumbnails (the documents precedent), server-side cap,
 * audited mutations gated on ProductPolicy::update, and the embed job
 * dispatched only when the capability can actually spend.
 */
class ProductPhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('qds.ingestion.media_disk', 'media'));
    }

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    /** Puts a real blob on the (faked) media disk and anchors a row to it. */
    private function makeStoredPhoto(Product $product): ProductReferencePhoto
    {
        $disk = (string) config('qds.ingestion.media_disk', 'media');
        $path = "tenants/{$product->tenant_id}/product-photos/{$product->id}/".fake()->uuid().'.jpg';
        Storage::disk($disk)->put($path, 'jpeg-bytes');

        return ProductReferencePhoto::factory()->create([
            'product_id' => $product->id,
            'storage_disk' => $disk,
            'storage_path' => $path,
        ]);
    }

    public function test_thumbnails_stream_via_signed_urls_only(): void
    {
        $this->actingAsCrmStaff();
        $photo = $this->makeStoredPhoto(Product::factory()->create());

        // Unsigned URL → rejected even for authorized staff.
        $this->get(route('crm.products.photo', ['productReferencePhoto' => $photo->id]))
            ->assertForbidden();

        $signed = URL::temporarySignedRoute(
            'crm.products.photo',
            now()->addMinutes(5),
            ['productReferencePhoto' => $photo->id],
        );

        $this->get($signed)->assertOk();
    }

    public function test_cross_tenant_photos_are_invisible_even_with_a_valid_signature(): void
    {
        [$tenantA] = $this->makeTenantPair();

        $foreign = $this->withTenant(
            $tenantA,
            fn (): ProductReferencePhoto => $this->makeStoredPhoto(Product::factory()->create()),
        );

        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));

        $signed = URL::temporarySignedRoute(
            'crm.products.photo',
            now()->addMinutes(5),
            ['productReferencePhoto' => $foreign->id],
        );

        // SetTenantContext scopes the route binding: the foreign row does
        // not exist for this tenant — 404, never 403 (no existence oracle).
        $this->get($signed)->assertNotFound();
    }

    public function test_client_viewers_cannot_view_thumbnails(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $photo = $this->makeStoredPhoto(Product::factory()->create());

        $signed = URL::temporarySignedRoute(
            'crm.products.photo',
            now()->addMinutes(5),
            ['productReferencePhoto' => $photo->id],
        );

        $this->get($signed)->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ProductPhotosTest`
Expected: ERROR ×3 — `Route [crm.products.photo] not defined.`

- [ ] **Step 3: Implement the controller and route**

Create `app/Modules/CRM/Http/Controllers/ProductPhotoController.php`:

```php
<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Modules\CRM\Models\ProductReferencePhoto;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a product reference photo to its authorized requester
 * (spec §6) — the DocumentDownloadController precedent: authenticated
 * session + ProductPolicy::view on the OWNING product + a valid,
 * unexpired signature. The private media disk is never exposed through a
 * public URL. Deliberately unaudited: grid renders would flood the
 * append-only log with reads of low-sensitivity catalog data (document
 * downloads stay audited; catalog thumbnails are not documents).
 */
class ProductPhotoController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, ProductReferencePhoto $productReferencePhoto): StreamedResponse
    {
        $this->authorize('view', $productReferencePhoto->product);

        $disk = Storage::disk((string) $productReferencePhoto->storage_disk);

        abort_unless($disk->exists((string) $productReferencePhoto->storage_path), 404, 'The stored product photo is missing.');

        return $disk->response((string) $productReferencePhoto->storage_path);
    }
}
```

In `app/Modules/CRM/routes.php`: add `use App\Modules\CRM\Http\Controllers\ProductPhotoController;` to the imports (lines 1–10), and after the `crm.documents.download` route block (lines 69–74) add:

```php
// Product reference-photo thumbnails (sub-project C, spec §6):
// authenticated + signed + policy-checked, streamed inline from the
// private media disk (the crm.documents.download precedent). Link TTL is
// qds.enrichment.visual_match.photo_link_ttl_minutes.
Route::middleware(['web', 'auth', 'signed', 'subscribed'])
    ->get('/crm/products/photos/{productReferencePhoto}', ProductPhotoController::class)
    ->name('crm.products.photo');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ProductPhotosTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Modules/CRM/Http/Controllers/ProductPhotoController.php app/Modules/CRM/routes.php tests/Feature/Crm/ProductPhotosTest.php
git commit -m "feat(crm): signed short-TTL product photo thumbnail route"
```

- [ ] **Step 6: Write the failing upload tests**

Add to `tests/Feature/Crm/ProductPhotosTest.php`:

```php
    public function test_upload_stores_the_blob_row_and_audit_event(): void
    {
        $staff = $this->actingAsCrmStaff();
        $product = Product::factory()->create();

        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->image('front.jpg', 40, 40))
            ->set('view_label', 'front')
            ->call('save')
            ->assertHasNoErrors();

        $photo = ProductReferencePhoto::query()->where('product_id', $product->id)->firstOrFail();

        // Spec §4.1: tenant-pathed private blob, sha256 checksum,
        // best-effort dimensions, uploader identity on the row.
        $this->assertStringStartsWith("tenants/{$photo->tenant_id}/product-photos/{$product->id}/", $photo->storage_path);
        Storage::disk((string) $photo->storage_disk)->assertExists($photo->storage_path);
        $this->assertSame('front', $photo->view_label?->value);
        $this->assertSame(
            hash('sha256', (string) Storage::disk((string) $photo->storage_disk)->get($photo->storage_path)),
            $photo->checksum,
        );
        $this->assertSame(40, $photo->width);
        $this->assertSame(40, $photo->height);
        $this->assertSame($staff->id, $photo->uploaded_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.photo_added', 'subject_id' => $photo->id]);
    }

    public function test_wrong_type_and_oversized_uploads_are_refused(): void
    {
        $this->actingAsCrmStaff();
        $product = Product::factory()->create();

        // Not an accepted image type (jpg/png/webp only in v1, spec §4.1).
        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->create('brief.pdf', 12))
            ->call('save')
            ->assertHasErrors(['upload']);

        // Above the 10 MB cap (max:10240 is kilobytes).
        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->create('huge.jpg', 10_241))
            ->call('save')
            ->assertHasErrors(['upload']);

        $this->assertDatabaseCount('product_reference_photos', 0);
    }

    public function test_the_photo_cap_is_enforced_server_side(): void
    {
        $this->actingAsCrmStaff();
        $product = Product::factory()->create();
        config()->set('qds.enrichment.visual_match.photo_cap', 2);

        $this->makeStoredPhoto($product);
        $this->makeStoredPhoto($product);

        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->image('side.jpg', 40, 40))
            ->call('save')
            ->assertHasErrors(['upload']);

        $this->assertSame(2, ProductReferencePhoto::query()->where('product_id', $product->id)->count());
    }

    public function test_upload_queues_the_embed_job_only_when_the_capability_can_spend(): void
    {
        Queue::fake();
        $this->actingAsCrmStaff();
        $product = Product::factory()->create();

        // Switch off (default): stored for later — qds:embed-product-photos
        // picks it up; no job, no spend.
        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->image('front.jpg', 40, 40))
            ->call('save')
            ->assertHasNoErrors();
        Queue::assertNothingPushed();

        // Switch on + configured provider: embed via the queue.
        config()->set('qds.enrichment.visual_match.enabled', true);
        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);

        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->image('back.jpg', 40, 40))
            ->call('save')
            ->assertHasNoErrors();

        $expected = ProductReferencePhoto::query()
            ->where('product_id', $product->id)->orderByDesc('id')->firstOrFail();

        Queue::assertPushed(
            EmbedProductPhotoJob::class,
            fn (EmbedProductPhotoJob $job): bool => $job->photoId === $expected->id && $job->queue === 'enrichment',
        );
    }
```

- [ ] **Step 7: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ProductPhotosTest`
Expected: 3 PASS, 4 ERROR — `Error: Class "App\Modules\CRM\Livewire\Products\ProductPhotos" not found`.

- [ ] **Step 8: Implement the component, view, registration, and page mount**

Create `app/Modules/CRM/Livewire/Products/ProductPhotos.php`:

```php
<?php

namespace App\Modules\CRM\Livewire\Products;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Jobs\EmbedProductPhotoJob;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\PhotoViewLabel;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Manage-photos modal for /crm/products (spec §6): reference photos are
 * the tenant's visual catalog for sub-project C. Blobs live on the
 * PRIVATE media disk under tenants/{tenant}/product-photos/{product}/
 * and are served ONLY via the short-TTL signed crm.products.photo route
 * (the documents precedent). Mutations require the same authorization as
 * product edit (ProductPolicy::update) and are audited as
 * product.photo_added / product.photo_removed with ids-only context.
 */
class ProductPhotos extends Component
{
    use WithFileUploads;

    /** Product being managed; null = modal closed. */
    public ?int $productId = null;

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public string $view_label = '';

    public ?int $confirmingDeleteId = null;

    #[On('open-product-photos')]
    public function open(int $productId): void
    {
        $product = Product::findOrFail($productId);

        $this->authorize('view', $product);

        $this->resetValidation();
        $this->upload = null;
        $this->view_label = '';
        $this->confirmingDeleteId = null;
        $this->productId = $product->id;
    }

    public function close(): void
    {
        $this->productId = null;
        $this->upload = null;
        $this->view_label = '';
        $this->confirmingDeleteId = null;
        $this->resetValidation();
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'upload' => 'photo',
            'view_label' => 'view label',
        ];
    }

    public function save(AuditLogger $audit): void
    {
        $product = Product::findOrFail($this->productId);

        $this->authorize('update', $product);

        $this->validate([
            // jpg/png/webp only in v1 (spec §4.1 — heic is model-supported
            // but renders in no browser); 10 MB mirrors the documents cap.
            'upload' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
            'view_label' => ['nullable', Rule::in(array_column(PhotoViewLabel::cases(), 'value'))],
        ]);

        $cap = (int) config('qds.enrichment.visual_match.photo_cap', 8);

        if (ProductReferencePhoto::query()->where('product_id', $product->id)->count() >= $cap) {
            $this->addError('upload', "This product already has the maximum of {$cap} photos.");

            return;
        }

        // ADR-0019: fail loudly BEFORE storing the blob so a context-less
        // request leaves no orphan file (the DocumentsPanel precedent).
        $tenantId = app(TenantContext::class)->idOrFail();

        $bytes = (string) $this->upload->get();
        $extension = strtolower($this->upload->getClientOriginalExtension());
        $disk = (string) config('qds.ingestion.media_disk', 'media');
        $path = "tenants/{$tenantId}/product-photos/{$product->id}/".Str::uuid().'.'.$extension;

        if (! Storage::disk($disk)->put($path, $bytes)) {
            throw new RuntimeException('The product photo blob could not be stored.');
        }

        // Best-effort dimensions (spec §4.1) — a corrupt-but-valid-mime
        // upload stores with null width/height rather than failing.
        $dimensions = @getimagesizefromstring($bytes) ?: null;

        $photo = ProductReferencePhoto::create([
            'product_id' => $product->id,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'view_label' => $this->view_label !== '' ? $this->view_label : null,
            'checksum' => hash('sha256', $bytes),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'uploaded_by' => Auth::id(),
        ]);

        // Ids only in the immutable audit context (house rule).
        $audit->record('product.photo_added', $photo, ['product_id' => $product->id]);

        // Embed now only when the capability can actually spend (spec §6);
        // otherwise qds:embed-product-photos picks the photo up later.
        if ((bool) config('qds.enrichment.visual_match.enabled')
            && app(EmbeddingProvider::class)->isConfigured()) {
            EmbedProductPhotoJob::dispatch($photo->id);
        }

        $this->upload = null;
        $this->view_label = '';
        $this->dispatch('product-photos-changed');
        $this->dispatch('notify', type: 'success', message: 'Photo added.');
    }

    // --- delete ------------------------------------------------------------

    public function confirmDelete(int $photoId): void
    {
        $product = Product::findOrFail($this->productId);

        $this->authorize('update', $product);

        $this->confirmingDeleteId = ProductReferencePhoto::query()
            ->where('product_id', $product->id)
            ->findOrFail($photoId)
            ->id;
    }

    public function deletePhoto(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $product = Product::findOrFail($this->productId);

        $this->authorize('update', $product);

        $photo = ProductReferencePhoto::query()
            ->where('product_id', $product->id)
            ->findOrFail($this->confirmingDeleteId);

        // House order (spec §6): the row (+ embedding rows via DB cascade)
        // goes in the transaction; the blob goes AFTER commit — a rolled-
        // back delete must leave the file in place, and an orphan blob is
        // recoverable where a dangling row is not.
        DB::transaction(function () use ($photo, $product, $audit): void {
            $photo->delete();

            $audit->record('product.photo_removed', $photo, ['product_id' => $product->id]);
        });

        Storage::disk((string) $photo->storage_disk)->delete((string) $photo->storage_path);

        $this->confirmingDeleteId = null;
        $this->dispatch('product-photos-changed');
        $this->dispatch('notify', type: 'success', message: 'Photo deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    // -------------------------------------------------------------------------

    /** Short-TTL signed thumbnail URL (never a public/static URL). */
    public function thumbnailUrl(ProductReferencePhoto $photo): string
    {
        return URL::temporarySignedRoute(
            'crm.products.photo',
            now()->addMinutes((int) config('qds.enrichment.visual_match.photo_link_ttl_minutes', 10)),
            ['productReferencePhoto' => $photo->id],
        );
    }

    public function render(): View
    {
        $product = $this->productId !== null ? Product::query()->find($this->productId) : null;

        return view('livewire.crm.product-photos', [
            'product' => $product,
            'photos' => $product !== null
                ? ProductReferencePhoto::query()->where('product_id', $product->id)->orderBy('id')->get()
                : collect(),
            'photoCap' => (int) config('qds.enrichment.visual_match.photo_cap', 8),
            'viewLabels' => PhotoViewLabel::cases(),
        ]);
    }
}
```

Create `resources/views/livewire/crm/product-photos.blade.php`:

```blade
<div>
    @if ($product !== null)
        <x-ui.modal title="Photos — {{ $product->name }}" close-action="close">
            <div class="space-y-5">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Add 3–5 diverse views (front, back, side, packaging, real-world use) so the
                    detector can recognize this product from any angle. JPG, PNG, or WebP, up to
                    10&nbsp;MB — {{ $photoCap }} photos max.
                </p>

                @if ($photos->isEmpty())
                    <x-states.empty title="No photos yet">
                        Upload the first reference photo below.
                    </x-states.empty>
                @else
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        @foreach ($photos as $photo)
                            <div wire:key="product-photo-{{ $photo->id }}" class="space-y-1.5">
                                <img src="{{ $this->thumbnailUrl($photo) }}"
                                    alt="{{ $photo->view_label?->value ?? 'Product photo' }}"
                                    class="aspect-square w-full rounded-lg border border-gray-200 object-cover dark:border-gray-800" />
                                <div class="flex items-center justify-between">
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $photo->view_label !== null ? ucwords(str_replace('_', ' ', $photo->view_label->value)) : '—' }}
                                    </span>
                                    @can('update', $product)
                                        <button type="button" wire:click="confirmDelete({{ $photo->id }})"
                                            class="text-theme-xs font-medium text-error-500 hover:text-error-600">Delete</button>
                                    @endcan
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @can('update', $product)
                    @if ($photos->count() < $photoCap)
                        <form wire:submit="save" class="space-y-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                            <div>
                                <x-form.label for="photo_upload" required>Photo</x-form.label>
                                <input id="photo_upload" type="file" wire:model="upload" accept=".jpg,.jpeg,.png,.webp"
                                    class="block w-full text-sm text-gray-500 dark:text-gray-400" />
                                <x-form.error for="upload" />
                            </div>

                            <div>
                                <x-form.label for="photo_view_label">View</x-form.label>
                                <x-form.select id="photo_view_label" wire:model="view_label" :error="$errors->has('view_label')">
                                    <option value="">No label</option>
                                    @foreach ($viewLabels as $labelOption)
                                        <option value="{{ $labelOption->value }}">{{ ucwords(str_replace('_', ' ', $labelOption->value)) }}</option>
                                    @endforeach
                                </x-form.select>
                                <x-form.error for="view_label" />
                            </div>

                            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save, upload">
                                <span wire:loading.remove wire:target="save">Add photo</span>
                                <span wire:loading wire:target="save">Uploading…</span>
                            </x-ui.button>
                        </form>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            This product has reached the {{ $photoCap }}-photo limit. Delete one to add another.
                        </p>
                    @endif
                @endcan
            </div>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="close">Close</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete photo?" confirm-action="deletePhoto" cancel-action="cancelDelete"
            confirm-label="Delete photo">
            The photo and its stored embeddings are removed. Matching for this product uses the
            remaining photos.
        </x-ui.confirm-modal>
    @endif
</div>
```

In `app/Modules/CRM/CrmServiceProvider.php`: add `use App\Modules\CRM\Livewire\Products\ProductPhotos;` to the import block (before the line-29 `ProductsIndex` import), and after `Livewire::component('crm.products-index', ProductsIndex::class);` (line 138) add:

```php
        // Sub-project C (spec §6): reference-photo management modal.
        Livewire::component('crm.product-photos', ProductPhotos::class);
```

In `resources/views/crm/products.blade.php`, after `@livewire('crm.products-index')` (line 8) add:

```blade
    @livewire('crm.product-photos')
```

- [ ] **Step 9: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ProductPhotosTest`
Expected: PASS (7 tests).

- [ ] **Step 10: Commit**

```bash
git add app/Modules/CRM/Livewire/Products/ProductPhotos.php resources/views/livewire/crm/product-photos.blade.php app/Modules/CRM/CrmServiceProvider.php resources/views/crm/products.blade.php tests/Feature/Crm/ProductPhotosTest.php
git commit -m "feat(crm): product reference-photo upload modal with cap, audit, embed dispatch"
```

- [ ] **Step 11: Write the failing delete + authorization tests**

Add to `tests/Feature/Crm/ProductPhotosTest.php`:

```php
    public function test_delete_removes_row_cascades_embeddings_and_blob_after_commit(): void
    {
        $this->actingAsCrmStaff();
        $product = Product::factory()->create();
        $photo = $this->makeStoredPhoto($product);

        ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $photo->id]);

        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->call('confirmDelete', $photo->id)
            ->call('deletePhoto');

        $this->assertDatabaseMissing('product_reference_photos', ['id' => $photo->id]);
        // Embedding rows cascade at the DB (spec §4.2).
        $this->assertDatabaseMissing('product_photo_embeddings', ['product_reference_photo_id' => $photo->id]);
        Storage::disk((string) $photo->storage_disk)->assertMissing($photo->storage_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.photo_removed', 'subject_id' => $photo->id]);
    }

    public function test_mutations_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $product = Product::factory()->create();
        $photo = $this->makeStoredPhoto($product);

        // Opening the grid is crm.view; both mutators re-authorize update —
        // including the direct-property bypass of confirmDelete.
        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->image('front.jpg', 40, 40))
            ->call('save')->assertForbidden();

        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('confirmingDeleteId', $photo->id)
            ->call('deletePhoto')->assertForbidden();

        $this->assertDatabaseCount('product_reference_photos', 1);
        Storage::disk((string) $photo->storage_disk)->assertExists($photo->storage_path);
    }
```

- [ ] **Step 12: Run test to verify the new tests' status**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ProductPhotosTest`
Expected: PASS (9 tests) — the delete/authorization paths were implemented in Step 8; these tests PIN the behaviour (cascade, blob-after-commit, policy on the bypass path). If any fails, fix the component before proceeding.

- [ ] **Step 13: Commit**

```bash
git add tests/Feature/Crm/ProductPhotosTest.php
git commit -m "test(crm): pin photo delete cascade, blob-after-commit order, manage-gated mutators"
```

- [ ] **Step 14: Write the failing ProductsIndex Photos-action test**

Add to `tests/Feature/Crm/ProductPhotosTest.php`:

```php
    public function test_products_index_row_shows_a_photos_action_with_count(): void
    {
        $this->actingAsCrmStaff();
        $product = Product::factory()->create();
        $this->makeStoredPhoto($product);
        $this->makeStoredPhoto($product);

        Livewire::test(ProductsIndex::class)
            ->assertSee('Photos (2)');
    }
```

- [ ] **Step 15: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter test_products_index_row_shows_a_photos_action_with_count`
Expected: FAIL — `Failed asserting that ... contains "Photos (2)"`.

- [ ] **Step 16: Implement the Photos action, count badge, and refresh listener**

In `app/Modules/CRM/Models/Product.php` (GUARDED — skip if Task 4 already added it): after `shipments()` (lines 76–80), add:

```php
    /** @return HasMany<ProductReferencePhoto, $this> */
    public function referencePhotos(): HasMany
    {
        return $this->hasMany(ProductReferencePhoto::class);
    }
```

(`HasMany` is already imported at line 13; `ProductReferencePhoto` lives in the same namespace — no new import.)

In `app/Modules/CRM/Livewire/Products/ProductsIndex.php`:

1. Add `use Livewire\Attributes\On;` to the imports (lines 1–20).
2. In `productsQuery()` (lines 72–85), change `Product::query()->with('brand')` to:

```php
            Product::query()
                ->with('brand')
                ->withCount('referencePhotos')
```

3. After `cancelDelete()` add:

```php
    /** Photos-modal mutations re-render the list so the badge stays fresh. */
    #[On('product-photos-changed')]
    public function refreshPhotoCounts(): void
    {
        // Intentionally empty: receiving the event triggers a re-render,
        // which re-reads reference_photos_count.
    }
```

In `resources/views/livewire/crm/products-index.blade.php`, inside the row-actions flex div (lines 80–91), BEFORE the `@can('update', $product)` Edit button, add:

```blade
                                    @can('view', $product)
                                        <button type="button"
                                            wire:click="$dispatch('open-product-photos', { productId: {{ $product->id }} })"
                                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                            Photos ({{ $product->reference_photos_count }})
                                        </button>
                                    @endcan
```

- [ ] **Step 17: Run the task's full test surface**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter 'ProductPhotosTest|ProductsCrudTest|DocumentsPanelTest'`
Expected: PASS (10 ProductPhotosTest tests + all pre-existing ProductsCrudTest/DocumentsPanelTest tests, 0 failures).

- [ ] **Step 18: Commit**

```bash
git add app/Modules/CRM/Models/Product.php app/Modules/CRM/Livewire/Products/ProductsIndex.php resources/views/livewire/crm/products-index.blade.php tests/Feature/Crm/ProductPhotosTest.php
git commit -m "feat(crm): products index Photos action with reference-photo count badge"
```

---

---

### Task 11: Product-delete blob cleanup (rows cascade in-transaction, blobs after commit)

**Files:**
- Modify: `app/Modules/CRM/Livewire/Products/ProductsIndex.php` (the `delete()` method — lines 200–231 before Task 10's edits; locate by method name after them; also imports, lines 1–20)
- Test: `tests/Feature/Crm/ProductDeletePhotoCleanupTest.php` (create)

**Interfaces:**
- Consumes: `ProductReferencePhoto` (Task 4 — `product_id` FK `cascadeOnDelete`; `storage_disk`/`storage_path` columns) + factory; `ProductPhotoEmbedding` (Task 5 — cascades from photo rows) + factory; existing restrict-FK reality (`shipments.product_id` is `constrained()` with no cascade — a referenced product cannot be deleted, migration `create_shipments` line 36); `Shipment` factory (existing).
- Produces: the hardened `ProductsIndex::delete()` — collect photo blob paths INSIDE the transaction, let rows cascade at the DB, delete blobs only AFTER commit; a rolled-back (restricted) delete leaves rows AND blobs untouched. No new public interfaces — Task 24's ADR-0029 references this lifecycle.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Crm/ProductDeletePhotoCleanupTest.php`:

```php
<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Products\ProductsIndex;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec §6 lifecycle: when a product delete proceeds, photo + embedding
 * ROWS cascade at the DB inside the transaction and the photo BLOBS are
 * removed only after commit (the GDPR house order — a rolled-back delete
 * must leave every blob in place; an orphan file is recoverable, a
 * dangling row is not).
 */
class ProductDeletePhotoCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('qds.ingestion.media_disk', 'media'));

        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    private function makeStoredPhoto(Product $product): ProductReferencePhoto
    {
        $disk = (string) config('qds.ingestion.media_disk', 'media');
        $path = "tenants/{$product->tenant_id}/product-photos/{$product->id}/".fake()->uuid().'.jpg';
        Storage::disk($disk)->put($path, 'jpeg-bytes');

        return ProductReferencePhoto::factory()->create([
            'product_id' => $product->id,
            'storage_disk' => $disk,
            'storage_path' => $path,
        ]);
    }

    public function test_product_delete_cascades_photo_rows_and_removes_blobs_after_commit(): void
    {
        $product = Product::factory()->create();
        $first = $this->makeStoredPhoto($product);
        $second = $this->makeStoredPhoto($product);
        ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $first->id]);

        Livewire::test(ProductsIndex::class)
            ->call('confirmDelete', $product->id)
            ->call('delete');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        // Rows cascade at the DB: product → photos → embeddings.
        $this->assertDatabaseCount('product_reference_photos', 0);
        $this->assertDatabaseCount('product_photo_embeddings', 0);
        // Blobs are app-managed and go after commit.
        Storage::disk((string) $first->storage_disk)->assertMissing($first->storage_path);
        Storage::disk((string) $second->storage_disk)->assertMissing($second->storage_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.deleted', 'subject_id' => $product->id]);
    }

    public function test_a_restricted_delete_keeps_rows_and_blobs(): void
    {
        $product = Product::factory()->create();
        $photo = $this->makeStoredPhoto($product);

        // shipments.product_id restricts product deletion (Step-4 schema).
        Shipment::factory()->create(['product_id' => $product->id]);

        Livewire::test(ProductsIndex::class)
            ->call('confirmDelete', $product->id)
            ->call('delete');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('product_reference_photos', ['id' => $photo->id]);
        Storage::disk((string) $photo->storage_disk)->assertExists($photo->storage_path);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'product.deleted', 'subject_id' => $product->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ProductDeletePhotoCleanupTest`
Expected: test 1 FAILS — the two `assertMissing` blob assertions report the files still exist (rows already cascade via Task 4/5 FKs; the blobs are the gap). Test 2 PASSES already (the existing QueryException catch keeps rows and blobs) — it pins the rollback contract against regression.

- [ ] **Step 3: Implement the blob cleanup in the delete path**

In `app/Modules/CRM/Livewire/Products/ProductsIndex.php`: add `use App\Modules\CRM\Models\ProductReferencePhoto;` and `use Illuminate\Support\Facades\Storage;` to the imports (lines 1–20), then replace the whole `delete()` method (lines 200–226 pre-Task-10) with:

```php
    public function delete(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $product = Product::findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $product);

        /** @var list<array{disk: string, path: string}> $blobs */
        $blobs = [];

        try {
            // Savepoint so a restrict-FK refusal leaves the connection
            // usable. Blob paths are collected INSIDE the transaction —
            // the photo rows are gone once the product cascade fires —
            // and the files are deleted only AFTER commit (spec §6, the
            // GDPR house order): a rolled-back delete must leave every
            // blob in place.
            DB::transaction(function () use ($product, &$blobs): void {
                $blobs = ProductReferencePhoto::query()
                    ->where('product_id', $product->id)
                    ->get(['storage_disk', 'storage_path'])
                    ->map(fn (ProductReferencePhoto $photo): array => [
                        'disk' => (string) $photo->storage_disk,
                        'path' => (string) $photo->storage_path,
                    ])
                    ->all();

                $product->delete();
            });
        } catch (QueryException) {
            $this->confirmingDeleteId = null;
            $this->dispatch('notify', type: 'error', message: 'Cannot delete: this product is referenced by seeding runs or shipments.');

            return;
        }

        // After commit: photo + embedding ROWS are already gone via the DB
        // cascade; the blobs go last, best-effort — an orphan file is
        // recoverable, a dangling row is not (the M31 ordering).
        foreach ($blobs as $blob) {
            Storage::disk($blob['disk'])->delete($blob['path']);
        }

        $audit->record('product.deleted', $product, ['name' => $product->name]);

        $this->confirmingDeleteId = null;
        $this->clearSelection();
        $this->clampPage();
        $this->dispatch('notify', type: 'success', message: 'Product deleted.');
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ProductDeletePhotoCleanupTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the task's full test surface**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter 'ProductDeletePhotoCleanupTest|ProductsCrudTest|ProductPhotosTest'`
Expected: PASS — 2 + all pre-existing ProductsCrudTest tests + the 10 ProductPhotosTest tests, 0 failures (the existing delete/restrict tests in ProductsCrudTest must stay green: behaviour for photo-less products is unchanged).

- [ ] **Step 6: Commit**

```bash
git add app/Modules/CRM/Livewire/Products/ProductsIndex.php tests/Feature/Crm/ProductDeletePhotoCleanupTest.php
git commit -m "feat(crm): product delete removes reference-photo blobs after commit"
```

---

### Task 12: Frame preparation (quality filter, dHash dedup, budget)

**Files:**
- Create: `app/Platform/Enrichment/VisualMatch/Frames/PreparedFrame.php`
- Create: `app/Platform/Enrichment/VisualMatch/Frames/FramePreparationResult.php`
- Create: `app/Platform/Enrichment/VisualMatch/Frames/FrameQualityFilter.php`
- Create: `app/Platform/Enrichment/VisualMatch/Frames/FrameDeduplicator.php`
- Create: `app/Platform/Enrichment/VisualMatch/Frames/FramePreparation.php`
- Modify: `config/qds.php` (inside the `'enrichment'` array starting line 253; the `qds.enrichment.visual_match` block — created by the earlier reference-photo tasks — gains its `quality_filter` and `dedup` sub-blocks; the `keyframes` block currently ends at line 309)
- Test: `tests/Feature/Enrichment/FrameQualityFilterTest.php` (create)
- Test: `tests/Feature/Enrichment/FrameDeduplicatorTest.php` (create)
- Test: `tests/Feature/Enrichment/FramePreparationTest.php` (create)

**Interfaces:**
- Consumes: `App\Platform\Enrichment\Keyframes\KeyframeSet` (existing B contract: `public array $frames` ordered by ordinal, `public string $status`, `isEmpty(): bool`); `App\Modules\Monitoring\Models\Keyframe` (`timestamp_ms` nullable, per-row `storage_disk`/`storage_path`, unique `(owner_type, owner_id, ordinal)`) and `Keyframe::factory()` from Task 3 (overridable `owner_type`, `owner_id`, `ordinal`, `timestamp_ms`, `storage_disk`, `storage_path`); config keys `qds.enrichment.visual_match.quality_filter.{enabled,min_mean_luminance,max_mean_luminance,min_luminance_stddev}` and `qds.enrichment.visual_match.dedup.{enabled,hamming_threshold}` (created here, spec §12 verbatim).
- Produces (frozen contract, verbatim — Tasks 13/16/17/19 rely on these):
```php
final readonly class PreparedFrame {
    public function __construct(
        public \App\Modules\Monitoring\Models\Keyframe $keyframe,
        public string $bytes, public string $mimeType,
        public int $representedFrames,           // 1 + dedup group members it represents
        public ?int $spanStartMs, public ?int $spanEndMs,
    ) {}
}
final readonly class FramePreparationResult {
    /** @param list<PreparedFrame> $frames */
    public function __construct(
        public array $frames, public int $framesAvailable,
        public int $skippedFormat, public int $skippedQuality, public int $deduped,
    ) {}
    public function coverageDegraded(): bool;    // skippedFormat + skippedQuality > 0 || frames === []
}
final class FramePreparation {
    public function prepare(\App\Platform\Enrichment\Keyframes\KeyframeSet $set, int $budget): FramePreparationResult;
}
final class FrameQualityFilter {   // returns null if frame passes, else 'undecodable'|'too-dark'|'too-bright'|'flat'
    public function rejectionReason(string $bytes): ?string;
}
final class FrameDeduplicator {    // 64-bit dHash; groups frames within hamming threshold
    /** @param list<array{keyframe: Keyframe, bytes: string, mimeType: string}> $frames
     *  @return list<PreparedFrame> */
    public function deduplicate(array $frames): array;
}
```
Doctrine encoded here (Tasks 17/19/21 must count consistently): `undecodable` is a FORMAT loss (spec §5 — counted into `skippedFormat` and reported by the filter even when `quality_filter.enabled` is false); `too-dark`/`too-bright`/`flat` are QUALITY losses; `heic`/`heif` are model-supported but GD-undecodable, so they bypass quality/dedup analysis and embed as-is; the `$budget` cap truncates silently (cost guard, not coverage loss).

- [ ] **Step 1: Write the failing quality-filter test** (all fixture images generated with GD in-test — no binary fixtures):

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\VisualMatch\Frames\FrameQualityFilter;
use Tests\TestCase;

/**
 * Local, free garbage detection before any embedding spend (spec §8 step 2).
 * Deliberately loose defaults: this filter skips garbage, it does not judge
 * photography.
 */
class FrameQualityFilterTest extends TestCase
{
    private function filter(): FrameQualityFilter
    {
        return new FrameQualityFilter;
    }

    /** Solid-color grayscale JPEG (mean ≈ the level, stddev ≈ 0). */
    private function solidJpeg(int $level): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefilledrectangle($image, 0, 0, 63, 63, (int) imagecolorallocate($image, $level, $level, $level));

        return $this->jpegBytes($image);
    }

    /** Left-to-right grayscale ramp: mid mean, high stddev — a "normal" frame. */
    private function rampJpeg(): string
    {
        $image = imagecreatetruecolor(64, 64);

        for ($x = 0; $x < 64; $x++) {
            $level = min(255, $x * 4);
            imagefilledrectangle($image, $x, 0, $x, 63, (int) imagecolorallocate($image, $level, $level, $level));
        }

        return $this->jpegBytes($image);
    }

    private function jpegBytes(\GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function test_quality_filter_and_dedup_config_defaults_are_registered(): void
    {
        $this->assertTrue((bool) config('qds.enrichment.visual_match.quality_filter.enabled'));
        $this->assertSame(10, config('qds.enrichment.visual_match.quality_filter.min_mean_luminance'));
        $this->assertSame(245, config('qds.enrichment.visual_match.quality_filter.max_mean_luminance'));
        $this->assertSame(4.0, config('qds.enrichment.visual_match.quality_filter.min_luminance_stddev'));
        $this->assertTrue((bool) config('qds.enrichment.visual_match.dedup.enabled'));
        $this->assertSame(6, config('qds.enrichment.visual_match.dedup.hamming_threshold'));
    }

    public function test_a_normal_frame_passes(): void
    {
        $this->assertNull($this->filter()->rejectionReason($this->rampJpeg()));
    }

    public function test_undecodable_bytes_are_rejected(): void
    {
        $this->assertSame('undecodable', $this->filter()->rejectionReason('definitely-not-an-image'));
    }

    public function test_extreme_darkness_is_rejected(): void
    {
        $this->assertSame('too-dark', $this->filter()->rejectionReason($this->solidJpeg(3)));
    }

    public function test_overexposure_is_rejected(): void
    {
        $this->assertSame('too-bright', $this->filter()->rejectionReason($this->solidJpeg(252)));
    }

    public function test_flat_frames_are_rejected(): void
    {
        $this->assertSame('flat', $this->filter()->rejectionReason($this->solidJpeg(128)));
    }

    public function test_disabling_the_filter_keeps_only_garbage_detection(): void
    {
        config(['qds.enrichment.visual_match.quality_filter.enabled' => false]);

        // Photographic judgments are off …
        $this->assertNull($this->filter()->rejectionReason($this->solidJpeg(3)));
        // … but undecodable garbage is a FORMAT concern (§5) and still reported.
        $this->assertSame('undecodable', $this->filter()->rejectionReason('definitely-not-an-image'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter FrameQualityFilterTest`
Expected: ERROR — `Class "App\Platform\Enrichment\VisualMatch\Frames\FrameQualityFilter" not found` (and, for the config test, `Failed asserting that null is identical to 10`).

- [ ] **Step 3: Add the config blocks and implement `FrameQualityFilter`**

In `config/qds.php`, inside `'enrichment' => [ … 'visual_match' => [ … ] ]` (the block the reference-photo tasks created; if it does not exist yet at this point in execution, create the full block exactly per spec §12 instead), append after `'thresholds'` (spec §12 verbatim):

```php
            'quality_filter' => [
                'enabled' => (bool) env('QDS_ENRICHMENT_VISUAL_MATCH_QUALITY_FILTER', true),
                'min_mean_luminance' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_MIN_LUMINANCE', 10),   // 0–255
                'max_mean_luminance' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_MAX_LUMINANCE', 245),
                'min_luminance_stddev' => (float) env('QDS_ENRICHMENT_VISUAL_MATCH_MIN_STDDEV', 4.0), // flat/blank proxy
            ],
            'dedup' => [
                'enabled' => (bool) env('QDS_ENRICHMENT_VISUAL_MATCH_DEDUP', true),
                'hamming_threshold' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_DEDUP_HAMMING', 6),     // of 64 dHash bits
            ],
```

Create `app/Platform/Enrichment/VisualMatch/Frames/FrameQualityFilter.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Frames;

/**
 * Local, free frame triage before any embedding spend (spec §8 step 2):
 * drops frames that cannot produce a reliable match — undecodable bytes,
 * extreme darkness/overexposure (mean luminance on a downsampled grayscale
 * copy) or near-zero luminance variance (flat/blank/heavily-blurred proxy).
 * Deliberately loose defaults: this filter skips garbage, it does not
 * judge photography.
 *
 * Gating: the quality_filter.enabled switch turns off only the
 * PHOTOGRAPHIC judgments. Undecodable content is a FORMAT concern
 * (spec §5 — frames_skipped_format covers "unknown or undecodable") and
 * is always reported; FramePreparation counts it accordingly.
 */
final class FrameQualityFilter
{
    public const REASON_UNDECODABLE = 'undecodable';

    public const REASON_TOO_DARK = 'too-dark';

    public const REASON_TOO_BRIGHT = 'too-bright';

    public const REASON_FLAT = 'flat';

    private const SAMPLE_SIZE = 32;

    /** Null when the frame passes; otherwise the rejection reason. */
    public function rejectionReason(string $bytes): ?string
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return self::REASON_UNDECODABLE;
        }

        if (! (bool) config('qds.enrichment.visual_match.quality_filter.enabled')) {
            imagedestroy($image);

            return null;
        }

        [$mean, $stddev] = $this->luminanceStats($image);
        imagedestroy($image);

        return match (true) {
            $mean < (int) config('qds.enrichment.visual_match.quality_filter.min_mean_luminance') => self::REASON_TOO_DARK,
            $mean > (int) config('qds.enrichment.visual_match.quality_filter.max_mean_luminance') => self::REASON_TOO_BRIGHT,
            $stddev < (float) config('qds.enrichment.visual_match.quality_filter.min_luminance_stddev') => self::REASON_FLAT,
            default => null,
        };
    }

    /** @return array{0: float, 1: float} mean + stddev of downsampled grayscale luminance (0–255) */
    private function luminanceStats(\GdImage $image): array
    {
        $sample = imagecreatetruecolor(self::SAMPLE_SIZE, self::SAMPLE_SIZE);
        imagecopyresampled($sample, $image, 0, 0, 0, 0, self::SAMPLE_SIZE, self::SAMPLE_SIZE, imagesx($image), imagesy($image));

        $values = [];

        for ($y = 0; $y < self::SAMPLE_SIZE; $y++) {
            for ($x = 0; $x < self::SAMPLE_SIZE; $x++) {
                $rgb = imagecolorat($sample, $x, $y);
                $values[] = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
            }
        }

        imagedestroy($sample);

        $mean = array_sum($values) / count($values);
        $variance = 0.0;

        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return [$mean, sqrt($variance / count($values))];
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter FrameQualityFilterTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add config/qds.php app/Platform/Enrichment/VisualMatch/Frames/FrameQualityFilter.php tests/Feature/Enrichment/FrameQualityFilterTest.php
git commit -m "feat(enrichment): visual-match quality filter + frame-prep config"
```

- [ ] **Step 6: Write the failing deduplicator test.** The near-duplicate fixture is a brightness-shifted copy of the same ramp: a monotonic +2 shift (no clipping — max 63×4+2 = 254) preserves every adjacent-pixel comparison, so its dHash is bit-identical (hamming 0 ≤ 6) — fully deterministic. The distinct fixture is the REVERSED ramp: every comparison flips (hamming 64).

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Enrichment\VisualMatch\Frames\FrameDeduplicator;
use Tests\TestCase;

/**
 * 64-bit difference-hash near-duplicate grouping (spec §8 step 3): only
 * the earliest representative of a group is embedded; dedup reduces cost,
 * never evidence (represented_frames + span carry the group forward).
 */
class FrameDeduplicatorTest extends TestCase
{
    /** Left-to-right grayscale ramp; +$shift is monotonic → identical dHash. */
    private function rampJpeg(int $shift = 0, bool $reversed = false): string
    {
        $image = imagecreatetruecolor(64, 64);

        for ($x = 0; $x < 64; $x++) {
            $level = min(255, ($reversed ? 63 - $x : $x) * 4 + $shift);
            imagefilledrectangle($image, $x, 0, $x, 63, (int) imagecolorallocate($image, $level, $level, $level));
        }

        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** @return array{keyframe: Keyframe, bytes: string, mimeType: string} */
    private function frame(string $bytes, int $ordinal, ?int $timestampMs): array
    {
        return [
            'keyframe' => new Keyframe(['ordinal' => $ordinal, 'timestamp_ms' => $timestampMs]),
            'bytes' => $bytes,
            'mimeType' => 'image/jpeg',
        ];
    }

    private function deduplicator(): FrameDeduplicator
    {
        return new FrameDeduplicator;
    }

    public function test_identical_frames_group_under_the_earliest_representative(): void
    {
        $bytes = $this->rampJpeg();
        $prepared = $this->deduplicator()->deduplicate([
            $this->frame($bytes, 0, 0),
            $this->frame($bytes, 1, 6000),
            $this->frame($bytes, 2, 12000),
        ]);

        $this->assertCount(1, $prepared);
        $this->assertSame(0, $prepared[0]->keyframe->ordinal);
        $this->assertSame(3, $prepared[0]->representedFrames);
        $this->assertSame(0, $prepared[0]->spanStartMs);
        $this->assertSame(12000, $prepared[0]->spanEndMs);
        $this->assertSame($bytes, $prepared[0]->bytes);
    }

    public function test_a_brightness_shift_stays_within_the_hamming_threshold(): void
    {
        $prepared = $this->deduplicator()->deduplicate([
            $this->frame($this->rampJpeg(), 0, 0),
            $this->frame($this->rampJpeg(2), 1, 6000),
        ]);

        $this->assertCount(1, $prepared);
        $this->assertSame(2, $prepared[0]->representedFrames);
    }

    public function test_distinct_frames_survive_as_their_own_groups(): void
    {
        $prepared = $this->deduplicator()->deduplicate([
            $this->frame($this->rampJpeg(), 0, 0),
            $this->frame($this->rampJpeg(reversed: true), 1, 6000),
        ]);

        $this->assertCount(2, $prepared);
        $this->assertSame([1, 1], [$prepared[0]->representedFrames, $prepared[1]->representedFrames]);
        $this->assertSame(0, $prepared[0]->spanStartMs);
        $this->assertSame(0, $prepared[0]->spanEndMs);
        $this->assertSame(6000, $prepared[1]->spanStartMs);
    }

    public function test_null_timestamp_groups_carry_a_null_span(): void
    {
        $bytes = $this->rampJpeg();
        $prepared = $this->deduplicator()->deduplicate([
            $this->frame($bytes, 0, null),
            $this->frame($bytes, 1, null),
        ]);

        $this->assertCount(1, $prepared);
        $this->assertSame(2, $prepared[0]->representedFrames);
        $this->assertNull($prepared[0]->spanStartMs);
        $this->assertNull($prepared[0]->spanEndMs);
    }

    public function test_disabled_dedup_keeps_every_frame(): void
    {
        config(['qds.enrichment.visual_match.dedup.enabled' => false]);

        $bytes = $this->rampJpeg();
        $prepared = $this->deduplicator()->deduplicate([
            $this->frame($bytes, 0, 0),
            $this->frame($bytes, 1, 6000),
        ]);

        $this->assertCount(2, $prepared);
        $this->assertSame(1, $prepared[0]->representedFrames);
        $this->assertSame(6000, $prepared[1]->spanStartMs);
        $this->assertSame(6000, $prepared[1]->spanEndMs);
    }

    public function test_undecodable_bytes_never_group(): void
    {
        $prepared = $this->deduplicator()->deduplicate([
            $this->frame('GARBAGE-BYTES', 0, 0),
            $this->frame('GARBAGE-BYTES', 1, 6000),
        ]);

        $this->assertCount(2, $prepared);
    }
}
```

- [ ] **Step 7: Run it to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter FrameDeduplicatorTest`
Expected: ERROR — `Class "App\Platform\Enrichment\VisualMatch\Frames\FrameDeduplicator" not found`.

- [ ] **Step 8: Implement `PreparedFrame` and `FrameDeduplicator`**

Create `app/Platform/Enrichment/VisualMatch/Frames/PreparedFrame.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Frames;

use App\Modules\Monitoring\Models\Keyframe;

/**
 * One frame that survived preparation and is worth embedding. A
 * representative of a near-duplicate group carries the group's size and
 * timestamp span — dedup reduces cost, never evidence (spec §8): the
 * BandMapper's support counting reads representedFrames, and the
 * visibility evidence reads the span.
 */
final readonly class PreparedFrame
{
    public function __construct(
        public Keyframe $keyframe,
        public string $bytes,
        public string $mimeType,
        /** 1 + the dedup-group members this frame represents. */
        public int $representedFrames,
        public ?int $spanStartMs,
        public ?int $spanEndMs,
    ) {}
}
```

Create `app/Platform/Enrichment/VisualMatch/Frames/FrameDeduplicator.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Frames;

/**
 * Near-duplicate grouping via 64-bit difference-hash (spec §8 step 3):
 * each frame joins the FIRST earlier group within the hamming threshold,
 * else starts its own; only the earliest representative is embedded.
 * Undecodable bytes hash to null and never group (each stays a singleton
 * — the quality filter, not this class, decides their fate). Iteration
 * order is input order (ordinal), so grouping is fully deterministic.
 */
final class FrameDeduplicator
{
    /**
     * @param  list<array{keyframe: \App\Modules\Monitoring\Models\Keyframe, bytes: string, mimeType: string}>  $frames
     * @return list<PreparedFrame>
     */
    public function deduplicate(array $frames): array
    {
        $enabled = (bool) config('qds.enrichment.visual_match.dedup.enabled');
        $threshold = (int) config('qds.enrichment.visual_match.dedup.hamming_threshold');

        /** @var list<array{hash: int|null, members: list<array{keyframe: \App\Modules\Monitoring\Models\Keyframe, bytes: string, mimeType: string}>}> $groups */
        $groups = [];

        foreach ($frames as $frame) {
            $hash = $enabled ? $this->dHash($frame['bytes']) : null;

            if ($enabled && $hash !== null) {
                foreach ($groups as $index => $group) {
                    if ($group['hash'] !== null && $this->hammingDistance($group['hash'], $hash) <= $threshold) {
                        $groups[$index]['members'][] = $frame;

                        continue 2;
                    }
                }
            }

            $groups[] = ['hash' => $hash, 'members' => [$frame]];
        }

        return array_map(fn (array $group): PreparedFrame => $this->prepared($group['members']), $groups);
    }

    /** @param non-empty-list<array{keyframe: \App\Modules\Monitoring\Models\Keyframe, bytes: string, mimeType: string}> $members */
    private function prepared(array $members): PreparedFrame
    {
        $representative = $members[0];
        $timestamps = [];

        foreach ($members as $member) {
            if ($member['keyframe']->timestamp_ms !== null) {
                $timestamps[] = (int) $member['keyframe']->timestamp_ms;
            }
        }

        return new PreparedFrame(
            keyframe: $representative['keyframe'],
            bytes: $representative['bytes'],
            mimeType: $representative['mimeType'],
            representedFrames: count($members),
            spanStartMs: $timestamps === [] ? null : min($timestamps),
            spanEndMs: $timestamps === [] ? null : max($timestamps),
        );
    }

    /** 64-bit dHash of a 9×8 downsampled grayscale copy; null when undecodable. */
    private function dHash(string $bytes): ?int
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $sample = imagecreatetruecolor(9, 8);
        imagecopyresampled($sample, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image));
        imagedestroy($image);

        $hash = 0;
        $bit = 0;

        for ($y = 0; $y < 8; $y++) {
            $previous = $this->luminanceAt($sample, 0, $y);

            for ($x = 1; $x < 9; $x++) {
                $current = $this->luminanceAt($sample, $x, $y);

                if ($current > $previous) {
                    $hash |= 1 << $bit;
                }

                $bit++;
                $previous = $current;
            }
        }

        imagedestroy($sample);

        return $hash;
    }

    private function luminanceAt(\GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);

        return 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
    }

    /** Popcount of XOR — logical shift keeps bit 63 (a negative int) safe. */
    private function hammingDistance(int $a, int $b): int
    {
        $xor = $a ^ $b;
        $distance = 0;

        while ($xor !== 0) {
            $distance += $xor & 1;
            $xor = ($xor >> 1) & PHP_INT_MAX;
        }

        return $distance;
    }
}
```

- [ ] **Step 9: Run it to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter FrameDeduplicatorTest`
Expected: PASS (6 tests).

- [ ] **Step 10: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/Frames/PreparedFrame.php app/Platform/Enrichment/VisualMatch/Frames/FrameDeduplicator.php tests/Feature/Enrichment/FrameDeduplicatorTest.php
git commit -m "feat(enrichment): visual-match dHash frame deduplicator"
```

- [ ] **Step 11: Write the failing frame-preparation test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Enrichment\Keyframes\KeyframeSet;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparation;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Spec §8 steps 1–3 over stored keyframes: format check → quality filter
 * → dedup → budget. Skips are coverage LOSS ("unavailable ≠ false"),
 * counted per kind; the budget cap is a cost guard, not coverage loss.
 */
class FramePreparationTest extends TestCase
{
    use RefreshDatabase;

    private ContentItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qds.ingestion.media_disk' => 'media']);
        Storage::fake('media');

        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $this->item = ContentItem::factory()->for($account, 'platformAccount')->create();
    }

    /** Left-to-right grayscale ramp; +$shift monotonic → identical dHash. */
    private function rampJpeg(int $shift = 0, bool $reversed = false): string
    {
        $image = imagecreatetruecolor(64, 64);

        for ($x = 0; $x < 64; $x++) {
            $level = min(255, ($reversed ? 63 - $x : $x) * 4 + $shift);
            imagefilledrectangle($image, $x, 0, $x, 63, (int) imagecolorallocate($image, $level, $level, $level));
        }

        return $this->jpegBytes($image);
    }

    /** Half-dark/half-bright: distinct dHash from both ramps (≈8 vs 0/64 rising bits). */
    private function halfJpeg(): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefilledrectangle($image, 0, 0, 31, 63, (int) imagecolorallocate($image, 20, 20, 20));
        imagefilledrectangle($image, 32, 0, 63, 63, (int) imagecolorallocate($image, 235, 235, 235));

        return $this->jpegBytes($image);
    }

    private function solidJpeg(int $level): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefilledrectangle($image, 0, 0, 63, 63, (int) imagecolorallocate($image, $level, $level, $level));

        return $this->jpegBytes($image);
    }

    private function jpegBytes(\GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** $bytes null = row exists but the blob is missing from the disk. */
    private function makeKeyframe(int $ordinal, ?int $timestampMs, string $extension, ?string $bytes): Keyframe
    {
        $path = "tenants/{$this->defaultTenant->id}/keyframes/instagram/{$this->item->platform_account_id}/content-x/{$ordinal}.{$extension}";

        if ($bytes !== null) {
            Storage::disk('media')->put($path, $bytes);
        }

        return Keyframe::factory()->create([
            'owner_type' => $this->item->getMorphClass(),
            'owner_id' => $this->item->id,
            'ordinal' => $ordinal,
            'timestamp_ms' => $timestampMs,
            'storage_disk' => 'media',
            'storage_path' => $path,
        ]);
    }

    /** @param list<Keyframe> $frames */
    private function prepare(array $frames, int $budget = 12): FramePreparationResult
    {
        return app(FramePreparation::class)->prepare(
            new KeyframeSet($frames, $frames === [] ? 'empty' : 'extracted'),
            $budget,
        );
    }

    public function test_supported_distinct_frames_survive_in_ordinal_order(): void
    {
        $frames = [
            $this->makeKeyframe(0, 0, 'jpg', $this->rampJpeg()),
            $this->makeKeyframe(1, 6000, 'jpg', $this->halfJpeg()),
            $this->makeKeyframe(2, 12000, 'jpg', $this->rampJpeg(reversed: true)),
        ];

        $result = $this->prepare($frames);

        $this->assertSame([0, 1, 2], array_map(fn ($f): int => $f->keyframe->ordinal, $result->frames));
        $this->assertSame('image/jpeg', $result->frames[0]->mimeType);
        $this->assertSame(3, $result->framesAvailable);
        $this->assertSame(0, $result->skippedFormat);
        $this->assertSame(0, $result->skippedQuality);
        $this->assertSame(0, $result->deduped);
        $this->assertFalse($result->coverageDegraded());
    }

    public function test_the_frame_budget_caps_prepared_frames(): void
    {
        $frames = [
            $this->makeKeyframe(0, 0, 'jpg', $this->rampJpeg()),
            $this->makeKeyframe(1, 6000, 'jpg', $this->halfJpeg()),
            $this->makeKeyframe(2, 12000, 'jpg', $this->rampJpeg(reversed: true)),
        ];

        $result = $this->prepare($frames, budget: 2);

        $this->assertSame([0, 1], array_map(fn ($f): int => $f->keyframe->ordinal, $result->frames));
        // Truncation is a cost guard, never coverage loss.
        $this->assertSame(3, $result->framesAvailable);
        $this->assertFalse($result->coverageDegraded());
    }

    public function test_unknown_format_and_missing_blob_are_format_loss(): void
    {
        $frames = [
            $this->makeKeyframe(0, 0, 'bin', 'whatever'),
            $this->makeKeyframe(1, 6000, 'jpg', null),
        ];

        $result = $this->prepare($frames);

        $this->assertSame([], $result->frames);
        $this->assertSame(2, $result->skippedFormat);
        $this->assertTrue($result->coverageDegraded());
    }

    public function test_garbage_and_dark_frames_are_skipped_by_kind(): void
    {
        $frames = [
            $this->makeKeyframe(0, 0, 'jpg', 'GARBAGE-NOT-JPEG'),
            $this->makeKeyframe(1, 6000, 'jpg', $this->solidJpeg(3)),
            $this->makeKeyframe(2, 12000, 'jpg', $this->rampJpeg()),
        ];

        $result = $this->prepare($frames);

        $this->assertCount(1, $result->frames);
        $this->assertSame(1, $result->skippedFormat);   // undecodable = format (§5)
        $this->assertSame(1, $result->skippedQuality);  // too-dark = quality
        $this->assertTrue($result->coverageDegraded());
    }

    public function test_near_duplicates_collapse_into_a_represented_span(): void
    {
        $frames = [
            $this->makeKeyframe(0, 0, 'jpg', $this->rampJpeg()),
            $this->makeKeyframe(1, 6000, 'jpg', $this->rampJpeg(2)),
            $this->makeKeyframe(2, 12000, 'jpg', $this->rampJpeg(reversed: true)),
        ];

        $result = $this->prepare($frames);

        $this->assertCount(2, $result->frames);
        $this->assertSame(2, $result->frames[0]->representedFrames);
        $this->assertSame(0, $result->frames[0]->spanStartMs);
        $this->assertSame(6000, $result->frames[0]->spanEndMs);
        $this->assertSame(1, $result->frames[1]->representedFrames);
        $this->assertSame(12000, $result->frames[1]->spanStartMs);
        $this->assertSame(1, $result->deduped);
        $this->assertFalse($result->coverageDegraded());
    }

    public function test_heic_frames_bypass_local_analysis_and_proceed(): void
    {
        // GD cannot decode HEIC, but the MODEL accepts it (spec §5) — the
        // frame must reach embedding unfiltered, not die as "undecodable".
        $result = $this->prepare([$this->makeKeyframe(0, null, 'heic', 'HEIC-BYTES')]);

        $this->assertCount(1, $result->frames);
        $this->assertSame('image/heic', $result->frames[0]->mimeType);
        $this->assertSame('HEIC-BYTES', $result->frames[0]->bytes);
        $this->assertSame(1, $result->frames[0]->representedFrames);
        $this->assertSame(0, $result->skippedFormat);
        $this->assertSame(0, $result->skippedQuality);
        $this->assertFalse($result->coverageDegraded());
    }

    public function test_an_empty_keyframe_set_is_degraded_coverage(): void
    {
        $result = $this->prepare([]);

        $this->assertSame([], $result->frames);
        $this->assertSame(0, $result->framesAvailable);
        $this->assertTrue($result->coverageDegraded());
    }
}
```

- [ ] **Step 12: Run it to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter FramePreparationTest`
Expected: ERROR — `Class "App\Platform\Enrichment\VisualMatch\Frames\FramePreparation" not found`.

- [ ] **Step 13: Implement `FramePreparationResult` and `FramePreparation`**

Create `app/Platform/Enrichment/VisualMatch/Frames/FramePreparationResult.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Frames;

/**
 * Outcome of local frame preparation with full coverage accounting —
 * a skipped frame is coverage LOSS ("unavailable ≠ false"), never treated
 * as absence of the product.
 */
final readonly class FramePreparationResult
{
    public function __construct(
        /** @var list<PreparedFrame> ordered by ordinal */
        public array $frames,
        public int $framesAvailable,
        public int $skippedFormat,
        public int $skippedQuality,
        public int $deduped,
    ) {}

    /**
     * Coverage may be insufficient: stored frames were skipped, or nothing
     * survived preparation. Drives the NO_MATCH vs INCONCLUSIVE run-outcome
     * split (spec §8). Budget truncation deliberately does NOT degrade
     * coverage — it is a cost guard.
     */
    public function coverageDegraded(): bool
    {
        return $this->skippedFormat + $this->skippedQuality > 0 || $this->frames === [];
    }
}
```

Create `app/Platform/Enrichment/VisualMatch/Frames/FramePreparation.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Frames;

use App\Platform\Enrichment\Keyframes\KeyframeSet;
use Illuminate\Support\Facades\Storage;

/**
 * Local + free preparation of a stored KeyframeSet before any embedding
 * spend (spec §8 steps 1–3): format check → quality filter → near-dup
 * removal → frame budget. C consumes the KeyframeSet contract only — it
 * never touches the keyframes table or media acquisition (B owns those).
 */
final class FramePreparation
{
    /** Formats the model officially accepts (spec §5): extension → request mimeType. */
    private const SUPPORTED = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
    ];

    /**
     * Model-supported but GD-undecodable: local quality/dedup analysis
     * would falsely reject them, so they embed as-is (singleton groups).
     */
    private const ANALYSIS_EXEMPT = ['heic', 'heif'];

    public function __construct(
        private readonly FrameQualityFilter $quality,
        private readonly FrameDeduplicator $deduplicator,
    ) {}

    public function prepare(KeyframeSet $set, int $budget): FramePreparationResult
    {
        $framesAvailable = count($set->frames);
        $skippedFormat = 0;
        $skippedQuality = 0;
        $analyzable = [];
        $exempt = [];

        foreach ($set->frames as $keyframe) {
            $extension = strtolower(pathinfo($keyframe->storage_path, PATHINFO_EXTENSION));
            $mimeType = self::SUPPORTED[$extension] ?? null;
            $disk = Storage::disk($keyframe->storage_disk);

            if ($mimeType === null || ! $disk->exists($keyframe->storage_path)) {
                // Unknown format or missing blob: coverage loss, never absence.
                $skippedFormat++;

                continue;
            }

            $bytes = (string) $disk->get($keyframe->storage_path);
            $frame = ['keyframe' => $keyframe, 'bytes' => $bytes, 'mimeType' => $mimeType];

            if (in_array($extension, self::ANALYSIS_EXEMPT, true)) {
                $exempt[] = $frame;

                continue;
            }

            $reason = $this->quality->rejectionReason($bytes);

            if ($reason === FrameQualityFilter::REASON_UNDECODABLE) {
                // Undecodable content is FORMAT loss (§5), not a quality judgment.
                $skippedFormat++;

                continue;
            }

            if ($reason !== null) {
                $skippedQuality++;

                continue;
            }

            $analyzable[] = $frame;
        }

        $prepared = $this->deduplicator->deduplicate($analyzable);
        $deduped = count($analyzable) - count($prepared);

        foreach ($exempt as $frame) {
            $timestamp = $frame['keyframe']->timestamp_ms === null ? null : (int) $frame['keyframe']->timestamp_ms;
            $prepared[] = new PreparedFrame($frame['keyframe'], $frame['bytes'], $frame['mimeType'], 1, $timestamp, $timestamp);
        }

        usort($prepared, fn (PreparedFrame $a, PreparedFrame $b): int => $a->keyframe->ordinal <=> $b->keyframe->ordinal);

        return new FramePreparationResult(
            frames: array_slice($prepared, 0, max(0, $budget)),
            framesAvailable: $framesAvailable,
            skippedFormat: $skippedFormat,
            skippedQuality: $skippedQuality,
            deduped: $deduped,
        );
    }
}
```

- [ ] **Step 14: Run the task's three test files together**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Enrichment/FrameQualityFilterTest.php tests/Feature/Enrichment/FrameDeduplicatorTest.php tests/Feature/Enrichment/FramePreparationTest.php`
Expected: PASS (20 tests).

- [ ] **Step 15: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/Frames/FramePreparationResult.php app/Platform/Enrichment/VisualMatch/Frames/FramePreparation.php tests/Feature/Enrichment/FramePreparationTest.php
git commit -m "feat(enrichment): visual-match frame preparation pipeline"
```

---

---

### Task 13: KeyframeEmbedder (per-model cache, one call per frame, telemetry)

**Files:**
- Create: `app/Platform/Enrichment/VisualMatch/Frames/KeyframeEmbedder.php`
- Test: `tests/Feature/Enrichment/KeyframeEmbedderTest.php` (create)

**Interfaces:**
- Consumes: `EmbeddingProvider` interface (Task 7, container-bound: `embedImage(string $bytes, string $mimeType): array` returning `list<float>`, `modelVersion(): string`, `isConfigured(): bool`); `SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS = 'SRC-google-gemini-embeddings'` (contract-frozen; must exist by Task 7 — the map's Task 18 placement is too late for this task and Task 9); `App\Modules\Monitoring\Models\KeyframeEmbedding` (Task 5: `BelongsToTenant`, unique `(keyframe_id, model_version)`, fillable `keyframe_id`/`model_version`/`embedding`, `embedding` stored as pgvector text literal string, created_at-only via the `ProviderCall` `UPDATED_AT = null` house pattern); `VectorLiteral::fromArray(array $vector): string` / `VectorLiteral::toArray(string $literal): array` (Task 1); `PreparedFrame` (Task 12); existing `ProviderCallRecorder::start/recordOperation/recordFailure`, `ProviderResponse`, `ProviderCallException`, `CallOutcome`; `Keyframe::factory()` (Task 3). The provider does NOT self-record telemetry — this class owns the recorder wiring because it holds the correlationId (the frozen `embedImage` returns only the vector).
- Produces (frozen contract, verbatim — Task 19 relies on it):
```php
final class KeyframeEmbedder {
    /** @return array{embedded: array<int, list<float>>, billedCalls: int, cacheHits: int}  keyed by keyframe id; frames whose embed transiently failed are omitted */
    public function embedAll(array $frames, string $correlationId): array;  // $frames = list<PreparedFrame>
}
```
Budget doctrine for Task 19: `AiBudgetGuard::allows('embedding', …)` runs in `VisualProductMatcher` BEFORE `embedAll` (the frozen shape has no denial channel); the returned `billedCalls` feed `AiBudgetGuard::record` and the run row's `embedding_calls`; `cacheHits` feeds `cache_hits`. Failed calls are never billed.

- [ ] **Step 1: Write the failing test** (container-stubbed provider — no HTTP anywhere):

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\KeyframeEmbedding;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Frames\KeyframeEmbedder;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Platform\Ingestion\Support\ErrorCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The embedder never sees HTTP: the provider seam is container-stubbed.
 * Cache per (keyframe, model_version); one call per frame (the model
 * fuses multi-image requests into ONE vector — verified, spec §5);
 * transient failures omit the frame and never fail the run.
 */
class KeyframeEmbedderTest extends TestCase
{
    use RefreshDatabase;

    private FakeEmbeddingProvider $provider;

    private ContentItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeEmbeddingProvider;
        $this->app->instance(EmbeddingProvider::class, $this->provider);

        $account = PlatformAccount::factory()->for(Creator::factory())->create();
        $this->item = ContentItem::factory()->for($account, 'platformAccount')->create();
    }

    /** @return list<float> a 3072-dim vector with one hot component (the DDL width is fixed). */
    private function vector(int $hot): array
    {
        $vector = array_fill(0, 3072, 0.0);
        $vector[$hot] = 1.0;

        return $vector;
    }

    private function makeFrame(int $ordinal, string $bytes): PreparedFrame
    {
        $keyframe = Keyframe::factory()->create([
            'owner_type' => $this->item->getMorphClass(),
            'owner_id' => $this->item->id,
            'ordinal' => $ordinal,
            'timestamp_ms' => $ordinal * 6000,
        ]);

        return new PreparedFrame($keyframe, $bytes, 'image/jpeg', 1, $ordinal * 6000, $ordinal * 6000);
    }

    private function embedder(): KeyframeEmbedder
    {
        return app(KeyframeEmbedder::class);
    }

    public function test_each_prepared_frame_is_embedded_once_and_cached(): void
    {
        $a = $this->makeFrame(0, 'FRAME-A');
        $b = $this->makeFrame(1, 'FRAME-B');
        $this->provider->vectors = ['FRAME-A' => $this->vector(1), 'FRAME-B' => $this->vector(2)];

        $result = $this->embedder()->embedAll([$a, $b], 'corr-embed-1');

        $this->assertCount(2, $this->provider->calls);
        $this->assertSame('image/jpeg', $this->provider->calls[0]['mimeType']);
        $this->assertSame(2, $result['billedCalls']);
        $this->assertSame(0, $result['cacheHits']);
        $this->assertSame($this->vector(1), $result['embedded'][$a->keyframe->id]);
        $this->assertSame($this->vector(2), $result['embedded'][$b->keyframe->id]);

        $this->assertSame(2, KeyframeEmbedding::query()->count());
        $row = KeyframeEmbedding::query()->where('keyframe_id', $a->keyframe->id)->firstOrFail();
        $this->assertSame('gemini-embedding-2', $row->model_version);
        $this->assertSame((int) $this->defaultTenant->id, (int) $row->tenant_id);
        $this->assertSame($this->vector(1), VectorLiteral::toArray((string) $row->embedding));

        $this->assertSame(2, ProviderCall::query()
            ->where('source', SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS)
            ->where('operation', 'embedding.embed')
            ->where('correlation_id', 'corr-embed-1')
            ->where('outcome', CallOutcome::Success->value)
            ->count());
    }

    public function test_cached_embeddings_cost_nothing(): void
    {
        $a = $this->makeFrame(0, 'FRAME-A');
        $b = $this->makeFrame(1, 'FRAME-B');
        KeyframeEmbedding::query()->create([
            'keyframe_id' => $a->keyframe->id,
            'model_version' => 'gemini-embedding-2',
            'embedding' => VectorLiteral::fromArray($this->vector(7)),
        ]);
        $this->provider->vectors = ['FRAME-B' => $this->vector(2)];

        $result = $this->embedder()->embedAll([$a, $b], 'corr-embed-2');

        $this->assertCount(1, $this->provider->calls);
        $this->assertSame('FRAME-B', $this->provider->calls[0]['bytes']);
        $this->assertSame(1, $result['billedCalls']);
        $this->assertSame(1, $result['cacheHits']);
        $this->assertSame($this->vector(7), $result['embedded'][$a->keyframe->id]);
    }

    public function test_a_second_run_is_all_cache_hits(): void
    {
        $a = $this->makeFrame(0, 'FRAME-A');
        $b = $this->makeFrame(1, 'FRAME-B');
        $this->provider->vectors = ['FRAME-A' => $this->vector(1), 'FRAME-B' => $this->vector(2)];

        $this->embedder()->embedAll([$a, $b], 'corr-embed-3');
        $result = $this->embedder()->embedAll([$a, $b], 'corr-embed-3');

        $this->assertCount(2, $this->provider->calls); // no new calls
        $this->assertSame(0, $result['billedCalls']);
        $this->assertSame(2, $result['cacheHits']);
        $this->assertSame(2, KeyframeEmbedding::query()->count());
    }

    public function test_a_transient_failure_omits_the_frame_and_never_throws(): void
    {
        $a = $this->makeFrame(0, 'FRAME-A');
        $b = $this->makeFrame(1, 'FRAME-B');
        $this->provider->vectors = ['FRAME-A' => $this->vector(1)];
        $this->provider->failures = ['FRAME-B' => new ProviderCallException(
            SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
            ErrorCategory::UpstreamError,
            'SRC-google-gemini-embeddings request failed (HTTP 500).',
            500,
        )];

        $result = $this->embedder()->embedAll([$a, $b], 'corr-embed-4');

        $this->assertArrayHasKey($a->keyframe->id, $result['embedded']);
        $this->assertArrayNotHasKey($b->keyframe->id, $result['embedded']);
        $this->assertSame(1, $result['billedCalls']); // failed calls are not billed
        $this->assertSame(0, KeyframeEmbedding::query()->where('keyframe_id', $b->keyframe->id)->count());
        $this->assertSame(1, ProviderCall::query()
            ->where('source', SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS)
            ->where('outcome', CallOutcome::Failure->value)
            ->count());
    }

    public function test_a_concurrent_winner_row_is_reloaded_never_clobbered(): void
    {
        $a = $this->makeFrame(0, 'FRAME-A');
        $winner = $this->vector(9);
        $this->provider->vectors = ['FRAME-A' => $this->vector(1)];
        $this->provider->onEmbed = function () use ($a, $winner): void {
            // Simulate a parallel run committing between our cache check and save.
            KeyframeEmbedding::query()->create([
                'keyframe_id' => $a->keyframe->id,
                'model_version' => 'gemini-embedding-2',
                'embedding' => VectorLiteral::fromArray($winner),
            ]);
        };

        $result = $this->embedder()->embedAll([$a], 'corr-embed-5');

        $this->assertSame(1, KeyframeEmbedding::query()->where('keyframe_id', $a->keyframe->id)->count());
        $this->assertSame($winner, $result['embedded'][$a->keyframe->id]);
        $this->assertSame(1, $result['billedCalls']); // our call really happened
    }
}

/** Container stub for the provider seam — behaviour driven per frame bytes. */
final class FakeEmbeddingProvider implements EmbeddingProvider
{
    /** @var list<array{bytes: string, mimeType: string}> */
    public array $calls = [];

    /** @var array<string, list<float>> vector returned, keyed by bytes */
    public array $vectors = [];

    /** @var array<string, ProviderCallException> failure thrown, keyed by bytes */
    public array $failures = [];

    /** Hook that runs mid-call — used to simulate concurrent winners. */
    public ?\Closure $onEmbed = null;

    public function embedImage(string $bytes, string $mimeType): array
    {
        $this->calls[] = ['bytes' => $bytes, 'mimeType' => $mimeType];

        if (isset($this->failures[$bytes])) {
            throw $this->failures[$bytes];
        }

        if ($this->onEmbed instanceof \Closure) {
            ($this->onEmbed)($bytes);
        }

        return $this->vectors[$bytes];
    }

    public function modelVersion(): string
    {
        return 'gemini-embedding-2';
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter KeyframeEmbedderTest`
Expected: ERROR — `Class "App\Platform\Enrichment\VisualMatch\Frames\KeyframeEmbedder" not found`.

- [ ] **Step 3: Implement `KeyframeEmbedder`**

Create `app/Platform/Enrichment/VisualMatch/Frames/KeyframeEmbedder.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Frames;

use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\KeyframeEmbedding;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\SourceRegistry;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Embeds prepared keyframes through the provider seam, cached per
 * (keyframe, model_version) so retries, multi-candidate scoring and later
 * re-runs are free. One provider call per frame — the model fuses
 * multi-image requests into a SINGLE vector (verified, spec §5), so
 * batching is useless here.
 *
 * Budget doctrine: the frozen embedAll() shape has no denial channel —
 * AiBudgetGuard::allows() runs in VisualProductMatcher BEFORE this class
 * is invoked, and the returned billedCalls feed AiBudgetGuard::record()
 * afterwards. This class only guarantees the billed count is minimal
 * (cache first) and honest (failed calls are never billed).
 *
 * Telemetry lives HERE, not in the provider: only this seam holds the
 * correlationId, and the provider's frozen contract returns bare vectors.
 * A transiently failing frame is telemetered and OMITTED from the result
 * — the run survives and the omission surfaces as reduced coverage,
 * never as a failed enrichment run (spec §5).
 */
final class KeyframeEmbedder
{
    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly ProviderCallRecorder $recorder,
    ) {}

    /**
     * @param  list<PreparedFrame>  $frames
     * @return array{embedded: array<int, list<float>>, billedCalls: int, cacheHits: int} keyed by keyframe id
     */
    public function embedAll(array $frames, string $correlationId): array
    {
        $modelVersion = $this->provider->modelVersion();
        $embedded = [];
        $billedCalls = 0;
        $cacheHits = 0;

        foreach ($frames as $frame) {
            $cached = KeyframeEmbedding::query()
                ->where('keyframe_id', $frame->keyframe->id)
                ->where('model_version', $modelVersion)
                ->first();

            if ($cached !== null) {
                $embedded[$frame->keyframe->id] = VectorLiteral::toArray((string) $cached->embedding);
                $cacheHits++;

                continue;
            }

            $context = $this->recorder->start(
                SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
                'embedding.embed',
                $correlationId,
            );
            $startedAt = microtime(true);

            try {
                $vector = $this->provider->embedImage($frame->bytes, $frame->mimeType);
            } catch (ProviderCallException $e) {
                // Transient failure: telemetered, the frame is omitted, the
                // run survives. Not billed — Google bills successful calls.
                $this->recorder->recordFailure($context, $e);

                continue;
            }

            // recordOperation needs a ProviderResponse; the decoded vector
            // is the only payload visible at this seam, so its serialized
            // size is the honest response-size proxy (no fabricated fields).
            $this->recorder->recordOperation($context, new ProviderResponse(
                items: [],
                httpStatus: 200,
                responseBytes: strlen((string) json_encode($vector)),
                requestMs: (microtime(true) - $startedAt) * 1000,
                sourceVersion: $modelVersion,
            ), 1);

            $billedCalls++;
            $embedded[$frame->keyframe->id] = $this->persist($frame->keyframe, $modelVersion, $vector);
        }

        return ['embedded' => $embedded, 'billedCalls' => $billedCalls, 'cacheHits' => $cacheHits];
    }

    /**
     * @param  list<float>  $vector
     * @return list<float> the vector now cached for this (keyframe, model_version)
     */
    private function persist(Keyframe $keyframe, string $modelVersion, array $vector): array
    {
        $row = new KeyframeEmbedding([
            'keyframe_id' => $keyframe->id,
            'model_version' => $modelVersion,
            'embedding' => VectorLiteral::fromArray($vector),
        ]);

        try {
            // SAVEPOINT when already inside a transaction, so a collision
            // never poisons a caller's wider unit of work (house pattern,
            // YouTubeTranscriptEnricher).
            KeyframeEmbedding::query()->withSavepointIfNeeded(fn () => $row->save());

            return $vector;
        } catch (UniqueConstraintViolationException) {
            // A concurrent embed of this frame won the (keyframe_id,
            // model_version) unique key. Same input + same model ⇒ the
            // winner's vector IS ours — reload it, never clobber. Our call
            // still happened, so it stays billed and telemetered.
            $winner = KeyframeEmbedding::query()
                ->where('keyframe_id', $keyframe->id)
                ->where('model_version', $modelVersion)
                ->first();

            return $winner === null ? $vector : VectorLiteral::toArray((string) $winner->embedding);
        }
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter KeyframeEmbedderTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the visual-match frame suite together** (Task 12's files plus this one — the shared `PreparedFrame` contract must stay green):

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Enrichment/FrameQualityFilterTest.php tests/Feature/Enrichment/FrameDeduplicatorTest.php tests/Feature/Enrichment/FramePreparationTest.php tests/Feature/Enrichment/KeyframeEmbedderTest.php`
Expected: PASS (25 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/Frames/KeyframeEmbedder.php tests/Feature/Enrichment/KeyframeEmbedderTest.php
git commit -m "feat(enrichment): keyframe embedder with per-model cache and telemetry"
```

---

---

### Task 14: Visual-match run + candidate tables, models, factories, GDPR erasure

**Files:**
- Create: `database/migrations/2026_07_20_100006_create_visual_match_tables.php`
- Create: `app/Shared/Enums/VisualMatchOutcome.php`
- Create: `app/Shared/Enums/VisualMatchBand.php`
- Create: `app/Modules/Monitoring/Models/VisualMatchRun.php`
- Create: `app/Modules/Monitoring/Models/VisualMatchCandidate.php`
- Create: `database/factories/VisualMatchRunFactory.php`
- Create: `database/factories/VisualMatchCandidateFactory.php`
- Modify: `app/Modules/CRM/Services/Gdpr/CreatorEraser.php` (insert one delete block after the `enrichment_runs` delete at lines 124–126, before the `mentions` delete at line 127)
- Test: `tests/Feature/Enrichment/VisualMatchTablesTest.php` (create)
- Test: `tests/Feature/Enrichment/KeyframeErasureTest.php` (extend — existing method spans lines 36–79; add one import block, capture the created Keyframe, add one new test method)

**Interfaces:**
- Consumes: `App\Platform\AiBudget\Priority` enum (Task 8: `case High = 'high'; case Medium = 'medium';`); `keyframe_embeddings` / `product_reference_photos` / `product_photo_embeddings` tables (Tasks 4/5) and `VectorLiteral::fromArray(array $vector): string` (Task 1) in the erasure test; pre-existing `(id, tenant_id)` uniques on `products`, `content_items`, `stories`, `seeding_campaigns` (from `2026_07_11_100002_add_tenant_ownership_to_business_tables.php`); factory names resolve flat via `AppServiceProvider::guessFactoryNamesUsing` (`Database\Factories\{basename}Factory`).
- Produces: `App\Shared\Enums\VisualMatchOutcome` (Matched='matched', Review='review', NoMatch='no_match', Inconclusive='inconclusive', SkippedBudget='skipped_budget', SkippedReadOnly='skipped_read_only', SkippedProvider='skipped_provider') and `App\Shared\Enums\VisualMatchBand` (Auto='auto', Review='review', Reject='reject') — consumed verbatim by Tasks 17, 18, 19, 21, 22, 23; models `App\Modules\Monitoring\Models\VisualMatchRun` / `VisualMatchCandidate` (both `BelongsToTenant` + `HasFactory`, `UPDATED_AT = null`) with factories — consumed by the recorder (Task 18), matcher (Task 19), dashboard (Task 21), backfill (Task 22).

- [ ] **Step 1: Write the failing persistence test**

Create `tests/Feature/Enrichment/VisualMatchTablesTest.php`:

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchCandidate;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\AiBudget\Priority;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VisualMatchOutcome;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sub-project C audit trail (spec §4.4/§4.5): visual_match_runs (one
 * append-only row per analysis run) + visual_match_candidates (ranked
 * shortlist with candidate-source and visibility evidence). Tenant-owned
 * with composite FKs; catalog edits must never rewrite the audit trail.
 */
class VisualMatchTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_and_candidate_factories_persist_and_round_trip_enums(): void
    {
        $run = VisualMatchRun::factory()->create([
            'outcome' => VisualMatchOutcome::Matched,
            'best_score' => 0.8123,
            'needs_verification' => true,
        ]);
        $candidate = VisualMatchCandidate::factory()->create([
            'visual_match_run_id' => $run->id,
            'band' => VisualMatchBand::Auto,
            'category' => SectorLabel::Tech,
            'first_support_ms' => 0,
            'last_support_ms' => 12000,
            'estimated_visible_ms' => 18000,
        ]);

        $run->refresh();
        $candidate->refresh();

        $this->assertSame(VisualMatchOutcome::Matched, $run->outcome);
        $this->assertSame(Priority::High, $run->priority);
        $this->assertTrue($run->needs_verification);
        $this->assertEqualsWithDelta(0.8123, $run->best_score, 0.00001);
        $this->assertNotNull($run->created_at);
        $this->assertIsArray($run->thresholds);
        $this->assertSame(VisualMatchBand::Auto, $candidate->band);
        $this->assertSame(SectorLabel::Tech, $candidate->category);
        $this->assertSame(18000, $candidate->estimated_visible_ms);
        $this->assertIsArray($candidate->supporting_frames);
        $this->assertSame($run->id, $candidate->run->id);
        $this->assertTrue($run->candidates()->whereKey($candidate->id)->exists());
    }

    public function test_a_run_with_both_targets_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        // Factory default already sets content_item_id; adding story_id
        // violates the num_nonnulls(content_item_id, story_id) = 1 CHECK.
        VisualMatchRun::factory()->create([
            'story_id' => Story::factory()->create()->id,
        ]);
    }

    public function test_a_run_with_no_target_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        VisualMatchRun::factory()->create(['content_item_id' => null, 'story_id' => null]);
    }

    public function test_in_story_state_builds_a_story_run(): void
    {
        $run = VisualMatchRun::factory()->inStory()->create();

        $this->assertNull($run->content_item_id);
        $this->assertNotNull($run->story_id);
        $this->assertSame($run->story_id, $run->story->id);
    }

    public function test_candidates_cascade_when_their_run_is_deleted(): void
    {
        $run = VisualMatchRun::factory()->create();
        VisualMatchCandidate::factory()->count(2)->sequence(['rank' => 1], ['rank' => 2])
            ->create(['visual_match_run_id' => $run->id]);

        DB::table('visual_match_runs')->where('id', $run->id)->delete();

        $this->assertSame(0, VisualMatchCandidate::query()->count());
    }

    public function test_product_delete_nulls_the_link_but_keeps_the_audit_label(): void
    {
        $product = Product::factory()->create();
        $candidate = VisualMatchCandidate::factory()->create([
            'product_id' => $product->id,
            'product_label' => 'Nexon Labs Headset',
        ]);

        DB::table('products')->where('id', $product->id)->delete();

        $candidate->refresh();
        // SET NULL is column-scoped (PG15+ column list): only product_id
        // clears — the denormalized label and tenant ownership survive.
        $this->assertNull($candidate->product_id);
        $this->assertSame('Nexon Labs Headset', $candidate->product_label);
        $this->assertNotNull($candidate->tenant_id);
    }

    public function test_runs_are_tenant_coherent_with_their_content_item(): void
    {
        $foreign = $this->makeTenant('Foreign Tenant');
        $foreignItem = $this->withTenant($foreign, fn (): ContentItem => ContentItem::factory()->create());

        $this->expectException(QueryException::class);

        // Composite (content_item_id, tenant_id) FK rejects the cross-tenant pair.
        VisualMatchRun::factory()->create(['content_item_id' => $foreignItem->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualMatchTablesTest`
Expected: 7 ERRORS — `Error: Class "App\Modules\Monitoring\Models\VisualMatchRun" not found`.

- [ ] **Step 3: Implement enums, migration, models, factories**

Create `app/Shared/Enums/VisualMatchOutcome.php`:

```php
<?php

namespace App\Shared\Enums;

/**
 * Run-level outcome of one visual product-match analysis (sub-project C,
 * spec §8). The no_match / inconclusive split is load-bearing: no_match =
 * "we looked properly and did not see it" (clean coverage); inconclusive =
 * "we could not look properly" (unavailable ≠ false) — sub-project D and
 * reviewers rely on the distinction. The skipped_* cases record runs that
 * never scored (budget / read-only / provider), never treated as absence.
 */
enum VisualMatchOutcome: string
{
    case Matched = 'matched';
    case Review = 'review';
    case NoMatch = 'no_match';
    case Inconclusive = 'inconclusive';
    case SkippedBudget = 'skipped_budget';
    case SkippedReadOnly = 'skipped_read_only';
    case SkippedProvider = 'skipped_provider';
}
```

Create `app/Shared/Enums/VisualMatchBand.php`:

```php
<?php

namespace App\Shared\Enums;

/**
 * Per-candidate confidence band (sub-project C, spec §8). AUTO writes a
 * HIGH VISUAL_PRODUCT detection, REVIEW writes LOW (queues for humans),
 * REJECT writes nothing — scores stay in visual_match_candidates only.
 */
enum VisualMatchBand: string
{
    case Auto = 'auto';
    case Review = 'review';
    case Reject = 'reject';
}
```

Create `database/migrations/2026_07_20_100006_create_visual_match_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sub-project C audit trail (spec §4.4/§4.5): one visual_match_runs row
     * per analysis run (append-only usage — the latest run per post is
     * authoritative, history kept for calibration in E and debugging) plus
     * ranked visual_match_candidates carrying candidate-source evidence
     * (why was this product considered) and visibility evidence.
     * Tenant-owned (ADR-0019/0020) with composite (col, tenant_id) FKs per
     * the reach_results pattern. needs_verification is sub-project D's poll
     * flag. Erased with the creator (CreatorEraser); candidates cascade
     * from runs at the DB.
     */
    public function up(): void
    {
        Schema::create('visual_match_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained();
            $table->foreignId('content_item_id')->nullable()->constrained();
            $table->foreignId('story_id')->nullable()->constrained();
            $table->string('correlation_id', 64);
            $table->string('model_version', 64);
            // low never produces a run: empty candidate set skips pre-spend (§10).
            $table->string('priority', 10);
            // Coverage accounting: stored vs actually embedded, plus why the
            // difference (unsupported format / quality filter / near-dupes).
            $table->smallInteger('frames_available');
            $table->smallInteger('frames_processed');
            $table->smallInteger('frames_skipped_format');
            $table->smallInteger('frames_skipped_quality');
            $table->smallInteger('frames_deduped');
            $table->smallInteger('cache_hits');
            $table->integer('processing_ms');
            $table->smallInteger('candidates_checked');
            $table->decimal('best_score', 5, 4)->nullable();
            $table->string('outcome', 30);
            $table->string('rejection_reason', 100)->nullable();
            // Snapshot {category_map_used, auto, review, margin} per candidate
            // category — reproducibility across threshold recalibrations (E).
            $table->jsonb('thresholds');
            // Billed calls this run (cache hits excluded) × list price.
            $table->smallInteger('embedding_calls');
            $table->integer('estimated_cost_micro_usd');
            // Sub-project D's poll flag (§11).
            $table->boolean('needs_verification')->default(false);
            $table->timestamp('created_at');

            // Latest-run-per-post lookups (authoritative row = max id).
            $table->index(['content_item_id', 'id']);
            $table->index(['story_id', 'id']);
        });

        DB::statement('ALTER TABLE visual_match_runs ADD CONSTRAINT visual_match_runs_id_tenant_unique UNIQUE (id, tenant_id)');
        // Exactly one target: content item XOR story (recognition_detections precedent).
        DB::statement('ALTER TABLE visual_match_runs ADD CONSTRAINT visual_match_runs_target_check CHECK (num_nonnulls(content_item_id, story_id) = 1)');
        DB::statement("ALTER TABLE visual_match_runs ADD CONSTRAINT visual_match_runs_priority_check CHECK (priority IN ('high', 'medium'))");
        DB::statement(<<<'SQL'
            ALTER TABLE visual_match_runs ADD CONSTRAINT visual_match_runs_outcome_check
                CHECK (outcome IN ('matched', 'review', 'no_match', 'inconclusive', 'skipped_budget', 'skipped_read_only', 'skipped_provider'))
        SQL);
        DB::statement('ALTER TABLE visual_match_runs ADD CONSTRAINT visual_match_runs_content_item_tenant_fk FOREIGN KEY (content_item_id, tenant_id) REFERENCES content_items (id, tenant_id)');
        DB::statement('ALTER TABLE visual_match_runs ADD CONSTRAINT visual_match_runs_story_tenant_fk FOREIGN KEY (story_id, tenant_id) REFERENCES stories (id, tenant_id)');

        Schema::create('visual_match_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained();
            $table->foreignId('visual_match_run_id')->constrained()->cascadeOnDelete();
            // Nullable on purpose: the audit survives catalog edits — the
            // composite FK below nulls ONLY this column on product delete.
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('product_label', 255);
            $table->string('category', 50)->nullable();
            $table->smallInteger('rank');
            $table->decimal('best_similarity', 5, 4);
            $table->decimal('margin_to_runner_up', 5, 4)->nullable();
            // list of {ordinal, timestamp_ms, similarity, photo_id, represented_frames}.
            $table->jsonb('supporting_frames');
            $table->string('band', 15);
            $table->string('rejection_reason', 100)->nullable();
            // Candidate-source evidence: WHY was this product considered (§4.5).
            $table->string('source', 20);
            $table->boolean('shipment_in_window');
            $table->foreignId('seeding_campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('shipment_anchor_at')->nullable();
            $table->smallInteger('shipment_age_days')->nullable();
            // Visibility evidence (§8): null when frames carry no timestamps.
            $table->integer('first_support_ms')->nullable();
            $table->integer('last_support_ms')->nullable();
            $table->integer('estimated_visible_ms')->nullable();
            $table->timestamp('created_at');

            $table->index(['visual_match_run_id', 'rank']);
        });

        DB::statement("ALTER TABLE visual_match_candidates ADD CONSTRAINT visual_match_candidates_band_check CHECK (band IN ('auto', 'review', 'reject'))");
        DB::statement("ALTER TABLE visual_match_candidates ADD CONSTRAINT visual_match_candidates_source_check CHECK (source IN ('shipment', 'roster'))");
        DB::statement('ALTER TABLE visual_match_candidates ADD CONSTRAINT visual_match_candidates_visual_match_run_tenant_fk FOREIGN KEY (visual_match_run_id, tenant_id) REFERENCES visual_match_runs (id, tenant_id)');
        // Catalog edits never rewrite the audit trail: product delete nulls
        // ONLY product_id (PostgreSQL 15+ column-scoped SET NULL — pg17
        // everywhere: pgvector/pgvector:pg17-bookworm locally, Neon PG17),
        // product_label survives. The composite reference keeps the pair
        // tenant-coherent while set (MATCH SIMPLE skips rows once nulled).
        DB::statement('ALTER TABLE visual_match_candidates ADD CONSTRAINT visual_match_candidates_product_tenant_fk FOREIGN KEY (product_id, tenant_id) REFERENCES products (id, tenant_id) ON DELETE SET NULL (product_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('visual_match_candidates');
        Schema::dropIfExists('visual_match_runs');
    }
};
```

Create `app/Modules/Monitoring/Models/VisualMatchRun.php`:

```php
<?php

namespace App\Modules\Monitoring\Models;

use App\Platform\AiBudget\Priority;
use App\Shared\Enums\VisualMatchOutcome;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One visual product-match analysis run over a post's keyframes
 * (sub-project C, spec §4.4). Append-only usage: the latest run per post is
 * authoritative; history stays for calibration (sub-project E) and
 * debugging. needs_verification is sub-project D's poll flag ("verify this
 * post with the VLM") — D adds its own consumption bookkeeping. Erased
 * with the creator's content (CreatorEraser); candidates cascade at the DB.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $content_item_id
 * @property int|null $story_id
 * @property string $correlation_id
 * @property string $model_version
 * @property Priority $priority
 * @property int $frames_available
 * @property int $frames_processed
 * @property int $frames_skipped_format
 * @property int $frames_skipped_quality
 * @property int $frames_deduped
 * @property int $cache_hits
 * @property int $processing_ms
 * @property int $candidates_checked
 * @property float|null $best_score
 * @property VisualMatchOutcome $outcome
 * @property string|null $rejection_reason
 * @property array<string, mixed> $thresholds
 * @property int $embedding_calls
 * @property int $estimated_cost_micro_usd
 * @property bool $needs_verification
 */
class VisualMatchRun extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\VisualMatchRunFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'content_item_id',
        'story_id',
        'correlation_id',
        'model_version',
        'priority',
        'frames_available',
        'frames_processed',
        'frames_skipped_format',
        'frames_skipped_quality',
        'frames_deduped',
        'cache_hits',
        'processing_ms',
        'candidates_checked',
        'best_score',
        'outcome',
        'rejection_reason',
        'thresholds',
        'embedding_calls',
        'estimated_cost_micro_usd',
        'needs_verification',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'best_score' => 'float',
            'outcome' => VisualMatchOutcome::class,
            'thresholds' => 'array',
            'needs_verification' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /** @return HasMany<VisualMatchCandidate, $this> */
    public function candidates(): HasMany
    {
        return $this->hasMany(VisualMatchCandidate::class);
    }
}
```

Create `app/Modules/Monitoring/Models/VisualMatchCandidate.php`:

```php
<?php

namespace App\Modules\Monitoring\Models;

use App\Modules\CRM\Models\Product;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ranked candidate of one visual-match run (sub-project C, spec §4.5):
 * scores, band, candidate-source evidence (why was this product
 * considered) and visibility evidence. product_label is denormalized so
 * the audit survives catalog edits — product delete nulls only product_id.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $visual_match_run_id
 * @property int|null $product_id
 * @property string $product_label
 * @property SectorLabel|null $category
 * @property int $rank
 * @property float $best_similarity
 * @property float|null $margin_to_runner_up
 * @property list<array<string, mixed>> $supporting_frames
 * @property VisualMatchBand $band
 * @property string|null $rejection_reason
 * @property string $source shipment|roster
 * @property bool $shipment_in_window
 * @property int|null $seeding_campaign_id
 * @property CarbonImmutable|null $shipment_anchor_at
 * @property int|null $shipment_age_days
 * @property int|null $first_support_ms
 * @property int|null $last_support_ms
 * @property int|null $estimated_visible_ms
 */
class VisualMatchCandidate extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\VisualMatchCandidateFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'visual_match_run_id',
        'product_id',
        'product_label',
        'category',
        'rank',
        'best_similarity',
        'margin_to_runner_up',
        'supporting_frames',
        'band',
        'rejection_reason',
        'source',
        'shipment_in_window',
        'seeding_campaign_id',
        'shipment_anchor_at',
        'shipment_age_days',
        'first_support_ms',
        'last_support_ms',
        'estimated_visible_ms',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => SectorLabel::class,
            'best_similarity' => 'float',
            'margin_to_runner_up' => 'float',
            'supporting_frames' => 'array',
            'band' => VisualMatchBand::class,
            'shipment_in_window' => 'boolean',
            'shipment_anchor_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<VisualMatchRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(VisualMatchRun::class, 'visual_match_run_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

Create `database/factories/VisualMatchRunFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\AiBudget\Priority;
use App\Shared\Enums\VisualMatchOutcome;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). One visual-match analysis run (spec §4.4).
 *
 * @extends Factory<VisualMatchRun>
 */
class VisualMatchRunFactory extends Factory
{
    use ResolvesTenant;

    protected $model = VisualMatchRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'content_item_id' => ContentItem::factory(),
            'story_id' => null,
            'correlation_id' => fake()->uuid(),
            'model_version' => 'gemini-embedding-2',
            'priority' => Priority::High,
            'frames_available' => 3,
            'frames_processed' => 3,
            'frames_skipped_format' => 0,
            'frames_skipped_quality' => 0,
            'frames_deduped' => 0,
            'cache_hits' => 0,
            'processing_ms' => 250,
            'candidates_checked' => 1,
            'best_score' => 0.7215,
            'outcome' => VisualMatchOutcome::Review,
            'rejection_reason' => null,
            'thresholds' => ['category_map_used' => 'default', 'auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
            'embedding_calls' => 3,
            'estimated_cost_micro_usd' => 360,
            'needs_verification' => false,
        ];
    }

    /** Run over a story instead of a content item. */
    public function inStory(): static
    {
        return $this->state(fn (array $attributes) => [
            'content_item_id' => null,
            'story_id' => Story::factory(),
        ]);
    }
}
```

Create `database/factories/VisualMatchCandidateFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\VisualMatchCandidate;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\VisualMatchBand;
use Carbon\CarbonImmutable;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). One ranked visual-match candidate (§4.5).
 *
 * @extends Factory<VisualMatchCandidate>
 */
class VisualMatchCandidateFactory extends Factory
{
    use ResolvesTenant;

    protected $model = VisualMatchCandidate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'visual_match_run_id' => VisualMatchRun::factory(),
            'product_id' => Product::factory(),
            'product_label' => 'Product '.fake()->unique()->numerify('####'),
            'category' => SectorLabel::Beauty,
            'rank' => 1,
            'best_similarity' => 0.7215,
            'margin_to_runner_up' => null,
            'supporting_frames' => [
                ['ordinal' => 0, 'timestamp_ms' => 0, 'similarity' => 0.7215, 'photo_id' => 1, 'represented_frames' => 1],
            ],
            'band' => VisualMatchBand::Review,
            'rejection_reason' => null,
            'source' => 'shipment',
            'shipment_in_window' => true,
            'seeding_campaign_id' => null,
            'shipment_anchor_at' => CarbonImmutable::now()->subDays(5),
            'shipment_age_days' => 5,
            'first_support_ms' => null,
            'last_support_ms' => null,
            'estimated_visible_ms' => null,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualMatchTablesTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_20_100006_create_visual_match_tables.php app/Shared/Enums/VisualMatchOutcome.php app/Shared/Enums/VisualMatchBand.php app/Modules/Monitoring/Models/VisualMatchRun.php app/Modules/Monitoring/Models/VisualMatchCandidate.php database/factories/VisualMatchRunFactory.php database/factories/VisualMatchCandidateFactory.php tests/Feature/Enrichment/VisualMatchTablesTest.php
git commit -m "feat(enrichment): visual_match_runs + visual_match_candidates audit tables"
```

- [ ] **Step 6: Write the failing GDPR erasure extension**

In `tests/Feature/Enrichment/KeyframeErasureTest.php`, add to the imports (after `use App\Modules\Monitoring\Models\Keyframe;`):

```php
use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\VisualMatchCandidate;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use Illuminate\Support\Facades\DB;
```

In the existing `test_erasure_removes_keyframe_rows_files_and_transcripts` method, change `Keyframe::query()->create([` (line 47) to `$keyframe = Keyframe::query()->create([`, then insert directly after that create call's closing `]);`:

```php
        // Sub-project C: a cached frame embedding must die with its keyframe
        // (DB ON DELETE CASCADE — the eraser needs no code for it).
        DB::table('keyframe_embeddings')->insert([
            'tenant_id' => $item->tenant_id,
            'keyframe_id' => $keyframe->id,
            'model_version' => 'gemini-embedding-2',
            'embedding' => VectorLiteral::fromArray(array_fill(0, 3072, 0.001)),
            'created_at' => now(),
        ]);
```

and add after the existing `$this->assertSame(1, $counts['keyframe_files']);` assertion:

```php
        $this->assertSame(0, DB::table('keyframe_embeddings')->count());
```

Then add this new test method at the end of the class:

```php
    public function test_erasure_removes_visual_match_runs_and_candidates_but_keeps_catalog_photos(): void
    {
        config(['qds.ingestion.media_disk' => 'media']);

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->for($creator)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create();

        $run = VisualMatchRun::factory()->create(['content_item_id' => $item->id]);
        VisualMatchCandidate::factory()->count(2)->sequence(['rank' => 1], ['rank' => 2])
            ->create(['visual_match_run_id' => $run->id]);

        // Catalog data must SURVIVE a creator erasure: reference photos
        // belong to the product, not the person (spec §13).
        $product = Product::factory()->create();
        $photoId = DB::table('product_reference_photos')->insertGetId([
            'tenant_id' => $item->tenant_id,
            'product_id' => $product->id,
            'storage_disk' => 'media',
            'storage_path' => "tenants/{$item->tenant_id}/product-photos/{$product->id}/ref.jpg",
            'view_label' => 'front',
            'checksum' => str_repeat('c', 64),
            'width' => 800,
            'height' => 800,
            'uploaded_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_photo_embeddings')->insert([
            'tenant_id' => $item->tenant_id,
            'product_reference_photo_id' => $photoId,
            'model_version' => 'gemini-embedding-2',
            'embedding' => VectorLiteral::fromArray(array_fill(0, 3072, 0.002)),
            'created_at' => now(),
        ]);

        $counts = app(CreatorEraser::class)->erase($creator);

        $this->assertSame(1, $counts['visual_match_runs']);
        $this->assertSame(0, VisualMatchRun::query()->withoutGlobalScopes()->count());
        // Candidates cascade from runs at the DB — no separate delete list entry.
        $this->assertSame(0, VisualMatchCandidate::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, DB::table('product_reference_photos')->where('id', $photoId)->count());
        $this->assertSame(1, DB::table('product_photo_embeddings')->count());
        $this->assertSame(1, Product::query()->withoutGlobalScopes()->where('id', $product->id)->count());
    }
```

- [ ] **Step 7: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter KeyframeErasureTest`
Expected: the new test ERRORS with `Illuminate\Database\QueryException … update or delete on table "content_items" violates foreign key constraint` (the eraser deletes content_items while visual_match_runs still reference them). The extended first test PASSES already (the embedding cascade is pure DDL).

- [ ] **Step 8: Implement the eraser addition**

In `app/Modules/CRM/Services/Gdpr/CreatorEraser.php`, insert directly after the `$counts['enrichment_runs'] = …;` statement (lines 124–126) and before `$counts['mentions'] = …;` (line 127):

```php
            // Visual-match audit trail (sub-project C): runs are anchored to
            // the creator's content; candidates cascade from runs at the DB
            // (keyframe embeddings likewise cascade with the keyframes above).
            $counts['visual_match_runs'] = ($contentIds === [] && $storyIds === []) ? 0 : DB::table('visual_match_runs')
                ->where(fn ($q) => $q->whereIn('content_item_id', $contentIds)->orWhereIn('story_id', $storyIds))
                ->delete();
```

- [ ] **Step 9: Run the erasure + tables tests**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter 'KeyframeErasureTest|VisualMatchTablesTest'`
Expected: PASS (9 tests).

- [ ] **Step 10: Run the GDPR-adjacent suite**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter 'KeyframeErasureTest|VisualMatchTablesTest|GdprTest|ContactGdprDeletionTest'`
Expected: PASS — the pre-existing GdprTest / ContactGdprDeletionTest stay green (the new delete block is a no-op when no visual rows exist).

- [ ] **Step 11: Commit**

```bash
git add app/Modules/CRM/Services/Gdpr/CreatorEraser.php tests/Feature/Enrichment/KeyframeErasureTest.php
git commit -m "feat(gdpr): erase visual-match runs and candidates with the creator's content"
```

---

---

### Task 15: CandidateScope (shipment window + active roster, priority, matchability)

**Files:**
- Create: `app/Platform/Enrichment/VisualMatch/Candidates/Candidate.php`
- Create: `app/Platform/Enrichment/VisualMatch/Candidates/CandidateSet.php`
- Create: `app/Platform/Enrichment/VisualMatch/Candidates/CandidateScope.php`
- Test: `tests/Feature/Enrichment/CandidateScopeTest.php` (create)

**Interfaces:**
- Consumes: `App\Platform\AiBudget\Priority` enum (Task 8: `High = 'high'`, `Medium = 'medium'`); `App\Modules\CRM\Models\ProductReferencePhoto` + `ProductReferencePhotoFactory` (Task 4; factory accepts a `product_id` override); `App\Modules\Monitoring\Models\ProductPhotoEmbedding` + `ProductPhotoEmbeddingFactory` (Task 5; factory accepts `product_reference_photo_id` and `model_version` overrides and supplies a valid default 3072-dim embedding literal); `MonitoringSettingsResolver::shipmentWindowDays(): int` (ADR-0025 per-tenant, context mode); `ActiveSeedingCreatorIds::ACTIVE_STATUSES` / `::statusValues()` (the single owner of "active seeding" = ACTIVE + SHIPPING); config `qds.enrichment.visual_match.model_version`; existing models `Shipment` (`shipped_at`/`delivered_at` immutable_datetime, `seedingCampaign`/`product` relations), `SeedingCampaign` (`product_id` nullable primary product, `creators()` pivot `seeding_campaign_creator`), `Product` (`category` nullable `SectorLabel`, `brand`), `ContentItem::published_at` / `Story::captured_at` (CarbonImmutable), creator resolution `$target->platformAccount?->creator_id` (byte-identical to `ShipmentEvidenceSource`). Window semantics are byte-identical to `MentionClassifier::timingSatisfied` (app/Platform/Enrichment/Attribution/MentionClassifier.php:325-347): anchor = `delivered_at ?? shipped_at`, in-window ⇔ `publishedAt >= anchor && publishedAt <= anchor->addDays(windowDays)` (both edges inclusive).
- Produces (frozen contract, verbatim — Tasks 16/17/18/19 rely on these):
```php
final readonly class Candidate {
    public function __construct(
        public int $productId, public string $productLabel, public string $brandName,
        public ?\App\Shared\Enums\SectorLabel $category, public string $source, // 'shipment'|'roster'
        public bool $shipmentInWindow, public ?int $seedingCampaignId,
        public ?\Carbon\CarbonImmutable $shipmentAnchorAt, public ?int $shipmentAgeDays,
        public bool $hasEmbeddedPhotos,
    ) {}
}
final readonly class CandidateSet {
    /** @param list<Candidate> $candidates */
    public function __construct(public array $candidates, public Priority $priority) {}
    /** @return list<Candidate> */ public function matchable(): array;  // hasEmbeddedPhotos only
    public function isEmpty(): bool;
    public function hasInWindowShipment(): bool;
}
final class CandidateScope {
    public function forTarget(ContentItem|Story $target): CandidateSet;
}
```
Semantics later tasks rely on: only IN-WINDOW shipments become candidates (out-of-window ⇒ absent, not flagged); one candidate per product — shipment source wins over roster, multiple shipments keep the freshest anchor (equal anchors: lower shipment id); candidate order is deterministic (shipment candidates by ascending productId, then roster by ascending productId); priority High ⇔ any candidate ties to an ACTIVE/SHIPPING campaign, Medium otherwise; empty set carries the inert placeholder `Priority::Medium` ("low" ≡ empty ⇒ the matcher skips with `skipped:no-candidates`/`skipped:no-creator` before priority is consulted); unmatchable candidates STAY in `candidates` (run coverage accounting, Task 18) and cost nothing (Task 19 embeds only when `matchable() !== []`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Modules\Monitoring\Models\Story;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateScope;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\SeedingCampaignStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §7: candidates = products of in-window shipments (byte-identical
 * window semantics to attribution, ADR-0025 per-tenant) ∪ primary products
 * of ACTIVE/SHIPPING roster campaigns. Empty set ⇒ the post costs nothing
 * — this tiering is what makes most posts free.
 */
class CandidateScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qds.enrichment.visual_match.model_version' => 'gemini-embedding-2']);
    }

    /** @return array{0: Creator, 1: ContentItem} */
    private function makeCreatorWithPost(CarbonImmutable $publishedAt): array
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $item = ContentItem::factory()->for($account, 'platformAccount')->create(['published_at' => $publishedAt]);

        return [$creator, $item];
    }

    private function makeProduct(string $name, ?SectorLabel $category = SectorLabel::Beauty): Product
    {
        return Product::factory()->create(['name' => $name, 'category' => $category]);
    }

    private function makeShipment(
        Creator $creator,
        Product $product,
        ?CarbonImmutable $shippedAt,
        ?CarbonImmutable $deliveredAt = null,
        SeedingCampaignStatus $campaignStatus = SeedingCampaignStatus::Completed,
    ): Shipment {
        $campaign = SeedingCampaign::factory()->create([
            'brand_id' => $product->brand_id,
            'status' => $campaignStatus,
        ]);

        return Shipment::factory()->create([
            'seeding_campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => $shippedAt,
            'delivered_at' => $deliveredAt,
        ]);
    }

    private function makeRosterCampaign(Creator $creator, Product $product, SeedingCampaignStatus $status): SeedingCampaign
    {
        $campaign = SeedingCampaign::factory()->create([
            'brand_id' => $product->brand_id,
            'product_id' => $product->id,
            'status' => $status,
        ]);
        $campaign->creators()->attach($creator->id);

        return $campaign;
    }

    private function embedPhoto(Product $product, string $modelVersion = 'gemini-embedding-2'): void
    {
        $photo = ProductReferencePhoto::factory()->create(['product_id' => $product->id]);
        ProductPhotoEmbedding::factory()->create([
            'product_reference_photo_id' => $photo->id,
            'model_version' => $modelVersion,
        ]);
    }

    private function scope(): CandidateScope
    {
        return app(CandidateScope::class);
    }

    public function test_in_window_shipment_products_become_candidates_with_evidence(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $product = $this->makeProduct('Nexon Headset', SectorLabel::Tech);
        $shipment = $this->makeShipment($creator, $product, $publishedAt->subDays(10), $publishedAt->subDays(8));

        $set = $this->scope()->forTarget($item);

        $this->assertCount(1, $set->candidates);
        $candidate = $set->candidates[0];
        $this->assertSame($product->id, $candidate->productId);
        $this->assertSame('Nexon Headset', $candidate->productLabel);
        $this->assertSame($product->brand->name, $candidate->brandName);
        $this->assertSame(SectorLabel::Tech, $candidate->category);
        $this->assertSame('shipment', $candidate->source);
        $this->assertTrue($candidate->shipmentInWindow);
        $this->assertSame($shipment->seeding_campaign_id, $candidate->seedingCampaignId);
        $this->assertTrue($candidate->shipmentAnchorAt !== null && $candidate->shipmentAnchorAt->equalTo($publishedAt->subDays(8)));
        $this->assertSame(8, $candidate->shipmentAgeDays);
        $this->assertFalse($candidate->hasEmbeddedPhotos);
        $this->assertTrue($set->hasInWindowShipment());
        $this->assertFalse($set->isEmpty());
    }

    public function test_the_anchor_falls_back_to_shipped_at_when_undelivered(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $this->makeShipment($creator, $this->makeProduct('Silk Scarf'), $publishedAt->subDays(12), null);

        $set = $this->scope()->forTarget($item);

        $this->assertCount(1, $set->candidates);
        $anchor = $set->candidates[0]->shipmentAnchorAt;
        $this->assertTrue($anchor !== null && $anchor->equalTo($publishedAt->subDays(12)));
        $this->assertSame(12, $set->candidates[0]->shipmentAgeDays);
    }

    public function test_window_edges_match_the_classifier_semantics(): void
    {
        $anchor = CarbonImmutable::parse('2026-05-01 00:00:00');
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $this->makeShipment($creator, $this->makeProduct('Edge Product'), $anchor->subDay(), $anchor);

        $postAt = fn (CarbonImmutable $at): ContentItem => ContentItem::factory()
            ->for($account, 'platformAccount')
            ->create(['published_at' => $at]);

        // Default window 60 days, BOTH edges inclusive (MentionClassifier parity).
        $this->assertCount(1, $this->scope()->forTarget($postAt($anchor))->candidates);
        $this->assertCount(1, $this->scope()->forTarget($postAt($anchor->addDays(60)))->candidates);
        $this->assertCount(0, $this->scope()->forTarget($postAt($anchor->addDays(60)->addSecond()))->candidates);
        $this->assertCount(0, $this->scope()->forTarget($postAt($anchor->subSecond()))->candidates);
    }

    public function test_the_per_tenant_window_setting_applies(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $this->makeShipment($creator, $this->makeProduct('Short Window'), $publishedAt->subDays(6), $publishedAt->subDays(5));

        MonitoringSetting::query()->create([
            'shipment_window_days' => 3, // anchor 5 days back → outside
            'engagement_trend_window_days' => 30,
            'story_retention_days' => 180,
            'communication_retention_days' => 0,
        ]);

        $this->assertTrue($this->scope()->forTarget($item)->isEmpty());
    }

    public function test_unshipped_and_out_of_window_shipments_are_never_candidates(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $this->makeShipment($creator, $this->makeProduct('Unshipped'), null);
        $this->makeShipment($creator, $this->makeProduct('Ancient'), $publishedAt->subDays(200), $publishedAt->subDays(190));

        $set = $this->scope()->forTarget($item);

        $this->assertTrue($set->isEmpty());
        $this->assertFalse($set->hasInWindowShipment());
    }

    public function test_roster_primaries_of_active_and_shipping_campaigns_are_candidates(): void
    {
        [$creator, $item] = $this->makeCreatorWithPost(CarbonImmutable::parse('2026-07-10 12:00:00'));

        $active = $this->makeProduct('Active Primary');
        $shipping = $this->makeProduct('Shipping Primary');
        $this->makeRosterCampaign($creator, $active, SeedingCampaignStatus::Active);
        $this->makeRosterCampaign($creator, $shipping, SeedingCampaignStatus::Shipping);
        // Not candidates: wrong status, other creator's roster, no primary product.
        $this->makeRosterCampaign($creator, $this->makeProduct('Draft Primary'), SeedingCampaignStatus::Draft);
        $this->makeRosterCampaign(Creator::factory()->create(), $this->makeProduct('Off Roster'), SeedingCampaignStatus::Active);
        $productless = SeedingCampaign::factory()->create(['status' => SeedingCampaignStatus::Active]);
        $productless->creators()->attach($creator->id);

        $set = $this->scope()->forTarget($item);

        $this->assertSame(
            [$active->id, $shipping->id],
            array_map(fn (Candidate $c): int => $c->productId, $set->candidates),
        );
        $this->assertSame('roster', $set->candidates[0]->source);
        $this->assertFalse($set->candidates[0]->shipmentInWindow);
        $this->assertNull($set->candidates[0]->shipmentAnchorAt);
        $this->assertNull($set->candidates[0]->shipmentAgeDays);
        $this->assertNotNull($set->candidates[0]->seedingCampaignId);
        $this->assertFalse($set->hasInWindowShipment());
        $this->assertSame(Priority::High, $set->priority);
    }

    public function test_a_product_seen_via_both_sources_is_one_shipment_candidate(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $product = $this->makeProduct('Both Sources');
        $this->makeShipment($creator, $product, $publishedAt->subDays(9), $publishedAt->subDays(7), SeedingCampaignStatus::Active);
        $this->makeRosterCampaign($creator, $product, SeedingCampaignStatus::Active);

        $set = $this->scope()->forTarget($item);

        $this->assertCount(1, $set->candidates);
        $this->assertSame('shipment', $set->candidates[0]->source);
        $this->assertTrue($set->candidates[0]->shipmentInWindow);
    }

    public function test_multiple_shipments_of_one_product_keep_the_freshest_anchor(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $product = $this->makeProduct('Restocked');
        $this->makeShipment($creator, $product, $publishedAt->subDays(30), $publishedAt->subDays(28));
        $this->makeShipment($creator, $product, $publishedAt->subDays(6), $publishedAt->subDays(4));

        $set = $this->scope()->forTarget($item);

        $this->assertCount(1, $set->candidates);
        $this->assertSame(4, $set->candidates[0]->shipmentAgeDays);
    }

    public function test_priority_is_high_only_with_an_active_campaign_link(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
        $this->makeShipment($creator, $this->makeProduct('Old Gift'), $publishedAt->subDays(9), $publishedAt->subDays(7), SeedingCampaignStatus::Completed);

        // Shipment outside an active campaign → medium.
        $this->assertSame(Priority::Medium, $this->scope()->forTarget($item)->priority);

        // An ACTIVE-campaign shipment lifts the whole run to high.
        $this->makeShipment($creator, $this->makeProduct('Fresh Gift'), $publishedAt->subDays(3), $publishedAt->subDays(2), SeedingCampaignStatus::Active);

        $this->assertSame(Priority::High, $this->scope()->forTarget($item)->priority);
    }

    public function test_only_products_with_embedded_photos_are_matchable(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');
        [$creator, $item] = $this->makeCreatorWithPost($publishedAt);

        $embedded = $this->makeProduct('Embedded');
        $photoOnly = $this->makeProduct('Photo Only');
        $staleModel = $this->makeProduct('Stale Model');

        foreach ([$embedded, $photoOnly, $staleModel] as $product) {
            $this->makeShipment($creator, $product, $publishedAt->subDays(9), $publishedAt->subDays(7));
        }

        $this->embedPhoto($embedded);
        ProductReferencePhoto::factory()->create(['product_id' => $photoOnly->id]);
        $this->embedPhoto($staleModel, 'some-older-model');

        $set = $this->scope()->forTarget($item);

        // Unmatchable candidates stay recorded (coverage) but cost nothing.
        $this->assertCount(3, $set->candidates);
        $this->assertSame([$embedded->id], array_map(fn (Candidate $c): int => $c->productId, $set->matchable()));
        $this->assertTrue($set->candidates[0]->hasEmbeddedPhotos);
    }

    public function test_an_unresolvable_creator_yields_an_empty_set(): void
    {
        $account = PlatformAccount::factory()->create(); // creator_id stays null
        $item = ContentItem::factory()->for($account, 'platformAccount')->create();

        $set = $this->scope()->forTarget($item);

        $this->assertTrue($set->isEmpty());
        $this->assertSame([], $set->matchable());
    }

    public function test_stories_scope_on_captured_at(): void
    {
        $capturedAt = CarbonImmutable::parse('2026-07-10 09:00:00');
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $story = Story::factory()->for($account, 'platformAccount')->create([
            'captured_at' => $capturedAt,
            'expires_at' => $capturedAt->addDay(),
        ]);
        $this->makeShipment($creator, $this->makeProduct('Story Gift'), $capturedAt->subDays(4), $capturedAt->subDays(3));

        $set = $this->scope()->forTarget($story);

        $this->assertCount(1, $set->candidates);
        $this->assertSame(3, $set->candidates[0]->shipmentAgeDays);
    }

    public function test_candidates_are_tenant_isolated(): void
    {
        [$tenantA, $tenantB] = $this->makeTenantPair();
        $publishedAt = CarbonImmutable::parse('2026-07-10 12:00:00');

        $postA = $this->withTenant($tenantA, function () use ($publishedAt): ContentItem {
            [$creator, $item] = $this->makeCreatorWithPost($publishedAt);
            $this->makeShipment($creator, $this->makeProduct('Product A'), $publishedAt->subDays(10), $publishedAt->subDays(8));

            return $item;
        });

        $this->withTenant($tenantB, function () use ($publishedAt): void {
            [$creator] = $this->makeCreatorWithPost($publishedAt);
            $this->makeShipment($creator, $this->makeProduct('Product B'), $publishedAt->subDays(10), $publishedAt->subDays(8));
        });

        $set = $this->withTenant($tenantA, fn (): CandidateSet => $this->scope()->forTarget($postA));

        $this->assertCount(1, $set->candidates);
        $this->assertSame('Product A', $set->candidates[0]->productLabel);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter CandidateScopeTest`
Expected: ERROR — `Class "App\Platform\Enrichment\VisualMatch\Candidates\CandidateScope" not found`.

- [ ] **Step 3: Implement the `Candidate` and `CandidateSet` DTOs**

Create `app/Platform/Enrichment/VisualMatch/Candidates/Candidate.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Candidates;

use App\Shared\Enums\SectorLabel;
use Carbon\CarbonImmutable;

/**
 * One product plausibly visible in a post, with the evidence of WHY it was
 * considered (candidate-source columns of visual_match_candidates, spec
 * §4.5). Only candidates with ≥ 1 embedded reference photo at the
 * configured model_version are matchable; the rest are recorded for
 * coverage accounting and cost nothing.
 */
final readonly class Candidate
{
    public function __construct(
        public int $productId,
        public string $productLabel,
        public string $brandName,
        public ?SectorLabel $category,
        public string $source,               // 'shipment'|'roster'
        public bool $shipmentInWindow,
        public ?int $seedingCampaignId,
        public ?CarbonImmutable $shipmentAnchorAt,
        public ?int $shipmentAgeDays,        // anchor → post published_at, whole days
        public bool $hasEmbeddedPhotos,
    ) {}
}
```

Create `app/Platform/Enrichment/VisualMatch/Candidates/CandidateSet.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Candidates;

use App\Platform\AiBudget\Priority;

/**
 * The scoped candidates of one post plus the budget priority tier the set
 * earns (spec §7): HIGH when any candidate ties to an ACTIVE/SHIPPING
 * campaign, MEDIUM otherwise. "Low" ≡ empty set — the matcher skips with
 * skipped:no-candidates before priority is ever consulted.
 */
final readonly class CandidateSet
{
    public function __construct(
        /** @var list<Candidate> deterministic order: shipment candidates by productId, then roster by productId */
        public array $candidates,
        public Priority $priority,
    ) {}

    /** @return list<Candidate> only candidates worth embedding against */
    public function matchable(): array
    {
        return array_values(array_filter(
            $this->candidates,
            fn (Candidate $candidate): bool => $candidate->hasEmbeddedPhotos,
        ));
    }

    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }

    public function hasInWindowShipment(): bool
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->shipmentInWindow) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Implement `CandidateScope`**

Create `app/Platform/Enrichment/VisualMatch/Candidates/CandidateScope.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Candidates;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\CRM\Services\ActiveSeedingCreatorIds;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\AiBudget\Priority;
use App\Shared\Settings\MonitoringSettingsResolver;
use Carbon\CarbonImmutable;

/**
 * Resolves which products could plausibly appear in a post (spec §7):
 *  - shipment candidates: the creator's dispatched shipments whose
 *    attribution window [anchor = delivered ?? shipped, anchor + tenant
 *    shipment_window_days] contains the post's publish/capture time —
 *    byte-identical semantics to MentionClassifier::timingSatisfied
 *    (ADR-0025, both edges inclusive), zero new knobs;
 *  - roster candidates: primary products of ACTIVE/SHIPPING seeding
 *    campaigns whose roster contains the creator.
 * Union, deduped (shipment evidence wins), tenant-scoped through the
 * models' BelongsToTenant under the enrichment job's TenantContext.
 * An empty set costs nothing — the tiering that keeps most posts free.
 */
final class CandidateScope
{
    public function __construct(private readonly MonitoringSettingsResolver $settings) {}

    public function forTarget(ContentItem|Story $target): CandidateSet
    {
        $creatorId = $target->platformAccount?->creator_id;
        $publishedAt = $target instanceof ContentItem ? $target->published_at : $target->captured_at;

        if ($creatorId === null || $publishedAt === null) {
            // The matcher reports skipped:no-creator before priority matters.
            return new CandidateSet([], Priority::Medium);
        }

        $windowDays = $this->settings->shipmentWindowDays();

        /** @var array<int, array{product: Product, anchor: CarbonImmutable, ageDays: int, campaignId: int|null, campaignActive: bool}> $shipmentRows */
        $shipmentRows = [];

        $shipments = Shipment::query()
            ->where('creator_id', $creatorId)
            ->whereNotNull('shipped_at')
            ->with(['seedingCampaign', 'product.brand'])
            ->orderBy('id')
            ->get();

        foreach ($shipments as $shipment) {
            /** @var CarbonImmutable $anchor delivery beats shipping (ShipmentEvidence::anchorDate) */
            $anchor = $shipment->delivered_at ?? $shipment->shipped_at;

            $inWindow = $publishedAt->greaterThanOrEqualTo($anchor)
                && $publishedAt->lessThanOrEqualTo($anchor->addDays($windowDays));

            if (! $inWindow) {
                continue;
            }

            $existing = $shipmentRows[$shipment->product_id] ?? null;

            // One candidate per product: the freshest anchor is the evidence
            // stamp; equal anchors keep the lower shipment id (determinism).
            if ($existing !== null && ! $anchor->greaterThan($existing['anchor'])) {
                continue;
            }

            $shipmentRows[$shipment->product_id] = [
                'product' => $shipment->product,
                'anchor' => $anchor,
                'ageDays' => (int) floor($anchor->diffInDays($publishedAt)),
                'campaignId' => $shipment->seeding_campaign_id,
                'campaignActive' => in_array($shipment->seedingCampaign->status, ActiveSeedingCreatorIds::ACTIVE_STATUSES, true),
            ];
        }

        /** @var array<int, array{product: Product, campaignId: int}> $rosterRows */
        $rosterRows = [];

        $campaigns = SeedingCampaign::query()
            ->whereIn('status', ActiveSeedingCreatorIds::statusValues())
            ->whereNotNull('product_id')
            ->whereHas('creators', fn ($query) => $query->whereKey($creatorId))
            ->with('product.brand')
            ->orderBy('id')
            ->get();

        foreach ($campaigns as $campaign) {
            $productId = (int) $campaign->product_id;

            if (isset($shipmentRows[$productId]) || isset($rosterRows[$productId])) {
                continue;
            }

            $rosterRows[$productId] = ['product' => $campaign->product, 'campaignId' => $campaign->id];
        }

        if ($shipmentRows === [] && $rosterRows === []) {
            return new CandidateSet([], Priority::Medium);
        }

        ksort($shipmentRows);
        ksort($rosterRows);

        $embedded = $this->productsWithEmbeddedPhotos([...array_keys($shipmentRows), ...array_keys($rosterRows)]);
        $candidates = [];

        foreach ($shipmentRows as $productId => $row) {
            $candidates[] = new Candidate(
                productId: $productId,
                productLabel: $row['product']->name,
                brandName: $row['product']->brand->name,
                category: $row['product']->category,
                source: 'shipment',
                shipmentInWindow: true,
                seedingCampaignId: $row['campaignId'],
                shipmentAnchorAt: $row['anchor'],
                shipmentAgeDays: $row['ageDays'],
                hasEmbeddedPhotos: in_array($productId, $embedded, true),
            );
        }

        foreach ($rosterRows as $productId => $row) {
            $candidates[] = new Candidate(
                productId: $productId,
                productLabel: $row['product']->name,
                brandName: $row['product']->brand->name,
                category: $row['product']->category,
                source: 'roster',
                shipmentInWindow: false,
                seedingCampaignId: $row['campaignId'],
                shipmentAnchorAt: null,
                shipmentAgeDays: null,
                hasEmbeddedPhotos: in_array($productId, $embedded, true),
            );
        }

        // HIGH: any active-campaign link (roster by construction, or a
        // shipment whose campaign is ACTIVE/SHIPPING). MEDIUM: shipments
        // outside active campaigns only (spec §7 priority tiers).
        $priority = $rosterRows !== []
            || array_filter($shipmentRows, fn (array $row): bool => $row['campaignActive']) !== []
            ? Priority::High
            : Priority::Medium;

        return new CandidateSet($candidates, $priority);
    }

    /**
     * Products with ≥ 1 embedded reference photo at the configured
     * model_version — the matchability gate (spec §7). Tenant isolation
     * rides on ProductReferencePhoto's qualified TenantScope.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    private function productsWithEmbeddedPhotos(array $productIds): array
    {
        $modelVersion = (string) config('qds.enrichment.visual_match.model_version');

        return ProductReferencePhoto::query()
            ->whereIn('product_reference_photos.product_id', $productIds)
            ->join(
                'product_photo_embeddings',
                'product_photo_embeddings.product_reference_photo_id',
                '=',
                'product_reference_photos.id',
            )
            ->where('product_photo_embeddings.model_version', $modelVersion)
            ->distinct()
            ->pluck('product_reference_photos.product_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
```

- [ ] **Step 5: Run it to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter CandidateScopeTest`
Expected: PASS (13 tests).

- [ ] **Step 6: Run the adjacent window-semantics suites** (nothing existing was modified, but the parity claim must hold against the real classifier):

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Enrichment/CandidateScopeTest.php tests/Feature/Enrichment/PerTenantShipmentWindowTest.php tests/Feature/Enrichment/ShipmentEvidenceSourceTest.php`
Expected: PASS (all tests, 0 failures).

- [ ] **Step 7: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/Candidates/Candidate.php app/Platform/Enrichment/VisualMatch/Candidates/CandidateSet.php app/Platform/Enrichment/VisualMatch/Candidates/CandidateScope.php tests/Feature/Enrichment/CandidateScopeTest.php
git commit -m "feat(enrichment): visual-match candidate scope with shipment/roster evidence"
```

---

### Task 16: FrameProductScorer — single-SQL exact cosine scan

**Files:**
- Create: `app/Platform/Enrichment/VisualMatch/Matching/FrameScore.php`
- Create: `app/Platform/Enrichment/VisualMatch/Matching/CandidateScores.php`
- Create: `app/Platform/Enrichment/VisualMatch/Matching/FrameProductScorer.php`
- Test: `tests/Feature/Enrichment/FrameProductScorerTest.php` (create)

**Interfaces:**
- Consumes: `PreparedFrame` (Task 12: `__construct(Keyframe $keyframe, string $bytes, string $mimeType, int $representedFrames, ?int $spanStartMs, ?int $spanEndMs)`); `Candidate` (Task 15, contract constructor); `VectorLiteral::fromArray(array $vector): string` (Task 1); `keyframe_embeddings` / `product_photo_embeddings` / `product_reference_photos` tables (Tasks 4/5); `TenantContext::idOrFail(): int`. pgvector semantics (verified, spec §18): `<=>` is cosine DISTANCE, similarity = `1 - (a <=> b)`, exact scan (no index).
- Produces (frozen, consumed by Tasks 17 and 19):

```php
final readonly class FrameScore {
    public function __construct(
        public int $keyframeId, public int $ordinal, public ?int $timestampMs,
        public float $similarity, public int $photoId, public int $representedFrames,
    ) {}
}
final readonly class CandidateScores {
    /** @param list<FrameScore> $frameScores  best photo per frame, ordered by ordinal */
    public function __construct(public Candidate $candidate, public array $frameScores) {}
    public function bestSimilarity(): float;    // 0.0 when no frames
}
final class FrameProductScorer {
    /** @param list<PreparedFrame> $frames @param list<Candidate> $matchable
     *  @return list<CandidateScores>  one per matchable candidate, candidate order preserved */
    public function score(array $frames, array $matchable, string $modelVersion): array;
}
```

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Enrichment/FrameProductScorerTest.php`. Test vectors are unit vectors at known angles in the first two of the 3072 DDL dimensions (zero-padding preserves cosine geometry), written through `VectorLiteral`:

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Platform\Enrichment\VisualMatch\Matching\FrameProductScorer;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exact-scan scorer over seeded vectors with KNOWN cosine geometry: unit
 * vectors at known angles in the first two of the 3072 dimensions
 * (zero-padding changes nothing about the angle), so every expected
 * similarity is a textbook cosine. Verified pgvector semantics (spec §18):
 * `<=>` is cosine DISTANCE, similarity = 1 - (a <=> b), exact scan.
 */
class FrameProductScorerTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'gemini-embedding-2';

    public function test_scores_best_photo_per_frame_per_candidate_with_known_cosine_geometry(): void
    {
        $item = ContentItem::factory()->create();
        $frameA = $this->makeKeyframe($item, 0, 0);
        $frameB = $this->makeKeyframe($item, 1, 6000);
        $this->embedKeyframe($frameA, [1.0, 0.0]);
        $this->embedKeyframe($frameB, [0.0, 1.0]);

        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        // Product A: one photo aligned with frame A (cos 0° = 1.0), one at
        // 60° from frame A (cos 60° = 0.5) — the aligned photo must win A/A.
        $alignedPhoto = $this->photoWithEmbedding($productA, [1.0, 0.0]);
        $sixtyPhoto = $this->photoWithEmbedding($productA, [0.5, sqrt(3) / 2]);
        // Product B: one photo orthogonal to frame A, aligned with frame B.
        $orthogonalPhoto = $this->photoWithEmbedding($productB, [0.0, 1.0]);

        $scores = app(FrameProductScorer::class)->score(
            [$this->preparedFrame($frameA), $this->preparedFrame($frameB, representedFrames: 2)],
            [$this->candidate($productA), $this->candidate($productB)],
            self::MODEL,
        );

        $this->assertCount(2, $scores);
        [$forA, $forB] = $scores;

        // Candidate order preserved even though B also scores.
        $this->assertSame($productA->id, $forA->candidate->productId);
        $this->assertSame($productB->id, $forB->candidate->productId);

        // Product A vs frame A: aligned photo wins at ~1.0 (60° photo loses
        // at 0.5); vs frame B: the 60° photo wins at cos 30° ≈ 0.8660.
        $this->assertCount(2, $forA->frameScores);
        [$a0, $a1] = $forA->frameScores; // ordered by ordinal
        $this->assertSame(0, $a0->ordinal);
        $this->assertSame($frameA->id, $a0->keyframeId);
        $this->assertSame(0, $a0->timestampMs);
        $this->assertSame($alignedPhoto, $a0->photoId);
        $this->assertSame(1, $a0->representedFrames);
        $this->assertEqualsWithDelta(1.0, $a0->similarity, 0.0001);
        $this->assertSame(1, $a1->ordinal);
        $this->assertSame(6000, $a1->timestampMs);
        $this->assertSame(2, $a1->representedFrames);
        $this->assertSame($sixtyPhoto, $a1->photoId);
        $this->assertEqualsWithDelta(sqrt(3) / 2, $a1->similarity, 0.0001);
        $this->assertEqualsWithDelta(1.0, $forA->bestSimilarity(), 0.0001);

        // Product B vs frame A: orthogonal → 0.0; vs frame B: aligned → 1.0.
        [$b0, $b1] = $forB->frameScores;
        $this->assertSame($orthogonalPhoto, $b0->photoId);
        $this->assertEqualsWithDelta(0.0, $b0->similarity, 0.0001);
        $this->assertEqualsWithDelta(1.0, $b1->similarity, 0.0001);
        $this->assertEqualsWithDelta(1.0, $forB->bestSimilarity(), 0.0001);
    }

    public function test_photo_similarity_ties_break_on_lower_photo_id(): void
    {
        $item = ContentItem::factory()->create();
        $frame = $this->makeKeyframe($item, 0, 0);
        $this->embedKeyframe($frame, [1.0, 0.0]);

        $product = Product::factory()->create();
        $firstPhoto = $this->photoWithEmbedding($product, [1.0, 0.0]);
        $this->photoWithEmbedding($product, [1.0, 0.0]); // identical vector, higher id

        $scores = app(FrameProductScorer::class)->score(
            [$this->preparedFrame($frame)],
            [$this->candidate($product)],
            self::MODEL,
        );

        // Fully-specified ORDER BY: deterministic winner on exact ties.
        $this->assertSame($firstPhoto, $scores[0]->frameScores[0]->photoId);
    }

    public function test_only_embeddings_at_the_requested_model_version_are_scored(): void
    {
        $item = ContentItem::factory()->create();
        $frame = $this->makeKeyframe($item, 0, 0);
        $this->embedKeyframe($frame, [1.0, 0.0]);

        $product = Product::factory()->create();
        $this->photoWithEmbedding($product, [1.0, 0.0], modelVersion: 'other-model-v9');

        $scores = app(FrameProductScorer::class)->score(
            [$this->preparedFrame($frame)],
            [$this->candidate($product)],
            self::MODEL,
        );

        // Vectors from another model live in an incompatible space (spec §5)
        // — never comparable. No fabricated score; bestSimilarity 0.0.
        $this->assertCount(1, $scores);
        $this->assertSame([], $scores[0]->frameScores);
        $this->assertSame(0.0, $scores[0]->bestSimilarity());
    }

    public function test_frames_without_a_cached_embedding_are_omitted_not_fabricated(): void
    {
        $item = ContentItem::factory()->create();
        $embedded = $this->makeKeyframe($item, 0, 0);
        $unembedded = $this->makeKeyframe($item, 1, 6000); // transient embed failure: no row
        $this->embedKeyframe($embedded, [1.0, 0.0]);

        $product = Product::factory()->create();
        $this->photoWithEmbedding($product, [1.0, 0.0]);

        $scores = app(FrameProductScorer::class)->score(
            [$this->preparedFrame($embedded), $this->preparedFrame($unembedded)],
            [$this->candidate($product)],
            self::MODEL,
        );

        $this->assertCount(1, $scores[0]->frameScores);
        $this->assertSame($embedded->id, $scores[0]->frameScores[0]->keyframeId);
    }

    public function test_empty_inputs_short_circuit_without_sql(): void
    {
        $product = Product::factory()->create();

        $this->assertSame([], app(FrameProductScorer::class)->score([], [], self::MODEL));

        $scores = app(FrameProductScorer::class)->score([], [$this->candidate($product)], self::MODEL);
        $this->assertCount(1, $scores);
        $this->assertSame([], $scores[0]->frameScores);
    }

    public function test_another_tenants_embeddings_never_leak_into_the_scan(): void
    {
        $item = ContentItem::factory()->create();
        $frame = $this->makeKeyframe($item, 0, 0);
        $this->embedKeyframe($frame, [0.5, sqrt(3) / 2]); // 60° from e1

        $product = Product::factory()->create();
        $ownPhoto = $this->photoWithEmbedding($product, [1.0, 0.0]); // cos 60° = 0.5

        // A second tenant with a perfectly-aligned photo chain of its own —
        // must be invisible to this tenant's scan.
        $other = $this->makeTenant('Other Tenant');
        $this->withTenant($other, function (): void {
            $foreignProduct = Product::factory()->create();
            $this->photoWithEmbedding($foreignProduct, [0.5, sqrt(3) / 2]); // would score 1.0
        });

        $scores = app(FrameProductScorer::class)->score(
            [$this->preparedFrame($frame)],
            [$this->candidate($product)],
            self::MODEL,
        );

        $this->assertCount(1, $scores);
        $this->assertCount(1, $scores[0]->frameScores);
        $this->assertSame($ownPhoto, $scores[0]->frameScores[0]->photoId);
        $this->assertEqualsWithDelta(0.5, $scores[0]->frameScores[0]->similarity, 0.0001);
    }

    /**
     * Zero-padded to the DDL's 3072 dims — padding preserves cosine geometry.
     *
     * @param  list<float>  $components
     * @return list<float>
     */
    private function vec(array $components): array
    {
        return array_pad($components, 3072, 0.0);
    }

    private function makeKeyframe(ContentItem $item, int $ordinal, ?int $timestampMs): Keyframe
    {
        return Keyframe::query()->create([
            'owner_type' => $item->getMorphClass(),
            'owner_id' => $item->id,
            'ordinal' => $ordinal,
            'timestamp_ms' => $timestampMs,
            'storage_disk' => 'media',
            'storage_path' => "tenants/{$item->tenant_id}/keyframes/instagram/1/content-{$item->id}/{$ordinal}.jpg",
            'width' => 100,
            'height' => 100,
            'kind' => KeyframeKind::VideoSample,
            'checksum' => hash('sha256', "frame-{$item->id}-{$ordinal}"),
            'source_checksum' => str_repeat('b', 64),
            'provenance' => new Provenance('SRC-apify-instagram-reel-scraper', CarbonImmutable::now(), 'keyframes-v1'),
        ]);
    }

    /** @param list<float> $components */
    private function embedKeyframe(Keyframe $keyframe, array $components, string $modelVersion = self::MODEL): void
    {
        DB::table('keyframe_embeddings')->insert([
            'tenant_id' => $keyframe->tenant_id,
            'keyframe_id' => $keyframe->id,
            'model_version' => $modelVersion,
            'embedding' => VectorLiteral::fromArray($this->vec($components)),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  list<float>  $components
     * @return int the reference-photo id
     */
    private function photoWithEmbedding(Product $product, array $components, string $modelVersion = self::MODEL): int
    {
        $photoId = (int) DB::table('product_reference_photos')->insertGetId([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'storage_disk' => 'media',
            'storage_path' => "tenants/{$product->tenant_id}/product-photos/{$product->id}/".uniqid('', true).'.jpg',
            'view_label' => 'front',
            'checksum' => hash('sha256', uniqid('photo', true)),
            'width' => 800,
            'height' => 800,
            'uploaded_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_photo_embeddings')->insert([
            'tenant_id' => $product->tenant_id,
            'product_reference_photo_id' => $photoId,
            'model_version' => $modelVersion,
            'embedding' => VectorLiteral::fromArray($this->vec($components)),
            'created_at' => now(),
        ]);

        return $photoId;
    }

    private function preparedFrame(Keyframe $keyframe, int $representedFrames = 1): PreparedFrame
    {
        return new PreparedFrame(
            keyframe: $keyframe,
            bytes: 'jpeg-bytes',
            mimeType: 'image/jpeg',
            representedFrames: $representedFrames,
            spanStartMs: $keyframe->timestamp_ms,
            spanEndMs: $keyframe->timestamp_ms,
        );
    }

    private function candidate(Product $product): Candidate
    {
        return new Candidate(
            productId: $product->id,
            productLabel: $product->name,
            brandName: 'Nexon Labs',
            category: $product->category,
            source: 'shipment',
            shipmentInWindow: true,
            seedingCampaignId: null,
            shipmentAnchorAt: null,
            shipmentAgeDays: 10,
            hasEmbeddedPhotos: true,
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter FrameProductScorerTest`
Expected: 6 ERRORS — `Error: Class "App\Platform\Enrichment\VisualMatch\Matching\FrameProductScorer" not found`.

- [ ] **Step 3: Implement the DTOs and the scorer**

Create `app/Platform/Enrichment/VisualMatch/Matching/FrameScore.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

/**
 * The best reference-photo similarity of ONE prepared frame against ONE
 * candidate product (sub-project C). timestampMs/representedFrames carry
 * the frame's dedup-group evidence forward into BandMapper's support
 * counting and visibility estimation.
 */
final readonly class FrameScore
{
    public function __construct(
        public int $keyframeId,
        public int $ordinal,
        public ?int $timestampMs,
        public float $similarity,
        public int $photoId,
        public int $representedFrames,
    ) {}
}
```

Create `app/Platform/Enrichment/VisualMatch/Matching/CandidateScores.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;

/**
 * All per-frame best scores of one matchable candidate (sub-project C).
 */
final readonly class CandidateScores
{
    /** @param list<FrameScore> $frameScores best photo per frame, ordered by ordinal */
    public function __construct(
        public Candidate $candidate,
        public array $frameScores,
    ) {}

    /** Best similarity across all frames; 0.0 when no frames scored. */
    public function bestSimilarity(): float
    {
        if ($this->frameScores === []) {
            return 0.0;
        }

        return max(array_map(fn (FrameScore $score): float => $score->similarity, $this->frameScores));
    }
}
```

Create `app/Platform/Enrichment/VisualMatch/Matching/FrameProductScorer.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ONE exact-scan SQL statement per run (sub-project C, spec §8): for every
 * (embedded frame, candidate product) pair, the best cosine similarity
 * across that product's reference photos at the requested model_version.
 * pgvector's `<=>` is cosine DISTANCE (verified, spec §18), so similarity
 * = 1 - (a <=> b) and ORDER BY distance ASC is best-first. Exact scan on
 * purpose — candidate sets are btree-pre-filtered to double digits, where
 * exact beats ANN, loses zero recall, and stays fully deterministic (photo
 * ties break on lower photo id via the fully-specified ORDER BY).
 *
 * Frames without a cached keyframe_embeddings row (transient embed
 * failure) simply drop out of the join — omitted, never fabricated.
 */
final class FrameProductScorer
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  list<PreparedFrame>  $frames
     * @param  list<Candidate>  $matchable
     * @return list<CandidateScores> one per matchable candidate, candidate order preserved
     */
    public function score(array $frames, array $matchable, string $modelVersion): array
    {
        if ($matchable === []) {
            return [];
        }

        /** @var array<int, PreparedFrame> $frameByKeyframeId */
        $frameByKeyframeId = [];
        foreach ($frames as $frame) {
            $frameByKeyframeId[(int) $frame->keyframe->getKey()] = $frame;
        }

        $best = $frameByKeyframeId === [] ? [] : $this->bestPhotoPerFrame(
            array_keys($frameByKeyframeId),
            array_map(fn (Candidate $candidate): int => $candidate->productId, $matchable),
            $modelVersion,
        );

        $results = [];
        foreach ($matchable as $candidate) {
            $scores = [];
            foreach ($best[$candidate->productId] ?? [] as $keyframeId => $row) {
                $frame = $frameByKeyframeId[$keyframeId];
                $scores[] = new FrameScore(
                    keyframeId: $keyframeId,
                    ordinal: (int) $frame->keyframe->ordinal,
                    timestampMs: $frame->keyframe->timestamp_ms === null ? null : (int) $frame->keyframe->timestamp_ms,
                    similarity: (float) $row->similarity,
                    photoId: (int) $row->photo_id,
                    representedFrames: $frame->representedFrames,
                );
            }
            usort($scores, fn (FrameScore $a, FrameScore $b): int => $a->ordinal <=> $b->ordinal);
            $results[] = new CandidateScores($candidate, $scores);
        }

        return $results;
    }

    /**
     * @param  list<int>  $keyframeIds
     * @param  list<int>  $productIds
     * @return array<int, array<int, object>> product id → keyframe id → winning row {photo_id, similarity}
     */
    private function bestPhotoPerFrame(array $keyframeIds, array $productIds, string $modelVersion): array
    {
        $tenantId = $this->tenantContext->idOrFail();
        $framePlaceholders = implode(',', array_fill(0, count($keyframeIds), '?'));
        $productPlaceholders = implode(',', array_fill(0, count($productIds), '?'));

        $sql = <<<SQL
            SELECT scored.keyframe_id, scored.product_id, scored.photo_id, scored.similarity
            FROM (
                SELECT ke.keyframe_id,
                       prp.product_id,
                       pe.product_reference_photo_id AS photo_id,
                       1 - (ke.embedding <=> pe.embedding) AS similarity,
                       ROW_NUMBER() OVER (
                           PARTITION BY ke.keyframe_id, prp.product_id
                           ORDER BY ke.embedding <=> pe.embedding ASC, pe.product_reference_photo_id ASC
                       ) AS best_rank
                FROM keyframe_embeddings ke
                JOIN product_photo_embeddings pe
                    ON pe.tenant_id = ke.tenant_id AND pe.model_version = ke.model_version
                JOIN product_reference_photos prp
                    ON prp.id = pe.product_reference_photo_id AND prp.tenant_id = pe.tenant_id
                WHERE ke.tenant_id = ?
                  AND ke.model_version = ?
                  AND ke.keyframe_id IN ({$framePlaceholders})
                  AND prp.product_id IN ({$productPlaceholders})
            ) scored
            WHERE scored.best_rank = 1
            ORDER BY scored.product_id ASC, scored.keyframe_id ASC
        SQL;

        $rows = DB::select($sql, [$tenantId, $modelVersion, ...$keyframeIds, ...$productIds]);

        $best = [];
        foreach ($rows as $row) {
            $best[(int) $row->product_id][(int) $row->keyframe_id] = $row;
        }

        return $best;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter FrameProductScorerTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Run the visual-match feature tests together**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter 'FrameProductScorerTest|VisualMatchTablesTest|KeyframeErasureTest'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/Matching/FrameScore.php app/Platform/Enrichment/VisualMatch/Matching/CandidateScores.php app/Platform/Enrichment/VisualMatch/Matching/FrameProductScorer.php tests/Feature/Enrichment/FrameProductScorerTest.php
git commit -m "feat(enrichment): frame-product scorer — single-SQL exact cosine scan"
```

---

---

### Task 17: Thresholds, ThresholdResolver, BandMapper — bands + run outcome

**Files:**
- Create: `app/Platform/Enrichment/VisualMatch/Matching/Thresholds.php`
- Create: `app/Platform/Enrichment/VisualMatch/Matching/ThresholdResolver.php`
- Create: `app/Platform/Enrichment/VisualMatch/Matching/BandResult.php`
- Create: `app/Platform/Enrichment/VisualMatch/Matching/BandMapper.php`
- Test: `tests/Unit/Enrichment/ThresholdResolverTest.php` (create)
- Test: `tests/Unit/Enrichment/BandMapperTest.php` (create)

**Interfaces:**
- Consumes: `FrameScore` / `CandidateScores` (Task 16), `FramePreparationResult` (Task 12: `coverageDegraded(): bool`, `public array $frames`, `public int $framesAvailable`) and `PreparedFrame` (Task 12), `Candidate` / `CandidateSet` (Task 15: `matchable(): array`, `isEmpty(): bool`), `Priority` (Task 8), `VisualMatchBand` / `VisualMatchOutcome` (Task 14), `SectorLabel`. Reads `config('qds.enrichment.visual_match.thresholds')` — Task 19 owns adding the §12 config block; the resolver falls back to the spec placeholder values (0.65/0.55/0.05) when keys are absent, and these tests set the keys explicitly.
- Produces (frozen, consumed by Tasks 18, 19, 23):

```php
final readonly class Thresholds {
    public function __construct(public float $auto, public float $review, public float $margin) {}
}
final class ThresholdResolver {
    public function for(?\App\Shared\Enums\SectorLabel $category): Thresholds;
}
final readonly class BandResult {
    /** @param list<FrameScore> $supportingFrames */
    public function __construct(
        public Candidate $candidate, public \App\Shared\Enums\VisualMatchBand $band,
        public array $supportingFrames, public int $supportCount,
        public ?float $marginToRunnerUp, public ?string $rejectionReason,
        public ?int $firstSupportMs, public ?int $lastSupportMs, public ?int $estimatedVisibleMs,
        public float $bestSimilarity,
    ) {}
}
final class BandMapper {
    public function __construct(private readonly ThresholdResolver $thresholds) {}
    /** @param list<CandidateScores> $scored @return list<BandResult> ranked best-first; ties by lower productId */
    public function map(array $scored, FramePreparationResult $prep): array;
    public function runOutcome(array $bandResults, FramePreparationResult $prep, CandidateSet $candidates): \App\Shared\Enums\VisualMatchOutcome;
}
```

Semantics later tasks rely on: `supportingFrames` = frames at **review** strength or better (the evidence trail for signals/persistence); `supportCount` = dedup-aware count of **auto**-strength frames (timestamped groups add their full `representedFrames`, null-timestamp groups add exactly 1); `rejectionReason` ∈ {`'below-review-threshold'` (REJECT), `'margin-ambiguous'` (REVIEW with auto support but failed margin), `null`}.

- [ ] **Step 1: Write the failing ThresholdResolver test**

Create `tests/Unit/Enrichment/ThresholdResolverTest.php`:

```php
<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\VisualMatch\Matching\ThresholdResolver;
use App\Shared\Enums\SectorLabel;
use Tests\TestCase;

/**
 * Per-category band thresholds (spec §8/§12). Values are deliberate
 * PLACEHOLDERS — calibration is sub-project E's mandate.
 */
class ThresholdResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('qds.enrichment.visual_match.thresholds', [
            'default' => ['auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
            'BEAUTY' => ['auto' => 0.70],
            'FOOD_BEVERAGE' => ['auto' => 0.70],
        ]);
    }

    public function test_null_category_resolves_the_default_band(): void
    {
        $thresholds = (new ThresholdResolver)->for(null);

        $this->assertSame(0.65, $thresholds->auto);
        $this->assertSame(0.55, $thresholds->review);
        $this->assertSame(0.05, $thresholds->margin);
    }

    public function test_category_override_merges_over_the_default(): void
    {
        $thresholds = (new ThresholdResolver)->for(SectorLabel::Beauty);

        // Only auto is overridden; review/margin inherit from default.
        $this->assertSame(0.70, $thresholds->auto);
        $this->assertSame(0.55, $thresholds->review);
        $this->assertSame(0.05, $thresholds->margin);
    }

    public function test_category_without_override_falls_back_to_default(): void
    {
        $thresholds = (new ThresholdResolver)->for(SectorLabel::Tech);

        $this->assertSame(0.65, $thresholds->auto);
        $this->assertSame(0.55, $thresholds->review);
        $this->assertSame(0.05, $thresholds->margin);
    }

    public function test_missing_config_resolves_the_spec_placeholders(): void
    {
        config()->set('qds.enrichment.visual_match.thresholds', null);

        $thresholds = (new ThresholdResolver)->for(SectorLabel::Beauty);

        $this->assertSame(0.65, $thresholds->auto);
        $this->assertSame(0.55, $thresholds->review);
        $this->assertSame(0.05, $thresholds->margin);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ThresholdResolverTest`
Expected: 4 ERRORS — `Error: Class "App\Platform\Enrichment\VisualMatch\Matching\ThresholdResolver" not found`.

- [ ] **Step 3: Implement Thresholds + ThresholdResolver**

Create `app/Platform/Enrichment/VisualMatch/Matching/Thresholds.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

/**
 * The resolved band thresholds for one candidate's category (spec §8):
 * auto = similarity floor for AUTO support, review = floor for REVIEW
 * evidence, margin = how far the top candidate must beat the runner-up.
 */
final readonly class Thresholds
{
    public function __construct(
        public float $auto,
        public float $review,
        public float $margin,
    ) {}
}
```

Create `app/Platform/Enrichment/VisualMatch/Matching/ThresholdResolver.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

use App\Shared\Enums\SectorLabel;

/**
 * Category-keyed threshold resolution (spec §8/§12): a per-category entry
 * (key = SectorLabel value, e.g. packaging-prone BEAUTY) overrides the
 * 'default' entry key-by-key. The config values are deliberate
 * PLACEHOLDERS — calibration against the eval golden set is sub-project
 * E's mandate; the code fallbacks mirror spec §12 so the resolver is safe
 * even before Task 19 lands the full config block.
 */
final class ThresholdResolver
{
    private const DEFAULT_AUTO = 0.65;

    private const DEFAULT_REVIEW = 0.55;

    private const DEFAULT_MARGIN = 0.05;

    public function for(?SectorLabel $category): Thresholds
    {
        $map = (array) config('qds.enrichment.visual_match.thresholds', []);
        $default = is_array($map['default'] ?? null) ? $map['default'] : [];
        $override = $category !== null && is_array($map[$category->value] ?? null) ? $map[$category->value] : [];

        return new Thresholds(
            auto: (float) ($override['auto'] ?? $default['auto'] ?? self::DEFAULT_AUTO),
            review: (float) ($override['review'] ?? $default['review'] ?? self::DEFAULT_REVIEW),
            margin: (float) ($override['margin'] ?? $default['margin'] ?? self::DEFAULT_MARGIN),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter ThresholdResolverTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/Matching/Thresholds.php app/Platform/Enrichment/VisualMatch/Matching/ThresholdResolver.php tests/Unit/Enrichment/ThresholdResolverTest.php
git commit -m "feat(enrichment): per-category visual-match threshold resolver"
```

- [ ] **Step 6: Write the failing BandMapper test**

Create `tests/Unit/Enrichment/BandMapperTest.php` (pure — no DB, no `RefreshDatabase`; `Keyframe` instances are unsaved models):

```php
<?php

namespace Tests\Unit\Enrichment;

use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Platform\Enrichment\VisualMatch\Matching\BandMapper;
use App\Platform\Enrichment\VisualMatch\Matching\BandResult;
use App\Platform\Enrichment\VisualMatch\Matching\CandidateScores;
use App\Platform\Enrichment\VisualMatch\Matching\FrameScore;
use App\Platform\Enrichment\VisualMatch\Matching\ThresholdResolver;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VisualMatchOutcome;
use Tests\TestCase;

/**
 * Spec §8 band rules, exhaustively: dedup-aware support counting
 * (timestamped groups count represented frames; null-timestamp groups
 * count once per distinct visual), the single-frame REVIEW cap, runner-up
 * margin ambiguity, category thresholds, ranking determinism, visibility
 * evidence, and the run-level NO_MATCH vs INCONCLUSIVE split.
 */
class BandMapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('qds.enrichment.visual_match.thresholds', [
            'default' => ['auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
            'BEAUTY' => ['auto' => 0.70],
            'FOOD_BEVERAGE' => ['auto' => 0.70],
        ]);
    }

    public function test_two_distinct_timestamped_frames_at_auto_strength_band_auto(): void
    {
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.80),
            $this->frameScore(11, 1, 6000, 0.70),
        ])];
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);

        $results = $this->mapper()->map($scored, $prep);

        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertSame(VisualMatchBand::Auto, $result->band);
        $this->assertSame(2, $result->supportCount);
        $this->assertNull($result->marginToRunnerUp); // alone: nothing to be ambiguous against
        $this->assertNull($result->rejectionReason);
        $this->assertEqualsWithDelta(0.80, $result->bestSimilarity, 1e-9);
        $this->assertCount(2, $result->supportingFrames);
        // Visibility: span 0–6000 ms; sampling span 6000/(2-1); 2 supported
        // represented frames ≈ 12 s visible.
        $this->assertSame(0, $result->firstSupportMs);
        $this->assertSame(6000, $result->lastSupportMs);
        $this->assertSame(12000, $result->estimatedVisibleMs);
    }

    public function test_a_video_dedup_group_contributes_its_full_represented_count(): void
    {
        // One representative for a near-identical 0–12 s span of 3 sampled
        // frames: repeated visibility, not one isolated moment (spec §8).
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.80, representedFrames: 3),
        ])];
        $prep = $this->prep(
            [$this->frame(10, 0, representedFrames: 3, spanEndMs: 12000)],
            framesAvailable: 3,
            deduped: 2,
        );

        $result = $this->mapper()->map($scored, $prep)[0];

        $this->assertSame(VisualMatchBand::Auto, $result->band);
        $this->assertSame(3, $result->supportCount);
        $this->assertSame(0, $result->firstSupportMs);
        // The group's span end, not its representative timestamp.
        $this->assertSame(12000, $result->lastSupportMs);
        // Sampling span = 12000/(3-1) = 6000 ms; 3 × 6000 = 18 s.
        $this->assertSame(18000, $result->estimatedVisibleMs);
    }

    public function test_a_null_timestamp_group_counts_once_no_matter_its_size(): void
    {
        // Two identical uploaded carousel images are ONE piece of evidence —
        // the single-frame REVIEW cap by construction (spec §8).
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, null, 0.90, representedFrames: 3),
        ])];
        $prep = $this->prep([$this->frame(10, null, representedFrames: 3)], framesAvailable: 3, deduped: 2);

        $result = $this->mapper()->map($scored, $prep)[0];

        $this->assertSame(VisualMatchBand::Review, $result->band);
        $this->assertSame(1, $result->supportCount);
        $this->assertNull($result->rejectionReason);
        $this->assertNull($result->firstSupportMs);
        $this->assertNull($result->lastSupportMs);
        $this->assertNull($result->estimatedVisibleMs);
    }

    public function test_two_distinct_null_timestamp_visuals_reach_auto(): void
    {
        // Two DIFFERENT carousel images showing the product are two pieces
        // of evidence (dedup already collapsed identical ones).
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, null, 0.80),
            $this->frameScore(11, 1, null, 0.75),
        ])];
        $prep = $this->prep([$this->frame(10, null), $this->frame(11, null)]);

        $result = $this->mapper()->map($scored, $prep)[0];

        $this->assertSame(VisualMatchBand::Auto, $result->band);
        $this->assertSame(2, $result->supportCount);
        $this->assertNull($result->estimatedVisibleMs);
    }

    public function test_ambiguous_margin_demotes_auto_support_to_review_for_both(): void
    {
        $scoredA = new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.72),
            $this->frameScore(11, 1, 6000, 0.70),
        ]);
        $scoredB = new CandidateScores($this->candidate(2), [
            $this->frameScore(10, 0, 0, 0.70),
            $this->frameScore(11, 1, 6000, 0.69),
        ]);
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);

        $results = $this->mapper()->map([$scoredA, $scoredB], $prep);

        $this->assertSame(1, $results[0]->candidate->productId);
        $this->assertSame(2, $results[1]->candidate->productId);
        // 0.72 beats 0.70 by only 0.02 < margin 0.05 → nobody auto-matches.
        $this->assertSame(VisualMatchBand::Review, $results[0]->band);
        $this->assertSame('margin-ambiguous', $results[0]->rejectionReason);
        $this->assertEqualsWithDelta(0.02, $results[0]->marginToRunnerUp, 1e-6);
        $this->assertSame(VisualMatchBand::Review, $results[1]->band);
        $this->assertSame('margin-ambiguous', $results[1]->rejectionReason);
        $this->assertEqualsWithDelta(-0.02, $results[1]->marginToRunnerUp, 1e-6);
    }

    public function test_clear_margin_top_auto_runner_up_stays_review(): void
    {
        $scoredA = new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.85),
            $this->frameScore(11, 1, 6000, 0.80),
        ]);
        $scoredB = new CandidateScores($this->candidate(2), [
            $this->frameScore(10, 0, 0, 0.66),
            $this->frameScore(11, 1, 6000, 0.66),
        ]);
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);

        // Input order must not matter: pass runner-up first.
        $results = $this->mapper()->map([$scoredB, $scoredA], $prep);

        $this->assertSame(1, $results[0]->candidate->productId);
        $this->assertSame(VisualMatchBand::Auto, $results[0]->band);
        $this->assertEqualsWithDelta(0.19, $results[0]->marginToRunnerUp, 1e-6);
        // The runner-up has AUTO-grade support of its own but loses the
        // margin — REVIEW, never a second AUTO.
        $this->assertSame(VisualMatchBand::Review, $results[1]->band);
        $this->assertSame('margin-ambiguous', $results[1]->rejectionReason);
    }

    public function test_margin_exactly_at_threshold_is_enough(): void
    {
        $scoredA = new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.70),
            $this->frameScore(11, 1, 6000, 0.70),
        ]);
        $scoredB = new CandidateScores($this->candidate(2), [
            $this->frameScore(10, 0, 0, 0.65),
        ]);
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);

        $results = $this->mapper()->map([$scoredA, $scoredB], $prep);

        // 0.70 - 0.65 = 0.05 ≥ margin 0.05 (float dust neutralized by
        // rounding — 0.70-0.65 is 0.049999… in IEEE 754).
        $this->assertSame(VisualMatchBand::Auto, $results[0]->band);
        $this->assertEqualsWithDelta(0.05, $results[0]->marginToRunnerUp, 1e-6);
    }

    public function test_one_strong_frame_is_never_auto_and_never_rejected(): void
    {
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.90),
            $this->frameScore(11, 1, 6000, 0.30),
        ])];
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);

        $result = $this->mapper()->map($scored, $prep)[0];

        // The locked "never auto-accept one isolated match" rule — and a
        // lone strong hit is never auto-rejected either: it lands REVIEW.
        $this->assertSame(VisualMatchBand::Review, $result->band);
        $this->assertSame(1, $result->supportCount);
        $this->assertNull($result->rejectionReason);
        // Only the strong frame supports (0.30 < review 0.55).
        $this->assertCount(1, $result->supportingFrames);
        $this->assertSame(10, $result->supportingFrames[0]->keyframeId);
    }

    public function test_mid_band_evidence_lands_review(): void
    {
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.60),
        ])];
        $prep = $this->prep([$this->frame(10, 0)]);

        $result = $this->mapper()->map($scored, $prep)[0];

        $this->assertSame(VisualMatchBand::Review, $result->band);
        $this->assertSame(0, $result->supportCount); // review strength is not AUTO support
        $this->assertCount(1, $result->supportingFrames);
    }

    public function test_below_review_threshold_rejects(): void
    {
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.40),
        ])];
        $prep = $this->prep([$this->frame(10, 0)]);

        $result = $this->mapper()->map($scored, $prep)[0];

        $this->assertSame(VisualMatchBand::Reject, $result->band);
        $this->assertSame('below-review-threshold', $result->rejectionReason);
        $this->assertSame([], $result->supportingFrames);
        $this->assertEqualsWithDelta(0.40, $result->bestSimilarity, 1e-9);
    }

    public function test_category_override_raises_the_auto_bar(): void
    {
        $frames = [
            $this->frameScore(10, 0, 0, 0.67),
            $this->frameScore(11, 1, 6000, 0.67),
        ];
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);

        // BEAUTY (packaging-prone): auto raised to 0.70 → same evidence
        // only reaches REVIEW.
        $beauty = $this->mapper()->map([new CandidateScores($this->candidate(1, SectorLabel::Beauty), $frames)], $prep)[0];
        $this->assertSame(VisualMatchBand::Review, $beauty->band);
        $this->assertSame(0, $beauty->supportCount);

        // Uncategorized product: default auto 0.65 → AUTO.
        $default = $this->mapper()->map([new CandidateScores($this->candidate(1), $frames)], $prep)[0];
        $this->assertSame(VisualMatchBand::Auto, $default->band);
        $this->assertSame(2, $default->supportCount);
    }

    public function test_ranking_is_best_first_ties_to_lower_product_id(): void
    {
        $prep = $this->prep([$this->frame(10, 0)]);
        $scored = [
            new CandidateScores($this->candidate(9), [$this->frameScore(10, 0, 0, 0.30)]),
            new CandidateScores($this->candidate(4), [$this->frameScore(10, 0, 0, 0.60)]),
            new CandidateScores($this->candidate(2), [$this->frameScore(10, 0, 0, 0.60)]),
        ];

        $results = $this->mapper()->map($scored, $prep);

        $this->assertSame([2, 4, 9], array_map(fn (BandResult $r): int => $r->candidate->productId, $results));
        // Determinism: identical inputs ⇒ identical output.
        $this->assertEquals($results, $this->mapper()->map($scored, $prep));
    }

    public function test_run_outcome_matched_wins_over_review(): void
    {
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);
        $candidates = new CandidateSet([$this->candidate(1), $this->candidate(2)], Priority::High);
        $results = $this->mapper()->map([
            new CandidateScores($this->candidate(1), [
                $this->frameScore(10, 0, 0, 0.85),
                $this->frameScore(11, 1, 6000, 0.80),
            ]),
            new CandidateScores($this->candidate(2), [$this->frameScore(10, 0, 0, 0.60)]),
        ], $prep);

        $this->assertSame(VisualMatchOutcome::Matched, $this->mapper()->runOutcome($results, $prep, $candidates));
    }

    public function test_run_outcome_review_when_no_auto(): void
    {
        $prep = $this->prep([$this->frame(10, 0)]);
        $candidates = new CandidateSet([$this->candidate(1)], Priority::High);
        $results = $this->mapper()->map([
            new CandidateScores($this->candidate(1), [$this->frameScore(10, 0, 0, 0.90)]),
        ], $prep);

        $this->assertSame(VisualMatchOutcome::Review, $this->mapper()->runOutcome($results, $prep, $candidates));
    }

    public function test_run_outcome_splits_no_match_from_inconclusive_on_coverage(): void
    {
        $candidates = new CandidateSet([$this->candidate(1)], Priority::High);
        $rejects = fn (FramePreparationResult $prep): array => $this->mapper()->map([
            new CandidateScores($this->candidate(1), [$this->frameScore(10, 0, 0, 0.20)]),
        ], $prep);

        // Clean coverage: every stored frame was processed → "we looked
        // properly and did not see it".
        $clean = $this->prep([$this->frame(10, 0)]);
        $this->assertSame(VisualMatchOutcome::NoMatch, $this->mapper()->runOutcome($rejects($clean), $clean, $candidates));

        // A quality-skipped frame: we may not have looked properly —
        // unavailable ≠ false.
        $degraded = $this->prep([$this->frame(10, 0)], framesAvailable: 2, skippedQuality: 1);
        $this->assertSame(VisualMatchOutcome::Inconclusive, $this->mapper()->runOutcome($rejects($degraded), $degraded, $candidates));

        // Zero frames survived preparation.
        $empty = $this->prep([], framesAvailable: 1, skippedFormat: 1);
        $this->assertSame(VisualMatchOutcome::Inconclusive, $this->mapper()->runOutcome([], $empty, $candidates));
    }

    public function test_run_outcome_inconclusive_when_candidates_exist_but_none_matchable(): void
    {
        $prep = $this->prep([$this->frame(10, 0)]);
        $unmatchable = new Candidate(
            productId: 7,
            productLabel: 'Product 7',
            brandName: 'Nexon Labs',
            category: null,
            source: 'roster',
            shipmentInWindow: false,
            seedingCampaignId: 3,
            shipmentAnchorAt: null,
            shipmentAgeDays: null,
            hasEmbeddedPhotos: false,
        );

        // Candidates existed but had no embedded reference photos: we never
        // actually compared anything — INCONCLUSIVE, not a clean NO_MATCH.
        $outcome = $this->mapper()->runOutcome([], $prep, new CandidateSet([$unmatchable], Priority::Medium));

        $this->assertSame(VisualMatchOutcome::Inconclusive, $outcome);
    }

    private function mapper(): BandMapper
    {
        return new BandMapper(new ThresholdResolver);
    }

    private function candidate(int $productId, ?SectorLabel $category = null): Candidate
    {
        return new Candidate(
            productId: $productId,
            productLabel: "Product {$productId}",
            brandName: 'Nexon Labs',
            category: $category,
            source: 'shipment',
            shipmentInWindow: true,
            seedingCampaignId: null,
            shipmentAnchorAt: null,
            shipmentAgeDays: 12,
            hasEmbeddedPhotos: true,
        );
    }

    private function frameScore(int $keyframeId, int $ordinal, ?int $timestampMs, float $similarity, int $representedFrames = 1): FrameScore
    {
        return new FrameScore($keyframeId, $ordinal, $timestampMs, $similarity, 900 + $keyframeId, $representedFrames);
    }

    private function keyframe(int $id, ?int $timestampMs): Keyframe
    {
        $keyframe = new Keyframe;
        $keyframe->id = $id;
        $keyframe->timestamp_ms = $timestampMs;

        return $keyframe;
    }

    private function frame(int $keyframeId, ?int $timestampMs, int $representedFrames = 1, ?int $spanEndMs = null): PreparedFrame
    {
        return new PreparedFrame(
            keyframe: $this->keyframe($keyframeId, $timestampMs),
            bytes: '',
            mimeType: 'image/jpeg',
            representedFrames: $representedFrames,
            spanStartMs: $timestampMs,
            spanEndMs: $spanEndMs ?? $timestampMs,
        );
    }

    /** @param list<PreparedFrame> $frames */
    private function prep(array $frames, ?int $framesAvailable = null, int $skippedFormat = 0, int $skippedQuality = 0, int $deduped = 0): FramePreparationResult
    {
        return new FramePreparationResult(
            frames: $frames,
            framesAvailable: $framesAvailable ?? count($frames),
            skippedFormat: $skippedFormat,
            skippedQuality: $skippedQuality,
            deduped: $deduped,
        );
    }
}
```

- [ ] **Step 7: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter BandMapperTest`
Expected: 16 ERRORS — `Error: Class "App\Platform\Enrichment\VisualMatch\Matching\BandMapper" not found`.

- [ ] **Step 8: Implement BandResult + BandMapper**

Create `app/Platform/Enrichment/VisualMatch/Matching/BandResult.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Shared\Enums\VisualMatchBand;

/**
 * One candidate's band decision (spec §8) plus everything the writer,
 * recorder, and reviewers need to see WHY: the review-strength-or-better
 * evidence frames, the dedup-aware AUTO support counter, the runner-up
 * margin, and the visibility evidence (null when no supporting frame
 * carries a timestamp). rejectionReason ∈ {'below-review-threshold'
 * (REJECT), 'margin-ambiguous' (REVIEW with AUTO support but failed
 * margin), null}.
 */
final readonly class BandResult
{
    /** @param list<FrameScore> $supportingFrames */
    public function __construct(
        public Candidate $candidate,
        public VisualMatchBand $band,
        public array $supportingFrames,
        public int $supportCount,
        public ?float $marginToRunnerUp,
        public ?string $rejectionReason,
        public ?int $firstSupportMs,
        public ?int $lastSupportMs,
        public ?int $estimatedVisibleMs,
        public float $bestSimilarity,
    ) {}
}
```

Create `app/Platform/Enrichment/VisualMatch/Matching/BandMapper.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VisualMatchOutcome;

/**
 * Pure scores → bands decision (spec §8): per-category thresholds,
 * dedup-aware support counting, runner-up margin, visibility evidence, and
 * the run-level NO_MATCH vs INCONCLUSIVE split. No I/O, no provider —
 * fully deterministic: identical embeddings + config ⇒ identical outcome;
 * ties break on lower product id. Confidence here is VISUAL-ONLY by
 * design (fusion is sub-project E's mandate).
 */
final class BandMapper
{
    public function __construct(private readonly ThresholdResolver $thresholds) {}

    /**
     * @param  list<CandidateScores>  $scored
     * @return list<BandResult> ranked best-first; ties broken by lower productId
     */
    public function map(array $scored, FramePreparationResult $prep): array
    {
        if ($scored === []) {
            return [];
        }

        $ranked = $scored;
        usort($ranked, fn (CandidateScores $a, CandidateScores $b): int => [$b->bestSimilarity(), $a->candidate->productId] <=> [$a->bestSimilarity(), $b->candidate->productId]);

        /** @var array<int, PreparedFrame> $frameByKeyframeId */
        $frameByKeyframeId = [];
        $maxObservedMs = null;
        foreach ($prep->frames as $frame) {
            $frameByKeyframeId[(int) $frame->keyframe->getKey()] = $frame;
            $end = $frame->spanEndMs ?? $frame->keyframe->timestamp_ms;
            if ($end !== null) {
                $maxObservedMs = max($maxObservedMs ?? 0, (int) $end);
            }
        }

        $results = [];
        foreach ($ranked as $index => $candidateScores) {
            $results[] = $this->bandFor($candidateScores, $ranked, $index, $frameByKeyframeId, $maxObservedMs, $prep->framesAvailable);
        }

        return $results;
    }

    /** @param list<BandResult> $bandResults */
    public function runOutcome(array $bandResults, FramePreparationResult $prep, CandidateSet $candidates): VisualMatchOutcome
    {
        $bands = array_map(fn (BandResult $result): VisualMatchBand => $result->band, $bandResults);

        if (in_array(VisualMatchBand::Auto, $bands, true)) {
            return VisualMatchOutcome::Matched;
        }

        if (in_array(VisualMatchBand::Review, $bands, true)) {
            return VisualMatchOutcome::Review;
        }

        // Nothing banded. Split "we looked properly and did not see it"
        // (clean NO_MATCH) from "we could not look properly" (INCONCLUSIVE
        // — unavailable ≠ false, spec §8/§11).
        if ($prep->coverageDegraded()) {
            return VisualMatchOutcome::Inconclusive;
        }

        if (! $candidates->isEmpty() && $candidates->matchable() === []) {
            // Candidates existed but none had embedded reference photos at
            // this model version: we never actually compared anything.
            return VisualMatchOutcome::Inconclusive;
        }

        return VisualMatchOutcome::NoMatch;
    }

    /**
     * @param  list<CandidateScores>  $ranked
     * @param  array<int, PreparedFrame>  $frameByKeyframeId
     */
    private function bandFor(
        CandidateScores $candidateScores,
        array $ranked,
        int $index,
        array $frameByKeyframeId,
        ?int $maxObservedMs,
        int $framesAvailable,
    ): BandResult {
        $candidate = $candidateScores->candidate;
        $thresholds = $this->thresholds->for($candidate->category);
        $best = $candidateScores->bestSimilarity();

        // Runner-up = the best similarity among the OTHER candidates; null
        // when this candidate stands alone (nothing to be ambiguous
        // against). Non-top candidates get a negative margin, so at most
        // one candidate can pass the AUTO margin gate.
        $runnerUp = null;
        foreach ($ranked as $otherIndex => $other) {
            if ($otherIndex !== $index) {
                $runnerUp = $runnerUp === null ? $other->bestSimilarity() : max($runnerUp, $other->bestSimilarity());
            }
        }
        // Rounded so an exactly-at-threshold margin never fails on IEEE 754 dust.
        $margin = $runnerUp === null ? null : round($best - $runnerUp, 6);

        // Supporting frames: everything at review strength or better — the
        // evidence trail reviewers and tier D ground against.
        $supporting = array_values(array_filter(
            $candidateScores->frameScores,
            fn (FrameScore $score): bool => $score->similarity >= $thresholds->review,
        ));

        // AUTO-rule support counter (dedup-aware, spec §8), auto-strength
        // frames only: a timestamped dedup group contributes its FULL
        // represented_frames count (a near-identical span IS repeated
        // visibility), a null-timestamp group (carousel/story image) counts
        // ONCE per distinct visual — identical uploads are one piece of
        // evidence, not two.
        $supportCount = 0;
        foreach ($candidateScores->frameScores as $score) {
            if ($score->similarity >= $thresholds->auto) {
                $supportCount += $score->timestampMs === null ? 1 : $score->representedFrames;
            }
        }

        [$firstMs, $lastMs, $visibleMs] = $this->visibility($supporting, $frameByKeyframeId, $maxObservedMs, $framesAvailable);

        if ($best < $thresholds->review) {
            return new BandResult($candidate, VisualMatchBand::Reject, $supporting, $supportCount,
                $margin, 'below-review-threshold', $firstMs, $lastMs, $visibleMs, $best);
        }

        if ($supportCount >= 2 && ($margin === null || $margin >= $thresholds->margin)) {
            return new BandResult($candidate, VisualMatchBand::Auto, $supporting, $supportCount,
                $margin, null, $firstMs, $lastMs, $visibleMs, $best);
        }

        // REVIEW: exactly one auto-strength frame, or review-band evidence
        // only, or AUTO support with an ambiguous margin. A lone strong hit
        // is never auto-rejected — it lands here for humans (and tier D).
        return new BandResult($candidate, VisualMatchBand::Review, $supporting, $supportCount,
            $margin, $supportCount >= 2 ? 'margin-ambiguous' : null, $firstMs, $lastMs, $visibleMs, $best);
    }

    /**
     * Visibility evidence (spec §8): first/last supported timestamp (dedup
     * group spans included) and estimated on-screen time ≈ supported
     * represented frames × the video's sampling span (duration ≈ the last
     * observed timestamp under B's even-interval extraction). All null when
     * no supporting frame carries a timestamp (images/stories).
     *
     * @param  list<FrameScore>  $supporting
     * @param  array<int, PreparedFrame>  $frameByKeyframeId
     * @return array{0: ?int, 1: ?int, 2: ?int}
     */
    private function visibility(array $supporting, array $frameByKeyframeId, ?int $maxObservedMs, int $framesAvailable): array
    {
        $first = null;
        $last = null;
        $represented = 0;

        foreach ($supporting as $score) {
            if ($score->timestampMs === null) {
                continue;
            }

            $frame = $frameByKeyframeId[$score->keyframeId] ?? null;
            $start = $frame?->spanStartMs ?? $score->timestampMs;
            $end = $frame?->spanEndMs ?? $score->timestampMs;
            $first = $first === null ? $start : min($first, $start);
            $last = $last === null ? $end : max($last, $end);
            $represented += $score->representedFrames;
        }

        if ($first === null || $maxObservedMs === null) {
            return [null, null, null];
        }

        $spanMs = $framesAvailable > 1 ? intdiv($maxObservedMs, $framesAvailable - 1) : $maxObservedMs;

        return [$first, $last, $represented * $spanMs];
    }
}
```

- [ ] **Step 9: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter BandMapperTest`
Expected: PASS (16 tests).

- [ ] **Step 10: Run the matching unit + feature tests together**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter 'BandMapperTest|ThresholdResolverTest|FrameProductScorerTest|VisualMatchTablesTest'`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/Matching/BandResult.php app/Platform/Enrichment/VisualMatch/Matching/BandMapper.php tests/Unit/Enrichment/BandMapperTest.php
git commit -m "feat(enrichment): band mapper — AUTO/REVIEW/REJECT bands + NO_MATCH/INCONCLUSIVE split"
```

---

### Task 18: VisualMatchWriter + VisualMatchRunRecorder (detections + audit trail)

**Files:**
- Create: `app/Platform/Enrichment/VisualMatch/VisualMatchWriter.php`
- Create: `app/Platform/Enrichment/VisualMatch/VisualMatchRunRecorder.php`
- Test: `tests/Feature/Enrichment/VisualMatchWriterTest.php` (create), `tests/Feature/Enrichment/VisualMatchRunRecorderTest.php` (create)

**Interfaces:**
- Consumes: `RecognitionType::VisualProduct` (T2); `App\Shared\Enums\VisualMatchBand` / `VisualMatchOutcome` (T14); models `App\Modules\Monitoring\Models\VisualMatchRun` / `VisualMatchCandidate` (T14, jsonb columns cast to array, enum-valued columns accept backed values); `Candidate` / `CandidateSet` (T15, frozen ctors); `FrameScore` (T16); `Thresholds` / `ThresholdResolver::for(?SectorLabel): Thresholds` (T17); `BandResult` (T17, frozen ctor); `FramePreparationResult` (T12); `KeyframeRepository::forOwner(ContentItem|Story): KeyframeSet`; `HumanPrecedence::allowsAiUpdate(?ConfidenceAssessment): bool`; `App\Platform\AiBudget\Priority` (T8); config `qds.ai_budget.capabilities.embedding.price_micro_usd_per_unit` (T8); `SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS` (added in Task 6 — consumed here, never re-added).
- Produces: `VisualMatchWriter::write(ContentItem|Story $target, BandResult $result, string $modelVersion): int` and `VisualMatchWriter::withdrawSupport(ContentItem|Story $target, int $productId): int`; `VisualMatchRunRecorder::record(ContentItem|Story $target, string $correlationId, CandidateSet $candidates, FramePreparationResult $prep, array $results, VisualMatchOutcome $outcome, string $modelVersion, int $billedCalls, int $cacheHits, int $processingMs, bool $needsVerification): VisualMatchRun` — all consumed verbatim by Task 19 and Task 22.

- [ ] **Step 1: Verify the source id already exists (added in Task 6 — do NOT re-add).**

Run: `grep -n "GOOGLE_GEMINI_EMBEDDINGS" app/Platform/Ingestion/SourceRegistry.php`
Expected: the const declaration and the `all()` entry, both landed by Task 6 (with its unit test). If either is missing, STOP — complete Task 6 first. *(Steps 2–5 of this task were consolidated into this verification: the SourceRegistry addition is owned by Task 6.)*

- [ ] **Step 6: Write the failing writer test.** Create `tests/Feature/Enrichment/VisualMatchWriterTest.php`:

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Matching\BandResult;
use App\Platform\Enrichment\VisualMatch\Matching\FrameScore;
use App\Platform\Enrichment\VisualMatch\VisualMatchWriter;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VisualMatchWriterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the resolver inputs so the threshold signal is deterministic.
        config(['qds.enrichment.visual_match.thresholds' => [
            'default' => ['auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
            'BEAUTY' => ['auto' => 0.70],
        ]]);
    }

    /** @return array{0: ContentItem, 1: Product} content with three stored keyframes + catalog product */
    private function wired(?SectorLabel $category = null): array
    {
        $content = ContentItem::factory()->create();

        foreach ([0, 1, 2] as $ordinal) {
            Keyframe::factory()->create([
                'owner_type' => $content->getMorphClass(),
                'owner_id' => $content->id,
                'ordinal' => $ordinal,
                'timestamp_ms' => $ordinal * 1000,
            ]);
        }

        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume', 'category' => $category]);

        return [$content, $product];
    }

    private function candidate(Product $product, ?SectorLabel $category = null): Candidate
    {
        return new Candidate(
            productId: $product->id, productLabel: $product->name, brandName: 'Glossier',
            category: $category, source: 'shipment', shipmentInWindow: true,
            seedingCampaignId: null, shipmentAnchorAt: null, shipmentAgeDays: 3,
            hasEmbeddedPhotos: true,
        );
    }

    private function result(Candidate $candidate, VisualMatchBand $band): BandResult
    {
        return new BandResult(
            candidate: $candidate, band: $band,
            supportingFrames: [
                new FrameScore(keyframeId: 1, ordinal: 1, timestampMs: 1000, similarity: 0.71, photoId: 11, representedFrames: 1),
                new FrameScore(keyframeId: 2, ordinal: 2, timestampMs: 5000, similarity: 0.68, photoId: 11, representedFrames: 1),
            ],
            supportCount: 2, marginToRunnerUp: null, rejectionReason: null,
            firstSupportMs: 1000, lastSupportMs: 5000, estimatedVisibleMs: 8000,
            bestSimilarity: 0.71,
        );
    }

    public function test_auto_band_writes_a_high_detection_with_the_frozen_signal_trail(): void
    {
        [$content, $product] = $this->wired();

        $written = app(VisualMatchWriter::class)->write($content, $this->result($this->candidate($product), VisualMatchBand::Auto), 'gemini-embedding-2');

        $this->assertSame(1, $written);

        $detection = RecognitionDetection::query()
            ->where('content_item_id', $content->id)
            ->where('recognition_type', RecognitionType::VisualProduct)
            ->firstOrFail();

        $this->assertSame('visual-product:'.$product->id, $detection->provider_label);
        $this->assertSame('Glossier', $detection->detected_brand);
        $this->assertSame('You Perfume', $detection->detected_product);
        $this->assertSame($product->id, $detection->product_id);
        $this->assertNull($detection->detected_text);

        $this->assertSame('Glossier', $detection->assessment->value);
        $this->assertSame(ConfidenceLevel::High, $detection->assessment->confidenceLevel);
        $this->assertSame(VerificationStatus::AiAssessed, $detection->assessment->verificationStatus);
        $this->assertSame([
            'visual-product-match:You Perfume',
            'visual-frames-supporting:2/3',
            'visual-frame:t=1000ms:sim=0.71',
            'visual-frame:t=5000ms:sim=0.68',
            'visual-threshold:default:auto=0.65:review=0.55:margin=0.05',
            'embedding-model:gemini-embedding-2',
        ], $detection->assessment->signals);

        $this->assertSame('SRC-google-gemini-embeddings', $detection->provenance->source);
        $this->assertSame('visual-match-v1', $detection->provenance->sourceVersion);
    }

    public function test_review_band_writes_low_and_category_thresholds_stamp_the_signal(): void
    {
        [$content, $product] = $this->wired(SectorLabel::Beauty);

        app(VisualMatchWriter::class)->write($content, $this->result($this->candidate($product, SectorLabel::Beauty), VisualMatchBand::Review), 'gemini-embedding-2');

        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame(ConfidenceLevel::Low, $detection->assessment->confidenceLevel);
        $this->assertTrue($detection->assessment->needsHumanReview());
        $this->assertContains('visual-threshold:BEAUTY:auto=0.70:review=0.55:margin=0.05', $detection->assessment->signals);
    }

    public function test_rerun_updates_the_same_row_and_identity_fields_seed_only_on_create(): void
    {
        [$content, $product] = $this->wired();
        $writer = app(VisualMatchWriter::class);

        $writer->write($content, $this->result($this->candidate($product), VisualMatchBand::Review), 'gemini-embedding-2');

        // Simulate a later catalog rename on the EXISTING AI row: the
        // identity-adjacent fields must NOT re-seed on the next pass.
        RecognitionDetection::query()->update(['detected_brand' => 'Renamed Brand']);

        $writer->write($content, $this->result($this->candidate($product), VisualMatchBand::Auto), 'gemini-embedding-2');

        $this->assertSame(1, RecognitionDetection::query()->count());
        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame('Renamed Brand', $detection->detected_brand);
        $this->assertSame('Renamed Brand', $detection->assessment->value);
        $this->assertSame(ConfidenceLevel::High, $detection->assessment->confidenceLevel);
    }

    public function test_human_touched_rows_are_never_overwritten(): void
    {
        [$content, $product] = $this->wired();
        RecognitionDetection::factory()->create([
            'content_item_id' => $content->id,
            'recognition_type' => RecognitionType::VisualProduct,
            'provider_label' => 'visual-product:'.$product->id,
            'detected_brand' => 'Corrected Brand',
            'product_id' => null,
            'assessment' => new ConfidenceAssessment('Corrected Brand', ConfidenceLevel::High, ['human-corrected'], VerificationStatus::HumanCorrected),
        ]);

        $written = app(VisualMatchWriter::class)->write($content, $this->result($this->candidate($product), VisualMatchBand::Auto), 'gemini-embedding-2');

        $this->assertSame(0, $written);
        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame('Corrected Brand', $detection->detected_brand);
        $this->assertSame(VerificationStatus::HumanCorrected, $detection->assessment->verificationStatus);
    }

    public function test_concurrent_insert_is_recovered_without_duplicates(): void
    {
        [$content, $product] = $this->wired();

        // Simulate the race: just before OUR insert commits, a concurrent
        // pass lands the same identity row (the partial unique index
        // recognition_detections_content_identity_unique is the backstop).
        $raced = false;
        RecognitionDetection::creating(function () use (&$raced, $content, $product): void {
            if ($raced) {
                return;
            }
            $raced = true;

            DB::table('recognition_detections')->insert([
                'tenant_id' => $this->defaultTenant->id,
                'content_item_id' => $content->id,
                'recognition_type' => 'VISUAL_PRODUCT',
                'provider_label' => 'visual-product:'.$product->id,
                'detected_brand' => 'Glossier',
                'detected_product' => 'You Perfume',
                'product_id' => $product->id,
                'assessment' => json_encode([
                    'value' => 'Glossier', 'confidenceLevel' => 'HIGH',
                    'signals' => ['visual-product-match:You Perfume'],
                    'verificationStatus' => 'AI_ASSESSED',
                ]),
                'provenance' => json_encode([
                    'source' => 'SRC-google-gemini-embeddings',
                    'fetchedAt' => now()->toIso8601String(),
                    'sourceVersion' => 'visual-match-v1',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $written = app(VisualMatchWriter::class)->write($content, $this->result($this->candidate($product), VisualMatchBand::Auto), 'gemini-embedding-2');

        $this->assertSame(0, $written); // the concurrent insert already recorded it
        $this->assertSame(1, RecognitionDetection::query()->count());
    }

    public function test_withdraw_support_downgrades_an_ai_row_to_review_once(): void
    {
        [$content, $product] = $this->wired();
        $writer = app(VisualMatchWriter::class);
        $writer->write($content, $this->result($this->candidate($product), VisualMatchBand::Auto), 'gemini-embedding-2');

        $this->assertSame(1, $writer->withdrawSupport($content, $product->id));

        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame(ConfidenceLevel::Low, $detection->assessment->confidenceLevel);
        $this->assertContains('visual-support-withdrawn', $detection->assessment->signals);
        $this->assertTrue($detection->assessment->needsHumanReview());

        // Idempotent: a second withdraw changes nothing.
        $this->assertSame(0, $writer->withdrawSupport($content, $product->id));
    }

    public function test_withdraw_support_skips_missing_and_human_rows(): void
    {
        [$content, $product] = $this->wired();
        $writer = app(VisualMatchWriter::class);

        $this->assertSame(0, $writer->withdrawSupport($content, $product->id)); // nothing to downgrade

        RecognitionDetection::factory()->create([
            'content_item_id' => $content->id,
            'recognition_type' => RecognitionType::VisualProduct,
            'provider_label' => 'visual-product:'.$product->id,
            'detected_brand' => 'Glossier',
            'assessment' => new ConfidenceAssessment('Glossier', ConfidenceLevel::High, ['human-approved'], VerificationStatus::HumanReviewed),
        ]);

        $this->assertSame(0, $writer->withdrawSupport($content, $product->id));
        $this->assertSame(VerificationStatus::HumanReviewed, RecognitionDetection::query()->firstOrFail()->assessment->verificationStatus);
    }

    public function test_story_targets_key_on_story_id(): void
    {
        $story = Story::factory()->create();
        foreach ([0, 1] as $ordinal) {
            Keyframe::factory()->create([
                'owner_type' => $story->getMorphClass(),
                'owner_id' => $story->id,
                'ordinal' => $ordinal,
                'timestamp_ms' => null,
            ]);
        }
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);

        $written = app(VisualMatchWriter::class)->write($story, $this->result($this->candidate($product), VisualMatchBand::Review), 'gemini-embedding-2');

        $this->assertSame(1, $written);
        $this->assertDatabaseHas('recognition_detections', [
            'story_id' => $story->id,
            'content_item_id' => null,
            'recognition_type' => 'VISUAL_PRODUCT',
            'provider_label' => 'visual-product:'.$product->id,
        ]);
    }
}
```

- [ ] **Step 7: Run it** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualMatchWriterTest`
Expected: ERROR — `Class "App\Platform\Enrichment\VisualMatch\VisualMatchWriter" not found`.

- [ ] **Step 8: Implement the writer.** Create `app/Platform/Enrichment/VisualMatch/VisualMatchWriter.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Keyframes\KeyframeRepository;
use App\Platform\Enrichment\Support\HumanPrecedence;
use App\Platform\Enrichment\VisualMatch\Matching\BandResult;
use App\Platform\Enrichment\VisualMatch\Matching\ThresholdResolver;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * DP-004-aware writer for VISUAL_PRODUCT detections (sub-project C),
 * mirroring TextSignalRecognizer::upsert: identity (target, VISUAL_PRODUCT,
 * 'visual-product:<productId>'), human-touched rows never overwritten,
 * identity-adjacent fields (brand/product/product_id) seeded only on
 * create, unique-violation recovery. AUTO writes HIGH; REVIEW writes LOW
 * (queues for humans). REJECT never writes a row — an EXISTING AI row
 * whose support vanished is downgraded via withdrawSupport, never deleted.
 */
final class VisualMatchWriter
{
    private const SOURCE_VERSION = 'visual-match-v1';

    private const WITHDRAWN_SIGNAL = 'visual-support-withdrawn';

    public function __construct(
        private readonly KeyframeRepository $keyframes,
        private readonly ThresholdResolver $thresholds,
    ) {}

    /**
     * Only called for AUTO/REVIEW bands (the matcher routes REJECT to
     * withdrawSupport).
     *
     * @return int 1 if the row was written/updated, 0 if a human decision blocked it
     */
    public function write(ContentItem|Story $target, BandResult $result, string $modelVersion): int
    {
        $identity = [
            $target instanceof ContentItem ? 'content_item_id' : 'story_id' => $target->id,
            'recognition_type' => RecognitionType::VisualProduct,
            'provider_label' => 'visual-product:'.$result->candidate->productId,
        ];

        $detection = RecognitionDetection::query()->firstOrNew($identity);

        if ($detection->exists && ! HumanPrecedence::allowsAiUpdate($detection->assessment)) {
            return 0;
        }

        if (! $detection->exists) {
            // Identity-adjacent fields seed on first insert only — a human
            // correction of brand/product survives later AI re-runs (DP-004).
            $detection->detected_brand = $result->candidate->brandName;
            $detection->detected_product = $result->candidate->productLabel;
            $detection->product_id = $result->candidate->productId;
        }

        $detection->fill([
            'detected_text' => null,
            'assessment' => new ConfidenceAssessment(
                // Value = brand name (review-correction contract: correction
                // is brand-only today; follows the row's brand).
                value: $detection->detected_brand ?? $result->candidate->brandName,
                confidenceLevel: $result->band === VisualMatchBand::Auto ? ConfidenceLevel::High : ConfidenceLevel::Low,
                signals: $this->signals($target, $result, $modelVersion),
                verificationStatus: VerificationStatus::AiAssessed,
            ),
            'provenance' => new Provenance(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS, CarbonImmutable::now(), self::SOURCE_VERSION),
        ]);

        try {
            $detection->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent pass inserted the same detection first (the
            // partial unique index is the backstop). Honour precedence on
            // the winning row; either way it is already recorded.
            $detection = RecognitionDetection::query()->where($identity)->firstOrFail();

            if (! HumanPrecedence::allowsAiUpdate($detection->assessment)) {
                return 0;
            }

            return 0;
        }

        return 1;
    }

    /**
     * Catalog/model drift: an earlier AI VISUAL_PRODUCT row whose candidate
     * now rejects is downgraded to LOW + 'visual-support-withdrawn' (routes
     * to review; humans decide). Human-touched rows stay untouched (DP-004).
     *
     * @return int 1 if an AI row was downgraded, 0 otherwise
     */
    public function withdrawSupport(ContentItem|Story $target, int $productId): int
    {
        $detection = RecognitionDetection::query()
            ->where($target instanceof ContentItem ? 'content_item_id' : 'story_id', $target->id)
            ->where('recognition_type', RecognitionType::VisualProduct)
            ->where('provider_label', 'visual-product:'.$productId)
            ->first();

        if ($detection === null || ! HumanPrecedence::allowsAiUpdate($detection->assessment)) {
            return 0;
        }

        // Already withdrawn — idempotent, leave the envelope untouched.
        if ($detection->assessment->confidenceLevel === ConfidenceLevel::Low
            && in_array(self::WITHDRAWN_SIGNAL, $detection->assessment->signals, true)) {
            return 0;
        }

        $detection->fill([
            'assessment' => new ConfidenceAssessment(
                value: $detection->assessment->value,
                confidenceLevel: ConfidenceLevel::Low,
                signals: array_values(array_unique([...$detection->assessment->signals, self::WITHDRAWN_SIGNAL])),
                verificationStatus: VerificationStatus::AiAssessed,
            ),
            'provenance' => new Provenance(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS, CarbonImmutable::now(), self::SOURCE_VERSION),
        ]);

        $detection->save();

        return 1;
    }

    /** @return non-empty-list<string> the frozen, review-UI-visible signal trail */
    private function signals(ContentItem|Story $target, BandResult $result, string $modelVersion): array
    {
        // Total = stored keyframes for the target, read through the
        // B-contract repository only (C never touches the keyframes table).
        $total = count($this->keyframes->forOwner($target)->frames);

        $signals = [
            'visual-product-match:'.$result->candidate->productLabel,
            sprintf('visual-frames-supporting:%d/%d', $result->supportCount, $total),
        ];

        // Per supporting frame, first 5. Null-timestamp frames (carousel/
        // story images) carry no t= evidence — the aggregate line covers them.
        foreach (array_slice($result->supportingFrames, 0, 5) as $frame) {
            if ($frame->timestampMs !== null) {
                $signals[] = sprintf('visual-frame:t=%dms:sim=%.2f', $frame->timestampMs, $frame->similarity);
            }
        }

        $thresholds = $this->thresholds->for($result->candidate->category);
        $signals[] = sprintf(
            'visual-threshold:%s:auto=%.2f:review=%.2f:margin=%.2f',
            $result->candidate->category?->value ?? 'default',
            $thresholds->auto,
            $thresholds->review,
            $thresholds->margin,
        );

        $signals[] = 'embedding-model:'.$modelVersion;

        return $signals;
    }
}
```

- [ ] **Step 9: Run again** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualMatchWriterTest`
Expected: PASS (8 tests).

- [ ] **Step 10: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/VisualMatchWriter.php tests/Feature/Enrichment/VisualMatchWriterTest.php
git commit -m "feat(enrichment): DP-004 visual-product detection writer with frozen signal trail"
```

- [ ] **Step 11: Write the failing recorder test.** Create `tests/Feature/Enrichment/VisualMatchRunRecorderTest.php`:

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\VisualMatchCandidate;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use App\Platform\Enrichment\VisualMatch\Matching\BandResult;
use App\Platform\Enrichment\VisualMatch\Matching\FrameScore;
use App\Platform\Enrichment\VisualMatch\VisualMatchRunRecorder;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VisualMatchOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisualMatchRunRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'qds.enrichment.visual_match.thresholds' => [
                'default' => ['auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
                'BEAUTY' => ['auto' => 0.70],
            ],
            'qds.ai_budget.capabilities.embedding.price_micro_usd_per_unit' => 120,
        ]);
    }

    public function test_records_the_run_and_ranked_candidate_rows(): void
    {
        $content = ContentItem::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);
        $seeding = SeedingCampaign::factory()->create(['brand_id' => $brand->id, 'campaign_id' => $campaign->id]);
        $matched = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume', 'category' => SectorLabel::Beauty]);
        $photoless = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'Cloud Blush']);

        $matchedCandidate = new Candidate($matched->id, 'You Perfume', 'Glossier', SectorLabel::Beauty, 'shipment', true, null, CarbonImmutable::parse('2026-07-10 10:00:00'), 6, true);
        $photolessCandidate = new Candidate($photoless->id, 'Cloud Blush', 'Glossier', null, 'roster', false, $seeding->id, null, null, false);
        $candidates = new CandidateSet([$matchedCandidate, $photolessCandidate], Priority::High);

        $prep = new FramePreparationResult(frames: [], framesAvailable: 4, skippedFormat: 1, skippedQuality: 0, deduped: 1);

        $result = new BandResult(
            candidate: $matchedCandidate, band: VisualMatchBand::Auto,
            supportingFrames: [new FrameScore(9, 0, 1500, 0.7123, 21, 3)],
            supportCount: 3, marginToRunnerUp: 0.12, rejectionReason: null,
            firstSupportMs: 1500, lastSupportMs: 1500, estimatedVisibleMs: 9000,
            bestSimilarity: 0.7123,
        );

        $run = app(VisualMatchRunRecorder::class)->record(
            $content, 'corr-rec-1', $candidates, $prep, [$result],
            VisualMatchOutcome::Matched, 'gemini-embedding-2', 2, 1, 345, false,
        );

        $this->assertDatabaseHas('visual_match_runs', [
            'id' => $run->id,
            'content_item_id' => $content->id,
            'correlation_id' => 'corr-rec-1',
            'model_version' => 'gemini-embedding-2',
            'priority' => 'high',
            'frames_available' => 4,
            'frames_processed' => 3, // billed 2 + cached 1
            'frames_skipped_format' => 1,
            'frames_skipped_quality' => 0,
            'frames_deduped' => 1,
            'cache_hits' => 1,
            'processing_ms' => 345,
            'candidates_checked' => 2,
            'outcome' => 'matched',
            'embedding_calls' => 2,
            'estimated_cost_micro_usd' => 240,
            'needs_verification' => false,
        ]);
        $this->assertSame(0.7123, (float) $run->best_score);
        $this->assertNull($run->rejection_reason);

        $thresholds = $run->thresholds;
        $this->assertEqualsWithDelta(0.70, $thresholds['BEAUTY']['auto'], 0.0001);
        $this->assertEqualsWithDelta(0.55, $thresholds['BEAUTY']['review'], 0.0001);
        $this->assertEqualsWithDelta(0.65, $thresholds['default']['auto'], 0.0001);

        $this->assertDatabaseHas('visual_match_candidates', [
            'visual_match_run_id' => $run->id, 'rank' => 1,
            'product_id' => $matched->id, 'product_label' => 'You Perfume',
            'category' => 'BEAUTY', 'band' => 'auto',
            'source' => 'shipment', 'shipment_in_window' => true, 'shipment_age_days' => 6,
            'first_support_ms' => 1500, 'last_support_ms' => 1500, 'estimated_visible_ms' => 9000,
        ]);
        // Unmatchable candidate: coverage accounting for D and reviewers.
        $this->assertDatabaseHas('visual_match_candidates', [
            'visual_match_run_id' => $run->id, 'rank' => 2,
            'product_id' => $photoless->id, 'band' => 'reject',
            'rejection_reason' => 'no-embedded-photos',
            'source' => 'roster', 'seeding_campaign_id' => $seeding->id,
        ]);

        $rows = VisualMatchCandidate::query()->where('visual_match_run_id', $run->id)->orderBy('rank')->get();
        $this->assertSame([
            ['ordinal' => 0, 'timestamp_ms' => 1500, 'similarity' => 0.7123, 'photo_id' => 21, 'represented_frames' => 3],
        ], $rows[0]->supporting_frames);
        $this->assertSame([], $rows[1]->supporting_frames);
    }

    public function test_skipped_runs_record_coverage_but_no_candidate_verdicts(): void
    {
        $content = ContentItem::factory()->create();
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);
        $candidates = new CandidateSet([
            new Candidate($product->id, 'You Perfume', 'Glossier', null, 'shipment', true, null, null, 2, true),
        ], Priority::Medium);
        $prep = new FramePreparationResult([], 3, 0, 0, 0);

        $run = app(VisualMatchRunRecorder::class)->record(
            $content, 'corr-rec-2', $candidates, $prep, [],
            VisualMatchOutcome::SkippedBudget, 'gemini-embedding-2', 0, 0, 12, false,
        );

        $this->assertDatabaseHas('visual_match_runs', [
            'id' => $run->id, 'outcome' => 'skipped_budget', 'priority' => 'medium',
            'embedding_calls' => 0, 'estimated_cost_micro_usd' => 0, 'candidates_checked' => 1,
        ]);
        $this->assertNull($run->best_score);
        // A skipped run assessed nothing — fabricating per-candidate
        // verdicts would be dishonest.
        $this->assertSame(0, VisualMatchCandidate::query()->count());
    }
}
```

- [ ] **Step 12: Run it** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualMatchRunRecorderTest`
Expected: ERROR — `Class "App\Platform\Enrichment\VisualMatch\VisualMatchRunRecorder" not found`.

- [ ] **Step 13: Implement the recorder.** Create `app/Platform/Enrichment/VisualMatch/VisualMatchRunRecorder.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchCandidate;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use App\Platform\Enrichment\VisualMatch\Matching\BandResult;
use App\Platform\Enrichment\VisualMatch\Matching\FrameScore;
use App\Platform\Enrichment\VisualMatch\Matching\ThresholdResolver;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VisualMatchOutcome;

/**
 * Persists the append-only audit trail of one visual-match analysis run: a
 * visual_match_runs row (always, once candidates were known — skips
 * included), plus ranked visual_match_candidates rows on completed runs.
 * The latest run per post is authoritative; history feeds calibration (E)
 * and sub-project D's needs_verification poll.
 */
final class VisualMatchRunRecorder
{
    private const REASON_NO_EMBEDDED_PHOTOS = 'no-embedded-photos';

    public function __construct(private readonly ThresholdResolver $thresholds) {}

    /** @param list<BandResult> $results ranked best-first (BandMapper order) */
    public function record(ContentItem|Story $target, string $correlationId, CandidateSet $candidates,
        FramePreparationResult $prep, array $results, VisualMatchOutcome $outcome,
        string $modelVersion, int $billedCalls, int $cacheHits, int $processingMs, bool $needsVerification): VisualMatchRun
    {
        $bestScore = null;

        foreach ($results as $result) {
            $bestScore = max($bestScore ?? 0.0, $result->bestSimilarity);
        }

        $run = VisualMatchRun::query()->create([
            $target instanceof ContentItem ? 'content_item_id' : 'story_id' => $target->id,
            'correlation_id' => $correlationId,
            'model_version' => $modelVersion,
            'priority' => $candidates->priority->value,
            'frames_available' => $prep->framesAvailable,
            'frames_processed' => $billedCalls + $cacheHits,
            'frames_skipped_format' => $prep->skippedFormat,
            'frames_skipped_quality' => $prep->skippedQuality,
            'frames_deduped' => $prep->deduped,
            'cache_hits' => $cacheHits,
            'processing_ms' => $processingMs,
            'candidates_checked' => count($candidates->candidates),
            'best_score' => $bestScore !== null ? round($bestScore, 4) : null,
            'outcome' => $outcome->value,
            'rejection_reason' => $this->runRejectionReason($results, $outcome),
            'thresholds' => $this->thresholdSnapshot($candidates),
            'embedding_calls' => $billedCalls,
            'estimated_cost_micro_usd' => $billedCalls * (int) config('qds.ai_budget.capabilities.embedding.price_micro_usd_per_unit'),
            'needs_verification' => $needsVerification,
        ]);

        $rank = 0;

        foreach ($results as $result) {
            $this->candidateRow($run, $result->candidate, ++$rank, [
                'band' => $result->band->value,
                'best_similarity' => round($result->bestSimilarity, 4),
                'margin_to_runner_up' => $result->marginToRunnerUp !== null ? round($result->marginToRunnerUp, 4) : null,
                'supporting_frames' => array_map(static fn (FrameScore $f): array => [
                    'ordinal' => $f->ordinal,
                    'timestamp_ms' => $f->timestampMs,
                    'similarity' => round($f->similarity, 4),
                    'photo_id' => $f->photoId,
                    'represented_frames' => $f->representedFrames,
                ], $result->supportingFrames),
                'rejection_reason' => $result->rejectionReason,
                'first_support_ms' => $result->firstSupportMs,
                'last_support_ms' => $result->lastSupportMs,
                'estimated_visible_ms' => $result->estimatedVisibleMs,
            ]);
        }

        // Unmatchable candidates (no embedded reference photos) are recorded
        // on COMPLETED runs only — coverage accounting for D and reviewers.
        // Skipped runs assessed nothing; no per-candidate verdicts there.
        $skipped = in_array($outcome, [
            VisualMatchOutcome::SkippedBudget, VisualMatchOutcome::SkippedReadOnly, VisualMatchOutcome::SkippedProvider,
        ], true);

        if (! $skipped) {
            foreach ($candidates->candidates as $candidate) {
                if ($candidate->hasEmbeddedPhotos) {
                    continue;
                }

                $this->candidateRow($run, $candidate, ++$rank, [
                    'band' => VisualMatchBand::Reject->value,
                    'best_similarity' => 0,
                    'margin_to_runner_up' => null,
                    'supporting_frames' => [],
                    'rejection_reason' => self::REASON_NO_EMBEDDED_PHOTOS,
                    'first_support_ms' => null,
                    'last_support_ms' => null,
                    'estimated_visible_ms' => null,
                ]);
            }
        }

        return $run;
    }

    /** @param array<string, mixed> $verdict */
    private function candidateRow(VisualMatchRun $run, Candidate $candidate, int $rank, array $verdict): void
    {
        VisualMatchCandidate::query()->create([
            'visual_match_run_id' => $run->id,
            'product_id' => $candidate->productId,
            'product_label' => $candidate->productLabel,
            'category' => $candidate->category?->value,
            'rank' => $rank,
            'source' => $candidate->source,
            'shipment_in_window' => $candidate->shipmentInWindow,
            'seeding_campaign_id' => $candidate->seedingCampaignId,
            'shipment_anchor_at' => $candidate->shipmentAnchorAt,
            'shipment_age_days' => $candidate->shipmentAgeDays,
            ...$verdict,
        ]);
    }

    /** @param list<BandResult> $results */
    private function runRejectionReason(array $results, VisualMatchOutcome $outcome): ?string
    {
        if (! in_array($outcome, [VisualMatchOutcome::NoMatch, VisualMatchOutcome::Inconclusive], true)) {
            return null;
        }

        return $results[0]->rejectionReason ?? null;
    }

    /** @return array<string, array{auto: float, review: float, margin: float}> snapshot per candidate category */
    private function thresholdSnapshot(CandidateSet $candidates): array
    {
        $snapshot = [];

        foreach ($candidates->candidates as $candidate) {
            $key = $candidate->category?->value ?? 'default';

            if (isset($snapshot[$key])) {
                continue;
            }

            $thresholds = $this->thresholds->for($candidate->category);
            $snapshot[$key] = ['auto' => $thresholds->auto, 'review' => $thresholds->review, 'margin' => $thresholds->margin];
        }

        // Always snapshot the default map too (run-level debugging aid).
        if (! isset($snapshot['default'])) {
            $thresholds = $this->thresholds->for(null);
            $snapshot['default'] = ['auto' => $thresholds->auto, 'review' => $thresholds->review, 'margin' => $thresholds->margin];
        }

        return $snapshot;
    }
}
```

- [ ] **Step 14: Run again** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualMatchRunRecorderTest`
Expected: PASS (2 tests).

- [ ] **Step 15: Task-level suite run** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter 'VisualMatchWriterTest|VisualMatchRunRecorderTest|SourceRegistryTest'`
Expected: PASS, no other failures.

- [ ] **Step 16: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/VisualMatchRunRecorder.php tests/Feature/Enrichment/VisualMatchRunRecorderTest.php
git commit -m "feat(enrichment): visual match run + candidate audit recorder"
```

---

---

### Task 19: VisualProductMatcher stage + EnrichmentPipeline wiring

**Files:**
- Create: `app/Platform/Enrichment/VisualMatch/VisualProductMatcher.php`
- Modify: `app/Platform/Enrichment/EnrichmentPipeline.php` (constructor lines 37–48; stage sequence between the `keyframes` block, lines 83–89, and the `text_signals` block, lines 91–95)
- Modify: `config/qds.php` (`enrichment` array — the `keyframes` block ends at line 309; the full `visual_match` block below must exist directly after it)
- Test: `tests/Feature/Enrichment/VisualProductMatcherTest.php` (create), `tests/Feature/Enrichment/VisualMatchPipelineWiringTest.php` (create)

**Interfaces:**
- Consumes: `EmbeddingProvider` (T7: `embedImage(string $bytes, string $mimeType): array`, `modelVersion(): string`, `isConfigured(): bool`, container-bound); `AiBudgetGuard::allows(string $capability, int $tenantId, int $units, Priority $priority): BudgetDecision` / `AiBudgetGuard::record(string $capability, int $tenantId, int $units, int $postsProcessed = 0, int $postsSkippedBudget = 0, int $postsSkippedNoCandidates = 0): void` (T8; deny reasons `'read-only'`, `'global-hard-exhausted'`, `'tenant-daily-exhausted'`); `CandidateScope::forTarget(ContentItem|Story): CandidateSet` (T15); `FramePreparation::prepare(KeyframeSet $set, int $budget): FramePreparationResult` (T12); `KeyframeEmbedder::embedAll(array $frames, string $correlationId): array{embedded: array<int, list<float>>, billedCalls: int, cacheHits: int}` (T13); `FrameProductScorer::score(array $frames, array $matchable, string $modelVersion): array` (T16); `BandMapper::map(array $scored, FramePreparationResult $prep): array` / `BandMapper::runOutcome(array $bandResults, FramePreparationResult $prep, CandidateSet $candidates): VisualMatchOutcome` (T17); `VisualMatchWriter` + `VisualMatchRunRecorder` (T18, exact signatures); `ProviderCircuitBreaker::shouldSkip(string $source): bool`; `KeyframeRepository::forOwner`; `KeyframeEmbedding` model (T5); `SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS` (T18).
- Produces: `VisualProductMatcher::enrich(ContentItem|Story $target, string $correlationId): string` returning exactly the frozen markers (`skipped:disabled` … `completed:inconclusive`); pipeline stage key `visual_match` between `keyframes` and `text_signals`; config `qds.enrichment.visual_match.*` (spec §12) — consumed by Task 20 (switch), Task 21 (runs data), Task 22 (backfill re-runs this exact method).

- [ ] **Step 1: Ensure the config block (end state = spec §12).** Tasks 12/17 already contributed `thresholds`/`quality_filter`/`dedup` sub-keys; after this step the `enrichment` array of `config/qds.php` must contain, directly after the `keyframes` block (line 309), exactly:

```php
        // Visual product matching (sub-project C): keyframes vs reference-
        // photo embeddings in pgvector. Kill switch: OFF = true no-op
        // (stage marker only, zero provider calls, evidence byte-identical).
        'visual_match' => [
            'enabled' => (bool) env('QDS_ENRICHMENT_VISUAL_MATCH_ENABLED', false), // kill switch, true no-op
            'model_version' => env('QDS_ENRICHMENT_VISUAL_MATCH_MODEL', 'gemini-embedding-2'), // pin exact versioned id at implementation
            'dimensions' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_DIMENSIONS', 3072),
            'frame_budget' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_FRAME_BUDGET', 12),
            'photo_cap' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_PHOTO_CAP', 8),
            'photo_link_ttl_minutes' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_PHOTO_LINK_TTL', 10),
            'thresholds' => [
                'default' => ['auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
                // per-category overrides, keys = SectorLabel values; packaging-prone stricter:
                'BEAUTY' => ['auto' => 0.70], 'FOOD_BEVERAGE' => ['auto' => 0.70],
                // NOTE: placeholders — calibration is sub-project E's mandate (eval golden set).
            ],
            'quality_filter' => [
                'enabled' => (bool) env('QDS_ENRICHMENT_VISUAL_MATCH_QUALITY_FILTER', true),
                'min_mean_luminance' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_MIN_LUMINANCE', 10),   // 0–255
                'max_mean_luminance' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_MAX_LUMINANCE', 245),
                'min_luminance_stddev' => (float) env('QDS_ENRICHMENT_VISUAL_MATCH_MIN_STDDEV', 4.0), // flat/blank proxy
            ],
            'dedup' => [
                'enabled' => (bool) env('QDS_ENRICHMENT_VISUAL_MATCH_DEDUP', true),
                'hamming_threshold' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_DEDUP_HAMMING', 6),     // of 64 dHash bits
            ],
        ],
```

Add only the keys that are missing; never duplicate the array key.

- [ ] **Step 2: Write the failing matcher test.** Create `tests/Feature/Enrichment/VisualProductMatcherTest.php`:

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\KeyframeEmbedding;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use App\Platform\Enrichment\VisualMatch\VisualProductMatcher;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Models\ProviderHealthState;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Platform\Ingestion\Support\ProviderStatus;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Enums\Platform;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\SeedingCampaignStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisualProductMatcherTest extends TestCase
{
    use RefreshDatabase;

    private FakeEmbeddingProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'qds.ingestion.media_disk' => 'media',
            'qds.enrichment.visual_match.enabled' => true,
            'qds.enrichment.visual_match.model_version' => 'gemini-embedding-2',
            'qds.enrichment.visual_match.dimensions' => 3072,
            'qds.enrichment.visual_match.frame_budget' => 12,
            'qds.enrichment.visual_match.thresholds' => [
                'default' => ['auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
            ],
            // Frame prep subtleties are T12's tests; here any decodable JPEG passes.
            'qds.enrichment.visual_match.quality_filter.enabled' => false,
            'qds.enrichment.visual_match.dedup.enabled' => false,
            'qds.ai_budget.read_only' => false,
            'qds.ai_budget.capabilities.embedding.price_micro_usd_per_unit' => 120,
            'qds.ai_budget.capabilities.embedding.per_post_units' => 12,
            'qds.ai_budget.capabilities.embedding.tenant_daily_units' => 2000,
            'qds.ai_budget.capabilities.embedding.tenant_monthly_units' => 40000,
            'qds.ai_budget.capabilities.embedding.global_daily_units' => 50000,
            'qds.ai_budget.capabilities.embedding.global_daily_hard_units' => 100000,
            'qds.ai_budget.capabilities.embedding.global_monthly_units' => 1000000,
            'qds.ai_budget.capabilities.embedding.global_monthly_hard_units' => 2000000,
            'qds.ingestion.circuit_breaker.enabled' => false,
        ]);

        Storage::fake('media');

        $this->provider = new FakeEmbeddingProvider;
        $this->swap(EmbeddingProvider::class, $this->provider);
    }

    /** @return array{0: ContentItem, 1: Creator} */
    private function wiredContent(): array
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->onPlatform(Platform::Instagram)->create();
        $content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'published_at' => CarbonImmutable::now()->subDay(),
        ]);

        return [$content, $creator];
    }

    /** Product with one embedded reference photo + an in-window ACTIVE-campaign shipment. */
    private function shippedProduct(Creator $creator, array $photoVector): Product
    {
        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume', 'category' => SectorLabel::Tech]);
        $seeding = SeedingCampaign::factory()->create([
            'brand_id' => $brand->id,
            'campaign_id' => Campaign::factory()->create(['brand_id' => $brand->id])->id,
            'product_id' => $product->id,
            'status' => SeedingCampaignStatus::Active,
        ]);
        Shipment::factory()->create([
            'seeding_campaign_id' => $seeding->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => CarbonImmutable::now()->subDays(5),
            'delivered_at' => CarbonImmutable::now()->subDays(3),
        ]);

        $photo = ProductReferencePhoto::factory()->create(['product_id' => $product->id]);
        ProductPhotoEmbedding::factory()->create([
            'product_reference_photo_id' => $photo->id,
            'model_version' => 'gemini-embedding-2',
            'embedding' => VectorLiteral::fromArray(array_pad($photoVector, 3072, 0.0)),
        ]);

        return $product;
    }

    /** Stores frame bytes on the media disk, registers the fake vector, returns the row. */
    private function storeFrame(ContentItem $content, int $ordinal, ?int $timestampMs, array $vector, string $extension = 'jpg'): Keyframe
    {
        $bytes = $extension === 'jpg' ? $this->jpegBytes($ordinal) : 'not-an-image-'.$ordinal;
        $path = "tenants/{$this->defaultTenant->id}/keyframes/{$content->id}/frame-{$ordinal}.{$extension}";
        Storage::disk('media')->put($path, $bytes);

        $this->provider->vectors[hash('sha256', $bytes)] = array_pad($vector, 3072, 0.0);

        return Keyframe::factory()->create([
            'owner_type' => $content->getMorphClass(),
            'owner_id' => $content->id,
            'ordinal' => $ordinal,
            'timestamp_ms' => $timestampMs,
            'kind' => $timestampMs === null ? KeyframeKind::SourceImage : KeyframeKind::VideoSample,
            'storage_disk' => 'media',
            'storage_path' => $path,
            'checksum' => hash('sha256', $bytes),
        ]);
    }

    private function jpegBytes(int $seed): string
    {
        $image = imagecreatetruecolor(32, 32);
        $shade = 40 + ($seed * 37) % 180;
        imagefilledrectangle($image, 0, 0, 31, 31, imagecolorallocate($image, $shade, $shade, ($shade * 7) % 255));
        ob_start();
        imagejpeg($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function test_kill_switch_off_is_a_true_noop(): void
    {
        config(['qds.enrichment.visual_match.enabled' => false]);
        [$content] = $this->wiredContent();

        $this->assertSame('skipped:disabled', app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1'));
        $this->assertSame(0, VisualMatchRun::query()->count());
        $this->assertSame(0, $this->provider->calls);
    }

    public function test_unconfigured_provider_skips(): void
    {
        $this->provider->configured = false;
        [$content] = $this->wiredContent();

        $this->assertSame('skipped:not-configured', app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1'));
        $this->assertSame(0, VisualMatchRun::query()->count());
    }

    public function test_unresolvable_creator_skips(): void
    {
        $account = PlatformAccount::factory()->create(['creator_id' => null, 'platform' => Platform::Instagram]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id, 'platform' => Platform::Instagram]);

        $this->assertSame('skipped:no-creator', app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1'));
    }

    public function test_empty_candidate_set_skips_free_and_counts(): void
    {
        [$content] = $this->wiredContent();

        $this->assertSame('skipped:no-candidates', app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1'));
        $this->assertSame(0, VisualMatchRun::query()->count());
        $this->assertSame(0, $this->provider->calls);
        $this->assertDatabaseHas('ai_usage_counters', [
            'capability' => 'embedding',
            'tenant_id' => $this->defaultTenant->id,
            'posts_skipped_no_candidates' => 1,
        ]);
    }

    public function test_empty_keyframe_set_skips(): void
    {
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);

        $this->assertSame('skipped:no-frames', app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1'));
        $this->assertSame(0, VisualMatchRun::query()->count());
    }

    public function test_auto_match_writes_the_detection_and_the_audit_run(): void
    {
        [$content, $creator] = $this->wiredContent();
        $product = $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);
        $this->storeFrame($content, 1, 5000, [1.0]);

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('completed:matched=1,review=0,rejected=0', $marker);
        $this->assertSame(2, $this->provider->calls);

        $this->assertDatabaseHas('recognition_detections', [
            'content_item_id' => $content->id,
            'recognition_type' => 'VISUAL_PRODUCT',
            'provider_label' => 'visual-product:'.$product->id,
            'product_id' => $product->id,
        ]);
        $this->assertDatabaseHas('visual_match_runs', [
            'content_item_id' => $content->id,
            'correlation_id' => 'corr-vm-1',
            'outcome' => 'matched',
            'priority' => 'high',
            'frames_available' => 2,
            'frames_processed' => 2,
            'embedding_calls' => 2,
            'cache_hits' => 0,
            'candidates_checked' => 1,
            'needs_verification' => false,
        ]);
        $this->assertDatabaseHas('visual_match_candidates', ['product_id' => $product->id, 'band' => 'auto', 'rank' => 1]);
        $this->assertSame(2, KeyframeEmbedding::query()->count());
        $this->assertDatabaseHas('ai_usage_counters', [
            'capability' => 'embedding', 'tenant_id' => $this->defaultTenant->id,
            'units' => 2, 'posts_processed' => 1,
        ]);
    }

    public function test_reruns_ride_the_embedding_cache(): void
    {
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);
        $this->storeFrame($content, 1, 5000, [1.0]);

        $matcher = app(VisualProductMatcher::class);
        $matcher->enrich($content, 'corr-vm-1');
        $marker = $matcher->enrich($content, 'corr-vm-2');

        $this->assertSame('completed:matched=1,review=0,rejected=0', $marker);
        $this->assertSame(2, $this->provider->calls); // nothing re-billed
        $this->assertSame(1, RecognitionDetection::query()->count());
        $this->assertDatabaseHas('visual_match_runs', ['correlation_id' => 'corr-vm-2', 'cache_hits' => 2, 'embedding_calls' => 0]);
    }

    public function test_single_frame_hit_lands_review_and_flags_verification(): void
    {
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('completed:matched=0,review=1,rejected=0', $marker);
        $detection = RecognitionDetection::query()->firstOrFail();
        $this->assertSame(ConfidenceLevel::Low, $detection->assessment->confidenceLevel);
        $this->assertTrue($detection->assessment->needsHumanReview());
        $this->assertDatabaseHas('visual_match_runs', ['outcome' => 'review', 'needs_verification' => true]);
    }

    public function test_exhausted_global_hard_budget_skips_before_any_call(): void
    {
        config(['qds.ai_budget.capabilities.embedding.global_daily_hard_units' => 0]);
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('skipped:budget-exhausted', $marker);
        $this->assertSame(0, $this->provider->calls);
        $this->assertSame(0, RecognitionDetection::query()->count());
        $this->assertDatabaseHas('visual_match_runs', ['outcome' => 'skipped_budget', 'needs_verification' => false]);
        $this->assertDatabaseHas('ai_usage_counters', ['capability' => 'embedding', 'posts_skipped_budget' => 1]);
    }

    public function test_read_only_mode_stops_spend_with_its_own_marker(): void
    {
        config(['qds.ai_budget.read_only' => true]);
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('skipped:ai-read-only', $marker);
        $this->assertSame(0, $this->provider->calls);
        $this->assertDatabaseHas('visual_match_runs', ['outcome' => 'skipped_read_only']);
    }

    public function test_open_circuit_breaker_skips_before_spending(): void
    {
        config(['qds.ingestion.circuit_breaker.enabled' => true, 'qds.ingestion.circuit_breaker.cooldown_minutes' => 60]);
        ProviderHealthState::query()->create([
            'source' => SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS,
            'status' => ProviderStatus::Failing,
            'consecutive_failures' => 3,
            'last_failure_at' => CarbonImmutable::now()->subMinutes(5),
            'last_error_category' => ErrorCategory::Authentication,
        ]);
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('skipped:provider-unavailable', $marker);
        $this->assertSame(0, $this->provider->calls);
        $this->assertDatabaseHas('visual_match_runs', ['outcome' => 'skipped_provider']);
    }

    public function test_transient_provider_failure_is_a_marker_never_a_crash(): void
    {
        $this->provider->failAll = true;
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0]);

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('skipped:provider-error', $marker);
        $this->assertSame(0, RecognitionDetection::query()->count());
        $this->assertDatabaseHas('visual_match_runs', ['outcome' => 'skipped_provider', 'embedding_calls' => 0]);
    }

    public function test_unusable_frames_yield_inconclusive_with_the_verification_flag(): void
    {
        [$content, $creator] = $this->wiredContent();
        $this->shippedProduct($creator, [1.0]);
        $this->storeFrame($content, 0, 1000, [1.0], extension: 'bin');

        $marker = app(VisualProductMatcher::class)->enrich($content, 'corr-vm-1');

        $this->assertSame('completed:inconclusive', $marker);
        $this->assertSame(0, $this->provider->calls);
        $this->assertSame(0, RecognitionDetection::query()->count());
        $this->assertDatabaseHas('visual_match_runs', [
            'outcome' => 'inconclusive',
            'frames_available' => 1,
            'frames_processed' => 0,
            'frames_skipped_format' => 1,
            // In-window shipment + no clean look → sub-project D verifies.
            'needs_verification' => true,
        ]);
    }
}

/** Deterministic container stub for the provider seam (spec §15). */
final class FakeEmbeddingProvider implements EmbeddingProvider
{
    /** @var array<string, list<float>> sha256(bytes) => vector */
    public array $vectors = [];

    public bool $configured = true;

    public bool $failAll = false;

    public int $calls = 0;

    public function embedImage(string $bytes, string $mimeType): array
    {
        if ($this->failAll) {
            throw new ProviderCallException(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS, ErrorCategory::UpstreamError, 'upstream boom', 500);
        }

        $this->calls++;

        return $this->vectors[hash('sha256', $bytes)] ?? array_pad([1.0], 3072, 0.0);
    }

    public function modelVersion(): string
    {
        return 'gemini-embedding-2';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }
}
```

- [ ] **Step 3: Run it** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualProductMatcherTest`
Expected: ERROR — `Class "App\Platform\Enrichment\VisualMatch\VisualProductMatcher" not found`.

- [ ] **Step 4: Implement the matcher.** Create `app/Platform/Enrichment/VisualMatch/VisualProductMatcher.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\KeyframeEmbedding;
use App\Modules\Monitoring\Models\Story;
use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\Enrichment\Keyframes\KeyframeRepository;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateScope;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparation;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use App\Platform\Enrichment\VisualMatch\Frames\KeyframeEmbedder;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Platform\Enrichment\VisualMatch\Matching\BandMapper;
use App\Platform\Enrichment\VisualMatch\Matching\BandResult;
use App\Platform\Enrichment\VisualMatch\Matching\FrameProductScorer;
use App\Platform\Ingestion\Observability\ProviderCircuitBreaker;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VisualMatchOutcome;

/**
 * The visual_match stage orchestrator (sub-project C): gates → candidate
 * scoping → free local frame preparation → budget-guarded embedding →
 * exact-scan scoring → banding → persistence. Fail-closed: every gate
 * exits with an explainable marker; unavailability is recorded at run
 * level, never fabricated as detection rows. Runs under the enrichment
 * job's TenantContext::runAs.
 */
final class VisualProductMatcher
{
    private const CAPABILITY = 'embedding';

    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly CandidateScope $candidates,
        private readonly KeyframeRepository $keyframes,
        private readonly FramePreparation $preparation,
        private readonly KeyframeEmbedder $embedder,
        private readonly FrameProductScorer $scorer,
        private readonly BandMapper $bands,
        private readonly VisualMatchWriter $writer,
        private readonly VisualMatchRunRecorder $recorder,
        private readonly AiBudgetGuard $budget,
        private readonly ProviderCircuitBreaker $breaker,
    ) {}

    /** @return string the EnrichmentRun stage marker (frozen set, spec §8) */
    public function enrich(ContentItem|Story $target, string $correlationId): string
    {
        if (! (bool) config('qds.enrichment.visual_match.enabled')) {
            return 'skipped:disabled';
        }

        if (! $this->provider->isConfigured()) {
            return 'skipped:not-configured';
        }

        if ($target->platformAccount?->creator_id === null) {
            return 'skipped:no-creator';
        }

        $startedAt = microtime(true);
        $tenantId = (int) $target->tenant_id;

        $candidates = $this->candidates->forTarget($target);

        if ($candidates->isEmpty()) {
            // The tiering that makes most posts free: no plausible product,
            // no spend, no run row — only the usage counter learns of it.
            $this->budget->record(self::CAPABILITY, $tenantId, 0, postsSkippedNoCandidates: 1);

            return 'skipped:no-candidates';
        }

        $set = $this->keyframes->forOwner($target);

        if ($set->isEmpty()) {
            // B could not extract: nothing for C OR D to see (no escalation).
            return 'skipped:no-frames';
        }

        // Local + free. The stage's own frame cap and the budget guard's
        // per-post ceiling agree by default (both 12); the stricter wins.
        $prep = $this->preparation->prepare($set, min(
            (int) config('qds.enrichment.visual_match.frame_budget'),
            (int) config('qds.ai_budget.capabilities.embedding.per_post_units'),
        ));

        $modelVersion = $this->provider->modelVersion();
        $matchable = $candidates->matchable();

        if ($matchable === [] || $prep->frames === []) {
            // Nothing scorable (no embedded reference photos, or no frame
            // survived preparation): zero spend, but the run IS recorded —
            // the coverage accounting is exactly what D and reviewers need.
            return $this->complete($target, $correlationId, $candidates, $prep, [], $modelVersion, 0, 0, $startedAt, $tenantId, spend: false);
        }

        // Paid path from here: consult the breaker BEFORE spending —
        // every call bills (deliberate improvement over recognition).
        if ($this->breaker->shouldSkip(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS)) {
            $this->recordSkip($target, $correlationId, $candidates, $prep, VisualMatchOutcome::SkippedProvider, $modelVersion, 0, 0, $startedAt);

            return 'skipped:provider-unavailable';
        }

        $decision = $this->budget->allows(self::CAPABILITY, $tenantId, $this->projectedCalls($prep, $modelVersion), $candidates->priority);

        if (! $decision->allowed) {
            if ($decision->reason === 'read-only') {
                $this->recordSkip($target, $correlationId, $candidates, $prep, VisualMatchOutcome::SkippedReadOnly, $modelVersion, 0, 0, $startedAt);

                return 'skipped:ai-read-only';
            }

            $this->budget->record(self::CAPABILITY, $tenantId, 0, postsSkippedBudget: 1);
            $this->recordSkip($target, $correlationId, $candidates, $prep, VisualMatchOutcome::SkippedBudget, $modelVersion, 0, 0, $startedAt);

            return 'skipped:budget-exhausted';
        }

        $embedding = $this->embedder->embedAll($prep->frames, $correlationId);

        if ($embedding['embedded'] === []) {
            // Transient provider trouble on every frame: a run-level marker
            // (mirroring speech:provider-error) — never a failed enrichment
            // run, never a re-bill of completed stages; cached rows are kept.
            $this->budget->record(self::CAPABILITY, $tenantId, $embedding['billedCalls']);
            $this->recordSkip($target, $correlationId, $candidates, $prep, VisualMatchOutcome::SkippedProvider, $modelVersion, $embedding['billedCalls'], $embedding['cacheHits'], $startedAt);

            return 'skipped:provider-error';
        }

        $this->budget->record(self::CAPABILITY, $tenantId, $embedding['billedCalls'], postsProcessed: 1);

        // Score only the frames that actually embedded (transient-failure omission).
        $scorable = array_values(array_filter(
            $prep->frames,
            static fn (PreparedFrame $frame): bool => array_key_exists($frame->keyframe->id, $embedding['embedded']),
        ));

        $results = $this->bands->map($this->scorer->score($scorable, $matchable, $modelVersion), $prep);

        return $this->complete($target, $correlationId, $candidates, $prep, $results, $modelVersion, $embedding['billedCalls'], $embedding['cacheHits'], $startedAt, $tenantId, spend: true);
    }

    /** @param list<BandResult> $results */
    private function complete(ContentItem|Story $target, string $correlationId, CandidateSet $candidates,
        FramePreparationResult $prep, array $results, string $modelVersion,
        int $billedCalls, int $cacheHits, float $startedAt, int $tenantId, bool $spend): string
    {
        if (! $spend) {
            $this->budget->record(self::CAPABILITY, $tenantId, 0, postsProcessed: 1);
        }

        $auto = $review = $reject = 0;

        foreach ($results as $result) {
            if ($result->band === VisualMatchBand::Reject) {
                $reject++;
                // Catalog/model drift: an earlier AI row whose candidate now
                // rejects downgrades to review — never deleted (DP-004).
                $this->writer->withdrawSupport($target, $result->candidate->productId);

                continue;
            }

            $result->band === VisualMatchBand::Auto ? $auto++ : $review++;
            $this->writer->write($target, $result, $modelVersion);
        }

        $outcome = $this->bands->runOutcome($results, $prep, $candidates);

        $this->recorder->record($target, $correlationId, $candidates, $prep, $results, $outcome,
            $modelVersion, $billedCalls, $cacheHits, $this->elapsedMs($startedAt), $this->needsVerification($results, $candidates));

        return match ($outcome) {
            VisualMatchOutcome::NoMatch => 'completed:no-match',
            VisualMatchOutcome::Inconclusive => 'completed:inconclusive',
            default => sprintf('completed:matched=%d,review=%d,rejected=%d', $auto, $review, $reject),
        };
    }

    private function recordSkip(ContentItem|Story $target, string $correlationId, CandidateSet $candidates,
        FramePreparationResult $prep, VisualMatchOutcome $outcome, string $modelVersion,
        int $billedCalls, int $cacheHits, float $startedAt): void
    {
        // A skipped run assessed nothing: needs_verification stays false —
        // the backfill command is the remedy, not D's verifier.
        $this->recorder->record($target, $correlationId, $candidates, $prep, [], $outcome,
            $modelVersion, $billedCalls, $cacheHits, $this->elapsedMs($startedAt), false);
    }

    /**
     * §11: D verifies lone REVIEW hits, and shipment-but-no-match runs
     * regardless of the no_match/inconclusive split. AUTO needs no help.
     *
     * @param list<BandResult> $results
     */
    private function needsVerification(array $results, CandidateSet $candidates): bool
    {
        $auto = false;
        $review = false;

        foreach ($results as $result) {
            $auto = $auto || $result->band === VisualMatchBand::Auto;
            $review = $review || $result->band === VisualMatchBand::Review;
        }

        return match (true) {
            $auto => false,
            $review => true,
            default => $candidates->hasInWindowShipment(),
        };
    }

    /** Projected UNCACHED call count — what the budget guard is asked for. */
    private function projectedCalls(FramePreparationResult $prep, string $modelVersion): int
    {
        $ids = array_map(static fn (PreparedFrame $frame): int => $frame->keyframe->id, $prep->frames);

        $cached = KeyframeEmbedding::query()
            ->whereIn('keyframe_id', $ids)
            ->where('model_version', $modelVersion)
            ->count();

        return max(0, count($ids) - $cached);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
```

- [ ] **Step 5: Run again** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualProductMatcherTest`
Expected: PASS (13 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/VisualProductMatcher.php config/qds.php tests/Feature/Enrichment/VisualProductMatcherTest.php
git commit -m "feat(enrichment): VisualProductMatcher stage orchestrator with budget, breaker and audit gates"
```

- [ ] **Step 7: Write the failing pipeline wiring test.** Create `tests/Feature/Enrichment/VisualMatchPipelineWiringTest.php`:

```php
<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Enrichment\Contracts\EnrichmentService;
use App\Platform\Enrichment\Models\EnrichmentRun;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisualMatchPipelineWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'qds.ingestion.media_disk' => 'media',
            'services.google_vision.api_key' => '',
            'services.google_video_intelligence.api_key' => '',
            'qds.enrichment.keyframes.enabled' => false,
        ]);
        Storage::fake('media');
        Http::fake(['93.184.216.34/*' => Http::response('synthetic-image-bytes')]);
    }

    private function wiredContent(): ContentItem
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create(['platform' => Platform::Instagram]);
        MonitoredSubject::factory()->create(['creator_id' => $creator->id, 'platforms' => [Platform::Instagram]]);

        return ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'content_type' => ContentType::ImagePost,
            'caption' => 'ein Post',
            'media_urls' => ['https://93.184.216.34/img.jpg'],
        ]);
    }

    public function test_stage_sits_between_keyframes_and_text_signals_and_kill_switch_records_disabled(): void
    {
        config(['qds.enrichment.visual_match.enabled' => false]);

        $content = $this->wiredContent();
        app(EnrichmentService::class)->enrich($content);

        $run = EnrichmentRun::query()->where('content_item_id', $content->id)->firstOrFail();

        $this->assertSame(
            ['hashtags', 'transcript', 'recognition', 'keyframes', 'visual_match', 'text_signals', 'sentiment', 'attribution', 'emv', 'reach'],
            array_keys($run->stages),
        );
        $this->assertSame('skipped:disabled', $run->stages['visual_match']);
    }

    public function test_enabled_but_unconfigured_provider_records_its_own_marker(): void
    {
        config([
            'qds.enrichment.visual_match.enabled' => true,
            'services.google_embeddings.credentials_path' => null,
            'services.google_embeddings.project_id' => null,
        ]);

        $content = $this->wiredContent();
        app(EnrichmentService::class)->enrich($content);

        $run = EnrichmentRun::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame('skipped:not-configured', $run->stages['visual_match']);
    }
}
```

- [ ] **Step 8: Run it** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualMatchPipelineWiringTest`
Expected: FAIL — the stages key list has no `visual_match` entry.

- [ ] **Step 9: Wire the pipeline.** In `app/Platform/Enrichment/EnrichmentPipeline.php`: add the import `use App\Platform\Enrichment\VisualMatch\VisualProductMatcher;`, add a constructor parameter after `private readonly YouTubeTranscriptEnricher $transcripts,` (line 47):

```php
        private readonly VisualProductMatcher $visualMatch,
```

and insert between the `keyframes` block (ends line 89) and the `text_signals` block (starts line 91):

```php
            // Sub-project C: visual product matching over the persisted
            // keyframes. After `keyframes` (frames must exist), before
            // `attribution` in the same run so VISUAL_PRODUCT detections
            // classify immediately. Kill switch OFF = marker only — the
            // matcher (and its provider chain) is never invoked.
            if ((bool) config('qds.enrichment.visual_match.enabled')) {
                $stages['visual_match'] = $this->visualMatch->enrich($target, $correlationId);
            } else {
                $stages['visual_match'] = 'skipped:disabled';
            }
```

Also update the class docblock pipeline order (line 26) to `hashtags → transcript → recognition → keyframes → visual match → text signals → sentiment → seeded attribution → EMV → reach`.

- [ ] **Step 10: Run again** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualMatchPipelineWiringTest`
Expected: PASS (2 tests).

- [ ] **Step 11: Task-level suite run** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter 'VisualProductMatcherTest|VisualMatchPipelineWiringTest|EnrichmentPipelineTest|EnrichmentHardeningTest'`
Expected: PASS — the pre-existing pipeline tests must stay green (the new stage records `skipped:disabled` by default).

- [ ] **Step 12: Commit**

```bash
git add app/Platform/Enrichment/EnrichmentPipeline.php tests/Feature/Enrichment/VisualMatchPipelineWiringTest.php
git commit -m "feat(enrichment): wire visual_match stage between keyframes and text_signals"
```

---

---

### Task 20: buildEvidence visual gate rework + end-to-end classifier proof

**Files:**
- Modify: `app/Platform/Enrichment/Attribution/AttributionService.php` (replace the whole `buildEvidence` method, lines 202–274 — from `private function buildEvidence(ContentItem|Story $target): EvidenceBundle` through the closing brace after `productDoctrine: $enabled,` / `);`; all needed enum imports already exist at lines 16–21)
- Test: `tests/Feature/Enrichment/VisualEvidenceGateTest.php` (create)

**Interfaces:**
- Consumes: `RecognitionType::VisualProduct` (T2); config `qds.enrichment.visual_match.enabled` (T19); existing `EvidenceBundle`, `MentionClassifier`, `SeededContentLinker` (all unchanged).
- Produces: the reworked evidence semantics every later task relies on — VISUAL_PRODUCT rows excluded entirely when the C switch is off (rollback no-op); VISUAL_PRODUCT `productId`/`product` withheld when the row is Low/Unknown AND AiAssessed (visual precision gate); text-family product fields still keyed on the A switch; `EvidenceBundle::productDoctrine = textEnabled || visualEnabled`. Task 22's backfill re-runs attribution through exactly this path.

- [ ] **Step 1: Write the failing test.** Create `tests/Feature/Enrichment/VisualEvidenceGateTest.php`:

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
use App\Platform\Enrichment\Attribution\EvidenceBundle;
use App\Platform\Enrichment\Matching\SeededContentLinker;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class VisualEvidenceGateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ContentItem, 1: Product, 2: Shipment} wired creator/content + in-window shipment */
    private function wired(): array
    {
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

        $brand = Brand::factory()->create(['name' => 'Glossier']);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'You Perfume']);
        $seeding = SeedingCampaign::factory()->create(['brand_id' => $brand->id, 'campaign_id' => $campaign->id]);
        $shipment = Shipment::factory()->create([
            'seeding_campaign_id' => $seeding->id,
            'creator_id' => $creator->id,
            'product_id' => $product->id,
            'shipped_at' => CarbonImmutable::parse('2026-06-01 10:00:00'),
            'delivered_at' => CarbonImmutable::parse('2026-06-03 10:00:00'),
        ]);

        return [$content, $product, $shipment];
    }

    private function visualDetection(ContentItem $content, Product $product, ConfidenceLevel $level, VerificationStatus $status = VerificationStatus::AiAssessed): RecognitionDetection
    {
        return RecognitionDetection::factory()->create([
            'content_item_id' => $content->id,
            'recognition_type' => RecognitionType::VisualProduct,
            'provider_label' => 'visual-product:'.$product->id,
            'detected_brand' => 'Glossier',
            'detected_product' => $product->name,
            'product_id' => $product->id,
            'assessment' => new ConfidenceAssessment(
                'Glossier',
                $level,
                ['visual-product-match:'.$product->name, 'embedding-model:gemini-embedding-2'],
                $status,
            ),
        ]);
    }

    private function evidenceFor(ContentItem $content): EvidenceBundle
    {
        return (new ReflectionMethod(AttributionService::class, 'buildEvidence'))
            ->invoke(app(AttributionService::class), $content);
    }

    public function test_auto_visual_match_drives_high_seeded_without_text_signals(): void
    {
        // Visual-only mode: A's switch OFF, C's switch ON — the doctrine OR.
        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => true]);

        [$content, $product] = $this->wired();
        $this->visualDetection($content, $product, ConfidenceLevel::High);

        // The product id flows into evidence (visual precision gate passes).
        $this->assertSame($product->id, $this->evidenceFor($content)->recognitions[0]['productId']);

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        $this->assertSame(ConfidenceLevel::High, $mention->classification->confidenceLevel);
        $this->assertNotContains('product-unconfirmed', $mention->classification->signals);
    }

    public function test_review_visual_match_caps_at_medium_product_unconfirmed_and_never_auto_links(): void
    {
        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => true]);

        [$content, $product, $shipment] = $this->wired();
        $this->visualDetection($content, $product, ConfidenceLevel::Low);

        // The REVIEW-band product id is withheld from evidence entirely.
        $this->assertNull($this->evidenceFor($content)->recognitions[0]['productId']);

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        $this->assertSame(ConfidenceLevel::Medium, $mention->classification->confidenceLevel);
        $this->assertContains('product-unconfirmed', $mention->classification->signals);

        // The §2.4 trap stays closed: guarded mentions never auto-link.
        $summary = app(SeededContentLinker::class)->run();
        $this->assertSame(0, $summary->linked);
        $this->assertDatabaseMissing('shipment_resulting_content', ['shipment_id' => $shipment->id]);
        $this->assertNull($mention->refresh()->campaign_id);
    }

    public function test_human_approved_low_detection_unlocks_product_flow_and_auto_link(): void
    {
        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => true]);

        [$content, $product, $shipment] = $this->wired();
        $this->visualDetection($content, $product, ConfidenceLevel::Low, VerificationStatus::HumanReviewed);

        // Human-blessed: the gate re-opens and product_id flows.
        $this->assertSame($product->id, $this->evidenceFor($content)->recognitions[0]['productId']);

        app(AttributionService::class)->enrich($content);

        $mention = Mention::query()->where('content_item_id', $content->id)->firstOrFail();
        $this->assertSame(MentionType::Seeded, $mention->mention_type);
        // A LOW recognition stays weak relevance → MEDIUM, but product-level
        // aligned and NOT flagged — auto-link eligible again.
        $this->assertSame(ConfidenceLevel::Medium, $mention->classification->confidenceLevel);
        $this->assertNotContains('product-unconfirmed', $mention->classification->signals);

        $summary = app(SeededContentLinker::class)->run();
        $this->assertSame(1, $summary->linked);
        $this->assertDatabaseHas('shipment_resulting_content', ['shipment_id' => $shipment->id]);
    }

    public function test_visual_rows_are_inert_when_the_switch_is_off(): void
    {
        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => false]);

        [$content, $product] = $this->wired();
        $this->visualDetection($content, $product, ConfidenceLevel::High);

        $mentions = app(AttributionService::class)->enrich($content);

        // Rollback no-op: no visual evidence, no other signal — nothing to
        // classify, exactly as pre-C behaviour.
        $this->assertSame([], $mentions);
        $this->assertSame(0, Mention::query()->count());
    }

    public function test_evidence_is_byte_identical_when_the_switch_is_off(): void
    {
        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => false]);

        [$content, $product] = $this->wired();

        $before = serialize($this->evidenceFor($content));

        $this->visualDetection($content, $product, ConfidenceLevel::High);

        $this->assertSame($before, serialize($this->evidenceFor($content)));

        // Sanity: the same comparison DOES change once the switch is on —
        // the byte-identity above is the gate's doing, not test blindness.
        config(['qds.enrichment.visual_match.enabled' => true]);
        $this->assertNotSame($before, serialize($this->evidenceFor($content)));
    }

    public function test_switch_off_excludes_visual_rows_even_when_text_signals_are_on(): void
    {
        config(['qds.enrichment.text_signals.enabled' => true, 'qds.enrichment.visual_match.enabled' => false]);

        [$content, $product] = $this->wired();
        $before = serialize($this->evidenceFor($content));

        $this->visualDetection($content, $product, ConfidenceLevel::High);

        $this->assertSame($before, serialize($this->evidenceFor($content)));
    }

    public function test_product_doctrine_is_the_or_of_both_switches(): void
    {
        [$content] = $this->wired();

        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => false]);
        $this->assertFalse($this->evidenceFor($content)->productDoctrine);

        config(['qds.enrichment.text_signals.enabled' => true, 'qds.enrichment.visual_match.enabled' => false]);
        $this->assertTrue($this->evidenceFor($content)->productDoctrine);

        config(['qds.enrichment.text_signals.enabled' => false, 'qds.enrichment.visual_match.enabled' => true]);
        $this->assertTrue($this->evidenceFor($content)->productDoctrine);
    }
}
```

- [ ] **Step 2: Run it** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualEvidenceGateTest`
Expected: FAIL — `test_review_visual_match…` (no `product-unconfirmed`, product id not withheld), `test_visual_rows_are_inert…` (a mention IS created), `test_evidence_is_byte_identical…` (bundles differ), `test_product_doctrine…` (visual-on case false). The AUTO/unlock tests fail on their reflection asserts (`productId` is null with the A switch off).

- [ ] **Step 3: Implement the gate rework.** In `app/Platform/Enrichment/Attribution/AttributionService.php`, replace the entire `buildEvidence` method (lines 202–274) with:

```php
    private function buildEvidence(ContentItem|Story $target): EvidenceBundle
    {
        // Two kill switches. A (text_signals, sub-project A) gates the
        // text-family product evidence, the LOGO precision gate, the paid
        // label and the contextual cues exactly as before. C (visual_match,
        // sub-project C) gates VISUAL_PRODUCT evidence. EITHER switch alone
        // enables the product-aware SEEDED doctrine; both off reproduces
        // the legacy brand-level behaviour byte-identically.
        $textEnabled = (bool) config('qds.enrichment.text_signals.enabled');
        $visualEnabled = (bool) config('qds.enrichment.visual_match.enabled');

        $recognitions = [];

        $detectionQuery = RecognitionDetection::query();
        $detectionQuery = $target instanceof ContentItem
            ? $detectionQuery->where('content_item_id', $target->id)
            : $detectionQuery->where('story_id', $target->id);

        foreach ($detectionQuery->get() as $detection) {
            $assessment = $detection->assessment;
            $isVisual = $detection->recognition_type === RecognitionType::VisualProduct;

            // Rollback no-op (sub-project C): with the switch off,
            // VISUAL_PRODUCT rows are excluded from evidence ENTIRELY.
            if ($isVisual && ! $visualEnabled) {
                continue;
            }

            // Human-rejected detections carry no evidential weight.
            if ($assessment->value === null || in_array('human-rejected', $assessment->signals, true)) {
                continue;
            }

            if ($detection->detected_brand === null) {
                continue;
            }

            // Precision gate: an UNMATCHED logo (brand not in the lexicon) or a
            // low-confidence logo carries no attribution relevance. Gated
            // behind the A kill switch — OFF reproduces the legacy behaviour
            // where such detections still carried evidential weight.
            if ($textEnabled
                && $detection->recognition_type === RecognitionType::Logo
                && (in_array('brand-lexicon:unmatched', $assessment->signals, true)
                    || $assessment->confidenceLevel === ConfidenceLevel::Low
                    || $assessment->confidenceLevel === ConfidenceLevel::Unknown)) {
                continue;
            }

            // Visual precision gate (closes the §2.4 trap): a REVIEW-band
            // VISUAL_PRODUCT row (LOW/UNKNOWN, still AI-assessed) flows its
            // BRAND but withholds the product id — the classifier then caps
            // the mention at SEEDED/MEDIUM + product-unconfirmed (held for
            // review, never auto-linked) instead of silently auto-linking on
            // one isolated visual hit. A human approving the detection
            // (HUMAN_REVIEWED/…) re-opens the gate on the next run.
            // Text-family rows flow product evidence only under A's switch
            // (unchanged: a stale/rolled-back productId must never align a
            // shipment on its own when A is off).
            $productFlows = $isVisual
                ? ! ($assessment->verificationStatus === VerificationStatus::AiAssessed
                    && in_array($assessment->confidenceLevel, [ConfidenceLevel::Low, ConfidenceLevel::Unknown], true))
                : $textEnabled;

            $recognitions[] = [
                'type' => $detection->recognition_type->value,
                'brand' => $detection->detected_brand,
                'level' => $assessment->confidenceLevel,
                'productId' => $productFlows ? $detection->product_id : null,
                'product' => $productFlows ? $detection->detected_product : null,
            ];
        }

        [$hashtagMatches, $ambiguous] = $target instanceof ContentItem
            ? $this->hashtagEvidence($target)
            : [[], []];

        return new EvidenceBundle(
            recognitions: $recognitions,
            hashtagMatches: $hashtagMatches,
            ambiguousHashtags: $ambiguous,
            shipments: $this->seedingEvidence->forTarget($target),
            paidPartnershipLabel: $textEnabled ? ($target instanceof ContentItem ? $target->branded_content_label : null) : false,
            contextualCues: $textEnabled && $target instanceof ContentItem
                ? app(ContextualCueDetector::class)->detect($target->caption)
                : [],
            publishedAt: $this->publicationDate($target),
            productDoctrine: $textEnabled || $visualEnabled,
        );
    }
```

No import changes are needed — `RecognitionType`, `ConfidenceLevel` and `VerificationStatus` are already imported (lines 16–21).

- [ ] **Step 4: Run again** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualEvidenceGateTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Guard the existing attribution behaviour** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter 'AttributionTest|AttributionProductEvidenceTest|ProductAwareDetectionTest|MentionClassifierTest|MentionClassifierProductTest|SeededContentLinkerTest|SeededContentLinkerProductGuardTest|TextSignalRecognizerTest|PerTenantShipmentWindowTest|ReviewWorkflowTest'`
Expected: PASS — with `visual_match.enabled` defaulting to false, every pre-C evidence path is byte-identical.

- [ ] **Step 6: Full suite** — `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit`
Expected: PASS (entire suite green).

- [ ] **Step 7: Commit**

```bash
git add app/Platform/Enrichment/Attribution/AttributionService.php tests/Feature/Enrichment/VisualEvidenceGateTest.php
git commit -m "feat(enrichment): buildEvidence visual gate - VISUAL_PRODUCT flow, precision gate, doctrine OR"
```

---

### Task 21: Ops AI-spend panel, plan-page embedding estimate, counter pruning

**Files:**
- Modify: `app/Modules/Monitoring/Livewire/Operations/OperationsDashboard.php` (use-block lines 3–17; the `render()` view-data array lines 54–90 — add one entry after `'alerts'`; `providerConfiguration()` lines 99–121; new private methods at the class end)
- Modify: `resources/views/livewire/monitoring/operations-dashboard.blade.php` (insert the panel between line 111 — the `</div>` closing the `lg:grid-cols-2` grid — and the provider-health comment at line 113)
- Modify: `app/Platform/Ingestion/Support/IngestionCostEstimator.php` (price constants lines 46–61; `perService()` gating variables lines 227–233 and the returned rows array — add one row before the closing `];` at line 331)
- Modify: `app/Platform/Ingestion/Console/PruneIngestionDataCommand.php` (whole file, 39 lines)
- Test: `tests/Feature/Monitoring/AiSpendPanelTest.php` (create)
- Test: `tests/Feature/Ingestion/MonitoringPlanTest.php` (append two methods after `test_the_per_service_sheet_splits_costs_per_creator`, line 189)
- Test: `tests/Feature/Ingestion/PruneIngestionDataTest.php` (create)

**Interfaces:**
- Consumes: `App\Platform\AiBudget\Models\AiUsageCounter` + config `qds.ai_budget.capabilities` (Task 8); `App\Modules\Monitoring\Models\VisualMatchRun` (TenantScoped) with columns `embedding_calls, cache_hits, frames_skipped_format, frames_skipped_quality, frames_deduped, candidates_checked, processing_ms, outcome, created_at` and its `VisualMatchRunFactory` (Task 14); `App\Shared\Enums\VisualMatchOutcome::SkippedBudget = 'skipped_budget'` (Task 14); `SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS = 'SRC-google-gemini-embeddings'` (Task 18); config `qds.enrichment.visual_match.enabled` (Task 19) and `services.google_embeddings.{credentials_path,project_id}` (Task 6); `IngestionCostEstimator::perService()` row shape `{service, detail, unit, monthly, per_creator, active, note}` (existing, lines 208–332).
- Produces: the operations AI-spend panel (view data key `aiSpend`); estimator constant `EMBEDDING_PER_IMAGE = 0.00012` and the `'Visual product matching (embeddings)'` per-service row (renders on `/monitoring/plan` through the existing data-driven table — no blade change needed there); `ai_usage_counters` pruning inside `qds:prune-ingestion-data` (retention = existing `qds.ingestion.telemetry_retention_days`, 90 d). Task 24 documents all three.

- [ ] **Step 1: Write the failing AI-spend panel test**

Create `tests/Feature/Monitoring/AiSpendPanelTest.php`:

```php
<?php

namespace Tests\Feature\Monitoring;

use App\Modules\Monitoring\Livewire\Operations\OperationsDashboard;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\AiBudget\Models\AiUsageCounter;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\VisualMatchOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec §10 — the operations AI-spend panel. ADR-0019 posture (pinned by
 * CrossTenantAlertTest): this dashboard is viewed by TENANT staff, so
 * only the viewer's own usage is itemized; platform figures are
 * anonymous aggregate totals and no other tenant is ever named.
 */
class AiSpendPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Admin));
    }

    public function test_panel_itemizes_own_usage_and_anonymous_platform_totals(): void
    {
        $today = CarbonImmutable::now()->toDateString();

        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $this->defaultTenant->id,
            'usage_date' => $today,
            'units' => 1234,
            'estimated_cost_micro_usd' => 1234 * 120, // $0.14808
            'posts_processed' => 10,
            'posts_skipped_budget' => 7,
            'posts_skipped_no_candidates' => 5,
        ]);

        $foreign = $this->makeTenant('Tenant B');
        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $foreign->id,
            'usage_date' => $today,
            'units' => 999999,
            'estimated_cost_micro_usd' => 999999 * 120,
            'posts_processed' => 50,
        ]);

        Livewire::test(OperationsDashboard::class)
            ->assertSee('AI spend')
            ->assertSee('embedding')
            ->assertSee('1,234')          // own units today
            ->assertSee('$0.15')          // own month spend
            ->assertSee('$0.0148')        // avg cost per processed post
            ->assertSee('1,001,233')      // platform total INCLUDES the foreign tenant…
            ->assertDontSee('999,999')    // …but its individual figure never renders
            ->assertDontSee('Tenant B');  // and no tenant is ever named
    }

    public function test_panel_aggregates_recent_visual_match_runs(): void
    {
        VisualMatchRun::factory()->create([
            'embedding_calls' => 9,
            'cache_hits' => 3,
            'frames_skipped_format' => 1,
            'frames_skipped_quality' => 2,
            'frames_deduped' => 3,
            'candidates_checked' => 4,
            'processing_ms' => 3000,
            'outcome' => VisualMatchOutcome::Matched,
        ]);
        VisualMatchRun::factory()->create([
            'embedding_calls' => 0,
            'cache_hits' => 0,
            'frames_skipped_format' => 0,
            'frames_skipped_quality' => 0,
            'frames_deduped' => 0,
            'candidates_checked' => 2,
            'processing_ms' => 1500,
            'outcome' => VisualMatchOutcome::SkippedBudget,
        ]);

        Livewire::test(OperationsDashboard::class)
            ->assertSee('Cache-hit rate')
            ->assertSee('25.0%')       // 3 cache hits of 12 embeddings needed
            ->assertSee('1 / 2 / 3')   // format / quality / dedup frame skips
            ->assertSee('Budget denials')
            ->assertSee('2,250 ms');   // average processing time
    }

    public function test_provider_table_marks_gemini_embeddings_configured(): void
    {
        config([
            'services.apify.token' => null,
            'services.youtube.api_key' => null,
            'services.google_vision.api_key' => null,
            'services.google_speech.api_key' => null,
            'services.google_video_intelligence.api_key' => null,
            'services.google_embeddings.credentials_path' => null,
            'services.google_embeddings.project_id' => null,
        ]);

        Livewire::test(OperationsDashboard::class)
            ->assertSee('SRC-google-gemini-embeddings')
            ->assertDontSee('credentials set');

        config([
            'services.google_embeddings.credentials_path' => storage_path('test-sa.json'),
            'services.google_embeddings.project_id' => 'qds-embeddings-test',
        ]);

        Livewire::test(OperationsDashboard::class)->assertSee('credentials set');
    }
}
```

- [ ] **Step 2: Run it to verify failure**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter AiSpendPanelTest`
Expected: FAIL — `assertSee('AI spend')` / `assertSee('Cache-hit rate')` find nothing (panel absent), and the third test fails on `assertDontSee('credentials set')` only if another provider is configured — with all keys nulled it fails on the second Livewire assertion (`credentials set` never rendered because `providerConfiguration()` has no gemini match arm and defaults to `false`).

- [ ] **Step 3: Implement the panel (component + blade + provider match arm)**

In `app/Modules/Monitoring/Livewire/Operations/OperationsDashboard.php`, add to the use-block:

```php
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\AiBudget\Models\AiUsageCounter;
use App\Shared\Enums\VisualMatchOutcome;
use Carbon\CarbonImmutable;
```

In `render()`, add after the `'alerts' => ...` entry (line 82–89) inside the view-data array:

```php
            // Spec §10 AI-spend panel: own-tenant usage + anonymous platform totals.
            'aiSpend' => $this->aiSpendPanel($tenantId),
```

In `providerConfiguration()` (lines 99–121): after the `$video = ...` line add

```php
        $embeddings = (string) config('services.google_embeddings.credentials_path') !== ''
            && (string) config('services.google_embeddings.project_id') !== '';
```

and add this match arm before `default => false,`:

```php
                $source === SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS => $embeddings,
```

Add at the end of the class:

```php
    /**
     * AI-spend + visual-match quality panel (spec §10). ADR-0019: this
     * dashboard is viewed by TENANT staff, so only the viewer's own usage
     * is itemized; platform figures are anonymous aggregates (same
     * posture as queue depth) — the spec's per-tenant "top spenders"
     * table is deliberately narrowed to own-tenant + platform totals,
     * because naming another tenant here would break the isolation
     * contract the cross-tenant alert tests pin down.
     *
     * @return array{capabilities: list<array<string, mixed>>, visual: array<string, mixed>|null}
     */
    private function aiSpendPanel(?int $tenantId): array
    {
        $today = CarbonImmutable::now()->toDateString();
        $monthStart = CarbonImmutable::now()->startOfMonth()->toDateString();

        $capabilities = [];

        foreach (array_keys((array) config('qds.ai_budget.capabilities')) as $capability) {
            // tenant_id 0 matches nothing: platform context itemizes nobody.
            $own = fn () => AiUsageCounter::query()
                ->where('capability', $capability)
                ->where('usage_date', '>=', $monthStart)
                ->where('tenant_id', $tenantId ?? 0);

            $global = fn () => AiUsageCounter::query()
                ->where('capability', $capability)
                ->where('usage_date', '>=', $monthStart);

            $ownMonthCostMicro = (int) $own()->sum('estimated_cost_micro_usd');
            $ownPostsProcessed = (int) $own()->sum('posts_processed');

            $capabilities[] = [
                'capability' => $capability,
                'own_today_units' => (int) $own()->where('usage_date', $today)->sum('units'),
                'own_month_units' => (int) $own()->sum('units'),
                'own_month_cost_usd' => $ownMonthCostMicro / 1_000_000,
                'own_skipped_budget' => (int) $own()->sum('posts_skipped_budget'),
                'own_skipped_no_candidates' => (int) $own()->sum('posts_skipped_no_candidates'),
                'avg_cost_per_post_usd' => $ownPostsProcessed > 0 ? $ownMonthCostMicro / $ownPostsProcessed / 1_000_000 : null,
                'global_today_units' => (int) $global()->where('usage_date', $today)->sum('units'),
                'global_month_units' => (int) $global()->sum('units'),
            ];
        }

        return ['capabilities' => $capabilities, 'visual' => $this->visualRunAggregates()];
    }

    /**
     * Quality/efficiency aggregates over the last 7 days of visual-match
     * runs. VisualMatchRun is TenantScoped, so this is the viewer's own
     * tenant automatically. Null when there are no recent runs.
     *
     * @return array<string, mixed>|null
     */
    private function visualRunAggregates(): ?array
    {
        $recent = fn () => VisualMatchRun::query()
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(7));

        $runs = (int) $recent()->count();

        if ($runs === 0) {
            return null;
        }

        $billed = (int) $recent()->sum('embedding_calls');
        $cacheHits = (int) $recent()->sum('cache_hits');

        return [
            'runs' => $runs,
            'embeddings_created' => $billed,
            'cache_hit_rate' => ($billed + $cacheHits) > 0 ? $cacheHits / ($billed + $cacheHits) : null,
            'skipped_format' => (int) $recent()->sum('frames_skipped_format'),
            'skipped_quality' => (int) $recent()->sum('frames_skipped_quality'),
            'deduped' => (int) $recent()->sum('frames_deduped'),
            'budget_denials' => (int) $recent()->where('outcome', VisualMatchOutcome::SkippedBudget->value)->count(),
            'avg_candidates' => round((float) $recent()->avg('candidates_checked'), 1),
            'avg_processing_ms' => (int) round((float) $recent()->avg('processing_ms')),
        ];
    }
```

In `resources/views/livewire/monitoring/operations-dashboard.blade.php`, insert between line 111 (`</div>` closing the two-column grid) and the provider-health comment (line 113):

```blade
    {{-- AI spend & visual-match quality (spec §10 — budget governance) --}}
    <div class="mt-4 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="font-semibold text-gray-800 dark:text-white/90">AI spend</h3>
        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
            Estimated from list prices — Google bills the truth. Platform totals are anonymous aggregates;
            only your own workspace's usage is itemized.
        </p>

        <div class="mt-3 overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-theme-xs uppercase tracking-wide text-gray-400 dark:border-gray-800 dark:text-gray-500">
                        <th scope="col" class="py-2 pr-4 font-medium">Capability</th>
                        <th scope="col" class="py-2 pr-4 text-right font-medium">Calls today</th>
                        <th scope="col" class="py-2 pr-4 text-right font-medium">Calls this month</th>
                        <th scope="col" class="py-2 pr-4 text-right font-medium">Est. spend (month)</th>
                        <th scope="col" class="py-2 pr-4 text-right font-medium">Skipped budget / no candidates</th>
                        <th scope="col" class="py-2 pr-4 text-right font-medium">Avg cost / post</th>
                        <th scope="col" class="py-2 text-right font-medium">Platform today / month</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($aiSpend['capabilities'] as $row)
                        <tr wire:key="ai-spend-{{ $row['capability'] }}">
                            <td class="py-3 pr-4 font-medium text-gray-800 dark:text-white/90">{{ $row['capability'] }}</td>
                            <td class="py-3 pr-4 text-right text-gray-600 dark:text-gray-300">{{ number_format($row['own_today_units']) }}</td>
                            <td class="py-3 pr-4 text-right text-gray-600 dark:text-gray-300">{{ number_format($row['own_month_units']) }}</td>
                            <td class="py-3 pr-4 text-right text-gray-600 dark:text-gray-300">${{ number_format($row['own_month_cost_usd'], 2) }}</td>
                            <td class="py-3 pr-4 text-right text-gray-600 dark:text-gray-300">{{ number_format($row['own_skipped_budget']) }} / {{ number_format($row['own_skipped_no_candidates']) }}</td>
                            <td class="py-3 pr-4 text-right text-gray-600 dark:text-gray-300">{{ $row['avg_cost_per_post_usd'] === null ? '—' : '$'.number_format($row['avg_cost_per_post_usd'], 4) }}</td>
                            <td class="py-3 text-right text-gray-600 dark:text-gray-300">{{ number_format($row['global_today_units']) }} / {{ number_format($row['global_month_units']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-3 text-sm text-gray-400">No AI capabilities configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($aiSpend['visual'] !== null)
            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800">
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">Cache-hit rate (7 d)</p>
                    <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white/90">
                        {{ $aiSpend['visual']['cache_hit_rate'] === null ? '—' : number_format($aiSpend['visual']['cache_hit_rate'] * 100, 1).'%' }}
                    </p>
                    <p class="text-theme-xs text-gray-400">{{ number_format($aiSpend['visual']['embeddings_created']) }} embeddings created</p>
                </div>
                <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800">
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">Frame skips (format / quality / dedup)</p>
                    <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white/90">
                        {{ number_format($aiSpend['visual']['skipped_format']) }} / {{ number_format($aiSpend['visual']['skipped_quality']) }} / {{ number_format($aiSpend['visual']['deduped']) }}
                    </p>
                </div>
                <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800">
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">Budget denials (7 d)</p>
                    <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white/90">{{ number_format($aiSpend['visual']['budget_denials']) }}</p>
                    <p class="text-theme-xs text-gray-400">of {{ number_format($aiSpend['visual']['runs']) }} runs</p>
                </div>
                <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800">
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">Avg candidates / processing</p>
                    <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white/90">
                        {{ $aiSpend['visual']['avg_candidates'] }} · {{ number_format($aiSpend['visual']['avg_processing_ms']) }} ms
                    </p>
                </div>
            </div>
        @else
            <p class="mt-3 text-theme-xs text-gray-400">No visual-match runs in the last 7 days.</p>
        @endif
    </div>
```

- [ ] **Step 4: Run to verify pass, then commit**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter AiSpendPanelTest`
Expected: OK (3 tests).

```bash
git add app/Modules/Monitoring/Livewire/Operations/OperationsDashboard.php resources/views/livewire/monitoring/operations-dashboard.blade.php tests/Feature/Monitoring/AiSpendPanelTest.php
git commit -m "feat(monitoring): operations AI-spend panel with visual-match quality aggregates"
```

- [ ] **Step 5: Write the failing plan-page estimate tests**

Append to `tests/Feature/Ingestion/MonitoringPlanTest.php` (after `test_the_per_service_sheet_splits_costs_per_creator`, line 189):

```php
    public function test_the_per_service_sheet_prices_visual_product_matching(): void
    {
        config([
            'qds.enrichment.enabled' => true,
            'qds.enrichment.sweep_batch' => 50,
            'qds.enrichment.visual_match.enabled' => false,
        ]);

        $settings = new CadenceSettings(new MonitoringPlanSetting([
            'baseline_content_interval_hours' => 84,
            'campaign_content_interval_hours' => 12,
            'stories_per_day' => 0,
            'profile_poll_interval_hours' => 168,
            'apify_plan' => 'STARTER',
        ]));

        $roster = [
            'ig_accounts' => 300,
            'tt_accounts' => 150,
            'campaign_ig' => 30,
            'campaign_tt' => 15,
            'story_active_ig' => 0,
        ];

        $estimator = app(IngestionCostEstimator::class);
        $estimate = $estimator->estimate($settings, $roster);
        $rows = collect($estimator->perService($settings, $roster, $estimate))->keyBy('service');

        // Sweep-capped volume: 6,000 items × 6 frames × $0.00012 = $4.32.
        $row = $rows['Visual product matching (embeddings)'];
        $this->assertSame(4.32, $row['monthly']);
        $this->assertSame(0.0096, $row['per_creator']); // ÷ 450 accounts
        $this->assertStringContainsString('$0.00012 per image', $row['unit']);

        // Kill switch off → visible but dimmed, priced for the decision.
        $this->assertFalse($row['active']);
        $this->assertStringContainsString('visual product matching is disabled', $row['note']);

        config(['qds.enrichment.visual_match.enabled' => true]);
        $rows = collect($estimator->perService($settings, $roster, $estimate))->keyBy('service');
        $this->assertTrue($rows['Visual product matching (embeddings)']['active']);
    }

    public function test_the_plan_page_shows_the_visual_matching_row(): void
    {
        $this->actingAs($this->makeUser(RoleName::Admin));

        $this->get('/monitoring/plan')
            ->assertOk()
            ->assertSee('Visual product matching (embeddings)');
    }
```

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter test_the_per_service_sheet_prices_visual_product_matching`
Expected: ERROR — undefined array key `'Visual product matching (embeddings)'` (no such row yet).

- [ ] **Step 6: Implement the estimator row**

In `app/Platform/Ingestion/Support/IngestionCostEstimator.php`: after `SPEECH_PER_MINUTE` (line 56) add

```php
    /**
     * Google Gemini Embedding 2 list price per image (USD) — verified
     * against official pricing 2026-07-19 (visual-matching spec §18):
     * $0.00012 per image, no output charge.
     */
    private const EMBEDDING_PER_IMAGE = 0.00012;
```

after `VIDEO_MINUTES_PER_ACCOUNT_MONTH` (line 61) add

```php
    /**
     * ESTIMATE: billable frame embeddings per enriched item — the
     * 12-frame budget minus typical quality-filter, dedup, and
     * keyframe-cache savings. Real spend shows on /monitoring/operations.
     */
    private const EMBEDDED_FRAMES_PER_ITEM = 6;
```

In `perService()`, after the `$videoMinutes = ...` line (line 233) add:

```php
        $visualMatchOn = $enrichmentOn && (bool) config('qds.enrichment.visual_match.enabled');
        $embeddedImages = $enrichedItems * self::EMBEDDED_FRAMES_PER_ITEM;
```

and add this row as the last element of the returned array (after the `'Spoken brand mentions'` entry, before the closing `];` at line 331):

```php
            [
                'service' => 'Visual product matching (embeddings)',
                'detail' => 'Gemini image embeddings compare video frames with product reference photos',
                'unit' => '$0.00012 per image embedded',
                'monthly' => round($embeddedImages * self::EMBEDDING_PER_IMAGE, 2),
                'per_creator' => $this->perAccount($embeddedImages * self::EMBEDDING_PER_IMAGE, $allAccounts),
                'active' => $visualMatchOn,
                'note' => match (true) {
                    $visualMatchOn => 'Billed by Google, not Apify — frame embeddings are cached, so real spend is usually lower.',
                    $enrichmentOn => 'Off — visual product matching is disabled (kill switch).',
                    default => 'Off — AI enrichment is disabled.',
                },
            ],
```

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter MonitoringPlanTest`
Expected: OK (10 tests) — the two new tests pass; the existing eight are untouched (the new row appends after every row they assert on).

```bash
git add app/Platform/Ingestion/Support/IngestionCostEstimator.php tests/Feature/Ingestion/MonitoringPlanTest.php
git commit -m "feat(monitoring): price visual product matching on the plan page"
```

- [ ] **Step 7: Write the failing counter-pruning test**

Create `tests/Feature/Ingestion/PruneIngestionDataTest.php`:

```php
<?php

namespace Tests\Feature\Ingestion;

use App\Platform\AiBudget\Models\AiUsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * qds:prune-ingestion-data — AI usage counters age out with the existing
 * telemetry retention window (spec §13: operational counters, no
 * personal data, pruned with telemetry — 90 d default).
 */
class PruneIngestionDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_ai_usage_counters_are_pruned_with_the_telemetry_window(): void
    {
        config(['qds.ingestion.telemetry_retention_days' => 90]);

        $tenantId = $this->defaultTenant->id;

        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $tenantId,
            'usage_date' => CarbonImmutable::now()->subDays(91)->toDateString(),
            'units' => 10,
            'estimated_cost_micro_usd' => 1200,
        ]);
        AiUsageCounter::query()->create([
            'capability' => 'embedding',
            'tenant_id' => $tenantId,
            'usage_date' => CarbonImmutable::now()->toDateString(),
            'units' => 5,
            'estimated_cost_micro_usd' => 600,
        ]);

        $this->artisan('qds:prune-ingestion-data')
            ->expectsOutputToContain('1 AI usage counter rows')
            ->assertExitCode(0);

        $this->assertSame(1, AiUsageCounter::query()->count());
        $this->assertSame(5, (int) AiUsageCounter::query()->value('units'));
    }
}
```

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter PruneIngestionDataTest`
Expected: FAIL — output contains `Pruned 0 response samples, 0 quarantined records, 0 provider calls.` without the counter segment, and both counter rows survive (count is 2).

- [ ] **Step 8: Implement counter pruning**

Replace `app/Platform/Ingestion/Console/PruneIngestionDataCommand.php` with:

```php
<?php

namespace App\Platform\Ingestion\Console;

use App\Platform\AiBudget\Models\AiUsageCounter;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Models\ProviderResponseSample;
use App\Platform\Ingestion\Models\QuarantinedRecord;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Retention enforcement for operational ingestion data (DP-005 retention
 * limits; response samples have a SHORT retention by design): expired
 * response samples and quarantined records are deleted, and provider-call
 * telemetry plus AI usage counters (spec §13 — operational counters, no
 * personal data) are pruned past the telemetry retention window.
 */
class PruneIngestionDataCommand extends Command
{
    protected $signature = 'qds:prune-ingestion-data';

    protected $description = 'Prune expired response samples, quarantined records, old provider-call telemetry, and AI usage counters';

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        $samples = ProviderResponseSample::query()->where('expires_at', '<=', $now)->delete();
        $quarantined = QuarantinedRecord::query()->where('expires_at', '<=', $now)->delete();

        $telemetryDays = max(1, (int) config('qds.ingestion.telemetry_retention_days'));
        $calls = ProviderCall::query()
            ->where('started_at', '<', $now->subDays($telemetryDays))
            ->delete();

        $counters = AiUsageCounter::query()
            ->where('usage_date', '<', $now->subDays($telemetryDays)->toDateString())
            ->delete();

        $this->info("Pruned {$samples} response samples, {$quarantined} quarantined records, {$calls} provider calls, {$counters} AI usage counter rows.");

        return self::SUCCESS;
    }
}
```

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter PruneIngestionDataTest`
Expected: OK (1 test).

- [ ] **Step 9: Run the task's related suite and commit**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter 'AiSpendPanelTest|MonitoringPlanTest|PruneIngestionDataTest|CrossTenantAlertTest'`
Expected: all green (3 + 10 + 1 + 5 tests) — the dashboard changes keep the pinned cross-tenant isolation behaviour.

```bash
git add app/Platform/Ingestion/Console/PruneIngestionDataCommand.php tests/Feature/Ingestion/PruneIngestionDataTest.php
git commit -m "feat(ingestion): prune ai_usage_counters with the telemetry retention window"
```

---

### Task 22: `qds:visual-match-backfill` — budget-respecting window sweep

**Files:**
- Create: `app/Platform/Enrichment/VisualMatch/Console/VisualMatchBackfillCommand.php`
- Modify: `app/Platform/PlatformServiceProvider.php` (imports block lines 1–42; `$this->commands([...])` array lines 103–118 as of main — Tasks 8/9 will have appended their command entries by now; add after the last VisualMatch entry)
- Test: `tests/Feature/Enrichment/VisualMatch/VisualMatchBackfillCommandTest.php` (create)

**Interfaces:**
- Consumes: `VisualProductMatcher::enrich(ContentItem|Story $target, string $correlationId): string` (Task 19; stage marker strings frozen in the contract); `AttributionService::enrich(ContentItem|Story $target): array` (existing, `app/Platform/Enrichment/Attribution/AttributionService.php:44` — note it returns `[]` when the creator has no `MonitoredSubject`); `TenantContext::runAs(Tenant|int|null $tenant, Closure $callback): mixed` (`app/Shared/Tenancy/TenantContext.php:96`); `EnrichmentRunStatus::Completed = 'COMPLETED'`; `ContentItem::keyframes()` / `Story::keyframes()` MorphMany + `enrichmentRuns()` HasMany (existing); for the end-to-end test: `EmbeddingProvider` interface (Task 7, container-rebindable), `VectorLiteral::fromArray(array $vector): string` (Task 1), `ProductReferencePhoto` model+factory (Task 4), `product_photo_embeddings` / `visual_match_runs` tables (Tasks 5/14), `ai_usage_counters` tables (Task 8).
- Produces: console command `qds:visual-match-backfill {--days=30} {--tenant=} {--dry-run}` (frozen signature). No later code task consumes it; Task 24 documents it.

Budget note (spec §14): the command adds NO budget logic of its own — every embed goes through the matcher's normal `AiBudgetGuard` path with normally computed priorities, so exhaustion surfaces as `skipped:budget-exhausted` markers in the tally, never as failures. Historic `EnrichmentRun` rows are never rewritten (append-only telemetry); attribution re-runs only for `completed:*` markers.

- [ ] **Step 1: Write the failing selection/gating tests**

Create `tests/Feature/Enrichment/VisualMatch/VisualMatchBackfillCommandTest.php`:

```php
<?php

namespace Tests\Feature\Enrichment\VisualMatch;

use App\Models\Tenant;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Models\EnrichmentRun;
use App\Platform\Enrichment\Support\EnrichmentRunStatus;
use App\Shared\Enums\KeyframeKind;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualMatchBackfillCommandTest`
Expected: 5 ERRORS — `The command "qds:visual-match-backfill" does not exist.`

- [ ] **Step 3: Implement the command + register it**

Create `app/Platform/Enrichment/VisualMatch/Console/VisualMatchBackfillCommand.php`:

```php
<?php

namespace App\Platform\Enrichment\VisualMatch\Console;

use App\Models\Tenant;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Platform\Enrichment\Support\EnrichmentRunStatus;
use App\Platform\Enrichment\VisualMatch\VisualProductMatcher;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Day-one rollout tool (sub-project C, spec §14): re-runs the visual_match
 * stage — and, when it completes, attribution — over recent posts that
 * ALREADY have keyframes and a completed enrichment run. Every embed goes
 * through the normal AiBudgetGuard with normally computed priorities, so a
 * backfill can never blow the budget: exhaustion surfaces as
 * skipped:budget-exhausted markers in the tally, never as failures.
 * Historic EnrichmentRun rows are never rewritten (append-only telemetry)
 * — backfilled evidence lives in visual_match_runs and the refreshed
 * Mention.
 */
class VisualMatchBackfillCommand extends Command
{
    protected $signature = 'qds:visual-match-backfill {--days=30} {--tenant=} {--dry-run}';

    protected $description = 'Re-run visual product matching (+ attribution) over recent posts that already have keyframes';

    private VisualProductMatcher $matcher;

    private AttributionService $attribution;

    private TenantContext $context;

    /** @var array<string, int> */
    private array $markers = [];

    private int $processed = 0;

    private int $attributionReruns = 0;

    public function handle(VisualProductMatcher $matcher, AttributionService $attribution, TenantContext $context): int
    {
        if (! (bool) config('qds.enrichment.visual_match.enabled')) {
            $this->warn('Visual matching is disabled (qds.enrichment.visual_match.enabled) — nothing to do.');

            return self::SUCCESS;
        }

        $this->matcher = $matcher;
        $this->attribution = $attribution;
        $this->context = $context;

        $days = max(1, (int) $this->option('days'));
        $since = CarbonImmutable::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');
        $correlationId = (string) Str::uuid();

        $tenantIds = $this->option('tenant') !== null
            ? [(int) $this->option('tenant')]
            : Tenant::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $this->info("Visual-match backfill over the last {$days} day(s) [correlation {$correlationId}].");

        foreach ($tenantIds as $tenantId) {
            $contentIds = $this->eligibleIds(ContentItem::class, 'published_at', $tenantId, $since);
            $storyIds = $this->eligibleIds(Story::class, 'captured_at', $tenantId, $since);

            if ($dryRun) {
                $this->line(sprintf(
                    'Tenant %d: would process %d content item(s), %d story(ies) [dry-run].',
                    $tenantId,
                    count($contentIds),
                    count($storyIds),
                ));

                continue;
            }

            $this->line(sprintf(
                'Tenant %d: %d content item(s), %d story(ies) eligible.',
                $tenantId,
                count($contentIds),
                count($storyIds),
            ));

            $this->process(ContentItem::class, $contentIds, $tenantId, $correlationId);
            $this->process(Story::class, $storyIds, $tenantId, $correlationId);
        }

        if ($dryRun) {
            $this->info('Dry run — nothing executed.');

            return self::SUCCESS;
        }

        ksort($this->markers);

        foreach ($this->markers as $marker => $count) {
            $this->line("  {$marker} ×{$count}");
        }

        $this->info("Backfill done: {$this->processed} target(s) processed, {$this->attributionReruns} attribution re-run(s).");

        return self::SUCCESS;
    }

    /**
     * Eligibility (spec §14): in the window, ≥ 1 keyframe, and a COMPLETED
     * enrichment run. The command runs tenant-less, so ownership is an
     * EXPLICIT tenant_id predicate on every table touched with global
     * scopes removed (the ADR-0025 command convention) — this also keeps
     * the query correct when a tenant context IS bound (tests).
     *
     * @param class-string<ContentItem|Story> $model
     * @return list<int>
     */
    private function eligibleIds(string $model, string $publishedColumn, int $tenantId, CarbonImmutable $since): array
    {
        return $model::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where($publishedColumn, '>=', $since)
            ->whereHas('keyframes', static function ($query) use ($tenantId): void {
                $query->withoutGlobalScopes()->where('keyframes.tenant_id', $tenantId);
            })
            ->whereHas('enrichmentRuns', static function ($query) use ($tenantId): void {
                $query->withoutGlobalScopes()
                    ->where('enrichment_runs.tenant_id', $tenantId)
                    ->where('status', EnrichmentRunStatus::Completed->value);
            })
            ->orderBy($publishedColumn)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param class-string<ContentItem|Story> $model
     * @param list<int> $ids
     */
    private function process(string $model, array $ids, int $tenantId, string $correlationId): void
    {
        foreach ($ids as $id) {
            /** @var string $marker */
            $marker = $this->context->runAs($tenantId, function () use ($model, $id, $correlationId): string {
                /** @var ContentItem|Story $target */
                $target = $model::query()->findOrFail($id);

                $marker = $this->matcher->enrich($target, $correlationId);

                if (str_starts_with($marker, 'completed:')) {
                    // Re-classify in the SAME tenant context so the fresh
                    // VISUAL_PRODUCT evidence lands on the Mention now, not
                    // at the next natural enrichment.
                    $this->attribution->enrich($target);
                    $this->attributionReruns++;
                }

                return $marker;
            });

            $this->markers[$marker] = ($this->markers[$marker] ?? 0) + 1;
            $this->processed++;
        }
    }
}
```

In `app/Platform/PlatformServiceProvider.php`: add the import `use App\Platform\Enrichment\VisualMatch\Console\VisualMatchBackfillCommand;` (alphabetical position in the imports block, lines 1–42) and add `VisualMatchBackfillCommand::class,` to the `$this->commands([...])` array (lines 103–118 on main; place it after the VisualMatch/AiBudget command entries added by Tasks 8/9).

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualMatchBackfillCommandTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Add the end-to-end test — a completed match re-runs attribution**

Append to `tests/Feature/Enrichment/VisualMatch/VisualMatchBackfillCommandTest.php` (add the imports `App\Modules\CRM\Models\Product`, `App\Modules\CRM\Models\ProductReferencePhoto`, `App\Modules\CRM\Models\SeedingCampaign`, `App\Modules\CRM\Models\Shipment`, `App\Modules\Monitoring\Models\MonitoredSubject`, `App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider`, `App\Platform\Enrichment\VisualMatch\Support\VectorLiteral`, `App\Shared\Enums\SeedingCampaignStatus`, `Illuminate\Support\Facades\DB`):

```php
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
```

- [ ] **Step 6: Run the full test class**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter VisualMatchBackfillCommandTest`
Expected: PASS (6 tests). This step verifies wiring only — no new production code. If the end-to-end test fails, the defect is in an earlier task's component (matcher gates, scorer, writer, evidence gate); fix it THERE, never by weakening this test.

- [ ] **Step 7: Commit**

```bash
git add app/Platform/Enrichment/VisualMatch/Console/VisualMatchBackfillCommand.php app/Platform/PlatformServiceProvider.php tests/Feature/Enrichment/VisualMatch/VisualMatchBackfillCommandTest.php
git commit -m "feat(enrichment): qds:visual-match-backfill — budget-guarded window sweep with dry-run"
```

---

---

### Task 23: `qds:eval-detection` visual extension — BandMapper-driven product-level scoring

**Files:**
- Modify: `app/Platform/Enrichment/Console/EvalDetectionCommand.php` (whole file, lines 1–70)
- Modify: `tests/Fixtures/eval/golden-set.json` (case 4 "Atelier Nord" lines 32–41; case 5 "Hearthside" lines 42–51; case 10 "Nexon Labs" lines 92–101)
- Test: `tests/Feature/Enrichment/EvalDetectionCommandTest.php` (existing, lines 1–28 — append four tests)

**Interfaces:**
- Consumes (all frozen in the contract): `BandMapper::map(array $scored, FramePreparationResult $prep): array` + `BandMapper::runOutcome(array $bandResults, FramePreparationResult $prep, CandidateSet $candidates): VisualMatchOutcome` (Task 17); `new CandidateScores(Candidate $candidate, array $frameScores)` + `new FrameScore(int $keyframeId, int $ordinal, ?int $timestampMs, float $similarity, int $photoId, int $representedFrames)` (Task 16); `new Candidate(int $productId, string $productLabel, string $brandName, ?SectorLabel $category, string $source, bool $shipmentInWindow, ?int $seedingCampaignId, ?CarbonImmutable $shipmentAnchorAt, ?int $shipmentAgeDays, bool $hasEmbeddedPhotos)` + `new CandidateSet(array $candidates, Priority $priority)` (Task 15); `new PreparedFrame(Keyframe $keyframe, string $bytes, string $mimeType, int $representedFrames, ?int $spanStartMs, ?int $spanEndMs)` + `new FramePreparationResult(array $frames, int $framesAvailable, int $skippedFormat, int $skippedQuality, int $deduped)` (Task 12); `Priority::High` (Task 8); `VisualMatchBand` / `VisualMatchOutcome` enums (Task 2 group); config `qds.enrichment.visual_match.thresholds` and `qds.ai_budget.capabilities.embedding.price_micro_usd_per_unit` (Tasks 19/8).
- Produces: the `visual` fixture-block schema (spec §15) and the visual metrics table — sub-project E's calibration surface. Text metrics and their table are byte-identical to today.

Key design point (spec §15): visual scoring runs the **real** `BandMapper` (resolved from the container, so `ThresholdResolver` and the config thresholds apply) over fixture vectors; similarity is plain cosine computed in PHP, **dimension-agnostic**, so fixtures use small (3–4 dim) vectors. No DB, no network, fully deterministic. Synthetic `Keyframe` instances are built in memory (`new Keyframe([...])`, id assigned, never saved).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Enrichment/EvalDetectionCommandTest.php` (keep the existing test unchanged):

```php
    public function test_visual_cases_score_product_level_metrics_through_the_real_band_mapper(): void
    {
        $path = base_path('tests/Fixtures/eval/visual-tiny.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            [
                'platform' => 'INSTAGRAM', 'caption' => '', 'mentions' => [], 'is_seeded' => false,
                'visual' => [
                    'candidates' => [[
                        'product' => 'Test Widget', 'brand' => 'Test Labs', 'category' => 'TECH',
                        'photo_vectors' => [[1, 0, 0]],
                        'source' => 'shipment', 'shipment_in_window' => true,
                    ]],
                    'frame_vectors' => [
                        ['t_ms' => 0, 'vec' => [1, 0, 0]],
                        ['t_ms' => 2000, 'vec' => [1, 0, 0]],
                    ],
                    'expected' => ['product' => 'Test Widget', 'band' => 'auto'],
                    'brief_appearance' => false,
                ],
            ],
            [
                'platform' => 'TIKTOK', 'caption' => '', 'mentions' => [], 'is_seeded' => false,
                'visual' => [
                    'candidates' => [[
                        'product' => 'Other Gadget', 'brand' => 'Other Co', 'category' => 'BEAUTY',
                        'photo_vectors' => [[0, 1, 0]],
                        'source' => 'roster', 'shipment_in_window' => false,
                    ]],
                    'frame_vectors' => [
                        ['t_ms' => 0, 'vec' => [0, 1, 0]],
                        ['t_ms' => 2000, 'vec' => [0, 1, 0]],
                    ],
                    // The label says nothing should match — the confident
                    // AUTO hit is a labelled false positive (BEAUTY).
                    'expected' => ['product' => null, 'band' => 'none'],
                    'brief_appearance' => false,
                ],
            ],
        ]));

        $this->artisan('qds:eval-detection', ['--fixture' => $path])
            ->expectsOutputToContain('product recall')
            ->expectsOutputToContain('0.500')       // product precision: 1 TP / (1 TP + 1 FP)
            ->expectsOutputToContain('BEAUTY=1')    // false positives by category
            ->expectsOutputToContain('auto=2')      // band distribution
            ->expectsOutputToContain('1/2')         // bands as expected
            ->expectsOutputToContain('$0.000240')   // 2 billable frames × $0.00012 per case
            ->assertExitCode(0);

        File::delete($path);
    }

    public function test_degraded_coverage_reports_inconclusive_and_missed_brief_appearance(): void
    {
        $path = base_path('tests/Fixtures/eval/visual-inconclusive.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([[
            'platform' => 'INSTAGRAM', 'caption' => '', 'mentions' => [], 'is_seeded' => false,
            'visual' => [
                'candidates' => [[
                    'product' => 'Ghost Lamp', 'category' => 'HOME_INTERIOR',
                    'photo_vectors' => [[1, 0, 0]],
                    'source' => 'shipment', 'shipment_in_window' => true,
                ]],
                'frame_vectors' => [],
                'frames_skipped_quality' => 2,
                'expected' => ['product' => null, 'band' => 'none'],
                'brief_appearance' => true,
            ],
        ]]));

        $this->artisan('qds:eval-detection', ['--fixture' => $path])
            ->expectsOutputToContain('inconclusive=1')       // NOT no_match: coverage was degraded (§8 split)
            ->expectsOutputToContain('1 of 1 brief case(s)') // the brief appearance was missed
            ->assertExitCode(0);

        File::delete($path);
    }

    public function test_fixtures_without_visual_blocks_print_no_visual_section(): void
    {
        $path = base_path('tests/Fixtures/eval/text-only.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([[
            'platform' => 'INSTAGRAM', 'caption' => 'plain day', 'mentions' => [], 'is_seeded' => false,
        ]]));

        $this->artisan('qds:eval-detection', ['--fixture' => $path])
            ->expectsOutputToContain('recall')
            ->doesntExpectOutputToContain('Visual product matching')
            ->assertExitCode(0);

        File::delete($path);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter EvalDetectionCommandTest`
Expected: existing test PASSES; the 3 new tests FAIL — `Output does not contain "product recall"` / `"inconclusive=1"` (the third fails only if the first two changed nothing; once implemented it guards the no-visual path).

- [ ] **Step 3: Implement — replace `app/Platform/Enrichment/Console/EvalDetectionCommand.php` with:**

```php
<?php

namespace App\Platform\Enrichment\Console;

use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\Recognition\BrandLexicon;
use App\Platform\Enrichment\TextSignals\ContextualCueDetector;
use App\Platform\Enrichment\TextSignals\MentionExtractor;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Platform\Enrichment\VisualMatch\Matching\BandMapper;
use App\Platform\Enrichment\VisualMatch\Matching\CandidateScores;
use App\Platform\Enrichment\VisualMatch\Matching\FrameScore;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\VisualMatchBand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Offline scorecard for seeded detection over a labelled golden set. Runs
 * the deterministic text-signal + classification path (no provider calls)
 * and prints recall/precision at brand and product level, per platform.
 * Cases may additionally carry a `visual` block (sub-project C, spec §15):
 * fixture photo/frame vectors scored through the REAL BandMapper — cosine
 * computed in PHP, dimension-agnostic, no DB and no network — reporting
 * product-level precision/recall, false positives by category, band
 * distribution, missed brief appearances, average margin, frame-skip
 * rate, and estimated embedding cost per case. The measurement baseline
 * for sub-projects D–E.
 */
class EvalDetectionCommand extends Command
{
    protected $signature = 'qds:eval-detection {--fixture=}';

    protected $description = 'Score seeded-product detection against a labelled golden set.';

    public function handle(BrandLexicon $lexicon, MentionExtractor $mentions, ContextualCueDetector $cues, BandMapper $bandMapper): int
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

        $this->scoreVisualCases($cases, $bandMapper);

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $cases */
    private function scoreVisualCases(array $cases, BandMapper $bandMapper): void
    {
        $visualCases = array_values(array_filter($cases, static fn (array $case): bool => isset($case['visual'])));

        if ($visualCases === []) {
            return;
        }

        $tp = $fp = $fn = 0;
        $bandsAsExpected = $missedBrief = $briefCases = 0;
        $skippedFrames = $availableFrames = $billableFrames = 0;
        /** @var array<string, int> $fpByCategory */
        $fpByCategory = [];
        /** @var array<string, int> $bandDistribution */
        $bandDistribution = [];
        /** @var list<float> $margins */
        $margins = [];

        foreach ($visualCases as $case) {
            /** @var array<string, mixed> $visual */
            $visual = $case['visual'];
            $frameVectors = array_values((array) ($visual['frame_vectors'] ?? []));
            $skippedFormat = (int) ($visual['frames_skipped_format'] ?? 0);
            $skippedQuality = (int) ($visual['frames_skipped_quality'] ?? 0);

            $prep = $this->prepFromFixture($frameVectors, $skippedFormat, $skippedQuality);
            [$candidates, $scored] = $this->scoreFixtureCandidates((array) ($visual['candidates'] ?? []), $frameVectors);

            $results = $bandMapper->map($scored, $prep);
            $outcome = $bandMapper->runOutcome($results, $prep, new CandidateSet($candidates, Priority::High));

            $top = null;

            foreach ($results as $result) {
                if ($result->band !== VisualMatchBand::Reject) {
                    $top = $result; // ranked best-first: first non-reject wins

                    break;
                }
            }

            $expected = (array) ($visual['expected'] ?? []);
            $expectedProduct = $expected['product'] ?? null;
            $expectedBand = (string) ($expected['band'] ?? 'none');
            $predictedProduct = $top?->candidate->productLabel;
            $predictedBand = $top?->band->value ?? 'none';

            if ($predictedProduct !== null && $predictedProduct === $expectedProduct) {
                $tp++;
            } elseif ($predictedProduct !== null) {
                $fp++;
                $category = $top->candidate->category?->value ?? 'default';
                $fpByCategory[$category] = ($fpByCategory[$category] ?? 0) + 1;

                if ($expectedProduct !== null) {
                    $fn++; // the WRONG product: both a false positive and a miss
                }
            } elseif ($expectedProduct !== null) {
                $fn++;
            }

            $bandKey = $top !== null ? $predictedBand : $outcome->value;
            $bandDistribution[$bandKey] = ($bandDistribution[$bandKey] ?? 0) + 1;

            if ($predictedBand === $expectedBand) {
                $bandsAsExpected++;
            }

            if ((bool) ($visual['brief_appearance'] ?? false)) {
                $briefCases++;

                if ($top === null) {
                    $missedBrief++;
                }
            }

            if ($top !== null && $top->marginToRunnerUp !== null) {
                $margins[] = $top->marginToRunnerUp;
            }

            $skippedFrames += $skippedFormat + $skippedQuality;
            $availableFrames += $prep->framesAvailable;
            $billableFrames += count($frameVectors);
        }

        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        ksort($fpByCategory);
        ksort($bandDistribution);

        $priceMicroUsd = (int) config('qds.ai_budget.capabilities.embedding.price_micro_usd_per_unit');
        $costPerCaseUsd = $billableFrames * $priceMicroUsd / count($visualCases) / 1_000_000;

        $this->newLine();
        $this->info('Visual product matching (real BandMapper over fixture vectors):');
        $this->table(['visual metric', 'value'], [
            ['visual cases', count($visualCases)],
            ['product true positives', $tp],
            ['product false positives', $fp],
            ['product false negatives', $fn],
            ['product recall', number_format($recall, 3)],
            ['product precision', number_format($precision, 3)],
            ['false positives by category', $fpByCategory === [] ? 'none' : $this->formatCounts($fpByCategory)],
            ['band distribution', $this->formatCounts($bandDistribution)],
            ['bands as expected', $bandsAsExpected.'/'.count($visualCases)],
            ['missed brief appearances', sprintf('%d of %d brief case(s)', $missedBrief, $briefCases)],
            ['avg margin (top candidate)', $margins === [] ? 'n/a' : number_format(array_sum($margins) / count($margins), 3)],
            ['frame skip rate', $availableFrames > 0 ? number_format($skippedFrames / $availableFrames, 3) : 'n/a'],
            ['est. embedding cost / case', '$'.number_format($costPerCaseUsd, 6)],
        ]);
    }

    /**
     * @param list<array{t_ms?: int|null, vec: list<float|int>, represented_frames?: int}> $frameVectors
     */
    private function prepFromFixture(array $frameVectors, int $skippedFormat, int $skippedQuality): FramePreparationResult
    {
        $frames = [];

        foreach ($frameVectors as $index => $frame) {
            $keyframe = new Keyframe(['ordinal' => $index, 'timestamp_ms' => $frame['t_ms'] ?? null]);
            $keyframe->id = $index + 1; // synthetic, never persisted — eval needs no DB

            $frames[] = new PreparedFrame(
                keyframe: $keyframe,
                bytes: '',
                mimeType: 'image/jpeg',
                representedFrames: (int) ($frame['represented_frames'] ?? 1),
                spanStartMs: $frame['t_ms'] ?? null,
                spanEndMs: $frame['t_ms'] ?? null,
            );
        }

        return new FramePreparationResult(
            frames: $frames,
            framesAvailable: count($frameVectors) + $skippedFormat + $skippedQuality,
            skippedFormat: $skippedFormat,
            skippedQuality: $skippedQuality,
            deduped: 0,
        );
    }

    /**
     * @param list<array<string, mixed>> $candidateSpecs
     * @param list<array{t_ms?: int|null, vec: list<float|int>, represented_frames?: int}> $frameVectors
     * @return array{0: list<Candidate>, 1: list<CandidateScores>}
     */
    private function scoreFixtureCandidates(array $candidateSpecs, array $frameVectors): array
    {
        $candidates = [];
        $scored = [];

        foreach (array_values($candidateSpecs) as $index => $spec) {
            $candidate = new Candidate(
                productId: $index + 1, // synthetic, stable within the case (tie-breaks stay deterministic)
                productLabel: (string) $spec['product'],
                brandName: (string) ($spec['brand'] ?? $spec['product']),
                category: isset($spec['category']) ? SectorLabel::from((string) $spec['category']) : null,
                source: (string) ($spec['source'] ?? 'shipment'),
                shipmentInWindow: (bool) ($spec['shipment_in_window'] ?? false),
                seedingCampaignId: null,
                shipmentAnchorAt: null,
                shipmentAgeDays: null,
                hasEmbeddedPhotos: true,
            );
            $candidates[] = $candidate;

            $frameScores = [];

            foreach ($frameVectors as $frameIndex => $frame) {
                $best = 0.0;
                $bestPhoto = 1;

                foreach (array_values((array) $spec['photo_vectors']) as $photoIndex => $photoVector) {
                    $similarity = $this->cosine((array) $frame['vec'], (array) $photoVector);

                    if ($photoIndex === 0 || $similarity > $best) {
                        $best = $similarity;
                        $bestPhoto = $photoIndex + 1;
                    }
                }

                $frameScores[] = new FrameScore(
                    keyframeId: $frameIndex + 1,
                    ordinal: $frameIndex,
                    timestampMs: $frame['t_ms'] ?? null,
                    similarity: $best,
                    photoId: $bestPhoto,
                    representedFrames: (int) ($frame['represented_frames'] ?? 1),
                );
            }

            $scored[] = new CandidateScores($candidate, $frameScores);
        }

        return [$candidates, $scored];
    }

    /**
     * Plain cosine similarity over fixture vectors — dimension-agnostic on
     * purpose so fixtures stay small. Production similarity lives in SQL
     * (FrameProductScorer); this mirrors pgvector's 1 - cosine distance.
     *
     * @param list<float|int> $a
     * @param list<float|int> $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = $normA = $normB = 0.0;

        foreach ($a as $index => $value) {
            $dot += (float) $value * (float) ($b[$index] ?? 0.0);
            $normA += (float) $value ** 2;
        }

        foreach ($b as $value) {
            $normB += (float) $value ** 2;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /** @param array<string, int> $counts */
    private function formatCounts(array $counts): string
    {
        return implode(' ', array_map(
            static fn (string $key, int $count): string => "{$key}={$count}",
            array_keys($counts),
            array_values($counts),
        ));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter EvalDetectionCommandTest`
Expected: PASS (4 tests — the pre-existing one plus the 3 new ones).

- [ ] **Step 5: Extend the bundled golden set with three `visual` blocks + a bundled-set test**

In `tests/Fixtures/eval/golden-set.json`, add a `"visual"` key to three EXISTING cases (adding keys never changes the text metrics — the text loop reads only caption/mentions/product_tags/is_seeded). Add a comma after each case's `"reason"` value, then the block. All vectors are 4-dim (cosine is dimension-agnostic).

Case 4 — "Atelier Nord" (lines 32–41), a visual TRUE NEGATIVE with clean coverage (`no_match`):

```json
        "visual": {
            "candidates": [
                {
                    "product": "Atelier Nord Jacket",
                    "brand": "Atelier Nord",
                    "category": "FASHION",
                    "photo_vectors": [[1, 0, 0, 0]],
                    "source": "roster",
                    "shipment_in_window": false
                }
            ],
            "frame_vectors": [
                {"t_ms": 2000, "vec": [0, 1, 0, 0]}
            ],
            "expected": {"product": null, "band": "none"},
            "brief_appearance": false,
            "reason": "The jacket on screen does not resemble the reference photos (similarity 0.0) — clean coverage, run outcome no_match: a correct visual true negative."
        }
```

Case 5 — "Hearthside" (lines 42–51), the caption-silent PR unboxing that VISUAL evidence now catches at REVIEW (one frame at similarity 0.60, inside [0.55, 0.65)):

```json
        "visual": {
            "candidates": [
                {
                    "product": "Hearthside Candle Set",
                    "brand": "Hearthside Living",
                    "category": "HOME_INTERIOR",
                    "photo_vectors": [[1, 0, 0, 0]],
                    "source": "shipment",
                    "shipment_in_window": true
                }
            ],
            "frame_vectors": [
                {"t_ms": 1000, "vec": [0.6, 0.8, 0, 0]},
                {"t_ms": 3000, "vec": [0, 0, 0, 1]}
            ],
            "expected": {"product": "Hearthside Candle Set", "band": "review"},
            "brief_appearance": true,
            "reason": "One frame lands in the review band [T_review, T_auto) — the lone-hit rule holds it for humans instead of auto-linking; the brief appearance is CAUGHT, not missed."
        }
```

Case 10 — "Nexon Labs" (lines 92–101), the spec §15 example: an AUTO match (two distinct-timestamp frames ≥ T_auto = 0.65; similarities 1.0 and ≈0.9998):

```json
        "visual": {
            "candidates": [
                {
                    "product": "Nexon Labs Headset",
                    "brand": "Nexon Labs",
                    "category": "TECH",
                    "photo_vectors": [[1, 0, 0, 0], [0.9, 0.1, 0, 0]],
                    "source": "shipment",
                    "shipment_in_window": true
                }
            ],
            "frame_vectors": [
                {"t_ms": 1500, "vec": [1, 0, 0, 0]},
                {"t_ms": 4500, "vec": [0.98, 0.02, 0, 0]},
                {"t_ms": 7500, "vec": [0, 0, 1, 0]}
            ],
            "expected": {"product": "Nexon Labs Headset", "band": "auto"},
            "brief_appearance": false,
            "reason": "The embargoed product is visually confirmed across two distinct timestamps — AUTO."
        }
```

Then append the bundled-set test to `tests/Feature/Enrichment/EvalDetectionCommandTest.php` (presence-only assertions so growing the set — sub-project E's job — never breaks it):

```php
    public function test_bundled_golden_set_scores_text_and_visual_sections(): void
    {
        $this->artisan('qds:eval-detection')
            ->expectsOutputToContain('recall')
            ->expectsOutputToContain('product recall')
            ->expectsOutputToContain('band distribution')
            ->assertExitCode(0);
    }
```

- [ ] **Step 6: Run the full test class**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --filter EvalDetectionCommandTest`
Expected: PASS (5 tests). Manual smoke (optional, needs the dev DB): `php artisan qds:eval-detection` — the text table is unchanged (baseline 0.714/0.833 over 10 cases) and the visual table shows: 3 visual cases, TP 2 / FP 0 / FN 0, recall 1.000, precision 1.000, band distribution `auto=1 no_match=1 review=1`, bands as expected `3/3`, missed brief `0 of 1 brief case(s)`, avg margin `n/a` (single-candidate cases), skip rate 0.000, cost `$0.000240`.

- [ ] **Step 7: Commit**

```bash
git add app/Platform/Enrichment/Console/EvalDetectionCommand.php tests/Fixtures/eval/golden-set.json tests/Feature/Enrichment/EvalDetectionCommandTest.php
git commit -m "feat(enrichment): qds:eval-detection scores visual fixtures through the real BandMapper"
```

---

---

### Task 24: Documentation — ADR-0029, deferred register, glossary, module docs, roadmap + final gate

**Files:**
- Modify: `docs/05-decisions/decision-log.md` (append after ADR-0028, which ends the file — lines 687–705)
- Modify: `docs/20-cross-cutting/01-deferred-register.md` (frontmatter `last_reviewed` line 14; summary table lines 36–49; append entries after DEF-011, which ends line 180; mermaid map lines 184–198)
- Modify: `docs/00-meta/03-glossary.md` (ENUM-RecognitionType section, lines 368–378)
- Modify: `docs/50-modules/seeded-product-detection.md` (§2 diagram lines 39–53; §3 add subsection after line 107; §10 table row line 267; §11 lines 282–296; §12 lines 300–317; §13 lines 321–341)
- Modify: `docs/50-modules/seeded-product-detection-roadmap.md` (status table lines 37–43; critical-path lines 45–46)

**Interfaces:** documentation only — no code, no tests. NOTE: `docs/50-modules/seeded-product-detection.md` and `docs/50-modules/seeded-product-detection-roadmap.md` are currently **untracked** in git (written during C's brainstorm); this task's commit adds them in full, including the amendments below. Steps are write → verify → commit (no TDD cycle).

- [ ] **Step 1: Append ADR-0029 to `docs/05-decisions/decision-log.md`** (exact in-file format — anchor, **Context.**, numbered **Decision.**, **Status.**, **Consequences.** — matching the ADR-0028 block that precedes it):

```markdown

<a id="adr-0029"></a>
## ADR-0029 — Visual product matching (sub-project C): Gemini embeddings, pgvector, AI budget governance

**Context.**

Detection was brand/name-based: a seeded product shown on camera with no legible brand text, tag, or spoken name was invisible (the documented §12 limit of `docs/50-modules/seeded-product-detection.md`). Sub-project B ([ADR-0028](#adr-0028)) gave every platform a persisted `KeyframeSet`. Sub-project C gives the detector eyes for the *product*: closed-set retrieval of a post's keyframes against the tenant's product reference photos. Spec: `docs/superpowers/specs/2026-07-19-visual-product-matching-design.md` (every external claim verified against official docs 2026-07-19, spec §18).

**Decision.**

1. **One provider addition (DP-006):** `SRC-google-gemini-embeddings` — Gemini Embedding 2 (`gemini-embedding-2`, GA 2026-04-22) via `POST …/locations/eu/publishers/google/models/gemini-embedding-2:embedContent` on the EU multi-region endpoint `aiplatform.eu.rep.googleapis.com` (the locked EU-residency choice; `global` carries no residency guarantee and is rejected). One call per image — multi-image inputs fuse into a single vector — at the published $0.00012/image. `embedContent` accepts **no API keys**, so this is the repo's first service-account machinery: `GoogleServiceAccountTokenProvider` runs the RS256 JWT-bearer flow via openssl (no new dependency), scope `cloud-platform`, cached bearer tokens; key material never appears in URLs, logs, or exceptions. Every payload passes `AiPayloadGuard`; every call runs through `ProviderCallRecorder` + `ProviderCircuitBreaker` under the new source id (breaker consulted BEFORE spending).
2. **pgvector on the app's Postgres (Neon)** stores the vectors: `vector(3072)` columns on `product_photo_embeddings` / `keyframe_embeddings`, keyed by `model_version` (a model upgrade is a re-embed backfill, never in-place mutation). The local docker image moves `postgres:17` → `pgvector/pgvector:pg17-bookworm` (verified drop-in, same volume); semantics pinned to Neon's pgvector **0.8.0**. Matching is a deterministic **exact scan** — similarity `1 - (embedding <=> query)`, no ANN index (candidate sets are double-digit; 3072 dims exceed the 2,000-dim `vector` index limit anyway); documented review trigger at ~50k embedding rows per tenant.
3. **Closed-set candidate scoping and honest bands.** Only products from the creator's in-window shipments (byte-identical window semantics to attribution, [ADR-0025](#adr-0025)) and ACTIVE/SHIPPING roster primaries are matched; an empty candidate set costs nothing. Bands AUTO/REVIEW/REJECT with **E-calibrated placeholder thresholds** (`qds.enrichment.visual_match.thresholds`); AUTO needs ≥ 2 distinct-timestamp supporting frames plus a runner-up margin; single-frame posts cap at REVIEW (never auto-accept one isolated match); run-level NO_MATCH ("looked properly, did not see it") is split from INCONCLUSIVE ("could not look properly" — unavailable ≠ false); `needs_verification` on `visual_match_runs` is sub-project D's pickup. Detections are `VISUAL_PRODUCT` rows (`provider_label = 'visual-product:<productId>'`, DP-004 upsert; REVIEW writes LOW → the human queue).
4. **The `buildEvidence` gate generalizes** (the only touch to attribution): `VISUAL_PRODUCT` rows are excluded from evidence entirely while the C switch (`qds.enrichment.visual_match.enabled`, default OFF) is off — rollback is byte-identical evidence; `productId` is withheld from LOW/UNKNOWN AI-assessed visual rows (REVIEW caps at `SEEDED`/`MEDIUM` `product-unconfirmed`, never auto-linked; human approval unlocks the product); `EvidenceBundle::productDoctrine = text_signals || visual_match`.
5. **AI budget governance is a platform subsystem** (`app/Platform/AiBudget/`, capability-keyed — `embedding` now, `vlm_verification` reserved for D): per-post / tenant-daily / tenant-monthly / global (+hard) dimensions with priority semantics (high ignores tenant soft caps, stops at global hard caps/read-only/breaker), atomic `ai_usage_counters` upserts, per-tenant `tenant_ai_quotas` overrides (`qds:ai-quota`), an emergency read-only mode (`qds:ai-read-only`), deduplicated threshold alerts (`AlertType::AiBudgetThreshold`, 50/80/95/100 %), an operations AI-spend panel, and a plan-page estimate row (`EMBEDDING_PER_IMAGE = 0.00012`). A budget denial is a skip marker, never a failed run.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)), 2026-07-19.

**Consequences.**

- "Product shown but never named" can finally reach `SEEDED` when a matching shipment exists; a visual match with no shipment stays `LIKELY_ORGANIC`. `RecognitionType` gains `VISUAL_PRODUCT` (glossary amended); the classifier needed zero changes.
- New tenant tables: `product_reference_photos` (managed from the `/crm/products` "Photos" modal, cap 8/product), `product_photo_embeddings`, `keyframe_embeddings` (DB `ON DELETE CASCADE` from `keyframes` keeps the GDPR eraser and the retention prune correct with no code change), and `visual_match_runs` / `visual_match_candidates` (append-only run evidence, erased with the creator).
- Rollout ships dark: `qds:embed-product-photos` embeds the catalog, `qds:visual-match-backfill` replays the recent window through the normal budget guard, and `qds:eval-detection` gains product-level visual metrics over fixture vectors (the E calibration surface).
- `DemoDataSeeder` randomizes over `RecognitionType::cases()` and will now fabricate `VISUAL_PRODUCT` demo rows — acceptable for demo data, noted.
- The pre-existing Vision/Speech clients still use global endpoints — recorded as an EU-residency follow-up ([DEF-020](../20-cross-cutting/01-deferred-register.md#def-020)). New deferred items DEF-012…DEF-020.
```

- [ ] **Step 2: Amend `docs/20-cross-cutting/01-deferred-register.md`**

(a) Frontmatter: change `last_reviewed: 2026-07-03` → `last_reviewed: 2026-07-19`.

(b) Append to the summary table (after the DEF-011 row, line 48):

```markdown
| [DEF-012](#def-012) | Denser keyframe re-extraction for visual matching | [ADR-0029](../05-decisions/decision-log.md#adr-0029) | — |
| [DEF-013](#def-013) | Long-video keyframe coverage beyond 12 frames | [ADR-0029](../05-decisions/decision-log.md#adr-0029) | — |
| [DEF-014](#def-014) | Product-level correction in the recognition review UI | [ADR-0029](../05-decisions/decision-log.md#adr-0029) | [DP-004](00-data-principles.md#dp-004) |
| [DEF-015](#def-015) | Human review re-triggering attribution | [ADR-0029](../05-decisions/decision-log.md#adr-0029) | — |
| [DEF-016](#def-016) | HEIC/HEIF reference-photo uploads (server-side transcoding) | [ADR-0029](../05-decisions/decision-log.md#adr-0029) | — |
| [DEF-017](#def-017) | Off-peak queue for low-priority AI work | [ADR-0029](../05-decisions/decision-log.md#adr-0029) | — |
| [DEF-018](#def-018) | Tenant offboarding data purge | [ADR-0029](../05-decisions/decision-log.md#adr-0029) | — |
| [DEF-019](#def-019) | Self-serve billing-plan AI-quota purchase | [ADR-0029](../05-decisions/decision-log.md#adr-0029) | — |
| [DEF-020](#def-020) | EU residency for the existing Vision/Speech clients | [ADR-0029](../05-decisions/decision-log.md#adr-0029) | — |
```

(c) Append the nine entries after the DEF-011 section (line 180), in the file's exact entry format:

```markdown
<a id="def-012"></a>
### DEF-012

**Denser keyframe re-extraction for visual matching.**

- **What is deferred.** Extracting additional or denser keyframes for a post whose visual match ended `no_match`/`inconclusive`, to give the matcher (or sub-project D's verifier) more frames to look at.
- **Why it is deferred.** [ADR-0028](../05-decisions/decision-log.md#adr-0028) extraction is once-only and the source video bytes are discarded (TikTok CDN URLs expire) — there is nothing left to re-sample. The prerequisites are B-contract work: an operator force re-extract command, scene-change sampling ([DEF-009](#def-009)), and keyframe lifecycle state ([DEF-010](#def-010)). A C-side shortcut around B's contract is explicitly rejected.
- **What v1 does instead.** The "dense pass" is matching over **all** stored frames (no subset), and a shipment with no visual match sets `needs_verification` on the run instead of pretending — that flag is exactly what sub-project D verifies.
- **What would be needed later.** B's re-extraction path (with DEF-009/DEF-010), then a frame-selection strategy pass in `VisualProductMatcher` (spec §17 designs the slot).
- **Linked decision.** [ADR-0029](../05-decisions/decision-log.md#adr-0029) (Status APPROVED).
- **UI behaviour.** Not user-facing; the run records `no_match`/`inconclusive` honestly (unavailable ≠ false).

<a id="def-013"></a>
### DEF-013

**Long-video keyframe coverage beyond 12 frames.**

- **What is deferred.** More than 12 sampled frames for long videos (and any adaptive or scene-change frame selection serving visual matching).
- **Why it is deferred.** Frame extraction is sub-project B's contract: B's sampling is already duration-adaptive (3–12 frames) and the ceiling is B's `max_frames` knob. [ADR-0029](../05-decisions/decision-log.md#adr-0029) forbids C-side extraction changes; C's `frame_budget` (default 12) is purely a cost guard.
- **What v1 does instead.** C matches every stored frame up to `frame_budget`; long-video coverage scales with B's duration-adaptive sampling.
- **What would be needed later.** Raising B's `max_frames` (config) and/or DEF-009 scene-change sampling — both through B's contract, never C-side.
- **Linked decision.** [ADR-0029](../05-decisions/decision-log.md#adr-0029) (Status APPROVED).
- **UI behaviour.** Not user-facing; coverage accounting on `visual_match_runs` records what was available vs processed.

<a id="def-014"></a>
### DEF-014

**Product-level correction in the recognition review UI.**

- **What is deferred.** Letting a reviewer correct a `VISUAL_PRODUCT` detection to a *different product*. The correction path is brand-only today.
- **Why it is deferred.** [ADR-0029](../05-decisions/decision-log.md#adr-0029) keeps the existing generic review queue (approve/reject already work for `VISUAL_PRODUCT` rows at LOW, and the signals trail — frames, similarities, thresholds — already renders); reject covers the v1 need.
- **What v1 does instead.** Approve (unlocks `product_id` into evidence per the §9 gate) or reject (nulls the value + `human-rejected`, honoured by `buildEvidence`).
- **What would be needed later.** A product picker in the review correction flow writing a DP-004-compliant human correction with the corrected `product_id`.
- **Linked decision.** [ADR-0029](../05-decisions/decision-log.md#adr-0029) (Status APPROVED).
- **UI behaviour.** The review queue offers approve/reject only for visual detections; no fake "correct product" affordance is shown.

<a id="def-015"></a>
### DEF-015

**Human review re-triggering attribution.**

- **What is deferred.** Automatically re-running attribution for an already-enriched post after a human approves/rejects one of its detections, so the mention reflects the decision immediately.
- **Why it is deferred.** Pre-existing behaviour for every detection kind, not new to C; the classification refresh has always waited for the next natural enrichment run. C documents rather than changes it.
- **What v1 does instead.** `qds:visual-match-backfill` (which re-runs visual matching AND attribution over a window, through the normal budget guard) is the operator remedy; the next natural enrichment also picks the decision up.
- **What would be needed later.** A review-action hook dispatching a targeted attribution-only re-run for the affected post.
- **Linked decision.** [ADR-0029](../05-decisions/decision-log.md#adr-0029) (Status APPROVED).
- **UI behaviour.** Not user-facing beyond timing: a human decision is visible on the detection immediately and on the mention after the next run/backfill.

<a id="def-016"></a>
### DEF-016

**HEIC/HEIF reference-photo uploads (server-side transcoding).**

- **What is deferred.** Accepting HEIC/HEIF product reference-photo uploads, which requires server-side transcoding to a browser-renderable format for the management UI.
- **Why it is deferred.** The embedding model officially accepts HEIC/HEIF (spec §18) — B's *keyframes* in those formats are embedded natively with no transcoding — but browsers cannot render HEIC in the photo-management grid, so [ADR-0029](../05-decisions/decision-log.md#adr-0029) restricts uploads to `jpg/jpeg/png/webp` rather than shipping a transcoder.
- **What v1 does instead.** Upload validation rejects HEIC with a clear message; keyframe-side `frames_skipped_format` remains only for unknown/undecodable content.
- **What would be needed later.** A transcode-on-upload step (e.g. imagick HEIC→JPEG) storing the derived render alongside (or instead of) the original.
- **Linked decision.** [ADR-0029](../05-decisions/decision-log.md#adr-0029) (Status APPROVED).
- **UI behaviour.** The upload control lists the accepted formats; a rejected HEIC upload shows a validation error, never a silent drop.

<a id="def-017"></a>
### DEF-017

**Off-peak queue for low-priority AI work.**

- **What is deferred.** A deferred/off-peak processing lane for low-priority visual work (posts with no candidate products, speculative re-scans).
- **Why it is deferred.** In C, "low priority" ≡ empty candidate set ⇒ the post is already skipped at zero cost — an off-peak lane has nothing to carry until sub-project D's open-set verifier gives low-priority work meaning.
- **What v1 does instead.** Priorities high/medium gate budget behaviour ([ADR-0029](../05-decisions/decision-log.md#adr-0029) §5); empty-candidate posts record `skipped:no-candidates`.
- **What would be needed later.** D's verifier plus a scheduled off-peak queue honouring the same `AiBudgetGuard`.
- **Linked decision.** [ADR-0029](../05-decisions/decision-log.md#adr-0029) (Status APPROVED).
- **UI behaviour.** Not user-facing.

<a id="def-018"></a>
### DEF-018

**Tenant offboarding data purge.**

- **What is deferred.** A complete purge path for a departing tenant's data — now including reference photos, embeddings, visual-match runs, and AI-usage counters.
- **Why it is deferred.** A pre-existing, documented platform gap (no tenant offboarding flow exists anywhere); C inherits and re-documents it rather than building a partial purge for its tables alone.
- **What v1 does instead.** Creator-level GDPR erasure covers personal data (visual-match runs/candidates and keyframe embeddings are erased with the creator); catalog data (photos + embeddings) lives with the product; counters prune with telemetry retention.
- **What would be needed later.** A tenant-offboarding workflow (rows + blobs across all modules, in FK order, with the append-only-gate precedent) authorized by its own ADR.
- **Linked decision.** [ADR-0029](../05-decisions/decision-log.md#adr-0029) (Status APPROVED).
- **UI behaviour.** Not user-facing in v1.

<a id="def-019"></a>
### DEF-019

**Self-serve billing-plan AI-quota purchase.**

- **What is deferred.** Buying higher AI budgets (embedding, later VLM) through the billing module / subscription plans.
- **Why it is deferred.** [ADR-0029](../05-decisions/decision-log.md#adr-0029) ships the enforcement hook (`tenant_ai_quotas`, NULL → config default) and an operator command; plan integration belongs to the billing module (ADR-0021 line of work), not to C.
- **What v1 does instead.** Operators set per-tenant quotas with `qds:ai-quota {tenant} {capability} --daily= --monthly=`; the operations dashboard shows usage.
- **What would be needed later.** Plan catalog entries mapping to quota rows plus a purchase/upgrade flow writing them.
- **Linked decision.** [ADR-0029](../05-decisions/decision-log.md#adr-0029) (Status APPROVED).
- **UI behaviour.** No self-serve purchase surface is shown; quota state is visible to staff on the operations dashboard.

<a id="def-020"></a>
### DEF-020

**EU residency for the existing Vision/Speech clients.**

- **What is deferred.** Moving the pre-existing Google Cloud Vision / Video Intelligence / Speech clients from global endpoints to EU-resident endpoints.
- **Why it is deferred.** Pre-existing posture, out of C's scope; C's own provider ([ADR-0029](../05-decisions/decision-log.md#adr-0029)) uses the EU multi-region endpoint from day one, which surfaced the inconsistency.
- **What v1 does instead.** The recognition clients keep their current global endpoints; the gap is recorded here and in ADR-0029's consequences.
- **What would be needed later.** Per-service EU endpoint/location support verified against official docs, config plumbing, and a residency follow-up decision.
- **Linked decision.** [ADR-0029](../05-decisions/decision-log.md#adr-0029) (Status APPROVED).
- **UI behaviour.** Not user-facing.
```

(d) In the mermaid dependency map (lines 184–198), add before the closing fence:

```
  ADR0029["ADR-0029"] --> DEF012["DEF-012\nDenser re-extraction"]
  ADR0029 --> DEF013["DEF-013\n>12-frame coverage"]
  ADR0029 --> DEF014["DEF-014\nProduct-level review correction"]
  ADR0029 --> DEF015["DEF-015\nReview-triggered re-attribution"]
  ADR0029 --> DEF016["DEF-016\nHEIC photo uploads"]
  ADR0029 --> DEF017["DEF-017\nOff-peak low-priority queue"]
  ADR0029 --> DEF018["DEF-018\nTenant offboarding purge"]
  ADR0029 --> DEF019["DEF-019\nBilling quota purchase"]
  ADR0029 --> DEF020["DEF-020\nVision/Speech EU residency"]
  DEF009 --> DEF012
  DEF010 --> DEF012
```

and after the existing "DEF-003 cannot be delivered…" sentence add: `DEF-012 cannot be delivered before DEF-009/DEF-010 land on sub-project B's side — extraction is once-only and source bytes are discarded.`

- [ ] **Step 3: Amend the glossary.** In `docs/00-meta/03-glossary.md` (lines 369–377), replace the ENUM-RecognitionType table with (the three A-era values have been live in `App\Shared\Enums\RecognitionType` since sub-project A but were never added here — this is a catch-up plus the new C value):

```markdown
| Value | Meaning |
|---|---|
| `IMAGE_TEXT_OCR` | Text read from an image via OCR. |
| `LOGO` | Detected logo. |
| `SPOKEN_BRAND` | Brand name detected in audio/speech. |
| `ON_SCREEN_TEXT` | Text detected on-screen in video. |
| `CAPTION_TEXT` | Brand/product name found in the caption prose (sub-project A). |
| `MENTION` | An `@handle` in the caption resolved to a CRM brand (sub-project A). |
| `PRODUCT_TAG` | A structured platform product tag resolved to a CRM brand and product (sub-project A). |
| `VISUAL_PRODUCT` | A product visually matched against the tenant's reference photos via multimodal embeddings ([ADR-0029](../05-decisions/decision-log.md#adr-0029)); carries `product_id`. |
```

- [ ] **Step 4: Amend `docs/50-modules/seeded-product-detection.md`** (five edits, exact old → new):

(4a) §2 pipeline line (line 44) — replace
`   hashtags → recognition → text-signals → sentiment → SEEDED ATTRIBUTION → EMV → reach`
with
`   hashtags → transcript → recognition → keyframes → VISUAL MATCH → text-signals → sentiment → SEEDED ATTRIBUTION → EMV → reach`
and after the diagram's last descriptor line (line 49, `└─ HashtagEnricher: …`) add:
```
   (transcript + keyframes: sub-project B, ADR-0028 — YouTube captions text and persisted ffmpeg
   frames; VISUAL MATCH: sub-project C, ADR-0029 — keyframes vs reference-photo embeddings →
   VISUAL_PRODUCT detections, §3f; each of these stages is kill-switched)
```

(4b) §3 — insert a new subsection after §3e (line 107), before the `---`:

```markdown
### 3f. Visual product matching (sub-project C, `VisualProductMatcher`)
`App\Platform\Enrichment\VisualMatch\VisualProductMatcher` (gated by
`qds.enrichment.visual_match.enabled`, default OFF) matches B's stored keyframes against the
tenant's product **reference photos** (uploaded on `/crm/products`, embedded with Google multimodal
embeddings, stored in pgvector) and writes `recognition_detections` of type:

- `VISUAL_PRODUCT` — the product itself seen on screen (brand + product + `product_id`), at HIGH
  for the AUTO band (≥ 2 distinct-timestamp supporting frames above the category threshold plus a
  runner-up margin) or LOW for the REVIEW band (routes to human review; the §9 evidence gate
  withholds `product_id` until a human approves).

Candidates are scoped to the creator's plausible catalog only (in-window shipments + ACTIVE/SHIPPING
roster primaries — an empty candidate set costs nothing); every run and its ranked candidate scores
persist in `visual_match_runs` / `visual_match_candidates`, and `needs_verification` flags the
posts sub-project D's VLM verifier should look at (ADR-0029).
```

(4c) §10 table (line 267) — in the `recognition_detections` row, extend the CHECK list: `… CAPTION_TEXT / MENTION / PRODUCT_TAG / VISUAL_PRODUCT — DB CHECK constraint) …`

(4d) §11 — after the `qds.enrichment.text_signals.short_brand_allowlist` bullet add:

```markdown
- `qds.enrichment.visual_match.enabled` — the visual product-matching kill switch (sub-project C, ADR-0029, default off); `qds.enrichment.visual_match.*` carries model version, frame budget, photo cap, and the E-calibrated placeholder thresholds.
- `qds.ai_budget.*` — capability-keyed AI spend governance (capability `embedding`); emergency stop `qds:ai-read-only`, per-tenant overrides `qds:ai-quota`.
```

and append to the "Measuring quality" paragraph: `Cases may also carry a "visual" block (candidate photo vectors + frame vectors) scored through the real BandMapper — product-level precision/recall, false positives by category, band distribution, and estimated embedding cost per case.`

(4e) §12 — replace the first two bullets and the closing paragraph:

```markdown
- **Visual product recognition is closed-set embeddings only** — sub-project C (ADR-0029) matches
  keyframes against the tenant's *uploaded reference photos* for *candidate* products (in-window
  shipments + active roster). A product with no reference photos, or shown in a form the photos do
  not cover, is still missed; open-set **Gemini VLM** grounding is sub-project D
  (`needs_verification` on `visual_match_runs` is its pickup).
- **YouTube video files are not downloaded** (DEF-007) — YouTube's visual signal is the single
  Data-API thumbnail keyframe; TikTok and Instagram get real multi-frame coverage since
  sub-project B (ADR-0028), and every platform's frames feed §3f.
```

closing paragraph →

```markdown
Media resolution/keyframes (B, ADR-0028) and reference-photo embeddings (C, ADR-0029) have landed;
the forward plan (VLM grounding → confidence calibration) is tracked in
`docs/50-modules/seeded-product-detection-roadmap.md`. This document describes the **current**
behaviour; update it when D/E land.
```

(4f) §13 code map — add after the "Quality scorecard" row:

```markdown
| Visual product matching (C) | `app/Platform/Enrichment/VisualMatch/` — `VisualProductMatcher`, `CandidateScope`, `FrameProductScorer`, `BandMapper`, `VisualMatchWriter`, frame/photo embedders |
| AI budget governance | `app/Platform/AiBudget/` — `AiBudgetGuard`, `TenantQuotaResolver`, `qds:ai-read-only`, `qds:ai-quota` |
```

and in the closing "Related docs" sentence append `ADR-0028` (media/keyframes) and `ADR-0029` (visual matching) to the ADR list.

- [ ] **Step 5: Flip the roadmap.** In `docs/50-modules/seeded-product-detection-roadmap.md`:

Row B (line 40) → status cell: `✅ DONE — merged to `main` (merge `9c9ef89`, ADR-0028)` (the row was written before B merged and is stale).
Row C (line 41) → status cell: `✅ DONE — built on `feat/seeded-detection-visual-match` (spec `docs/superpowers/specs/2026-07-19-visual-product-matching-design.md`, ADR-0029)`.
Critical-path paragraph (lines 45–46) → `**Critical path to "see the product on screen":** B ✅ → C ✅ → D. D can start now (B and C have landed); E's calibration feeds on C's `visual_match_runs` history and the eval visual metrics.`

- [ ] **Step 6: Verify the docs**

- `grep -c 'adr-0029' docs/05-decisions/decision-log.md` ≥ 1; `grep -c 'def-01[2-9]\|def-020' docs/20-cross-cutting/01-deferred-register.md` covers all nine entries (anchor + table + map).
- `grep -n 'VISUAL_PRODUCT' docs/00-meta/03-glossary.md docs/50-modules/seeded-product-detection.md` — both hit.
- Eyeball the mermaid block for balanced quotes/brackets (it must still parse).
- Confirm no other doc restates the deferred list (register stays the single authority).

- [ ] **Step 7: FINAL GATE — run the FULL suite**

Run: `XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit`
Expected: green — 0 failures, 0 errors (the pre-C baseline was 1,356 tests; C's tasks added more). Also spot-check the kill switch: with `QDS_ENRICHMENT_VISUAL_MATCH_ENABLED=false` an enrichment run's stages show `visual_match → skipped:disabled` with zero provider calls and evidence byte-identical to today (Task 20's tests assert this — just confirm they ran).

- [ ] **Step 8: Commit**

```bash
git add docs/05-decisions/decision-log.md docs/20-cross-cutting/01-deferred-register.md docs/00-meta/03-glossary.md docs/50-modules/seeded-product-detection.md docs/50-modules/seeded-product-detection-roadmap.md
git commit -m "docs: ADR-0029 visual product matching; deferred register, glossary, module docs"
```
