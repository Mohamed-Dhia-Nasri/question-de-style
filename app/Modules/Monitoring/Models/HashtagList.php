<?php

namespace App\Modules\Monitoring\Models;

use App\Models\User;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Platform\Enrichment\Support\HashtagScope;
use Database\Factories\HashtagListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A configured hashtag list entry — one hashtag registered for a campaign,
 * brand, product, or the agency itself. Used by SVC-EnrichmentAI hashtag
 * matching as ATTRIBUTION EVIDENCE ONLY: a hashtag may strengthen a
 * classification but never proves PAID/SEEDED alone (ADR-0008, DP-003).
 *
 * FLAGGED DEVIATION: not a canonical ENT-* — awaiting a data-model doc
 * amendment (see the create_enrichment_tables migration).
 *
 * @property int $id
 * @property HashtagScope $scope
 * @property int|null $campaign_id
 * @property int|null $brand_id
 * @property string|null $product_label
 * @property string $hashtag
 * @property string $normalized
 * @property bool $active
 * @property int|null $created_by
 */
class HashtagList extends Model
{
    /** @use HasFactory<HashtagListFactory> */
    use HasFactory;

    protected $fillable = [
        'scope',
        'campaign_id',
        'brand_id',
        'product_label',
        'hashtag',
        'normalized',
        'active',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => HashtagScope::class,
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
