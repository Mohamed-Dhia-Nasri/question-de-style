<?php

namespace Tests\Feature\Monitoring;

use App\Models\User;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\Monitoring\Livewire\Hashtags\HashtagListsIndex;
use App\Modules\Monitoring\Models\ContentHashtag;
use App\Modules\Monitoring\Models\HashtagList;
use App\Platform\Enrichment\Support\HashtagScope;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hashtag list management — the operator registry HashtagMatcher consults
 * (attribution evidence only, ADR-0008/DP-003). Page needs monitoring.view;
 * every mutation re-authorizes on monitoring.manage (HashtagListPolicy).
 * Scope targets mirror the DB CHECK (each scope names exactly its owner);
 * dedupe is per (normalized, scope, target) — the app check is friendly,
 * the partial unique index the backstop.
 */
class HashtagListsCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::Analyst);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_page_renders_and_client_viewers_are_refused(): void
    {
        $this->actingAsStaff();
        $this->get('/monitoring/hashtags')->assertOk()->assertSeeLivewire(HashtagListsIndex::class);

        $this->actingAs($this->makeUser(RoleName::ClientViewer));
        $this->get('/monitoring/hashtags')->assertForbidden();
        Livewire::test(HashtagListsIndex::class)->assertForbidden();
    }

    public function test_each_scope_requires_exactly_its_owner(): void
    {
        $this->actingAsStaff();

        // CAMPAIGN without a campaign is refused.
        Livewire::test(HashtagListsIndex::class)
            ->call('create')
            ->set('hashtag_value', '#summerglow')
            ->set('hashtag_scope', HashtagScope::Campaign->value)
            ->call('save')
            ->assertHasErrors(['hashtag_campaign_id']);

        // PRODUCT without a brand / without a label is refused.
        Livewire::test(HashtagListsIndex::class)
            ->call('create')
            ->set('hashtag_value', '#summerglow')
            ->set('hashtag_scope', HashtagScope::Product->value)
            ->call('save')
            ->assertHasErrors(['hashtag_brand_id']);

        Livewire::test(HashtagListsIndex::class)
            ->call('create')
            ->set('hashtag_value', '#summerglow')
            ->set('hashtag_scope', HashtagScope::Product->value)
            ->set('hashtag_brand_id', (string) Brand::factory()->create()->id)
            ->call('save')
            ->assertHasErrors(['hashtag_product_label']);

        // Unknown scope never reaches the DB CHECK.
        Livewire::test(HashtagListsIndex::class)
            ->call('create')
            ->set('hashtag_value', '#summerglow')
            ->set('hashtag_scope', 'GLOBAL')
            ->call('save')
            ->assertHasErrors(['hashtag_scope' => 'in']);

        $this->assertDatabaseCount('hashtag_lists', 0);
    }

    public function test_hashtags_are_normalized_created_and_audited_per_scope(): void
    {
        $staff = $this->actingAsStaff();
        $campaign = Campaign::factory()->create();

        Livewire::test(HashtagListsIndex::class)
            ->call('create')
            ->set('hashtag_value', '#SummerGlow')
            ->set('hashtag_scope', HashtagScope::Campaign->value)
            ->set('hashtag_campaign_id', (string) $campaign->id)
            ->call('save')
            ->assertHasNoErrors();

        $entry = HashtagList::query()->sole();
        $this->assertSame('#SummerGlow', $entry->hashtag);
        $this->assertSame('summerglow', $entry->normalized);
        $this->assertSame(HashtagScope::Campaign, $entry->scope);
        $this->assertSame($campaign->id, $entry->campaign_id);
        $this->assertNull($entry->brand_id);
        $this->assertTrue($entry->active);
        $this->assertSame($staff->id, $entry->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hashtag_list.created', 'subject_id' => $entry->id]);

        // Agency scope drops every target (DB CHECK: all owners null).
        Livewire::test(HashtagListsIndex::class)
            ->call('create')
            ->set('hashtag_value', '#qdsagency')
            ->set('hashtag_scope', HashtagScope::Agency->value)
            ->set('hashtag_brand_id', (string) Brand::factory()->create()->id) // stale field from a scope flip
            ->call('save')
            ->assertHasNoErrors();

        $agency = HashtagList::query()->where('normalized', 'qdsagency')->sole();
        $this->assertNull($agency->brand_id);
        $this->assertNull($agency->campaign_id);
    }

    public function test_a_hashtag_of_only_symbols_is_refused(): void
    {
        $this->actingAsStaff();

        Livewire::test(HashtagListsIndex::class)
            ->call('create')
            ->set('hashtag_value', '#')
            ->set('hashtag_scope', HashtagScope::Agency->value)
            ->call('save')
            ->assertHasErrors(['hashtag_value']);
    }

    public function test_duplicates_are_refused_per_scope_target_but_allowed_across_targets(): void
    {
        $this->actingAsStaff();
        $brand = Brand::factory()->create();
        HashtagList::factory()->hashtag('#SummerGlow')->create(['brand_id' => $brand->id]);

        // Same normalized hashtag, same brand → duplicate (case-insensitive).
        Livewire::test(HashtagListsIndex::class)
            ->call('create')
            ->set('hashtag_value', '#SUMMERGLOW')
            ->set('hashtag_scope', HashtagScope::Brand->value)
            ->set('hashtag_brand_id', (string) $brand->id)
            ->call('save')
            ->assertHasErrors(['hashtag_value']);

        // Same hashtag under ANOTHER brand is a distinct registration.
        Livewire::test(HashtagListsIndex::class)
            ->call('create')
            ->set('hashtag_value', '#SummerGlow')
            ->set('hashtag_scope', HashtagScope::Brand->value)
            ->set('hashtag_brand_id', (string) Brand::factory()->create()->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('hashtag_lists', 2);
    }

    public function test_edit_toggle_and_delete_are_audited(): void
    {
        $this->actingAsStaff();
        $entry = HashtagList::factory()->hashtag('#oldtag')->create();

        Livewire::test(HashtagListsIndex::class)
            ->call('edit', $entry->id)
            ->set('hashtag_value', '#newtag')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('newtag', $entry->refresh()->normalized);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hashtag_list.updated', 'subject_id' => $entry->id]);

        // Deactivate: the matcher consults active entries only.
        Livewire::test(HashtagListsIndex::class)->call('toggleActive', $entry->id);
        $this->assertFalse($entry->refresh()->active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hashtag_list.deactivated', 'subject_id' => $entry->id]);

        Livewire::test(HashtagListsIndex::class)->call('toggleActive', $entry->id);
        $this->assertTrue($entry->refresh()->active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hashtag_list.activated', 'subject_id' => $entry->id]);

        Livewire::test(HashtagListsIndex::class)
            ->call('confirmDelete', $entry->id)
            ->call('delete');

        $this->assertDatabaseMissing('hashtag_lists', ['id' => $entry->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hashtag_list.deleted', 'subject_id' => $entry->id]);
    }

    public function test_the_usage_count_shows_distinct_content_carrying_the_hashtag(): void
    {
        $this->actingAsStaff();

        $entry = HashtagList::factory()->hashtag('#SummerGlow')->create();

        // Two distinct posts carry the hashtag (occurrences within one post
        // do not inflate the count); an unrelated hashtag never counts.
        ContentHashtag::factory()->create(['normalized' => 'summerglow', 'original' => '#SummerGlow', 'occurrences' => 3]);
        ContentHashtag::factory()->create(['normalized' => 'summerglow', 'original' => '#summerglow']);
        ContentHashtag::factory()->create(['normalized' => 'othertag']);

        Livewire::test(HashtagListsIndex::class)
            ->assertSee('2 posts');

        // A registered hashtag never extracted shows a real zero.
        HashtagList::factory()->hashtag('#neverused')->create();

        Livewire::test(HashtagListsIndex::class)
            ->assertSee('0 posts');
    }

    public function test_mutations_require_monitoring_manage_not_just_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::MONITORING_VIEW);
        $this->actingAs($viewer);

        $entry = HashtagList::factory()->create();

        // Guarded entry points refuse…
        Livewire::test(HashtagListsIndex::class)->call('create')->assertForbidden();
        Livewire::test(HashtagListsIndex::class)->call('edit', $entry->id)->assertForbidden();
        Livewire::test(HashtagListsIndex::class)->call('toggleActive', $entry->id)->assertForbidden();
        Livewire::test(HashtagListsIndex::class)->call('confirmDelete', $entry->id)->assertForbidden();

        // …and so do direct-mutator bypasses (state set without the guard).
        Livewire::test(HashtagListsIndex::class)
            ->set('hashtag_value', '#sneaky')
            ->set('hashtag_scope', HashtagScope::Agency->value)
            ->call('save')
            ->assertForbidden();

        Livewire::test(HashtagListsIndex::class)
            ->set('confirmingDeleteId', $entry->id)
            ->call('delete')
            ->assertForbidden();

        $this->assertDatabaseCount('hashtag_lists', 1);
        $this->assertTrue($entry->refresh()->active);
    }
}
