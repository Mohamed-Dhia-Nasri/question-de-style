<?php

namespace App\Shared\Enums;

/**
 * ENUM-RelationshipStatus — canonical values:
 * docs/00-meta/03-glossary.md#enum-relationshipstatus.
 */
enum RelationshipStatus: string
{
    case None = 'NONE';
    case Prospect = 'PROSPECT';
    case Contacted = 'CONTACTED';
    case InConversation = 'IN_CONVERSATION';
    case Active = 'ACTIVE';
    case Collaborated = 'COLLABORATED';
    case Paused = 'PAUSED';
    case Declined = 'DECLINED';
    case Blocklisted = 'BLOCKLISTED';
}
