<?php

namespace App\Modules\CRM\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ENT-Client — an agency client, top of the client → brand → product
 * hierarchy (docs/30-data-model/00-data-model.md#ent-client).
 *
 * Write-owner: Module 3 CRM (ownership matrix). Module 1 and Module 2 read
 * only; the CRM write paths arrive with the Module 3 build.
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'country',
    ];

    /** @return HasMany<Brand, $this> */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }
}
