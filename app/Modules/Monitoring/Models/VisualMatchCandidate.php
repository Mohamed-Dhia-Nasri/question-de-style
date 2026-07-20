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
