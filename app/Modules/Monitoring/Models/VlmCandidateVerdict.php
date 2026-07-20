<?php

namespace App\Modules\Monitoring\Models;

use App\Modules\CRM\Models\Product;
use App\Shared\Enums\VlmBand;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One per-candidate VLM verdict of one verification run (sub-project D,
 * spec §8.2) — sub-project E's "Gemini agreement" fusion input reads from
 * here. product_label / brand_label are denormalized so the audit survives
 * catalog edits — product delete nulls only product_id.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $vlm_verification_run_id
 * @property int|null $product_id
 * @property string $product_label
 * @property string $brand_label
 * @property int $rank
 * @property bool $visible
 * @property bool $spoken
 * @property bool $gifting_cue
 * @property float $confidence
 * @property list<int|null> $frame_timestamps validated ms offsets; null entries for unstamped frames
 * @property string $rationale
 * @property VlmBand|null $band
 * @property string|null $rejection_reason
 */
class VlmCandidateVerdict extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\VlmCandidateVerdictFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'vlm_verification_run_id',
        'product_id',
        'product_label',
        'brand_label',
        'rank',
        'visible',
        'spoken',
        'gifting_cue',
        'confidence',
        'frame_timestamps',
        'rationale',
        'band',
        'rejection_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'spoken' => 'boolean',
            'gifting_cue' => 'boolean',
            'confidence' => 'float',
            'frame_timestamps' => 'array',
            'band' => VlmBand::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<VlmVerificationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(VlmVerificationRun::class, 'vlm_verification_run_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
