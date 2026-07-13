<?php

namespace App\Modules\CRM\Exceptions;

use App\Modules\CRM\Models\Creator;
use App\Shared\Enums\Platform;
use RuntimeException;

/**
 * A platform-account write would violate an identity invariant:
 *
 *  - one account per ENUM-Platform per Creator (data model ER rule "one per
 *    ENUM-Platform presence"; enforced at the application layer — Step 1
 *    flagged that no DB constraint exists for it), or
 *  - the global (platform, handle) uniqueness from Step 1 (two creators can
 *    never claim the same external account).
 *
 * Thrown by CreatorWriter so UI surfaces a caught, human-readable error
 * instead of silently creating a second account (spec §2.4).
 */
class PlatformAccountConflict extends RuntimeException
{
    public static function platformTaken(Creator $creator, Platform $platform): self
    {
        return new self(
            "Creator [{$creator->display_name}] already has a {$platform->value} account — "
            .'a creator holds at most one account per platform.'
        );
    }

    public static function handleTaken(Platform $platform, string $handle): self
    {
        return new self(
            "The {$platform->value} handle [{$handle}] is already claimed by another creator — "
            .'platform handles are globally unique.'
        );
    }
}
