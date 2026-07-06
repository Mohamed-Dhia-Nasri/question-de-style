<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DP-005 smoke test (spec §6): a Contact row is HARD-deletable — no
 * append-only trigger, no soft-delete blocker, no dependent FK. This is
 * the GDPR erase path for manually-entered personal data (REQ-M3-002).
 */
class ContactGdprDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_row_is_hard_deletable(): void
    {
        $contact = Contact::factory()->create();
        $id = $contact->id;

        $contact->delete();

        $this->assertDatabaseMissing('contacts', ['id' => $id]);
    }

    public function test_deleting_a_contact_leaves_its_creator_intact(): void
    {
        $contact = Contact::factory()->create();
        $creatorId = $contact->creator_id;

        $contact->delete();

        $this->assertDatabaseHas('creators', ['id' => $creatorId]);
    }
}
