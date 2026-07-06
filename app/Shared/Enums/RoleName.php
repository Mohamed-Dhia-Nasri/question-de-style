<?php

namespace App\Shared\Enums;

/**
 * ENUM-RoleName — canonical values: docs/00-meta/03-glossary.md#enum-rolename.
 *
 * The closed set of permission roles. CLIENT_VIEWER sees ONLY approved
 * reports for its own brands (REQ-M3-012) — no raw CRM, no unapproved data,
 * no cross-brand data. Do not invent additional role names.
 */
enum RoleName: string
{
    case Admin = 'ADMIN';
    case AccountDirector = 'ACCOUNT_DIRECTOR';
    case CampaignManager = 'CAMPAIGN_MANAGER';
    case InfluencerRelationsManager = 'INFLUENCER_RELATIONS_MANAGER';
    case Analyst = 'ANALYST';
    case ClientViewer = 'CLIENT_VIEWER';

    /**
     * Internal agency-staff roles (everyone except the external client viewer).
     *
     * @return list<self>
     */
    public static function staff(): array
    {
        return [
            self::Admin,
            self::AccountDirector,
            self::CampaignManager,
            self::InfluencerRelationsManager,
            self::Analyst,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::AccountDirector => 'Account Director',
            self::CampaignManager => 'Campaign Manager',
            self::InfluencerRelationsManager => 'Influencer Relations Manager',
            self::Analyst => 'Analyst',
            self::ClientViewer => 'Client Viewer',
        };
    }
}
