<?php

namespace App\Modules\Monitoring\Models;

use App\Shared\Casts\AsValueObject;
use App\Shared\Enums\KeyframeKind;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\Provenance;
use Database\Factories\KeyframeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
 * @property string $checksum sha256 of the stored frame file
 * @property string $source_checksum sha256 of the source media it derives from
 * @property Provenance $provenance
 */
class Keyframe extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<KeyframeFactory> */
    use HasFactory;

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
        'source_checksum',
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
