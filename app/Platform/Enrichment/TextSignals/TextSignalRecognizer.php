<?php

namespace App\Platform\Enrichment\TextSignals;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Recognition\BrandLexicon;
use App\Platform\Enrichment\Support\HumanPrecedence;
use App\Platform\Ingestion\DTO\ProductTag;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Deterministic Tier-0 recognizer: mines the caption + platform tags for
 * brand/product evidence and writes CaptionText/Mention/ProductTag
 * detections. Idempotent (provider_label = stable per-match key); honors
 * DP-004; fail-closed (no signal → no row). No provider calls.
 */
final class TextSignalRecognizer
{
    public function __construct(
        private readonly BrandLexicon $lexicon,
        private readonly MentionExtractor $mentions,
        private readonly ProductResolver $products,
    ) {}

    public function enrich(ContentItem|Story $target): string
    {
        if (! $target instanceof ContentItem) {
            return 'skipped:stories-have-no-caption';
        }

        $caption = (string) ($target->caption ?? '');
        $written = 0;

        // 1. Caption brands (all, diacritic-folded).
        $captionBrands = $caption === '' ? [] : $this->lexicon->matchAllInText($caption);

        foreach ($captionBrands as $brand) {
            $written += $this->upsert($target, RecognitionType::CaptionText, 'caption:'.mb_strtolower($brand), $brand, null, null, ['caption-brand-match:'.$brand]);
        }

        // 2. @mention → brand.
        foreach ($this->mentions->extract($caption) as $handle) {
            $brand = $this->lexicon->resolveHandle($handle);

            if ($brand !== null) {
                $written += $this->upsert($target, RecognitionType::Mention, 'mention:'.$handle, $brand, null, null, ['mention-brand-match:'.$brand.':@'.$handle]);
            }
        }

        // 3. Caption products (brand co-occurrence guard uses the caption brands).
        foreach ($this->products->matchInCaption($caption, $captionBrands) as $rp) {
            $written += $this->upsert($target, RecognitionType::CaptionText, 'caption-product:'.$rp->productId, $rp->brandName, $rp->name, $rp->productId, ['caption-product-match:'.$rp->name.':rung='.$rp->rung]);
        }

        // 4. Structured product tags (exact product, near-ground-truth).
        foreach ($this->productTags($target) as $tag) {
            $rp = $this->products->resolveTag($tag);

            if ($rp === null) {
                continue;
            }

            $key = 'product-tag:'.($tag->providerTagId ?? (string) $rp->productId);
            $written += $this->upsert($target, RecognitionType::ProductTag, $key, $rp->brandName, $rp->name, $rp->productId, ['product-tag-match:'.$rp->name.':rung='.$rp->rung]);
        }

        return 'completed:'.$written.' text-signal detection(s)';
    }

    /** @return list<ProductTag> */
    private function productTags(ContentItem $target): array
    {
        $tags = [];

        foreach ((array) ($target->product_tags ?? []) as $row) {
            if (is_array($row)) {
                $tags[] = new ProductTag($row['brand_ref'] ?? null, $row['product_name'] ?? null, $row['product_sku'] ?? null, $row['provider_tag_id'] ?? null);
            }
        }

        return $tags;
    }

    /** @param list<string> $signals @return int 1 if written, 0 if a human decision blocked it */
    private function upsert(ContentItem $target, RecognitionType $type, string $providerLabel, string $brand, ?string $product, ?int $productId, array $signals): int
    {
        $identity = ['content_item_id' => $target->id, 'recognition_type' => $type, 'provider_label' => $providerLabel];

        $detection = RecognitionDetection::query()->firstOrNew($identity);

        if ($detection->exists && ! HumanPrecedence::allowsAiUpdate($detection->assessment)) {
            return 0;
        }

        if (! $detection->exists) {
            $detection->detected_brand = $brand;
            $detection->detected_product = $product;
            $detection->product_id = $productId;
        }

        $detection->fill([
            'detected_text' => null,
            'assessment' => new ConfidenceAssessment(
                value: $detection->detected_product ?? $detection->detected_brand ?? $brand,
                confidenceLevel: $productId !== null ? ConfidenceLevel::High : ConfidenceLevel::Medium,
                signals: $signals,
                verificationStatus: VerificationStatus::AiAssessed,
            ),
            'provenance' => new Provenance(SourceRegistry::AGENCY_MANUAL_ENTRY, CarbonImmutable::now(), 'text-signals-v1'),
        ]);

        try {
            $detection->save();
        } catch (UniqueConstraintViolationException) {
            $detection = RecognitionDetection::query()->where($identity)->firstOrFail();

            if (! HumanPrecedence::allowsAiUpdate($detection->assessment)) {
                return 0;
            }

            return 0; // concurrent insert already recorded it
        }

        return 1;
    }
}
