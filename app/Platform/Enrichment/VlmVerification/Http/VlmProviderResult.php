<?php

namespace App\Platform\Enrichment\VlmVerification\Http;

/**
 * The interpreted generateContent response (spec §5). blockReason non-null
 * means a PERMANENT safety block (prompt blockReason or a blocking
 * finishReason) — the call billed, never retried (skipped:safety-block).
 * An empty json with blockReason null (MAX_TOKENS, unparseable text) is
 * the malformed-output signal the VerdictValidator turns into a bounded
 * corrective retry (§6). Token counts come from usageMetadata and land on
 * the vlm_verification_runs row.
 */
final readonly class VlmProviderResult
{
    public function __construct(
        /** @var array<array-key, mixed> decoded candidate text; [] when blocked/unparseable */
        public array $json,
        public ?string $blockReason,
        public string $finishReason,
        public ?int $promptTokens,
        public ?int $outputTokens,
        public ?int $thinkingTokens,
    ) {}
}
