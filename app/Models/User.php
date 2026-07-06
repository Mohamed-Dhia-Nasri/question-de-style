<?php

namespace App\Models;

use App\Shared\Enums\RoleName;
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
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

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

    public function initials(): string
    {
        return Str::of($this->display_name)
            ->explode(' ')
            ->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }
}
