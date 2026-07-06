<?php

namespace App\Modules\CRM\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CommunicationLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ENT-CommunicationLog — a logged interaction with a creator
 * (docs/30-data-model/00-data-model.md#ent-communicationlog, REQ-M3-004).
 *
 * Write-owner: Module 3 CRM (ownership matrix); no reader modules.
 * Manual/internal entity — no Provenance envelope. `channel` and
 * `direction` are canonical string fields (no glossary enum → no closed
 * set is invented here).
 *
 * @property int $id
 * @property int $creator_id
 * @property int|null $campaign_id
 * @property string $channel
 * @property string $direction
 * @property string $summary
 * @property CarbonImmutable $occurred_at
 */
class CommunicationLog extends Model
{
    /** @use HasFactory<CommunicationLogFactory> */
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'campaign_id',
        'channel',
        'direction',
        'summary',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
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
