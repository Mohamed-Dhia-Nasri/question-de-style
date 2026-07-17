<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Creators\CreatorCsvImport;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CSV creator import (F10) — bulk creation that routes every row through
 * CreatorWriter so Monitoring auto-enrollment keeps working, previews each
 * row before writing, and skips bad or conflicting rows one at a time rather
 * than failing the whole file.
 */
class CreatorCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    private function upload(string $csv, string $name = 'creators.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $csv);
    }

    public function test_preview_flags_bad_rows_and_import_creates_the_good_ones(): void
    {
        $this->actingAsCrmStaff();

        $csv = "name,language,instagram\nAnna Alpha,de,@anna.alpha\n,de,orphan\nBella Beta,de,anna.alpha\n";

        $component = Livewire::test(CreatorCsvImport::class)
            ->call('open')
            ->set('upload', $this->upload($csv));

        $component->assertSet('rows.0.verdict', 'ready')
            ->assertSet('rows.1.verdict', 'skip')      // missing name
            ->assertSet('rows.2.verdict', 'skip');     // duplicate handle within file

        $component->call('import')->assertHasNoErrors();

        $anna = Creator::query()->where('display_name', 'Anna Alpha')->firstOrFail();
        $this->assertSame('anna.alpha', $anna->platformAccounts()->firstOrFail()->handle); // @ stripped
        $this->assertNull(Creator::query()->where('display_name', 'Bella Beta')->first()?->platformAccounts()->first());
        $this->assertSame(1, Creator::query()->count());
    }

    public function test_imported_creators_are_enrolled_into_monitoring(): void
    {
        $this->actingAsCrmStaff();

        $csv = "name\nMona Monitor\n";
        Livewire::test(CreatorCsvImport::class)
            ->call('open')
            ->set('upload', $this->upload($csv, 'c.csv'))
            ->call('import');

        $creator = Creator::query()->where('display_name', 'Mona Monitor')->firstOrFail();
        $this->assertTrue(
            MonitoredSubject::query()->where('creator_id', $creator->id)->exists()
        );
    }

    public function test_existing_tenant_handle_skips_the_row_but_other_rows_import(): void
    {
        $this->actingAsCrmStaff();

        $existing = Creator::factory()->create();
        PlatformAccount::factory()->forCreator($existing)->onPlatform(Platform::Instagram)->create(['handle' => 'taken.handle']);

        $csv = "name,instagram\nNew Nora,taken.handle\nFree Frida,free.handle\n";
        Livewire::test(CreatorCsvImport::class)
            ->call('open')
            ->set('upload', $this->upload($csv, 'c.csv'))
            ->call('import');

        $this->assertNull(Creator::query()->where('display_name', 'New Nora')->first());
        $this->assertNotNull(Creator::query()->where('display_name', 'Free Frida')->first());
    }

    public function test_a_file_with_more_than_200_rows_is_rejected(): void
    {
        $this->actingAsCrmStaff();

        $lines = "name\n";
        for ($i = 1; $i <= 201; $i++) {
            $lines .= "Creator {$i}\n";
        }

        Livewire::test(CreatorCsvImport::class)
            ->call('open')
            ->set('upload', $this->upload($lines))
            ->assertHasErrors('upload')
            ->assertSet('rows', []);

        $this->assertSame(0, Creator::query()->count());
    }

    public function test_a_file_without_a_name_column_is_rejected(): void
    {
        $this->actingAsCrmStaff();

        $csv = "language,instagram\nde,some.handle\n";

        Livewire::test(CreatorCsvImport::class)
            ->call('open')
            ->set('upload', $this->upload($csv))
            ->assertHasErrors('upload')
            ->assertSet('rows', []);
    }

    public function test_a_view_only_user_cannot_open_the_import(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        Livewire::test(CreatorCsvImport::class)
            ->call('open')
            ->assertForbidden();

        // The persisting mutator re-authorizes too: a viewer who smuggles
        // state past the gated open() must still be refused at import.
        Livewire::test(CreatorCsvImport::class)
            ->call('import')
            ->assertForbidden();
    }

    public function test_an_imported_account_carries_the_csv_import_provenance_surface(): void
    {
        $this->actingAsCrmStaff();

        $csv = "name,instagram\nProvenance Peter,peter.handle\n";
        Livewire::test(CreatorCsvImport::class)
            ->call('open')
            ->set('upload', $this->upload($csv, 'c.csv'))
            ->call('import');

        $account = Creator::query()->where('display_name', 'Provenance Peter')
            ->firstOrFail()->platformAccounts()->firstOrFail();

        $this->assertSame('crm-csv-import-v1', $account->provenance->sourceVersion);
    }

    public function test_a_successful_import_dispatches_creators_imported(): void
    {
        $this->actingAsCrmStaff();

        $csv = "name\nDispatch Dora\n";
        Livewire::test(CreatorCsvImport::class)
            ->call('open')
            ->set('upload', $this->upload($csv, 'c.csv'))
            ->call('import')
            ->assertDispatched('creators-imported');
    }

    public function test_the_import_records_audit_events_for_creators_and_accounts(): void
    {
        $this->actingAsCrmStaff();

        $csv = "name,instagram\nAudit Amy,amy.handle\n";
        Livewire::test(CreatorCsvImport::class)
            ->call('open')
            ->set('upload', $this->upload($csv, 'c.csv'))
            ->call('import');

        $amy = Creator::query()->where('display_name', 'Audit Amy')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'creator.created',
            'subject_id' => $amy->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'platform_account.added',
            'subject_id' => $amy->platformAccounts()->firstOrFail()->id,
        ]);
    }
}
