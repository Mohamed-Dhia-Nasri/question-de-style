<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Creators\ContactsPanel;
use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Models\Creator;
use App\Shared\Audit\AuditLog;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Contacts panel: manual CRUD (REQ-M3-002, AC-M3-004), the literal
 * "unavailable" auto-extraction affordance (DEF-002, AC-M3-005), and the
 * GDPR hard-delete path (DP-005, AC-M3-006) with an identifier-only audit
 * event.
 */
class ContactsPanelTest extends TestCase
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

        Livewire::test(ContactsPanel::class, ['creator' => Creator::factory()->create()])
            ->assertForbidden();
    }

    public function test_the_auto_extraction_affordance_renders_the_literal_unavailable(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        // AC-M3-005 / DEF-002 / Rule 8: the affordance shows "unavailable" —
        // never empty, never zero, never a working auto-fill.
        Livewire::test(ContactsPanel::class, ['creator' => $creator])
            ->assertSee('Auto-extract email/phone:')
            ->assertSee('unavailable');
    }

    public function test_an_operator_adds_a_manual_contact(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        Livewire::test(ContactsPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('contact_email', 'creator@example.test')
            ->set('contact_phone', '+49 30 1234567')
            ->set('contact_postal_address', "Beispielstraße 1\n10115 Berlin")
            ->set('contact_preferred_channel', 'email')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('contacts', [
            'creator_id' => $creator->id,
            'email' => 'creator@example.test',
            'phone' => '+49 30 1234567',
            'preferred_channel' => 'email',
        ]);
    }

    public function test_all_detail_fields_are_optional_but_email_must_be_valid(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        // An entirely empty contact is allowed — every detail field is
        // optional per the canonical ENT-Contact shape.
        Livewire::test(ContactsPanel::class, ['creator' => $creator])
            ->call('add')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $creator->contacts()->count());

        Livewire::test(ContactsPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('contact_email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['contact_email' => 'email']);
    }

    public function test_an_operator_edits_a_contact(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $contact = Contact::factory()->create(['creator_id' => $creator->id, 'email' => 'old@example.test']);

        Livewire::test(ContactsPanel::class, ['creator' => $creator])
            ->call('edit', $contact->id)
            ->assertSet('contact_email', 'old@example.test')
            ->set('contact_email', 'new@example.test')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'email' => 'new@example.test']);
    }

    public function test_gdpr_delete_hard_deletes_the_contact_with_an_identifier_only_audit_event(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $contact = Contact::factory()->create([
            'creator_id' => $creator->id,
            'email' => 'erase-me@example.test',
            'phone' => '+49 170 0000000',
        ]);

        Livewire::test(ContactsPanel::class, ['creator' => $creator])
            ->call('confirmDelete', $contact->id)
            ->assertSet('confirmingDeleteId', $contact->id)
            ->call('delete');

        // Hard delete — the row is gone, not soft-deleted (DP-005).
        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);

        // The audit trail records the event but never the erased personal data.
        $log = AuditLog::where('action', 'contact.deleted')->where('subject_id', $contact->id)->firstOrFail();
        $this->assertStringNotContainsString('erase-me@example.test', json_encode($log->context));
        $this->assertStringNotContainsString('0000000', json_encode($log->context));
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
        $contact = Contact::factory()->create(['creator_id' => $creator->id]);

        Livewire::test(ContactsPanel::class, ['creator' => $creator])->assertOk()
            ->call('add')->assertForbidden();
        Livewire::test(ContactsPanel::class, ['creator' => $creator])
            ->call('confirmDelete', $contact->id)->assertForbidden();

        // The persisting mutators re-authorize themselves even when the
        // gated form-open actions are bypassed via direct state writes —
        // save() must not create and delete() must not GDPR-erase.
        Livewire::test(ContactsPanel::class, ['creator' => $creator])
            ->set('contact_email', 'smuggled@example.test')
            ->call('save')->assertForbidden();
        Livewire::test(ContactsPanel::class, ['creator' => $creator])
            ->set('confirmingDeleteId', $contact->id)
            ->call('delete')->assertForbidden();

        $this->assertDatabaseMissing('contacts', ['email' => 'smuggled@example.test']);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }
}
