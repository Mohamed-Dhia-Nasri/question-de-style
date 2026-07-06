<?php

namespace App\Platform\Enrichment\Sentiment;

use App\Platform\Enrichment\Contracts\SentimentClassifier;

/**
 * Default binding: no sentiment model/provider is canonically decided
 * (flagged missing decision — the docs mandate sentiment behaviour and
 * labels but name no model, and the frozen SRC-* registry contains no NLP
 * provider). Until an ADR picks the model, sentiment is UNAVAILABLE:
 * no SentimentAnalysis rows are produced and surfaces render
 * "unavailable" — never a fabricated NEUTRAL.
 */
class UnavailableSentimentClassifier implements SentimentClassifier
{
    public function classify(string $text): ?SentimentPrediction
    {
        return null;
    }
}
