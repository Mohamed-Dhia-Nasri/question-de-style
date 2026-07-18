<?php

namespace App\Modules\CRM\Exceptions;

use App\Shared\Enums\CampaignStatus;
use RuntimeException;

/**
 * A campaign status change was refused because it is not a legal lifecycle
 * transition (M04). Completed and Cancelled are terminal and a campaign never
 * returns to Draft once it has left setup — the closed-set Rule::in check only
 * proves the target is *some* valid status, not that the FROM→TO move is
 * allowed. Enforced as block-and-tell; the UI surfaces it as a validation
 * error on the status field.
 */
class CampaignStatusTransitionNotAllowed extends RuntimeException
{
    public static function from(CampaignStatus $from, CampaignStatus $to): self
    {
        return new self(
            "A {$from->label()} campaign cannot be moved to {$to->label()}."
        );
    }
}
