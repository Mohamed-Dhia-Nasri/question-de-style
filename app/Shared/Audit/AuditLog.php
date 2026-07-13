<?php

namespace App\Shared\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit event. Written via AuditLogger; never updated or edited.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * tenant_id and user_id are the trust-critical ownership/actor stamps and
     * are deliberately NOT mass assignable — AuditLogger force-fills them from
     * server state (TenantContext / Auth), so the audit trail's attribution
     * can never be forged through a request-derived array (ADR-0019).
     */
    protected $fillable = [
        'action',
        'subject_type',
        'subject_id',
        'context',
        'request_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
