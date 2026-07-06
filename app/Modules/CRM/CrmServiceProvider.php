<?php

namespace App\Modules\CRM;

use App\Models\User;
use App\Modules\CRM\Contracts\CreatorProposals;
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
use App\Modules\CRM\Services\PendingCreatorProposals;
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
        // XMC-001 seam: implementation is M3 Step-2 work.
        $this->app->bind(CreatorProposals::class, PendingCreatorProposals::class);
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
    }
}
