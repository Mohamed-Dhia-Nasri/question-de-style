<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Stripe webhook idempotency ledger (ADR-0021) — GLOBAL, append-only.
 *
 * One row per accepted Stripe event id; the unique index is the dedup
 * mechanism (see StripeWebhookController). Deliberately payload-free:
 * event ids and types only — never payment data. No update path exists
 * (the audit-log append-only pattern).
 *
 * @property int $id
 * @property string $stripe_event_id
 * @property string $type
 * @property Carbon $created_at
 */
class StripeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'stripe_event_id',
        'type',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
