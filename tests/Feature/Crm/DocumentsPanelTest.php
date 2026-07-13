<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Documents\DocumentsPanel;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\DocumentAttachment;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Audit\AuditLog;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Documents (REQ-M3-010, AC-M3-016): one reusable panel anchored to a
 * creator, campaign, or seeding run (seeding anchor: spec D6), private-disk
 * blobs behind short-lived signed downloads (spec D7 — the SVC-Export
 * precedent), uploads/deletes audited, and crm.manage enforced on every
 * mutator — including the direct-property bypass path.
 */
class DocumentsPanelTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    /** Puts a real blob on the (faked) documents disk and anchors a row to it. */
    private function makeStoredDocument(array $attributes = []): DocumentAttachment
    {
        $path = 'documents/2026/07/'.fake()->uuid().'.pdf';
        Storage::disk((string) config('qds.documents.disk'))->put($path, 'blob-bytes');

        return DocumentAttachment::factory()->create(['storage_url' => $path] + $attributes);
    }

    public function test_client_viewers_cannot_mount_the_panel(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        Livewire::test(DocumentsPanel::class, ['creator' => Creator::factory()->create()])
            ->assertForbidden();
    }

    public function test_the_panel_requires_exactly_one_parent(): void
    {
        $this->actingAsCrmStaff();

        // The InvalidArgumentException from mount surfaces wrapped by the
        // Blade layer that renders the component.
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('must be mounted with exactly one parent');

        Livewire::test(DocumentsPanel::class, [
            'creator' => Creator::factory()->create(),
            'campaign' => Campaign::factory()->create(),
        ]);
    }

    public function test_a_parentless_mount_is_refused(): void
    {
        $this->actingAsCrmStaff();

        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('must be mounted with exactly one parent');

        Livewire::test(DocumentsPanel::class);
    }

    public function test_a_document_uploads_and_anchors_to_each_parent_kind(): void
    {
        Storage::fake((string) config('qds.documents.disk'));
        $this->actingAsCrmStaff();

        $parents = [
            'creator' => ['creator_id', Creator::factory()->create()],
            'campaign' => ['campaign_id', Campaign::factory()->create()],
            'seedingCampaign' => ['seeding_campaign_id', SeedingCampaign::factory()->create()],
        ];

        foreach ($parents as $parameter => [$column, $parent]) {
            Livewire::test(DocumentsPanel::class, [$parameter => $parent])
                ->call('openForm')
                ->set('upload', UploadedFile::fake()->create('contract.pdf', 12))
                ->call('save')
                ->assertHasNoErrors();

            $document = DocumentAttachment::query()->where($column, $parent->id)->firstOrFail();

            // AC-M3-016: the upload links to the anchoring record, keeps the
            // original client name as display metadata, and the blob lands on
            // the private disk under the owning tenant's documents/ prefix
            // (ADR-0019; never the client name).
            $this->assertSame('contract.pdf', $document->file_name);
            $this->assertStringStartsWith("tenants/{$document->tenant_id}/documents/", $document->storage_url);
            $this->assertNotNull($document->uploaded_at);
            Storage::disk((string) config('qds.documents.disk'))->assertExists($document->storage_url);
            $this->assertDatabaseHas('audit_logs', ['action' => 'document.uploaded', 'subject_id' => $document->id]);
            // L2 pin: no client-supplied name in the immutable audit context.
            $uploadLog = AuditLog::query()
                ->where('action', 'document.uploaded')->where('subject_id', $document->id)->firstOrFail();
            $this->assertArrayNotHasKey('file_name', $uploadLog->context);
        }
    }

    public function test_disallowed_extensions_and_oversized_files_are_refused(): void
    {
        Storage::fake((string) config('qds.documents.disk'));
        $this->actingAsCrmStaff();
        $creator = Creator::factory()->create();

        // Extension outside the D7 allowlist.
        Livewire::test(DocumentsPanel::class, ['creator' => $creator])
            ->call('openForm')
            ->set('upload', UploadedFile::fake()->create('malware.exe', 12))
            ->call('save')
            ->assertHasErrors(['upload']);

        // Above the 10 MB cap (max:10240 is kilobytes).
        Livewire::test(DocumentsPanel::class, ['creator' => $creator])
            ->call('openForm')
            ->set('upload', UploadedFile::fake()->create('huge.pdf', 10_241))
            ->call('save')
            ->assertHasErrors(['upload']);

        $this->assertDatabaseCount('document_attachments', 0);
    }

    public function test_downloads_stream_via_a_signed_url_only(): void
    {
        Storage::fake((string) config('qds.documents.disk'));
        $this->actingAsCrmStaff();

        $document = $this->makeStoredDocument(['file_name' => 'brief.pdf']);

        // Unsigned URL → rejected even for authorized staff.
        $this->get(route('crm.documents.download', ['documentAttachment' => $document->id]))
            ->assertForbidden();

        $signed = URL::temporarySignedRoute(
            'crm.documents.download',
            now()->addMinutes(5),
            ['documentAttachment' => $document->id],
        );

        $response = $this->get($signed);
        $response->assertOk();
        $this->assertStringContainsString('brief.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.downloaded', 'subject_id' => $document->id]);
        // L2 pin: no client-supplied name in the immutable audit context.
        $downloadLog = AuditLog::query()
            ->where('action', 'document.downloaded')->where('subject_id', $document->id)->firstOrFail();
        $this->assertArrayNotHasKey('file_name', $downloadLog->context ?? []);
    }

    public function test_client_viewers_cannot_download_even_with_a_valid_signature(): void
    {
        Storage::fake((string) config('qds.documents.disk'));
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $document = $this->makeStoredDocument();

        $signed = URL::temporarySignedRoute(
            'crm.documents.download',
            now()->addMinutes(5),
            ['documentAttachment' => $document->id],
        );

        $this->get($signed)->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'document.downloaded']);
    }

    public function test_delete_removes_the_row_and_the_blob(): void
    {
        Storage::fake((string) config('qds.documents.disk'));
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $document = $this->makeStoredDocument(['creator_id' => $creator->id]);

        Livewire::test(DocumentsPanel::class, ['creator' => $creator])
            ->call('confirmDelete', $document->id)
            ->call('delete');

        $this->assertDatabaseMissing('document_attachments', ['id' => $document->id]);
        Storage::disk((string) config('qds.documents.disk'))->assertMissing($document->storage_url);

        $log = AuditLog::query()
            ->where('action', 'document.deleted')
            ->where('subject_id', $document->id)
            ->firstOrFail();
        // Deep-review L2: the append-only audit context carries ids ONLY —
        // a client-supplied file name may be personal data and would
        // survive a GDPR erasure in the immutable log.
        $this->assertArrayNotHasKey('file_name', $log->context);
        $this->assertSame($creator->id, $log->context['creator_id']);
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        Storage::fake((string) config('qds.documents.disk'));
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $creator = Creator::factory()->create();
        $document = $this->makeStoredDocument(['creator_id' => $creator->id]);

        Livewire::test(DocumentsPanel::class, ['creator' => $creator])->assertOk()
            ->call('openForm')->assertForbidden();

        // Direct-property bypass: skipping openForm must not skip the policy.
        Livewire::test(DocumentsPanel::class, ['creator' => $creator])
            ->set('upload', UploadedFile::fake()->create('contract.pdf', 12))
            ->call('save')->assertForbidden();
        Livewire::test(DocumentsPanel::class, ['creator' => $creator])
            ->set('confirmingDeleteId', $document->id)
            ->call('delete')->assertForbidden();

        $this->assertDatabaseHas('document_attachments', ['id' => $document->id]);
        Storage::disk((string) config('qds.documents.disk'))->assertExists($document->storage_url);
        $this->assertDatabaseCount('document_attachments', 1);
    }
}
