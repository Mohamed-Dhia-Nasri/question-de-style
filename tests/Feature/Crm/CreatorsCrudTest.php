<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Creators\CreatorsIndex;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Discovery\Contracts\CreatorGeography;
use App\Modules\Monitoring\Models\ContentItem;
use App\Shared\Audit\AuditLog;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CRM creators index (spec §2.4) — feature coverage analogous to
 * UsersCrudTest: rendering, searching (name + handle), sorting whitelist,
 * filtering, pagination, create validation, authorization, delete
 * confirmation with the ADR-0014 stray-duplicate semantics, and audit
 * events.
 */
class CreatorsCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_audit_events_store_no_personal_data_in_context(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(CreatorsIndex::class)
            ->call('create')
            ->set('display_name', 'Erika Musterfrau')
            ->call('save')
            ->assertHasNoErrors();

        $creator = Creator::where('display_name', 'Erika Musterfrau')->firstOrFail();

        Livewire::test(CreatorsIndex::class)
            ->call('confirmDelete', $creator->id)
            ->call('delete')
            ->assertHasNoErrors();

        // subject_id still identifies the record; the display name (PII) must
        // never sit in the append-only audit context (M29).
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator.created', 'subject_id' => $creator->id]);

        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString(
                'Erika Musterfrau',
                (string) json_encode($log->context),
                "audit_logs.{$log->action} context leaked the creator's display name",
            );
        }
    }

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_creators_filter_by_their_assigned_geography(): void
    {
        // Geography belongs to CREATORS (ADR-0018) — the list filters by the
        // operator-assigned country/city and shows it per creator.
        $this->actingAsCrmStaff();

        $munich = Creator::factory()->create(['display_name' => 'Munich Creator']);
        $paris = Creator::factory()->create(['display_name' => 'Paris Creator']);
        $unassigned = Creator::factory()->create(['display_name' => 'Nowhere Creator']);

        app(CreatorGeography::class)->assign($munich, 'DE', 'Bavaria', 'Munich');
        app(CreatorGeography::class)->assign($paris, 'FR', null, 'Paris');

        Livewire::test(CreatorsIndex::class)
            ->set('countryFilter', 'DE')
            ->assertSee('Munich Creator')
            ->assertDontSee('Paris Creator')
            ->assertDontSee('Nowhere Creator')
            ->set('countryFilter', '')
            ->set('cityFilter', 'Paris')
            ->assertSee('Paris Creator')
            ->assertDontSee('Munich Creator');

        // The geography column shows the assignment; unassigned creators
        // render unavailable — never blank (DEF register rule).
        Livewire::test(CreatorsIndex::class)
            ->assertSee('Munich')
            ->assertSee('unavailable');
    }

    public function test_component_renders_on_the_crm_creators_page(): void
    {
        $this->actingAsCrmStaff();

        $this->get('/crm/creators')
            ->assertOk()
            ->assertSeeLivewire(CreatorsIndex::class);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/crm/creators')->assertRedirect('/login');
    }

    public function test_client_viewers_cannot_reach_the_route_or_mount_the_component(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $this->get('/crm/creators')->assertForbidden();

        Livewire::test(CreatorsIndex::class)->assertForbidden();
    }

    public function test_search_matches_display_name_and_platform_handle(): void
    {
        $this->actingAsCrmStaff();

        $ada = Creator::factory()->create(['display_name' => 'Ada Lovelace']);
        PlatformAccount::factory()->forCreator($ada)->create(['handle' => 'ada.codes']);
        Creator::factory()->create(['display_name' => 'Grace Hopper']);

        Livewire::test(CreatorsIndex::class)
            ->set('search', 'lovelace')
            ->assertSee('Ada Lovelace')
            ->assertDontSee('Grace Hopper')
            // The operator hunting a stray duplicate searches by handle.
            ->set('search', 'ada.codes')
            ->assertSee('Ada Lovelace')
            ->assertDontSee('Grace Hopper');
    }

    public function test_relationship_status_filter_narrows_the_result(): void
    {
        $this->actingAsCrmStaff();

        Creator::factory()->create(['display_name' => 'Active Anna', 'relationship_status' => RelationshipStatus::Active]);
        Creator::factory()->create(['display_name' => 'Prospect Paula', 'relationship_status' => RelationshipStatus::Prospect]);

        Livewire::test(CreatorsIndex::class)
            ->set('statusFilter', RelationshipStatus::Prospect->value)
            ->assertSee('Prospect Paula')
            ->assertDontSee('Active Anna');
    }

    public function test_sorting_toggles_direction_and_ignores_unknown_columns(): void
    {
        $this->actingAsCrmStaff();

        $component = Livewire::test(CreatorsIndex::class)
            ->call('sortBy', 'created_at')
            ->assertSet('sortField', 'created_at')
            ->assertSet('sortDirection', 'asc')
            ->call('sortBy', 'created_at')
            ->assertSet('sortDirection', 'desc');

        $component->call('sortBy', 'primary_language')
            ->assertSet('sortField', 'created_at');
    }

    public function test_tampered_query_string_sort_falls_back_safely(): void
    {
        $this->actingAsCrmStaff();

        Livewire::withQueryParams(['sortField' => 'display_name;DROP TABLE creators'])
            ->test(CreatorsIndex::class)
            ->assertOk();
    }

    public function test_pagination_limits_the_page_size(): void
    {
        $this->actingAsCrmStaff();

        Creator::factory()->count(15)->create();

        Livewire::test(CreatorsIndex::class)
            ->assertViewHas('creators', fn ($creators) => $creators->count() === 10 && $creators->total() === 15)
            ->set('perPage', 25)
            ->assertViewHas('creators', fn ($creators) => $creators->count() === 15);
    }

    public function test_create_validates_server_side(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(CreatorsIndex::class)
            ->call('create')
            ->assertSet('showForm', true)
            ->set('display_name', '')
            ->set('primary_language', 'too-long-code')
            ->set('relationship_status', 'BEST_FRIENDS')
            ->call('save')
            ->assertHasErrors([
                'display_name' => 'required',
                'primary_language' => 'max',
                'relationship_status' => 'in',
            ]);
    }

    public function test_staff_can_create_a_creator_with_an_audit_event(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(CreatorsIndex::class)
            ->call('create')
            ->set('display_name', 'Neue Kreatorin')
            ->set('primary_language', 'de')
            ->set('relationship_status', RelationshipStatus::Prospect->value)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $created = Creator::where('display_name', 'Neue Kreatorin')->firstOrFail();

        $this->assertSame(RelationshipStatus::Prospect, $created->relationship_status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'creator.created',
            'subject_id' => $created->id,
        ]);
    }

    public function test_delete_requires_confirmation_removes_children_and_records_an_audit_event(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        $contact = $creator->contacts()->create(['email' => 'stray@example.test']);

        Livewire::test(CreatorsIndex::class)
            ->call('confirmDelete', $creator->id)
            ->assertSet('confirmingDeleteId', $creator->id)
            ->call('delete');

        $this->assertDatabaseMissing('creators', ['id' => $creator->id]);
        $this->assertDatabaseMissing('platform_accounts', ['id' => $account->id]);
        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator.deleted', 'subject_id' => $creator->id]);
    }

    public function test_delete_is_refused_when_monitoring_history_exists(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->forCreator($creator)->create();
        ContentItem::factory()->create(['platform_account_id' => $account->id]);

        Livewire::test(CreatorsIndex::class)
            ->call('confirmDelete', $creator->id)
            ->call('delete')
            ->assertSet('confirmingDeleteId', null);

        // Nothing was deleted and no misleading audit event was recorded.
        $this->assertDatabaseHas('creators', ['id' => $creator->id]);
        $this->assertDatabaseHas('platform_accounts', ['id' => $account->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'creator.deleted', 'subject_id' => $creator->id]);
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        // A user holding ONLY crm.view can mount the list, but every
        // mutating action re-authorizes server-side against crm.manage.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $creator = Creator::factory()->create();

        Livewire::test(CreatorsIndex::class)->assertOk()
            ->call('create')->assertForbidden();
        Livewire::test(CreatorsIndex::class)
            ->call('confirmDelete', $creator->id)->assertForbidden();

        // The persisting mutators re-authorize themselves: Livewire exposes
        // public methods/properties to the client, so a viewer can bypass the
        // gated form-open actions and hit save()/delete() with state set
        // directly. Both must refuse and leave the database untouched.
        Livewire::test(CreatorsIndex::class)
            ->set('display_name', 'Smuggled Creator')
            ->call('save')->assertForbidden();
        Livewire::test(CreatorsIndex::class)
            ->set('confirmingDeleteId', $creator->id)
            ->call('delete')->assertForbidden();

        $this->assertDatabaseMissing('creators', ['display_name' => 'Smuggled Creator']);
        $this->assertDatabaseHas('creators', ['id' => $creator->id]);
    }
}
