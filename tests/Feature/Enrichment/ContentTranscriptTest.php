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
            'status' => ContentTranscript::STATUS_AVAILABLE,
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
        $this->assertSame(ContentTranscript::STATUS_AVAILABLE, $row->status);
    }

    public function test_one_transcript_per_item_language_provider(): void
    {
        $item = $this->makeContentItem();
        ContentTranscript::query()->create($this->attributes($item));

        $this->expectException(UniqueConstraintViolationException::class);
        ContentTranscript::query()->create($this->attributes($item));
    }

    public function test_unavailable_negative_cache_row_needs_no_text(): void
    {
        $item = $this->makeContentItem();

        $row = ContentTranscript::query()->create([
            ...$this->attributes($item),
            'status' => ContentTranscript::STATUS_UNAVAILABLE,
            'text' => null,
            'segments' => null,
            'checksum' => null,
        ]);

        $this->assertSame(ContentTranscript::STATUS_UNAVAILABLE, $row->status);
        $this->assertNull($row->text);
    }
}
