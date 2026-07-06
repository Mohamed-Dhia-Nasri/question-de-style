<?php

namespace App\Shared\Enums;

/**
 * ENUM-SeedingCampaignStatus — canonical values:
 * docs/00-meta/03-glossary.md#enum-seedingcampaignstatus.
 */
enum SeedingCampaignStatus: string
{
    case Draft = 'DRAFT';
    case Planned = 'PLANNED';
    case Active = 'ACTIVE';
    case Shipping = 'SHIPPING';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
