<?php

namespace App\Modules\Monitoring\Models;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Shared\Tenancy\BelongsToTenant;
use Database\Factories\ProductPhotoEmbeddingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One 3072-dimension Gemini embedding of one product reference photo at one
 * model_version (sub-project C, spec §4.2). Immutable per key — replaced
 * photos and upgraded models get NEW rows, never in-place mutation.
 * `embedding` is the raw pgvector text literal ('[0.1,0.2,…]'): format with
 * VectorLiteral, compare in SQL with `1 - (embedding <=> ?)` (`<=>` is
 * cosine DISTANCE). Rows die with their photo via DB-level cascade.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $product_reference_photo_id
 * @property string $model_version
 * @property string $embedding pgvector text literal '[0.1,0.2,…]'
 * @property \Carbon\CarbonImmutable $created_at
 */
class ProductPhotoEmbedding extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProductPhotoEmbeddingFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'product_reference_photo_id',
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

    /** @return BelongsTo<ProductReferencePhoto, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(ProductReferencePhoto::class, 'product_reference_photo_id');
    }
}
