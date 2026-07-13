<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Shared\Enums\RoleName;
use App\Shared\Tenancy\BelongsToTenant;
use Database\Factories\TeamInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ENT-TeamInvitation (ADR-0021) — a secure, tenant-bound team invitation:
 * docs/30-data-model/00-data-model.md#ent-teaminvitation. Write-owner:
 * Billing module.
 *
 * Tenant-owned. The plaintext token exists only in the invitation email —
 * this row stores its SHA-256 hash, so neither a DB read nor a log line can
 * be replayed into account creation. An invitation is pending until it is
 * accepted (single-use, consumed under lock inside the seat-reserving
 * transaction), revoked, or expires. Pending invitations deliberately do
 * NOT consume a seat (ADR-0021 seat model): availability is re-checked
 * atomically at acceptance instead of being reserved by unanswered emails.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $email
 * @property RoleName $role
 * @property string $token_hash
 * @property int $invited_by_user_id
 * @property int|null $accepted_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
class TeamInvitation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TeamInvitationFactory> */
    use HasFactory;

    /**
     * token_hash, the consumption stamps and the inviter are trusted server
     * state — force-filled, never mass assigned (the audit-log pattern).
     */
    protected $fillable = [
        'email',
        'role',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => RoleName::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    /** Deterministic token → hash mapping (the only lookup key). */
    public static function hashToken(string $plaintextToken): string
    {
        return hash('sha256', $plaintextToken);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /** Presentation-only state label (pending/accepted/revoked/expired). */
    public function statusLabel(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'Accepted',
            $this->revoked_at !== null => 'Revoked',
            $this->expires_at->isPast() => 'Expired',
            default => 'Pending',
        };
    }
}
