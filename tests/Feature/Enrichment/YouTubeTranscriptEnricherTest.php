<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ContentTranscript;
use App\Platform\Enrichment\Transcripts\YouTubeTranscriptEnricher;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesProviderResponses;
use Tests\TestCase;

class YouTubeTranscriptEnricherTest extends TestCase
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

    private function enrich(ContentItem $item): string
    {
        return app(YouTubeTranscriptEnricher::class)->enrich($item, 'corr-x', 0);
    }

    public function test_fetches_persists_available_row_and_records_telemetry(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.youtube_transcript'), $this->fixture('youtube-transcript'));
        $item = $this->makeYouTubeItem();

        $summary = $this->enrich($item);

        $this->assertSame('completed:fetched', $summary);
        $row = ContentTranscript::query()->where('content_item_id', $item->id)->firstOrFail();
        $this->assertSame(ContentTranscript::STATUS_AVAILABLE, $row->status);
        $this->assertSame('danke an Glossier für das PR Paket der You Perfume Duft ist unglaublich', $row->text);
        $this->assertSame('und', $row->language);
        $this->assertSame(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, $row->provider);
        $this->assertCount(2, $row->segments);
        $this->assertSame(1, ProviderCall::query()->where('source', SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT)->where('operation', 'transcript.fetch')->count());
    }

    public function test_available_row_short_circuits_without_actor_call(): void
    {
        $this->fakeProviderCredentials();
        Http::fake();
        $item = $this->makeYouTubeItem();
        ContentTranscript::query()->create([
            'content_item_id' => $item->id, 'language' => 'und',
            'status' => ContentTranscript::STATUS_AVAILABLE, 'text' => 'schon da',
            'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
            'provenance' => new Provenance(SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT, CarbonImmutable::now(), 'youtube-transcript-v1'),
            'checksum' => hash('sha256', 'schon da'), 'fetched_at' => CarbonImmutable::now(),
        ]);

        $this->assertSame('completed:cached', $this->enrich($item));
        Http::assertNothingSent();
    }

    public function test_no_captions_persists_the_negative_cache_and_never_rebills(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.youtube_transcript'), [['data' => []]]);
        $item = $this->makeYouTubeItem();

        $this->assertSame('skipped:no-captions', $this->enrich($item));
        $row = ContentTranscript::query()->where('content_item_id', $item->id)->firstOrFail();
        $this->assertSame(ContentTranscript::STATUS_UNAVAILABLE, $row->status);
        $this->assertNull($row->text);

        // Second run: negative cache — NO second actor call.
        Http::fake();
        $this->assertSame('skipped:no-captions', $this->enrich($item));
        Http::assertNothingSent();
    }

    public function test_provider_failure_persists_nothing_so_a_retry_can_refetch(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.youtube_transcript'), [], 500);
        $item = $this->makeYouTubeItem();

        $this->assertSame('skipped:provider-error', $this->enrich($item));
        $this->assertSame(0, ContentTranscript::query()->count());
        // Outcome value per the Task 0 CallOutcome audit (seam 1):
        $this->assertSame(1, ProviderCall::query()->where('source', SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT)->where('outcome', CallOutcome::Failure->value)->count());
    }

    public function test_concurrent_run_wins_the_persist_race_and_this_run_recovers_instead_of_crashing(): void
    {
        $this->fakeProviderCredentials();
        $this->fakeApifyActor((string) config('services.apify.actors.youtube_transcript'), $this->fixture('youtube-transcript'));
        $item = $this->makeYouTubeItem();

        // Deterministically reproduce the race WITHOUT any production
        // testability seam. A single shared connection can't do it: our own
        // save() runs inside a SAVEPOINT (withSavepointIfNeeded), so
        // anything we insert on the SAME connection during the 'saving'
        // hook gets erased by the ROLLBACK TO SAVEPOINT our own conflicting
        // insert triggers — that is not how a real concurrent process
        // behaves. A second, genuinely separate DB connection commits
        // independently, so it survives our rollback-to-savepoint exactly
        // like a real concurrent run would. The model's 'saving' event
        // fires right after enrich()'s own null-check has passed and right
        // before its INSERT — the same window a concurrent run's commit
        // would land in.
        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);

        ContentTranscript::saving(function (ContentTranscript $model) use ($item) {
            if ($model->exists || $model->content_item_id !== $item->id) {
                return;
            }

            // RefreshDatabase keeps the whole test's fixtures (tenant,
            // content item, ...) in ONE uncommitted transaction on the
            // primary connection, invisible to this second session — so its
            // insert would otherwise fail the tenant/content_item foreign
            // keys, not the unique key we're actually racing. Skip FK
            // enforcement for this session only (a standard bulk-load
            // technique); the unique index we ARE testing is unaffected.
            DB::connection('pgsql_race')->statement('SET session_replication_role = replica');
            DB::connection('pgsql_race')->table('content_transcripts')->insert([
                'tenant_id' => $item->tenant_id,
                'content_item_id' => $item->id,
                'language' => 'und',
                'status' => ContentTranscript::STATUS_AVAILABLE,
                'text' => 'winner text',
                'segments' => null,
                'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
                'provenance' => json_encode([
                    'source' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
                    'fetchedAt' => CarbonImmutable::now()->toIso8601String(),
                    'sourceVersion' => 'youtube-transcript-v1',
                ]),
                'checksum' => hash('sha256', 'winner text'),
                'fetched_at' => CarbonImmutable::now(),
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);
            DB::connection('pgsql_race')->disconnect();
        });

        $summary = $this->enrich($item);

        // The winner's row is AVAILABLE, so this run reports the winner's
        // outcome, not its own — and never crashes on the collision.
        $this->assertSame('completed:cached', $summary);
        $this->assertSame(1, ContentTranscript::query()->where('content_item_id', $item->id)->count());
        $row = ContentTranscript::query()->where('content_item_id', $item->id)->firstOrFail();
        $this->assertSame('winner text', $row->text);
        // Our own (losing) actor call still happened and must stay
        // telemetered — recordOperation runs before the recovered return.
        $this->assertSame(1, ProviderCall::query()->where('source', SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT)->where('operation', 'transcript.fetch')->count());
    }

    public function test_concurrent_winner_committed_before_our_select_is_never_overwritten(): void
    {
        $this->fakeProviderCredentials();
        $item = $this->makeYouTubeItem();

        // Deterministically reproduce the PRE-SELECT race window: unlike
        // the sibling test above (whose winner lands between our SELECT
        // and our INSERT, tripping UniqueConstraintViolationException),
        // here the winner is already committed BEFORE our own firstOrNew()
        // SELECT even runs — that SELECT happens right after runActor()
        // returns, so the winner is inserted from inside the HTTP fake's
        // response callback (a second, genuinely separate DB connection —
        // same reasoning as the sibling test: a same-connection insert
        // done this early would just be found by our own SELECT as a
        // normal pre-existing row, which is a different, already-covered
        // case; a truly independent connection is what makes this the
        // right regression test for the exists()-but-uncaught branch).
        //
        // The worst-case scenario this guards: our OWN actor response
        // (faked here as EMPTY captions) would, without the exists() guard,
        // fill()+save() the winner's row from AVAILABLE to UNAVAILABLE —
        // destroying a good transcript and salting the negative cache so
        // it can never be refetched. Assert that does NOT happen.
        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);

        $actorId = (string) config('services.apify.actors.youtube_transcript');
        Http::fake([
            "api.apify.com/v2/acts/{$actorId}/run-sync-get-dataset-items*" => function () use ($item) {
                DB::connection('pgsql_race')->statement('SET session_replication_role = replica');
                DB::connection('pgsql_race')->table('content_transcripts')->insert([
                    'tenant_id' => $item->tenant_id,
                    'content_item_id' => $item->id,
                    'language' => 'und',
                    'status' => ContentTranscript::STATUS_AVAILABLE,
                    'text' => 'winner text (pre-select)',
                    'segments' => null,
                    'provider' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
                    'provenance' => json_encode([
                        'source' => SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT,
                        'fetchedAt' => CarbonImmutable::now()->toIso8601String(),
                        'sourceVersion' => 'youtube-transcript-v1',
                    ]),
                    'checksum' => hash('sha256', 'winner text (pre-select)'),
                    'fetched_at' => CarbonImmutable::now(),
                    'created_at' => CarbonImmutable::now(),
                    'updated_at' => CarbonImmutable::now(),
                ]);
                DB::connection('pgsql_race')->disconnect();

                // OUR OWN redundant call comes back with NO captions — the
                // divergent-response worst case.
                return Http::response([['data' => []]]);
            },
        ]);

        $summary = $this->enrich($item);

        // The winner's AVAILABLE row must survive completely intact — not
        // flipped to unavailable, not nulled out, not double-counted.
        $this->assertSame('completed:cached', $summary);
        $this->assertSame(1, ContentTranscript::query()->where('content_item_id', $item->id)->count());
        $row = ContentTranscript::query()->where('content_item_id', $item->id)->firstOrFail();
        $this->assertSame(ContentTranscript::STATUS_AVAILABLE, $row->status);
        $this->assertSame('winner text (pre-select)', $row->text);
        // Our own (losing, redundant) actor call still happened and must
        // stay telemetered.
        $this->assertSame(1, ProviderCall::query()->where('source', SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT)->where('operation', 'transcript.fetch')->count());
    }

    public function test_kill_switch_off_and_non_youtube_targets_skip_cleanly(): void
    {
        Http::fake();
        $item = $this->makeYouTubeItem();

        config(['qds.ingestion.youtube_transcript.enabled' => false]);
        $this->assertSame('skipped:disabled', $this->enrich($item));

        config(['qds.ingestion.youtube_transcript.enabled' => true]);
        $account = PlatformAccount::factory()->for(Creator::factory())->create(['platform' => Platform::Instagram]);
        $insta = ContentItem::factory()->for($account, 'platformAccount')->create(['platform' => Platform::Instagram, 'content_type' => ContentType::Reel]);
        $this->assertSame('skipped:not-applicable', $this->enrich($insta));

        Http::assertNothingSent();
    }
}
