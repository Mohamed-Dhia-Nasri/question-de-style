<?php

namespace Tests\Feature\Export;

use App\Models\User;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Livewire\Exports\ExportsIndex;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Platform\Export\ExportManager;
use App\Platform\Export\Models\ExportJob;
use App\Platform\Export\ReportBuilder;
use App\Platform\Export\Support\ExportJobStatus;
use App\Shared\Audit\AuditLog;
use App\Shared\Enums\ExportFormat;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SVC-Export (REQ-M1-012, AC-M1-012): validated filters, private expiring
 * storage, signed downloads, duplicate prevention, personal-data
 * exclusion, tier/disclosure fidelity, and auditing.
 */
class ExportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('exports');
    }

    private function analyst(): User
    {
        return $this->makeUser(RoleName::Analyst);
    }

    private function requestCsv(User $user, array $filters = ['grain' => 'month']): ExportJob
    {
        return app(ExportManager::class)
            ->request($user, ReportBuilder::MONITORING_SUMMARY, ExportFormat::Csv, $filters);
    }

    public function test_export_completes_into_private_storage_with_tier_labels_and_disclosures(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $job = $this->requestCsv($user)->fresh();

        // QUEUE_CONNECTION=sync in tests: the render already ran.
        $this->assertSame(ExportJobStatus::Completed, $job->status);
        $this->assertSame('exports', $job->disk);
        $this->assertNotNull($job->expires_at);
        $this->assertTrue($job->expires_at->isFuture());

        Storage::disk('exports')->assertExists($job->file_path);
        $csv = Storage::disk('exports')->get($job->file_path);

        // Metric tiers, filter set, EMV model disclosure, and deferred
        // capabilities all survive into the artifact (AC-M1-011/012).
        $this->assertStringContainsString('[PUBLIC]', $csv);
        $this->assertStringContainsString('[DERIVED]', $csv);
        $this->assertStringContainsString('[ESTIMATED]', $csv);
        $this->assertStringContainsString('Filter: Grain', $csv);
        $this->assertStringContainsString('EMV model: Unavailable', $csv);
        $this->assertStringContainsString('DEF-003', $csv);
        $this->assertStringContainsString('DEF-005', $csv);

        // Personal data never enters a default export.
        $this->assertStringNotContainsString($user->email, $csv);
        $this->assertStringContainsString('Unavailable', $csv); // absent values render the literal, never zero

        // Audit trail: requested + completed, content-free context.
        $this->assertSame(1, AuditLog::query()->where('action', 'export.requested')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'export.completed')->count());
    }

    public function test_excel_and_pdf_artifacts_have_their_format_signatures(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $xlsx = app(ExportManager::class)
            ->request($user, ReportBuilder::MONITORING_SUMMARY, ExportFormat::Excel, ['grain' => 'month'])
            ->fresh();
        $pdf = app(ExportManager::class)
            ->request($user, ReportBuilder::MONITORING_SUMMARY, ExportFormat::Pdf, ['grain' => 'month'])
            ->fresh();

        $this->assertStringStartsWith("PK\x03\x04", Storage::disk('exports')->get($xlsx->file_path));
        $this->assertStringStartsWith('%PDF-1.4', Storage::disk('exports')->get($pdf->file_path));
    }

    public function test_duplicate_live_requests_collapse_onto_one_job(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        // Keep the first job live so the duplicate window applies.
        config(['queue.default' => 'database']);

        $first = $this->requestCsv($user);
        $second = $this->requestCsv($user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ExportJob::query()->count());
    }

    public function test_unsupported_filters_are_rejected_not_silently_ignored(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $this->expectException(ValidationException::class);
        $this->requestCsv($user, ['grain' => 'month', 'sentiment' => 'POSITIVE']);
    }

    public function test_download_requires_signature_authorization_and_unexpired_artifact(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);
        $job = $this->requestCsv($user)->fresh();

        // Unsigned URL → rejected even for the owner.
        $this->get(route('exports.download', ['exportJob' => $job->id]))->assertForbidden();

        $signed = app(ExportManager::class)->downloadUrl($job);
        $this->get($signed)->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'export.downloaded')->count());

        // Another (non-admin) staff member cannot download someone else's export.
        $other = $this->makeUser(RoleName::CampaignManager);
        $this->actingAs($other);
        $this->get(app(ExportManager::class)->downloadUrl($job))->assertForbidden();

        // Expired artifact → gone.
        $this->actingAs($user);
        $job->update(['expires_at' => now()->subMinute()]);
        $this->get(app(ExportManager::class)->downloadUrl($job->fresh()))->assertStatus(410);
    }

    public function test_client_viewer_can_never_request_or_download_exports(): void
    {
        $client = $this->makeUser(RoleName::ClientViewer);
        $this->actingAs($client);

        $this->get('/monitoring/exports')->assertForbidden();

        $staffJob = ExportJob::factory()->completed()->create();
        Storage::disk('exports')->put($staffJob->file_path, 'x');

        $signed = URL::temporarySignedRoute('exports.download', now()->addMinutes(5), ['exportJob' => $staffJob->id]);
        $this->get($signed)->assertForbidden();
    }

    public function test_seeding_results_export_builds_with_tier_suffixed_columns_and_disclosures(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $job = app(ExportManager::class)
            ->request($user, ReportBuilder::SEEDING_RESULTS, ExportFormat::Csv, ['grain' => 'month'])
            ->fresh();

        $this->assertSame(ExportJobStatus::Completed, $job->status);
        $csv = Storage::disk('exports')->get($job->file_path);

        // Section titles + tier-suffixed columns (Step-4 spec §2.5).
        $this->assertStringContainsString('ROLLUP-SeedingByProduct', $csv);
        $this->assertStringContainsString('ROLLUP-SeedingByShipment', $csv);
        $this->assertStringContainsString('Shipments [CONFIRMED]', $csv);
        $this->assertStringContainsString('Post rate [DERIVED]', $csv);
        $this->assertStringContainsString('Total views [PUBLIC]', $csv);
        $this->assertStringContainsString('Estimated reach [ESTIMATED]', $csv);
        $this->assertStringContainsString('EMV [ESTIMATED]', $csv);

        // Disclosures: tier legend, CPE/CPM formulas, DEF-003 reach note,
        // and the EMV model line (Unavailable — no active configuration).
        $this->assertStringContainsString('Metric tiers: PUBLIC', $csv);
        $this->assertStringContainsString('CPE = agency-entered spend', $csv);
        $this->assertStringContainsString('CPM = spend / (total views', $csv);
        $this->assertStringContainsString('DEF-003', $csv);
        $this->assertStringContainsString('EMV model: Unavailable', $csv);
    }

    public function test_seeding_export_mirrors_dashboard_filters_with_slices_and_period_labels(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        // Slice + product filters now ride the SAME validated set as the
        // dashboard (REQ-M1-012 parity); grain=year proves the multi-year
        // period labels ("2026") replace bare bucket dates.
        $brand = Brand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        // Dim lookups feed the filter echo — names live in dims after a refresh.
        app(AnalyticsService::class)->refreshRollups();

        $job = app(ExportManager::class)
            ->request($user, ReportBuilder::SEEDING_RESULTS, ExportFormat::Csv, [
                'grain' => 'year',
                'brand_id' => $brand->id,
                'product_id' => $product->id,
                'platform' => 'INSTAGRAM',
            ])
            ->fresh();

        $this->assertSame(ExportJobStatus::Completed, $job->status);
        $csv = Storage::disk('exports')->get($job->file_path);

        // The slice section joins the document; the filter echo names every
        // active dimension so the file is self-describing.
        $this->assertStringContainsString('Slice breakdown', $csv);
        $this->assertStringContainsString('slice-agnostic', $csv);
        $this->assertStringContainsString('Filter: Product', $csv);
        $this->assertStringContainsString($product->name, $csv);
        $this->assertStringContainsString('"Filter: Platform",INSTAGRAM', $csv);
        $this->assertStringContainsString('Period', $csv);
        // Geography columns are CREATOR-scoped by name (never brand/product).
        $this->assertStringContainsString('Creator country', $csv);
        $this->assertStringContainsString('CREATOR attribute', $csv);

        // Unknown slice values are rejected, never silently ignored.
        $this->expectException(ValidationException::class);
        app(ExportManager::class)->request($user, ReportBuilder::SEEDING_RESULTS, ExportFormat::Csv, [
            'city' => 'Atlantis',
        ]);
    }

    public function test_the_export_kind_list_derives_from_the_report_builder(): void
    {
        $this->assertSame(
            [ReportBuilder::MONITORING_SUMMARY, ReportBuilder::SEEDING_RESULTS],
            ReportBuilder::reports(),
        );

        $user = $this->analyst();
        $this->actingAs($user);

        // The exports screen requests whichever registered kind is picked…
        Livewire::test(ExportsIndex::class)
            ->set('report', ReportBuilder::SEEDING_RESULTS)
            ->call('requestExport')
            ->assertHasNoErrors();

        $this->assertSame(ReportBuilder::SEEDING_RESULTS, ExportJob::query()->sole()->report);

        // …and an unknown kind is rejected server-side, never silently mapped.
        Livewire::test(ExportsIndex::class)
            ->set('report', 'bogus-report')
            ->call('requestExport')
            ->assertHasErrors(['report']);
    }

    public function test_pruning_deletes_expired_artifacts_and_marks_jobs(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);
        $job = $this->requestCsv($user)->fresh();
        $path = $job->file_path;

        $job->update(['expires_at' => now()->subHour()]);

        $this->artisan('qds:prune-expired-exports')->assertSuccessful();

        Storage::disk('exports')->assertMissing($path);
        $this->assertSame(ExportJobStatus::Expired, $job->fresh()->status);
        $this->assertNull($job->fresh()->file_path);
        $this->assertSame(1, AuditLog::query()->where('action', 'export.pruned')->count());
    }
}
