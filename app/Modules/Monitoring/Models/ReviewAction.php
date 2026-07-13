<?php

namespace App\Modules\Monitoring\Models;

use App\Models\User;
use App\Platform\Enrichment\Support\ReviewDecision;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One human review decision on one AI output (DP-004): the original AI
 * value snapshot, the decision, the correction payload, the reason, and the
 * reviewer identity. Append-only — the correction history is the record
 * that "corrections are stored and feed back into future rules"; it never
 * silently overwrites the provenance of the original judgement.
 *
 * actor_id snapshots the reviewer id so accountability survives GDPR user
 * deletion (same convention as audit_logs.context.actor_id).
 *
 * FLAGGED DEVIATION: not a canonical ENT-* — awaiting a data-model doc
 * amendment (see the create_enrichment_tables migration).
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $reviewable_type
 * @property int $reviewable_id
 * @property ReviewDecision $action
 * @property array<string, mixed> $original
 * @property array<string, mixed>|null $correction
 * @property string|null $reason
 * @property int|null $user_id
 * @property int $actor_id
 */
class ReviewAction extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'reviewable_type',
        'reviewable_id',
        'action',
        'original',
        'correction',
        'reason',
        'user_id',
        'actor_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => ReviewDecision::class,
            'original' => 'array',
            'correction' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('review_actions is append-only: corrections are stored, never rewritten (DP-004).');
        });

        static::deleting(function (): never {
            throw new LogicException('review_actions is append-only: corrections are stored, never rewritten (DP-004).');
        });
    }

    /** @return MorphTo<Model, $this> */
    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
