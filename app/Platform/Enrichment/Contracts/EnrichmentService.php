<?php

namespace App\Platform\Enrichment\Contracts;

/**
 * SVC-EnrichmentAI (L3) — docs/60-architecture/00-system-architecture.md.
 *
 * Produces every inferred/estimated value (mention classification, sentiment,
 * EMV, brand recognition) and attaches the ConfidenceAssessment envelope
 * (DP-003). Low-confidence outputs route to a human review queue; corrections
 * are stored and feed future rules (DP-004). AI values use the AI_ASSESSED
 * verification state.
 *
 * Implementation is P1 work; the AI providers are limited to the frozen
 * SRC-google-* contracts (SourceRegistry, ADR-0001).
 */
interface EnrichmentService
{
    /**
     * Run the enrichment pipeline for one ingested record. Every produced
     * value must carry a ConfidenceAssessment; nothing inferred is stored as
     * bare fact.
     */
    public function enrich(object $record): void;
}
