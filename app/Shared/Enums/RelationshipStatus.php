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

    /** Human-facing label (presentation only — same convention as RoleName). */
    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Prospect => 'Prospect',
            self::Contacted => 'Contacted',
            self::InConversation => 'In conversation',
            self::Active => 'Active',
            self::Collaborated => 'Collaborated',
            self::Paused => 'Paused',
            self::Declined => 'Declined',
            self::Blocklisted => 'Blocklisted',
        };
    }
}
