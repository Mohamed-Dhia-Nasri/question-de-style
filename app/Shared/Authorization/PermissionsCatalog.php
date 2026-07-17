<?php

namespace App\Shared\Authorization;

use App\Shared\Enums\RoleName;

/**
 * The application permission catalog and its role assignments.
 *
 * Role NAMES are canonical (ENUM-RoleName, docs/00-meta/03-glossary.md).
 * Permission names, however, are NOT canonically enumerated anywhere in the
 * documentation tree (ENT-Role only defines `permissions: list of string`).
 * This catalog therefore contains only the minimal set directly traceable to
 * documented facts:
 *
 *  - users.manage / roles.manage — ENT-User/ENT-Role writes are restricted to
 *    ADMIN (ownership matrix; module 3 spec REQ-M3-012).
 *  - reports.view-approved — CLIENT_VIEWER sees ONLY approved reports for its
 *    own brands (REQ-M3-012). Brand-level scoping is enforced additionally by
 *    policy once ENT-Client/ENT-Brand/report entities exist (P3).
 *  - internal.access + <module>.view — the complement of the CLIENT_VIEWER
 *    rule: internal surfaces are staff-only. No documented fact restricts
 *    one staff role from another module's area, so all staff roles receive
 *    all module areas; the fine-grained per-role permission matrix is P3
 *    scope (REQ-M3-012) and a flagged documentation gap.
 *  - audit.view — audit trail administration, ADMIN only.
 *  - monitoring.manage — Module 1 write surfaces: roster (MonitoredSubject)
 *    configuration and the human-review corrections mandated by DP-004
 *    (mention class, sentiment, recognition). Staff-wide for the same
 *    reason as <module>.view: no documented fact restricts it to specific
 *    staff roles; tightening is P3 scope. Never granted to CLIENT_VIEWER.
 *  - emv.manage — create/edit/activate/deactivate/archive EMV
 *    configurations (REQ-M1-011: "configurable" implies an authorized
 *    configuration surface). ADMIN only until the P3 permission matrix
 *    says otherwise. FLAGGED DEVIATION: like every other permission name,
 *    not canonically enumerated — traceable to REQ-M1-011/AC-M1-011.
 *
 * Extend this catalog only alongside the module that introduces the
 * behaviour, and keep every entry traceable to a documented requirement.
 */
final class PermissionsCatalog
{
    public const INTERNAL_ACCESS = 'internal.access';

    public const MONITORING_VIEW = 'monitoring.view';

    public const MONITORING_MANAGE = 'monitoring.manage';

    public const DISCOVERY_VIEW = 'discovery.view';

    public const CRM_VIEW = 'crm.view';

    /**
     * Module 3 write surfaces: CRM records (clients, brands, products,
     * creators, platform accounts, contacts, preferences, campaigns,
     * seeding, shipments, communication logs, documents, tasks). Staff-wide
     * for the same reason as monitoring.manage: no documented fact
     * restricts it to specific staff roles; the fine-grained per-role
     * matrix is later P3 scope (REQ-M3-012). Never granted to
     * CLIENT_VIEWER. User/Role writes stay under users.manage/roles.manage
     * (ADMIN only, AC-M3-018) — crm.manage does NOT cover them.
     */
    public const CRM_MANAGE = 'crm.manage';

    public const REPORTS_VIEW_APPROVED = 'reports.view-approved';

    public const USERS_MANAGE = 'users.manage';

    public const ROLES_MANAGE = 'roles.manage';

    public const AUDIT_VIEW = 'audit.view';

    public const EMV_MANAGE = 'emv.manage';

    /**
     * Staff-visible Settings area (EMV + Reach formula pages).
     */
    public const SETTINGS_VIEW = 'settings.view';

    /**
     * Create/edit/activate reach configurations. ADMIN only.
     */
    public const REACH_MANAGE = 'reach.manage';

    /**
     * Save the per-tenant monitoring settings (gift-link window, trend
     * window, retention periods — ADR-0025). ADMIN only.
     */
    public const MONITORING_SETTINGS_MANAGE = 'monitoring-settings.manage';

    /**
     * Request/download rollup-backed report exports (REQ-M1-012). Staff
     * only — CLIENT_VIEWER never exports internal reports; its surface is
     * the approved-reports area (REQ-M3-012, P3).
     */
    public const EXPORTS_CREATE = 'exports.create';

    /**
     * Internal operational visibility: provider configuration/health, last
     * ingestion, queue/failed-job state, snapshot/analytics/story
     * freshness, export failures. Staff only — CLIENT_VIEWER must never
     * see provider-health details or technical errors (REQ-M3-012).
     */
    public const OPERATIONS_VIEW = 'operations.view';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::INTERNAL_ACCESS,
            self::MONITORING_VIEW,
            self::MONITORING_MANAGE,
            self::DISCOVERY_VIEW,
            self::CRM_VIEW,
            self::CRM_MANAGE,
            self::REPORTS_VIEW_APPROVED,
            self::USERS_MANAGE,
            self::ROLES_MANAGE,
            self::AUDIT_VIEW,
            self::EMV_MANAGE,
            self::SETTINGS_VIEW,
            self::REACH_MANAGE,
            self::MONITORING_SETTINGS_MANAGE,
            self::EXPORTS_CREATE,
            self::OPERATIONS_VIEW,
        ];
    }

    /**
     * Permissions granted to each canonical role.
     *
     * @return array<string, list<string>>
     */
    public static function roleAssignments(): array
    {
        $staff = [
            self::INTERNAL_ACCESS,
            self::MONITORING_VIEW,
            self::MONITORING_MANAGE,
            self::DISCOVERY_VIEW,
            self::CRM_VIEW,
            self::CRM_MANAGE,
            self::REPORTS_VIEW_APPROVED,
            self::SETTINGS_VIEW,
            self::EXPORTS_CREATE,
            self::OPERATIONS_VIEW,
        ];

        return [
            RoleName::Admin->value => self::all(),
            RoleName::AccountDirector->value => $staff,
            RoleName::CampaignManager->value => $staff,
            RoleName::InfluencerRelationsManager->value => $staff,
            RoleName::Analyst->value => $staff,
            // External client: approved reports for their own brands ONLY.
            RoleName::ClientViewer->value => [self::REPORTS_VIEW_APPROVED],
        ];
    }

    private function __construct() {}
}
