<?php

namespace App\Modules\Discovery;

use App\Modules\Discovery\Contracts\CreatorGeography;
use App\Modules\Discovery\Services\CreatorGeographyWriter;
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
    public function register(): void
    {
        // ADR-0018 — operator-assigned creator geography: ENT-GeoAttribution
        // is M2-owned, so the CRM writes it only through this owner-side
        // seam (the XMC-001/XMC-003 contract pattern).
        $this->app->bind(
            CreatorGeography::class,
            CreatorGeographyWriter::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
