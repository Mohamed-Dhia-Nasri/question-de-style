<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\SpeechAudioChunk;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Synthetic chunk rows (DP-005) — no blob is written. Default owner is a
 * fresh ContentItem; no morph map exists, so owner_type stores the FQCN.
 * Ordinals are faker-unique (≥ 1 — chunk 0 is never persisted) so
 * multiple chunks for ONE owner never collide on the
 * (owner_type, owner_id, ordinal) unique; pass an explicit ordinal (and
 * offset_ms) when a test needs deterministic positions.
 *
 * @extends Factory<SpeechAudioChunk>
 */
class SpeechAudioChunkFactory extends Factory
{
    use ResolvesTenant;

    protected $model = SpeechAudioChunk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ordinal = fake()->unique()->numberBetween(1, 9_999);

        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'owner_type' => ContentItem::class,
            'owner_id' => ContentItem::factory(),
            'ordinal' => $ordinal,
            // 55-second chunks (qds.enrichment.speech.chunk_seconds default).
            'offset_ms' => $ordinal * 55_000,
            'duration_ms' => 55_000,
            'storage_disk' => 'media',
            'storage_path' => 'tenants/test/audio-chunks/instagram/'.fake()->unique()->numberBetween(1, 999_999).'/'.$ordinal.'.flac',
            'byte_size' => 700_000,
            'checksum' => hash('sha256', fake()->uuid()),
            'status' => SpeechAudioChunk::STATUS_PENDING,
        ];
    }

    /** Attach the chunk to an existing owner (ContentItem or Story). */
    public function forOwner(Model $owner): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }

    /** A chunk the job already transcribed (blob deleted in real flows). */
    public function transcribed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SpeechAudioChunk::STATUS_TRANSCRIBED,
        ]);
    }
}
