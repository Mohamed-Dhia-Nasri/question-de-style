<?php

namespace App\Modules\Monitoring\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Database\Factories\SpeechAudioChunkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One persisted extension chunk of an owner's audio (sub-project D, spec
 * §8.3) — a mono 16 kHz FLAC slice awaiting TranscribeExtendedAudioJob.
 * Ordinals are 1-based: chunk 0 is the in-pipeline sync pass and is never
 * persisted. The row and its blob are deleted after successful
 * transcription; the daily orphan prune and CreatorEraser are the
 * backstops (GDPR). Files live on the private media disk under
 * tenants/{tenant}/audio-chunks/….
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $owner_type
 * @property int $owner_id
 * @property int $ordinal 1-based extension-chunk position
 * @property int $offset_ms position of the chunk start in the source audio
 * @property int $duration_ms
 * @property string $storage_disk
 * @property string $storage_path
 * @property int $byte_size
 * @property string $checksum sha256 of the stored FLAC bytes
 * @property string $status pending | transcribed | failed
 */
class SpeechAudioChunk extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SpeechAudioChunkFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_TRANSCRIBED = 'transcribed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'ordinal',
        'offset_ms',
        'duration_ms',
        'storage_disk',
        'storage_path',
        'byte_size',
        'checksum',
        'status',
    ];

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
