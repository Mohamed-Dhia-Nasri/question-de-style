<?php

namespace App\Platform\Enrichment\VisualMatch\Candidates;

use App\Platform\AiBudget\Priority;

/**
 * The scoped candidates of one post plus the budget priority tier the set
 * earns (spec §7): HIGH when any candidate ties to an ACTIVE/SHIPPING
 * campaign, MEDIUM otherwise. "Low" ≡ empty set — the matcher skips with
 * skipped:no-candidates before priority is ever consulted.
 */
final readonly class CandidateSet
{
    public function __construct(
        /** @var list<Candidate> deterministic order: shipment candidates by productId, then roster by productId */
        public array $candidates,
        public Priority $priority,
    ) {}

    /** @return list<Candidate> only candidates worth embedding against */
    public function matchable(): array
    {
        return array_values(array_filter(
            $this->candidates,
            fn (Candidate $candidate): bool => $candidate->hasEmbeddedPhotos,
        ));
    }

    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }

    public function hasInWindowShipment(): bool
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->shipmentInWindow) {
                return true;
            }
        }

        return false;
    }
}
