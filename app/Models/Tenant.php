<?php

namespace App\Models;

use App\Modules\Billing\Models\TeamInvitation;
use App\Modules\Billing\Models\TenantSubscription;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ENT-Tenant (ADR-0019) — the customer account that owns all business data.
 *
 * A tenant is one subscribing customer organisation. Every user belongs to
 * exactly one tenant (users.tenant_id), and every tenant-owned business
 * record carries a tenant_id ownership key. The tenant's owner is a plain
 * attribute (owner_user_id) — NOT a role: ENUM-RoleName is a closed set and
 * the owner concept is a tenant-level fact, orthogonal to permission roles.
 *
 * The tenant is also the billable Stripe customer (ADR-0021): one tenant ↔
 * one Stripe customer (stripe_customer_id — NOT mass assignable, force-
 * filled once under a row lock, and the ONLY trusted webhook→tenant
 * mapping). Subscription state lives in ENT-TenantSubscription; individual
 * staff users are never separate Stripe customers.
 *
 * @property int $id
 * @property string $name
 * @property int|null $owner_user_id
 * @property string|null $stripe_customer_id
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * The user who owns this tenant (billing/administrative anchor).
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * All users (staff members, including the owner) of this tenant.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * All subscription rows, live and historical (ADR-0021).
     *
     * @return HasMany<TenantSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    /**
     * Team invitations issued by this tenant (ADR-0021).
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function teamInvitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /** The tenant's live (non-terminal) subscription, or null. */
    public function currentSubscription(): ?TenantSubscription
    {
        return TenantSubscription::liveFor((int) $this->id);
    }
}
