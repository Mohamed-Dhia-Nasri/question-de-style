<?php

namespace App\Modules\Discovery;

use Illuminate\Support\ServiceProvider;

/**
 * Module 2 — Discovery (SVC-Discovery, phase P2).
 * Spec: docs/50-modules/module-2-discovery.md. Write-owns
 * SectorClassification, GeoAttribution, AuthenticityAssessment,
 * SuitabilityScore, Shortlist (ownership matrix). New creators are PROPOSED
 * to CRM via XMC-001 — never written directly.
 *
 * P0 ships only the module boundary, its route area, and navigation entry;
 * REQ-M2-* behaviour is buildable when P2 becomes the active phase.
 */
class DiscoveryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
