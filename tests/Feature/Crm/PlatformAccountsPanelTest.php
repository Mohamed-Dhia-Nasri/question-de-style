<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Creators\PlatformAccountsPanel;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Audit\AuditLog;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The operator's identity-authority surface (spec §2.4, ADR-0014): accounts
 * are added/edited/removed by hand through CreatorWriter. One account per
 * ENUM-Platform per creator and global (platform, handle) uniqueness surface
 * as caught validation errors; manual entries carry the ADR-0015 provenance;
 * removals are blocked when M1 monitoring history anchors to the account.
 */
class PlatformAccountsPanelTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_client_viewers_cannot_mount_the_panel(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        Livewire::test(PlatformAccountsPanel::class, ['creator' => Creator::factory()->create()])
            ->assertForbidden();
    }

    public function test_the_panel_lists_accounts_and_has_no_merge_or_autodetect_control(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        PlatformAccount::factory()->forCreator($creator)->create(['handle' => 'listed.handle']);

        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->assertSee('listed.handle')
            // ADR-0014: the automatic path and the merge operation are absent
            // FEATURES (no control at all), not unavailable data fields.
            ->assertDontSee('Auto-detect')
            ->assertDontSee('Merge');
    }

    public function test_an_operator_adds_an_account_with_manual_entry_provenance_and_an_audit_event(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->call('add')
            ->assertSet('showForm', true)
            ->set('account_platform', Platform::Instagram->value)
            ->set('account_handle', 'hand.curated')
            ->set('account_bio', 'typed by the operator')
            ->set('account_links', "https://example.test\nhttps://shop.example.test")
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $account = PlatformAccount::where('handle', 'hand.curated')->firstOrFail();

        $this->assertSame($creator->id, $account->creator_id);
        $this->assertSame(['https://example.test', 'https://shop.example.test'], $account->external_links);
        // The human is the identity authority; the stamp says so (ADR-0015).
        $this->assertSame(SourceRegistry::AGENCY_MANUAL_ENTRY, $account->provenance->source);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_account.added', 'subject_id' => $account->id]);

        // The social handle (PII) must not sit in the append-only audit context (M29).
        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString(
                'hand.curated',
                (string) json_encode($log->context),
                "audit_logs.{$log->action} context leaked the account handle",
            );
        }
    }

    public function test_the_form_validates_platform_handle_and_links(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('account_platform', 'MYSPACE')
            ->set('account_handle', '')
            ->call('save')
            ->assertHasErrors([
                'account_platform' => 'in',
                'account_handle' => 'required',
            ]);

        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('account_platform', Platform::Instagram->value)
            ->set('account_handle', 'valid.handle')
            ->set('account_links', "https://ok.example\nnot-a-url")
            ->call('save')
            ->assertHasErrors(['account_links']);
    }

    public function test_the_form_rejects_dangerous_non_http_link_schemes(): void
    {
        // H6 regression: FILTER_VALIDATE_URL accepts javascript:// URLs
        // (e.g. "javascript://comment%0aalert(1)"), which the profile blade
        // then renders as a clickable <a href> — a stored XSS vector against
        // any staff member who clicks it. Only http/https links may be stored.
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('account_platform', Platform::Instagram->value)
            ->set('account_handle', 'xss.handle')
            ->set('account_links', 'javascript://comment%0aalert(document.domain)')
            ->call('save')
            ->assertHasErrors(['account_links']);

        $this->assertDatabaseMissing('platform_accounts', ['handle' => 'xss.handle']);
    }

    public function test_a_second_account_on_the_same_platform_is_a_caught_error_not_a_silent_create(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        PlatformAccount::factory()->forCreator($creator)->onPlatform(Platform::Instagram)->create();

        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('account_platform', Platform::Instagram->value)
            ->set('account_handle', 'second.instagram')
            ->call('save')
            ->assertHasErrors(['account_handle']);

        $this->assertDatabaseMissing('platform_accounts', ['handle' => 'second.instagram']);
    }

    public function test_a_handle_claimed_by_another_creator_is_a_caught_error(): void
    {
        $this->actingAsCrmStaff();

        PlatformAccount::factory()
            ->forCreator(Creator::factory()->create())
            ->onPlatform(Platform::Instagram)
            ->create(['handle' => 'already.claimed']);

        $creator = Creator::factory()->create();

        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('account_platform', Platform::Instagram->value)
            ->set('account_handle', 'already.claimed')
            ->call('save')
            ->assertHasErrors(['account_handle']);

        $this->assertSame(0, $creator->platformAccounts()->count());
    }

    public function test_an_operator_edit_keeps_the_accounts_origin_provenance_and_is_audited(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->onPlatform(Platform::Instagram)->create();
        $originalSource = $account->provenance->source;

        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->call('edit', $account->id)
            ->assertSet('account_handle', $account->handle)
            ->set('account_handle', 'corrected.handle')
            ->call('save')
            ->assertHasNoErrors();

        $account->refresh();
        $this->assertSame('corrected.handle', $account->handle);
        // ADR-0015: provenance records the record's origin — an operator edit
        // of a scraper-sourced row must NOT re-stamp it as manual entry.
        $this->assertSame($originalSource, $account->provenance->source);
        $this->assertNotSame(SourceRegistry::AGENCY_MANUAL_ENTRY, $account->provenance->source);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_account.updated', 'subject_id' => $account->id]);
    }

    public function test_removal_requires_confirmation_and_records_an_audit_event(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();

        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->call('confirmRemove', $account->id)
            ->assertSet('confirmingRemoveId', $account->id)
            ->call('remove');

        $this->assertDatabaseMissing('platform_accounts', ['id' => $account->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_account.removed', 'subject_id' => $account->id]);
    }

    public function test_removal_is_refused_when_the_account_anchors_monitoring_history(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        ContentItem::factory()->create(['platform_account_id' => $account->id]);

        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->call('confirmRemove', $account->id)
            ->call('remove')
            ->assertSet('confirmingRemoveId', null);

        // M1's history is protected; no misleading audit event is recorded.
        $this->assertDatabaseHas('platform_accounts', ['id' => $account->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'platform_account.removed', 'subject_id' => $account->id]);
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        // A user holding ONLY crm.view can mount the panel, but every
        // mutating action re-authorizes server-side against crm.manage.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();

        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])->assertOk()
            ->call('add')->assertForbidden();
        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->call('edit', $account->id)->assertForbidden();
        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->call('confirmRemove', $account->id)->assertForbidden();

        // The persisting mutators re-authorize themselves even when the
        // gated form-open actions are bypassed via direct state writes.
        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->set('account_platform', Platform::YouTube->value)
            ->set('account_handle', 'smuggled.handle')
            ->call('save')->assertForbidden();
        Livewire::test(PlatformAccountsPanel::class, ['creator' => $creator])
            ->set('confirmingRemoveId', $account->id)
            ->call('remove')->assertForbidden();

        $this->assertDatabaseMissing('platform_accounts', ['handle' => 'smuggled.handle']);
        $this->assertDatabaseHas('platform_accounts', ['id' => $account->id]);
    }
}
