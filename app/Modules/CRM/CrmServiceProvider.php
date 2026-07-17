<?php

namespace App\Modules\CRM;

use App\Models\User;
use App\Modules\CRM\Console\GdprEnforceRetentionCommand;
use App\Modules\CRM\Console\GdprEraseCreatorCommand;
use App\Modules\CRM\Console\GdprExportCreatorCommand;
use App\Modules\CRM\Console\SendTaskRemindersCommand;
use App\Modules\CRM\Contracts\CreatorProposals;
use App\Modules\CRM\Livewire\Brands\BrandDetail;
use App\Modules\CRM\Livewire\Brands\BrandsIndex;
use App\Modules\CRM\Livewire\Campaigns\CampaignCreatorsPanel;
use App\Modules\CRM\Livewire\Campaigns\CampaignsIndex;
use App\Modules\CRM\Livewire\Clients\ClientsIndex;
use App\Modules\CRM\Livewire\Creators\BrandPreferencesPanel;
use App\Modules\CRM\Livewire\Creators\CommunicationLogPanel;
use App\Modules\CRM\Livewire\Creators\ContactsPanel;
use App\Modules\CRM\Livewire\Creators\CreatorCsvImport;
use App\Modules\CRM\Livewire\Creators\CreatorProfile;
use App\Modules\CRM\Livewire\Creators\CreatorsIndex;
use App\Modules\CRM\Livewire\Creators\GeographyPanel;
use App\Modules\CRM\Livewire\Creators\ParticipationPanel;
use App\Modules\CRM\Livewire\Creators\PlatformAccountsPanel;
use App\Modules\CRM\Livewire\Documents\DocumentsPanel;
use App\Modules\CRM\Livewire\Products\ProductsIndex;
use App\Modules\CRM\Livewire\Results\CampaignResultsPanel;
use App\Modules\CRM\Livewire\Results\SeedingResultsDashboard;
use App\Modules\CRM\Livewire\Results\SeedingResultsPanel;
use App\Modules\CRM\Livewire\Seeding\SeedingCampaignsIndex;
use App\Modules\CRM\Livewire\Seeding\SeedingCreatorsPanel;
use App\Modules\CRM\Livewire\Seeding\SeedingRunCreatePanel;
use App\Modules\CRM\Livewire\Seeding\ShipmentsPanel;
use App\Modules\CRM\Livewire\Tasks\TasksIndex;
use App\Modules\CRM\Livewire\Tasks\TasksPanel;
use App\Modules\CRM\Livewire\Users\UsersIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\DocumentAttachment;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\CRM\Models\Task;
use App\Modules\CRM\Policies\BrandPolicy;
use App\Modules\CRM\Policies\BrandPreferencePolicy;
use App\Modules\CRM\Policies\CampaignPolicy;
use App\Modules\CRM\Policies\ClientPolicy;
use App\Modules\CRM\Policies\CommunicationLogPolicy;
use App\Modules\CRM\Policies\ContactPolicy;
use App\Modules\CRM\Policies\CreatorPolicy;
use App\Modules\CRM\Policies\DocumentAttachmentPolicy;
use App\Modules\CRM\Policies\PlatformAccountPolicy;
use App\Modules\CRM\Policies\ProductPolicy;
use App\Modules\CRM\Policies\SeedingCampaignPolicy;
use App\Modules\CRM\Policies\ShipmentPolicy;
use App\Modules\CRM\Policies\TaskPolicy;
use App\Modules\CRM\Policies\UserPolicy;
use App\Modules\CRM\Services\CreatorProposalIntake;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Module 3 — CRM & Seeding (SVC-CRM, phase P3).
 * Spec: docs/50-modules/module-3-crm-seeding.md. System of record for
 * creator identity; write-owns Creator, PlatformAccount, Client, Brand,
 * Product, Contact, BrandPreference, Campaign, SeedingCampaign, Shipment,
 * CommunicationLog, DocumentAttachment, Task, User, Role (ownership matrix).
 *
 * P0 shipped the module boundary, the roles/permissions substrate, and
 * ADMIN user administration. M3 Step 1 (data foundation) adds the full
 * M3-owned persistence layer, its policies, and the SVC-CRM write seams:
 * CreatorWriter (in-module Creator/PlatformAccount writes), XMC-001
 * (CreatorProposals — M1/M2 proposals, body lands Step 2), and the
 * pre-existing IngestedProfileSync (profile-sync half). XMC-002
 * (content-match feedback, M3 → M1) is Step-3 scope — declared, not built.
 */
class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // XMC-001: M1/M2 proposals create fresh creators via SVC-CRM
        // (AC-M3-002); no dedup — identity is operator-managed (ADR-0014).
        $this->app->bind(CreatorProposals::class, CreatorProposalIntake::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        // ENT-User/ENT-Role writes stay ADMIN-only (AC-M3-018).
        Gate::policy(User::class, UserPolicy::class);

        // M3-owned CRM records: staff crm.view / crm.manage.
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Brand::class, BrandPolicy::class);
        Gate::policy(Creator::class, CreatorPolicy::class);
        Gate::policy(PlatformAccount::class, PlatformAccountPolicy::class);
        Gate::policy(Campaign::class, CampaignPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(BrandPreference::class, BrandPreferencePolicy::class);
        Gate::policy(SeedingCampaign::class, SeedingCampaignPolicy::class);
        Gate::policy(Shipment::class, ShipmentPolicy::class);
        Gate::policy(CommunicationLog::class, CommunicationLogPolicy::class);
        Gate::policy(DocumentAttachment::class, DocumentAttachmentPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);

        Livewire::component('crm.users-index', UsersIndex::class);

        // Step 2 — the first real Module 3 screens (ADR-0012 hand-built
        // Livewire; UsersIndex is the reference CRUD pattern).
        Livewire::component('crm.creators-index', CreatorsIndex::class);
        Livewire::component('crm.creator-csv-import', CreatorCsvImport::class);
        Livewire::component('crm.creator-profile', CreatorProfile::class);
        Livewire::component('crm.creator-participation', ParticipationPanel::class);
        Livewire::component('crm.creator-platform-accounts', PlatformAccountsPanel::class);
        Livewire::component('crm.creator-contacts', ContactsPanel::class);
        Livewire::component('crm.creator-brand-preferences', BrandPreferencesPanel::class);
        Livewire::component('crm.creator-communication-log', CommunicationLogPanel::class);
        Livewire::component('crm.creator-geography', GeographyPanel::class);

        // Step 3 — master data, campaigns, seeding, shipments
        // (REQ-M3-005/006/007 + the operator half of REQ-M3-008).
        Livewire::component('crm.clients-index', ClientsIndex::class);
        Livewire::component('crm.brands-index', BrandsIndex::class);
        Livewire::component('crm.brand-detail', BrandDetail::class);
        Livewire::component('crm.products-index', ProductsIndex::class);
        Livewire::component('crm.campaigns-index', CampaignsIndex::class);
        Livewire::component('crm.campaign-creators', CampaignCreatorsPanel::class);
        Livewire::component('crm.seeding-campaigns-index', SeedingCampaignsIndex::class);
        Livewire::component('crm.seeding-creators', SeedingCreatorsPanel::class);
        Livewire::component('crm.seeding-shipments', ShipmentsPanel::class);
        Livewire::component('crm.seeding-run-create', SeedingRunCreatePanel::class);

        // Step 4 — results & reporting (REQ-M3-009/013): rollup-backed
        // read panels on the detail pages plus the cross-influencer
        // product dashboard. All aggregates flow through RollupReader
        // (ADR-0010) — never FACT-* tables, never OLTP sums.
        Livewire::component('crm.campaign-results', CampaignResultsPanel::class);
        Livewire::component('crm.seeding-results', SeedingResultsPanel::class);
        Livewire::component('crm.seeding-results-dashboard', SeedingResultsDashboard::class);

        // Step 4 — documents & tasks (REQ-M3-010/011): one reusable
        // documents panel per parent record, the tasks CRUD surface, and
        // the compact anchored tasks panel.
        Livewire::component('crm.documents-panel', DocumentsPanel::class);
        Livewire::component('crm.tasks-index', TasksIndex::class);
        Livewire::component('crm.tasks-panel', TasksPanel::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                SendTaskRemindersCommand::class,
                GdprExportCreatorCommand::class,
                GdprEraseCreatorCommand::class,
                GdprEnforceRetentionCommand::class,
            ]);
        }
    }
}
