<?php

namespace App\Modules\CRM\Livewire\Seeding;

use App\Modules\CRM\Exceptions\BrandRestrictionViolation;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\CRM\Services\BrandRestrictionGuard;
use App\Modules\CRM\Services\ShipmentContentWriter;
use App\Modules\Monitoring\Contracts\ContentMatchFeedback;
use App\Modules\Monitoring\Models\ContentItem;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Tenancy\TenantRule;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Shipments panel (REQ-M3-007) — per-shipment lifecycle inside a seeding
 * run. AC-M3-012: status is always exactly one ENUM-ShipmentStatus value,
 * manual updates always supported (courier APIs are optional and outside
 * the frozen stack); status transitions are recorded.
 *
 * posted/postedAt are MATCHING-owned (REQ-M3-008) and read-only here; the
 * operator's manual "Link content" / "Remove link" actions are the human
 * half of XMC-002 (confirm/deny) — M3 rows go through ShipmentContentWriter,
 * mention attribution through the M1-side ContentMatchFeedback contract.
 */
class ShipmentsPanel extends Component
{
    public SeedingCampaign $seedingCampaign;

    // --- create/edit form state ---
    public bool $showForm = false;

    public ?int $editingShipmentId = null;

    public string $shipment_creator_id = '';

    public string $shipment_product_id = '';

    public string $shipment_status = '';

    public string $shipment_tracking_number = '';

    public string $shipment_shipped_at = '';

    public string $shipment_delivered_at = '';

    public string $shipment_quantity = '';

    public string $shipment_value = '';

    public bool $shipment_posting_required = false;

    // --- delete confirmation state ---
    public ?int $confirmingDeleteId = null;

    // --- manual content link state (XMC-002 confirm/deny) ---
    public ?int $linkingShipmentId = null;

    public string $link_content_id = '';

    public ?int $unlinkShipmentId = null;

    public ?int $unlinkContentId = null;

    public function mount(SeedingCampaign $seedingCampaign): void
    {
        $this->authorize('view', $seedingCampaign);

        $this->seedingCampaign = $seedingCampaign;
    }

    // --- create / edit -----------------------------------------------------

    public function create(): void
    {
        $this->authorize('create', Shipment::class);

        $this->resetForm();
        $this->shipment_status = ShipmentStatus::Pending->value;
        $this->shipment_product_id = $this->seedingCampaign->product_id !== null
            ? (string) $this->seedingCampaign->product_id
            : '';
        $this->showForm = true;
    }

    public function edit(int $shipmentId): void
    {
        $shipment = $this->seedingCampaign->shipments()->findOrFail($shipmentId);

        $this->authorize('update', $shipment);

        $this->resetForm();
        $this->editingShipmentId = $shipment->id;
        $this->shipment_creator_id = (string) $shipment->creator_id;
        $this->shipment_product_id = (string) $shipment->product_id;
        $this->shipment_status = $shipment->status->value;
        $this->shipment_tracking_number = $shipment->tracking_number ?? '';
        $this->shipment_shipped_at = $shipment->shipped_at?->format('Y-m-d\TH:i') ?? '';
        $this->shipment_delivered_at = $shipment->delivered_at?->format('Y-m-d\TH:i') ?? '';
        $this->shipment_quantity = $shipment->quantity !== null ? (string) $shipment->quantity : '';
        $this->shipment_value = $shipment->product_value_at_ship !== null
            ? (string) $shipment->product_value_at_ship->amount
            : '';
        $this->shipment_posting_required = (bool) $shipment->posting_required;
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $editing = $this->editingShipmentId !== null;
        $shipment = $editing ? $this->seedingCampaign->shipments()->findOrFail($this->editingShipmentId) : null;

        $this->authorize($editing ? 'update' : 'create', $shipment ?? Shipment::class);

        $validated = $this->validate([
            // Spec D5 + F03: recipients live on the run's roster — an
            // off-roster recipient is auto-attached at save (brand
            // restrictions still block).
            'shipment_creator_id' => ['required', 'integer'],
            'shipment_product_id' => ['required', 'integer', TenantRule::exists('products', 'id')],
            'shipment_status' => ['required', Rule::in(array_column(ShipmentStatus::cases(), 'value'))],
            'shipment_tracking_number' => ['nullable', 'string', 'max:255'],
            'shipment_shipped_at' => ['nullable', 'date'],
            'shipment_delivered_at' => ['nullable', 'date', 'after_or_equal:shipment_shipped_at'],
            'shipment_quantity' => ['nullable', 'integer', 'min:1'],
            'shipment_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $creatorId = (int) $validated['shipment_creator_id'];
        $productId = (int) $validated['shipment_product_id'];

        // F03 self-heal: a shipment recipient belongs on the roster. For new
        // shipments the dropdown only offers roster creators, so this path is
        // legacy rows (pre-backfill) and non-UI writes — attach instead of
        // dead-ending, but never past a brand restriction. All checks below
        // are read-only; the actual attach happens later, inside the
        // transaction, only once every validation has passed — otherwise a
        // later failure (e.g. the product check) would leave a phantom
        // roster attach for a shipment that was never created.
        $needsAttach = ! $this->seedingCampaign->creators()->whereKey($creatorId)->exists();
        $creatorToAttach = null;

        if ($needsAttach) {
            $creatorToAttach = Creator::query()->whereKey($creatorId)->first();

            if ($creatorToAttach === null) {
                throw ValidationException::withMessages([
                    'shipment_creator_id' => 'Pick a creator from this seeding run.',
                ]);
            }

            try {
                app(BrandRestrictionGuard::class)->assertNotRestricted($creatorToAttach, $this->seedingCampaign->brand);
            } catch (BrandRestrictionViolation $violation) {
                throw ValidationException::withMessages(['shipment_creator_id' => $violation->getMessage()]);
            }
        }

        if (Product::findOrFail($productId)->brand_id !== $this->seedingCampaign->brand_id) {
            throw ValidationException::withMessages([
                'shipment_product_id' => 'The product must belong to the seeding run\'s brand.',
            ]);
        }

        $previousStatus = $shipment?->status;

        $attributes = [
            'creator_id' => $creatorId,
            'product_id' => $productId,
            'status' => ShipmentStatus::from($validated['shipment_status']),
            'tracking_number' => ($validated['shipment_tracking_number'] ?? '') !== '' ? $validated['shipment_tracking_number'] : null,
            'shipped_at' => ($validated['shipment_shipped_at'] ?? '') !== '' ? $validated['shipment_shipped_at'] : null,
            'delivered_at' => ($validated['shipment_delivered_at'] ?? '') !== '' ? $validated['shipment_delivered_at'] : null,
            'quantity' => ($validated['shipment_quantity'] ?? '') !== '' ? (int) $validated['shipment_quantity'] : null,
            // Manual agency input → tier CONFIRMED (glossary ENUM-MetricTier).
            'product_value_at_ship' => ($validated['shipment_value'] ?? '') !== ''
                ? new MetricValue((float) $validated['shipment_value'], MetricTier::Confirmed)
                : null,
            'posting_required' => $this->shipment_posting_required,
        ];

        // Everything above is validation-only (no writes). Everything below
        // mutates, so it runs atomically: the self-heal attach and the
        // shipment write either both land or neither does.
        DB::transaction(function () use ($needsAttach, $creatorToAttach, $audit, $editing, &$shipment, $attributes, $previousStatus) {
            if ($needsAttach) {
                $this->seedingCampaign->creators()->syncWithoutDetaching([$creatorToAttach->id]);
                $audit->record('seeding_campaign_creator.attached', $this->seedingCampaign, ['creator_id' => $creatorToAttach->id]);
            }

            if ($editing) {
                $shipment->update($attributes);
            } else {
                $shipment = $this->seedingCampaign->shipments()->create($attributes);
            }

            $audit->record($editing ? 'shipment.updated' : 'shipment.created', $shipment, [
                'creator_id' => $shipment->creator_id,
                'product_id' => $shipment->product_id,
            ]);

            // AC-M3-012: state changes are recorded.
            if ($editing && $previousStatus !== $shipment->status) {
                $audit->record('shipment.status_changed', $shipment, [
                    'from' => $previousStatus->value,
                    'to' => $shipment->status->value,
                ]);
            }
        });

        $this->seedingCampaign->refresh();
        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: $editing ? 'Shipment updated.' : 'Shipment created.');
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    // --- delete ------------------------------------------------------------

    public function confirmDelete(int $shipmentId): void
    {
        $this->authorize('delete', $this->seedingCampaign->shipments()->findOrFail($shipmentId));

        $this->confirmingDeleteId = $shipmentId;
    }

    public function delete(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $shipment = $this->seedingCampaign->shipments()->findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $shipment);

        $audit->record('shipment.deleted', $shipment, ['creator_id' => $shipment->creator_id]);

        $shipment->delete();

        $this->seedingCampaign->refresh();
        $this->confirmingDeleteId = null;
        $this->dispatch('notify', type: 'success', message: 'Shipment deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    // --- manual content links (the operator half of XMC-002) ----------------

    public function openLinkForm(int $shipmentId): void
    {
        $this->authorize('update', $this->seedingCampaign->shipments()->findOrFail($shipmentId));

        $this->resetValidation();
        $this->linkingShipmentId = $shipmentId;
        $this->link_content_id = '';
    }

    public function linkContent(ShipmentContentWriter $writer, ContentMatchFeedback $feedback, AuditLogger $audit): void
    {
        if ($this->linkingShipmentId === null) {
            return;
        }

        $shipment = $this->seedingCampaign->shipments()->findOrFail($this->linkingShipmentId);

        $this->authorize('update', $shipment);

        $validated = $this->validate([
            'link_content_id' => ['required', 'integer', TenantRule::exists('content_items', 'id')],
        ]);

        $content = ContentItem::findOrFail((int) $validated['link_content_id']);

        $outcome = $writer->link($shipment->id, $content);

        if ($outcome === null) {
            throw ValidationException::withMessages([
                'link_content_id' => 'This content does not belong to the shipment\'s recipient.',
            ]);
        }

        if ($outcome->newlyLinked) {
            $audit->record('match.confirmed', $shipment, ['content_item_id' => $content->id]);

            // XMC-002 confirm: attribute the content's mentions to the run's
            // parent campaign (the M1-side recorder is the only writer).
            // Scoped to THIS shipment's evidence (C1): only the mention(s)
            // this link justifies are stamped, never brand-foreign siblings.
            if ($outcome->campaignId !== null) {
                $feedback->confirm($content, $outcome->campaignId, [$shipment->id]);
            }
        }

        $this->seedingCampaign->refresh();
        $this->linkingShipmentId = null;
        $this->link_content_id = '';
        $this->dispatch('notify', type: 'success', message: 'Content linked to the shipment.');
    }

    public function cancelLinkForm(): void
    {
        $this->linkingShipmentId = null;
        $this->link_content_id = '';
        $this->resetValidation();
    }

    public function confirmUnlink(int $shipmentId, int $contentItemId): void
    {
        $this->authorize('update', $this->seedingCampaign->shipments()->findOrFail($shipmentId));

        $this->unlinkShipmentId = $shipmentId;
        $this->unlinkContentId = $contentItemId;
    }

    public function unlink(ShipmentContentWriter $writer, ContentMatchFeedback $feedback, AuditLogger $audit): void
    {
        if ($this->unlinkShipmentId === null || $this->unlinkContentId === null) {
            return;
        }

        $shipment = $this->seedingCampaign->shipments()->findOrFail($this->unlinkShipmentId);

        $this->authorize('update', $shipment);

        $content = ContentItem::findOrFail($this->unlinkContentId);

        $writer->unlink($shipment, $content);

        $audit->record('match.denied', $shipment, ['content_item_id' => $content->id]);

        // XMC-002 deny: retract the parent-campaign attribution — but only
        // when NO surviving shipment link still justifies it (deep-review
        // finding M3: removing one of two links must not retract an
        // attribution the other link still supports).
        if ($this->seedingCampaign->campaign_id !== null && ! $this->survivingLinkFor($content)) {
            $feedback->deny($content, $this->seedingCampaign->campaign_id);
        }

        $this->seedingCampaign->refresh();
        $this->unlinkShipmentId = null;
        $this->unlinkContentId = null;
        $this->dispatch('notify', type: 'success', message: 'Content link removed.');
    }

    /**
     * True when the content is still linked to at least one shipment whose
     * seeding run resolves to this run's parent campaign — in that case the
     * attribution remains justified and must not be retracted (M3).
     */
    private function survivingLinkFor(ContentItem $content): bool
    {
        return Shipment::query()
            ->whereHas('resultingContent', fn ($query) => $query->whereKey($content->id))
            ->whereHas('seedingCampaign', fn ($query) => $query->where('campaign_id', $this->seedingCampaign->campaign_id))
            ->exists();
    }

    public function cancelUnlink(): void
    {
        $this->unlinkShipmentId = null;
        $this->unlinkContentId = null;
    }

    // -------------------------------------------------------------------------

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingShipmentId = null;
        $this->shipment_creator_id = '';
        $this->shipment_product_id = '';
        $this->shipment_status = '';
        $this->shipment_tracking_number = '';
        $this->shipment_shipped_at = '';
        $this->shipment_delivered_at = '';
        $this->shipment_quantity = '';
        $this->shipment_value = '';
        $this->shipment_posting_required = false;
    }

    public function render(): View
    {
        $shipments = $this->seedingCampaign->shipments()
            ->with(['creator', 'product', 'resultingContent'])
            ->orderBy('id')
            ->get();

        $linkableContent = collect();

        if ($this->linkingShipmentId !== null) {
            $shipment = $shipments->firstWhere('id', $this->linkingShipmentId);

            if ($shipment !== null) {
                $linkableContent = ContentItem::query()
                    ->whereHas('platformAccount', fn ($query) => $query->where('creator_id', $shipment->creator_id))
                    ->whereNotIn('id', $shipment->resultingContent->pluck('id'))
                    ->orderByDesc('published_at')
                    ->limit(50)
                    ->get();
            }
        }

        return view('livewire.crm.seeding-shipments', [
            'shipments' => $shipments,
            'recipients' => $this->seedingCampaign->creators()->orderBy('display_name')->get(),
            'products' => $this->seedingCampaign->brand->products()->orderBy('name')->get(),
            'statuses' => ShipmentStatus::cases(),
            'linkableContent' => $linkableContent,
        ]);
    }
}
