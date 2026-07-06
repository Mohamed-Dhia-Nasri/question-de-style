<?php

namespace App\Modules\CRM\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ENT-Contact — contact + address for a Creator
 * (docs/30-data-model/00-data-model.md#ent-contact).
 *
 * Write-owner: Module 3 CRM (ownership matrix); no reader modules.
 * Manual entry ONLY (REQ-M3-002) — auto-extraction is DEF-002 and any UI
 * affordance for it renders "unavailable". GDPR (DP-005): rows are hard-
 * deletable; never add soft deletes or an append-only trigger here.
 *
 * @property int $id
 * @property int $creator_id
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $postal_address
 * @property string|null $preferred_channel
 */
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'email',
        'phone',
        'postal_address',
        'preferred_channel',
    ];

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }
}
