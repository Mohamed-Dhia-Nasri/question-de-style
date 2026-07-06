<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Shared\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ENT-Task — a task / deadline / follow-up
 * (docs/30-data-model/00-data-model.md#ent-task, REQ-M3-011).
 *
 * Write-owner: Module 3 CRM (ownership matrix); no reader modules.
 * Manual/internal entity — no Provenance envelope.
 *
 * @property int $id
 * @property string $title
 * @property TaskStatus $status
 * @property int|null $assignee_user_id
 * @property CarbonImmutable|null $due_at
 * @property int|null $creator_id
 * @property int|null $campaign_id
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'status',
        'assignee_user_id',
        'due_at',
        'creator_id',
        'campaign_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'due_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
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
