<?php

namespace App\Shared\Enums;

/**
 * Run-level outcome of one VLM verification (sub-project D, spec §8.1).
 * Pending is the open crash-safe ledger state — the row is created before
 * the first billed call and finalized exactly once to a terminal outcome,
 * never re-opened. Unverifiable = "we could not look" (DEF-021 discovery
 * rows, never sent to Gemini) — recorded as a fact, never as product
 * absence. Deferral conditions (budget deny / read-only / provider
 * unavailable before any billed call) are NOT outcomes: they write no row,
 * so the anchor stays unconsumed and sweep-eligible.
 */
enum VlmRunOutcome: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Absent = 'absent';
    case Inconclusive = 'inconclusive';
    case Unverifiable = 'unverifiable';
    case FailedMalformed = 'failed_malformed';
    case SkippedProvider = 'skipped_provider';
    case SkippedSafetyBlock = 'skipped_safety_block';
    case SkippedPayloadGuard = 'skipped_payload_guard';
    case SkippedNoFrames = 'skipped_no_frames';
}
