<?php

namespace App\Models;

use App\Shared\Enums\RoleName;
use App\Shared\Tenancy\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

/**
 * ENT-User (docs/30-data-model/00-data-model.md#ent-user).
 *
 * Written only by Module 3 / ADMIN (ownership matrix). Every user holds
 * EXACTLY ONE role from ENUM-RoleName — the data model defines a single
 * roleId. spatie/laravel-permission stores roles in a pivot, so the
 * single-role rule is enforced at the application layer: always assign via
 * syncRoles([$role]), never assignRole() on top of an existing role.
 *
 * Every user belongs to exactly one tenant (ADR-0019); tenant_id is
 * deliberately NOT mass assignable — it is stamped from TenantContext or
 * set explicitly by TenantProvisioner. users.email stays globally unique
 * (the login identity spans tenants).
 *
 * @property int $id
 * @property int|null $tenant_id
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'display_name',
        'email',
        'password',
        'active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    /** The user's single canonical role, if assigned. */
    public function roleName(): ?RoleName
    {
        $name = $this->getRoleNames()->first();

        return $name === null ? null : RoleName::from($name);
    }

    public function isClientViewer(): bool
    {
        return $this->roleName() === RoleName::ClientViewer;
    }

    /**
     * True when this user is their tenant's billing owner (a tenant
     * attribute, not a role). The owner is RESTRICT-referenced by
     * tenants.owner_user_id, so they can never be deleted until ownership is
     * reassigned — deleting them would fail at the database.
     */
    public function isTenantOwner(): bool
    {
        if ($this->tenant_id === null) {
            return false;
        }

        // Read the owner id via the relation QUERY (not a lazy attribute
        // access) so it also holds under preventLazyLoading; use the loaded
        // relation when it is already present.
        $ownerId = $this->relationLoaded('tenant')
            ? $this->getRelation('tenant')?->owner_user_id
            : $this->tenant()->value('owner_user_id');

        return $ownerId !== null && (int) $ownerId === (int) $this->id;
    }

    public function initials(): string
    {
        return Str::of($this->display_name)
            ->explode(' ')
            ->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }
}
