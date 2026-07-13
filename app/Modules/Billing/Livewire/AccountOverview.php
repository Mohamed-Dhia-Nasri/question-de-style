<?php

namespace App\Modules\Billing\Livewire;

use App\Models\Tenant;
use App\Modules\Billing\Services\SeatLimiter;
use App\Shared\Authorization\PermissionsCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Account page (ADR-0021): tenant name, current plan, subscription status,
 * and seat usage. Read-only — recovery actions live on the owner-gated
 * billing page, team actions on the team page.
 */
class AccountOverview extends Component
{
    public function mount(): void
    {
        $this->authorize(PermissionsCatalog::INTERNAL_ACCESS);
    }

    public function render(SeatLimiter $seats): View
    {
        /** @var Tenant $tenant */
        $tenant = auth()->user()->tenant;
        $subscription = $tenant->currentSubscription();

        return view('livewire.billing.account-overview', [
            'tenant' => $tenant,
            'subscription' => $subscription,
            'seatsUsed' => $seats->activeSeats((int) $tenant->id),
            'seatLimit' => $subscription?->seatLimit(),
            'overLimit' => $seats->overLimit($tenant),
            'isOwner' => Gate::allows('billing.manage'),
            'enforced' => (bool) config('billing.enforced'),
        ]);
    }
}
