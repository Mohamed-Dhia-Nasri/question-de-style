<?php

namespace App\Platform\Ingestion\Persistence;

/**
 * Outcome of persisting one normalized batch: how many records were newly
 * created, how many were recognized as already-known duplicates and had
 * their mutable public metrics refreshed in place (never re-created —
 * idempotency requirement 8/9), and how long persistence took.
 */
final readonly class PersistenceResult
{
    public function __construct(
        public int $created = 0,
        /** Existing records matched by canonical external id and refreshed. */
        public int $duplicates = 0,
        /** Records skipped without write (e.g. profile with nothing new). */
        public int $skipped = 0,
        public float $persistenceMs = 0.0,
        public float $mediaMs = 0.0,
        /**
         * Items accepted by validation but persisted for NO account because
         * they carried no attributable owner handle (M22). A first-class
         * signal: a batch that loses all owner handles is a provider schema
         * change, not a clean success.
         */
        public int $unattributed = 0,
        /** @var list<int> Ids of newly created ContentItem rows (ADR-0023 per-pull enrichment). */
        public array $createdIds = [],
    ) {}
}
