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
 * An UNAVAILABLE row is a negative cache: the provider confirmed this
 * video has no captions, so the fetch is never re-billed. Transport
 * failures persist nothing (retrying is correct there).
 *
 * Tenant-owned (ADR-0019); erased with the creator's content (GDPR).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $content_item_id
 * @property string $language BCP-47; 'und' when the provider names none
 * @property string $status available | unavailable
 * @property string|null $text null on unavailable rows
 * @property array<int, array<string, string>>|null $segments timestamped cues
 * @property string $provider SRC-* id
 * @property Provenance $provenance
 * @property string|null $checksum sha256 of text; null on unavailable rows
 * @property CarbonImmutable $fetched_at
 */
class ContentTranscript extends Model
{
    use BelongsToTenant;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'content_item_id',
        'language',
        'status',
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
