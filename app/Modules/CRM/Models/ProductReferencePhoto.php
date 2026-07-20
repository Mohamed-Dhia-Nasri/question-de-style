<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Shared\Enums\PhotoViewLabel;
use App\Shared\Tenancy\BelongsToTenant;
use Database\Factories\ProductReferencePhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ENT-ProductReferencePhoto — a tenant-uploaded catalog photo of a product
 * (visual matching sub-project C, spec §4.1). Min 1 to be matchable,
 * recommended 3–5 diverse views, hard cap 8 (enforced in the upload
 * component). Files live on the private media disk under
 * tenants/{tenant}/product-photos/{product}/…; blob deletion is app-managed
 * (rows cascade at the DB, files after commit). Tenant CATALOG data:
 * creator-GDPR erase never touches it; no retention sweep applies.
 *
 * Write-owner: Module 3 CRM (ownership matrix). Derived embeddings live in
 * Monitoring (ProductPhotoEmbedding) and FK here — this model deliberately
 * has no reverse relation so CRM never depends on Monitoring.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $product_id
 * @property string $storage_disk
 * @property string $storage_path
 * @property PhotoViewLabel|null $view_label
 * @property string $checksum sha256 of the stored bytes
 * @property int|null $width
 * @property int|null $height
 * @property int|null $uploaded_by
 */
class ProductReferencePhoto extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProductReferencePhotoFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'storage_disk',
        'storage_path',
        'view_label',
        'checksum',
        'width',
        'height',
        'uploaded_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'view_label' => PhotoViewLabel::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
