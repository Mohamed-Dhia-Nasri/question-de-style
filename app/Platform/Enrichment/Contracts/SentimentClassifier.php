<?php

namespace App\Platform\Enrichment\Contracts;

use App\Platform\Enrichment\Sentiment\SentimentPrediction;

/**
 * Sentiment classification boundary (REQ-M1-009). Sentiment is an INTERNAL
 * AI output — the canonical ENT-SentimentAnalysis carries no Provenance
 * envelope, and no external sentiment provider exists in the frozen SRC-*
 * registry (ADR-0001: "enrichment/AI orchestration is QDS's own database
 * and AI").
 *
 * Inputs are captions and transcripts ONLY — comments are never analyzed
 * (REQ-M1-010 is DEFERRED, DEF-005/ADR-0009).
 *
 * Returning null means the classifier cannot produce a defensible label —
 * the content's sentiment stays UNAVAILABLE (never guessed, never zeroed).
 */
interface SentimentClassifier
{
    public function classify(string $text): ?SentimentPrediction;
}
