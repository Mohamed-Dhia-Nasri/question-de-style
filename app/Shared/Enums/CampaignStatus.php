<?php

namespace App\Shared\Enums;

/**
 * ENUM-CampaignStatus — canonical values:
 * docs/00-meta/03-glossary.md#enum-campaignstatus.
 */
enum CampaignStatus: string
{
    case Draft = 'DRAFT';
    case Planned = 'PLANNED';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
