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
