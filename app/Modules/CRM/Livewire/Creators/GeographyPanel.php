<?php

namespace App\Modules\CRM\Livewire\Creators;

use App\Modules\CRM\Models\Creator;
use App\Modules\Discovery\Contracts\CreatorGeography;
use App\Modules\Discovery\Models\GeoAttribution;
use App\Shared\Enums\Country;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Operator-assigned creator geography (ADR-0018): the human half of
 * REQ-M2-003 ahead of Module 2's automatic inference. The panel never
 * writes ENT-GeoAttribution itself — every mutation goes through the
 * M2-owned CreatorGeography seam, and the stored row carries its
 * HUMAN_REVIEWED ConfidenceAssessment (DP-003: location is never a fact).
 *
 * Feeds DIM-Geo on the next rollup refresh, which lights up the country
 * slices on /crm/results and the geo rollup for this creator's content.
 */
class GeographyPanel extends Component
{
    public Creator $creator;

    public string $geo_country = '';

    public string $geo_region = '';

    public string $geo_city = '';

    public function mount(Creator $creator): void
    {
        $this->authorize('view', $creator);

        $this->fillFromCurrent();
    }

    public function save(CreatorGeography $geography): void
    {
        $this->authorize('update', $this->creator);

        // Case-insensitive on entry (the seam stores uppercase either way).
        $this->geo_country = strtoupper(trim($this->geo_country));

        $validated = $this->validate([
            // Operating countries only (DACH + France) — see Country enum.
            'geo_country' => ['nullable', 'string', Rule::in(Country::values())],
            'geo_region' => ['nullable', 'string', 'max:255'],
            'geo_city' => ['nullable', 'string', 'max:255'],
        ]);

        $country = trim($validated['geo_country'] ?? '') !== '' ? strtoupper(trim($validated['geo_country'])) : null;
        $region = trim($validated['geo_region'] ?? '') !== '' ? trim($validated['geo_region']) : null;
        $city = trim($validated['geo_city'] ?? '') !== '' ? trim($validated['geo_city']) : null;

        // City/region without a country cannot feed the country slices and
        // is almost always a slip — require the country as the anchor.
        if ($country === null && ($region !== null || $city !== null)) {
            throw ValidationException::withMessages([
                'geo_country' => 'Set the country to anchor the region/city.',
            ]);
        }

        if ($country === null) {
            $geography->clear($this->creator);
            $this->fillFromCurrent();
            $this->dispatch('notify', type: 'success', message: 'Geography cleared — it renders unavailable again.');

            return;
        }

        $geography->assign($this->creator, $country, $region, $city);
        $this->fillFromCurrent();
        $this->dispatch('notify', type: 'success', message: 'Geography assigned. Rollups pick it up on the next refresh.');
    }

    private function fillFromCurrent(): void
    {
        $current = $this->current();
        $this->geo_country = $current->country_code ?? '';
        $this->geo_region = $current->region ?? '';
        $this->geo_city = $current->city ?? '';
    }

    private function current(): ?GeoAttribution
    {
        return GeoAttribution::query()->where('creator_id', $this->creator->id)->first();
    }

    public function render(): View
    {
        return view('livewire.crm.creator-geography', [
            'current' => $this->current(),
            'countries' => Country::cases(),
        ]);
    }
}
