<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Exceptions\PlatformAccountConflict;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Contracts\RosterEnrollment;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SVC-CRM's single sanctioned write path for ENT-Creator and
 * ENT-PlatformAccount (ownership matrix: "ALL Creator writes route through
 * the CRM/ingestion service"). Application code — seeders, consoles, the
 * CRM UI — creates, updates, and deletes creators and their platform
 * accounts HERE, never via direct model writes from non-owner modules.
 *
 * Step 2 (ADR-0014, operator-managed identity): adds the operator's
 * curation paths — creator update/delete and platform-account
 * add/update/remove — and enforces the one-account-per-ENUM-Platform-per-
 * Creator invariant at the application layer (the latent gap Step 1
 * flagged; only (platform, handle) is unique in the DB). There is NO merge,
 * un-merge, or reassignment method — deferred out of v1 by ADR-0014.
 */
class CreatorWriter
{
    /**
     * sourceVersion stamped on operator-entered rows (ADR-0015): names the
     * entry surface so manual entries stay reproducible/auditable.
     */
    private const MANUAL_ENTRY_SURFACE = 'crm-ui-v1';

    public function __construct(private readonly RosterEnrollment $roster) {}

    /**
     * Creation enrolls the creator on the active monitoring roster in the
     * same transaction (RosterEnrollment seam — the M1-side service is the
     * monitored_subjects writer): every creator is watched from the moment
     * it exists, and the next scheduled cycle polls its accounts without
     * operator action.
     */
    public function createCreator(
        string $displayName,
        ?string $primaryLanguage = null,
        ?RelationshipStatus $relationshipStatus = null,
    ): Creator {
        return DB::transaction(function () use ($displayName, $primaryLanguage, $relationshipStatus): Creator {
            $creator = Creator::create([
                'display_name' => $displayName,
                'primary_language' => $primaryLanguage,
                'relationship_status' => $relationshipStatus,
            ]);

            $this->roster->enroll($creator);

            return $creator;
        });
    }

    /**
     * Operator edit of the creator's own fields (display name, language,
     * ENUM-RelationshipStatus — module-3 §2.4 ties relationship stage to
     * the enum).
     */
    public function updateCreator(
        Creator $creator,
        string $displayName,
        ?string $primaryLanguage,
        ?RelationshipStatus $relationshipStatus,
    ): Creator {
        $creator->update([
            'display_name' => $displayName,
            'primary_language' => $primaryLanguage,
            'relationship_status' => $relationshipStatus,
        ]);

        return $creator;
    }

    /**
     * Delete a creator — the operator's tool for removing a stray duplicate
     * (ADR-0014: no merge; duplicates are reconciled by deleting the stray).
     *
     * Deletes the creator's M3-owned, profile-managed children (platform
     * accounts, contacts, brand preferences, communication logs) in one
     * transaction, then the creator row. The roster entry that creation
     * added is lifecycle-coupled configuration and is withdrawn with it —
     * through the RosterEnrollment seam, since monitored_subjects is
     * M1-owned. M1 HISTORY rows are never touched (ownership matrix): if
     * monitoring data anchors to an account (content_items / stories /
     * metric_snapshots) or to a roster entry (mentions), the DB's restrict
     * FKs abort the whole transaction — surface that to the operator, do
     * not force it.
     */
    public function deleteCreator(Creator $creator): void
    {
        DB::transaction(function () use ($creator): void {
            $this->roster->withdraw($creator);
            $creator->contacts()->delete();
            $creator->brandPreferences()->delete();
            $creator->communicationLogs()->delete();
            $creator->platformAccounts()->delete();
            $creator->delete();
        });
    }

    /**
     * Attach a platform account to a creator. Provenance is mandatory —
     * ENT-PlatformAccount is externally sourced (DP-002) or operator-entered
     * (ADR-0015). Enforces one account per ENUM-Platform per creator and the
     * global (platform, handle) uniqueness as caught, human-readable errors.
     *
     * @param  list<string>  $externalLinks
     *
     * @throws PlatformAccountConflict
     */
    public function addPlatformAccount(
        Creator $creator,
        Platform $platform,
        string $handle,
        Provenance $provenance,
        ?string $bio = null,
        array $externalLinks = [],
        ?MetricValue $followerCount = null,
    ): PlatformAccount {
        $this->assertPlatformFree($creator, $platform);
        $this->assertHandleFree($platform, $handle, $creator->tenant_id);

        try {
            return $creator->platformAccounts()->create([
                'platform' => $platform,
                'handle' => $handle,
                'bio' => $bio,
                'external_links' => $externalLinks,
                'follower_count' => $followerCount,
                'provenance' => $provenance,
            ]);
        } catch (QueryException $e) {
            // The partial unique index (deep-review L1) is the backstop for
            // the check-then-insert race two overlapping requests can win:
            // translate it into the same conflict the app check raises.
            throw $this->translateUniqueViolation($e, $creator, $platform);
        }
    }

    /**
     * Operator add path (spec §2.4): the human asserts this account belongs
     * to this creator. Manual entry carries the internal ADR-0015 provenance
     * — never a scraper id. The operator panel takes no follower count: the
     * spec's operator-editable field set is platform + handle + bio +
     * external links; observed counts arrive via profile sync.
     *
     * @param  list<string>  $externalLinks
     *
     * @throws PlatformAccountConflict
     */
    public function addManualPlatformAccount(
        Creator $creator,
        Platform $platform,
        string $handle,
        ?string $bio = null,
        array $externalLinks = [],
    ): PlatformAccount {
        return $this->addPlatformAccount(
            $creator,
            $platform,
            $handle,
            $this->manualEntryProvenance(),
            $bio,
            $externalLinks,
        );
    }

    /**
     * Operator edit path (spec §2.4 "matching update path"). Identity fields
     * (platform, handle) may change — the invariants are re-checked. The
     * provenance envelope is NOT re-stamped: it records the record's ORIGIN
     * (ADR-0015 — the manual stamp must never sit on a provider-fetched
     * record, e.g. its follower count), and the operator's change is
     * captured in the caller's audit event instead. Distinct from
     * IngestedProfileSync, which is the external-PUBLIC-fields half and
     * never touches identity fields.
     *
     * @param  list<string>  $externalLinks
     *
     * @throws PlatformAccountConflict
     */
    public function updatePlatformAccount(
        PlatformAccount $account,
        Platform $platform,
        string $handle,
        ?string $bio = null,
        array $externalLinks = [],
    ): PlatformAccount {
        $creator = $account->creator;

        if ($creator !== null && $platform !== $account->platform) {
            $this->assertPlatformFree($creator, $platform, ignoring: $account);
        }

        if ($platform !== $account->platform || $handle !== $account->handle) {
            $this->assertHandleFree($platform, $handle, $account->tenant_id, ignoring: $account);
        }

        try {
            $account->update([
                'platform' => $platform,
                'handle' => $handle,
                'bio' => $bio,
                'external_links' => $externalLinks,
            ]);
        } catch (QueryException $e) {
            throw $creator !== null
                ? $this->translateUniqueViolation($e, $creator, $platform)
                : $e;
        }

        return $account;
    }

    /**
     * Operator remove path (spec §2.4 "detach path"). Deletes the account
     * row. There is deliberately NO move-to-another-creator alternative in
     * v1 (ADR-0014 known limitation): if M1 monitoring history anchors to
     * the account, the DB's restrict FKs refuse the delete — callers surface
     * that instead of destroying another module's data.
     */
    public function removePlatformAccount(PlatformAccount $account): void
    {
        // Transaction (a savepoint when one is already open) so an FK
        // refusal leaves the surrounding connection usable for the caller's
        // error handling.
        DB::transaction(function () use ($account): void {
            $account->delete();
        });
    }

    private function manualEntryProvenance(): Provenance
    {
        return new Provenance(
            SourceRegistry::AGENCY_MANUAL_ENTRY,
            CarbonImmutable::now(),
            self::MANUAL_ENTRY_SURFACE,
        );
    }

    /**
     * Map a violation of the L1 backstop index onto the same conflict the
     * app-layer check raises, so the race and the ordinary duplicate share
     * one UI error path; anything else propagates untouched.
     */
    private function translateUniqueViolation(QueryException $e, Creator $creator, Platform $platform): PlatformAccountConflict|QueryException
    {
        if (str_contains($e->getMessage(), 'platform_accounts_creator_platform_unique')) {
            return PlatformAccountConflict::platformTaken($creator, $platform);
        }

        return $e;
    }

    /** @throws PlatformAccountConflict */
    private function assertPlatformFree(Creator $creator, Platform $platform, ?PlatformAccount $ignoring = null): void
    {
        // ADR-0019: FK-anchored to the creator, so TenantScope already
        // covers it whenever a context is set — the explicit tenant filter
        // is added anyway (explicit beats ambient in shared write paths).
        $exists = $creator->platformAccounts()
            ->where('tenant_id', $creator->tenant_id)
            ->where('platform', $platform->value)
            ->when($ignoring !== null, fn ($query) => $query->whereKeyNot($ignoring->getKey()))
            ->exists();

        if ($exists) {
            throw PlatformAccountConflict::platformTaken($creator, $platform);
        }
    }

    /**
     * ADR-0019: handle uniqueness is PER TENANT — the DB unique key is now
     * (tenant_id, platform, handle). The check scopes to the owning row's
     * tenant explicitly; another tenant tracking the same public handle is
     * not a conflict.
     *
     * @throws PlatformAccountConflict
     */
    private function assertHandleFree(Platform $platform, string $handle, ?int $tenantId, ?PlatformAccount $ignoring = null): void
    {
        $exists = PlatformAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('platform', $platform->value)
            ->where('handle', $handle)
            ->when($ignoring !== null, fn ($query) => $query->whereKeyNot($ignoring->getKey()))
            ->exists();

        if ($exists) {
            throw PlatformAccountConflict::handleTaken($platform, $handle);
        }
    }
}
