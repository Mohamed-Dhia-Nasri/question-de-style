<?php

namespace App\Platform\Ingestion\Normalization;

use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\DTO\RejectedRecord;
use App\Platform\Ingestion\Support\ErrorCategory;
use Closure;
use Throwable;

/**
 * Shared per-item normalization loop for provider adapters: every raw item
 * either becomes a documented DTO or a RejectedRecord bound for quarantine.
 * One bad item never aborts the batch (partial-failure handling), and
 * nothing invalid is silently stored.
 */
trait NormalizesItems
{
    /**
     * @param  Closure(array<array-key, mixed>): (object|null)  $mapItem  returns a DTO, or null to skip benignly
     */
    protected function normalizeBatch(ProviderResponse $response, Closure $mapItem): NormalizedBatch
    {
        $validationStart = microtime(true);

        $items = [];
        $rejected = [];

        // Envelope-level validation (list shape) already happened in the
        // client; per-item type validation happens here.
        $validationMs = (microtime(true) - $validationStart) * 1000;

        $normalizationStart = microtime(true);

        foreach ($response->items as $rawItem) {
            if (! is_array($rawItem)) {
                $rejected[] = new RejectedRecord(
                    ErrorCategory::InvalidFieldTypes,
                    'Dataset item is not an object.',
                    ['value' => $rawItem],
                );

                continue;
            }

            try {
                $dto = $mapItem($rawItem);

                if ($dto !== null) {
                    $items[] = $dto;
                }
            } catch (RecordRejected $e) {
                $rejected[] = new RejectedRecord($e->category, $e->getMessage(), $rawItem, $e->externalHint);
            } catch (Throwable $e) {
                // Unexpected normalization failure: quarantine the item with
                // a sanitized, category-tagged reason — never rethrow raw
                // provider content.
                $rejected[] = new RejectedRecord(
                    ErrorCategory::NormalizationFailed,
                    'Unexpected normalization failure: '.get_class($e),
                    $rawItem,
                );
            }
        }

        $normalizationMs = (microtime(true) - $normalizationStart) * 1000;

        return new NormalizedBatch(
            items: $items,
            rejected: $rejected,
            response: $response,
            validationMs: $validationMs,
            normalizationMs: $normalizationMs,
        );
    }
}
