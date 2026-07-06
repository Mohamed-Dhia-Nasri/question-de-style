<?php

namespace App\Modules\CRM\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DocumentAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ENT-DocumentAttachment — a stored document/attachment
 * (docs/30-data-model/00-data-model.md#ent-documentattachment, REQ-M3-010).
 *
 * Write-owner: Module 3 CRM (ownership matrix); no reader modules.
 * `creator_id` / `campaign_id` are both nullable per canonical shape —
 * either, both, or neither. `storage_url` references the stored blob;
 * upload/storage UI is later-step work.
 *
 * @property int $id
 * @property int|null $creator_id
 * @property int|null $campaign_id
 * @property string $file_name
 * @property string $storage_url
 * @property CarbonImmutable $uploaded_at
 */
class DocumentAttachment extends Model
{
    /** @use HasFactory<DocumentAttachmentFactory> */
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'campaign_id',
        'file_name',
        'storage_url',
        'uploaded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'uploaded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
