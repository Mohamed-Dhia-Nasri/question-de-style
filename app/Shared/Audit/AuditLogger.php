<?php

namespace App\Shared\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records auditable events for sensitive changes (user/role administration
 * now; identity merges, personal-data access, and exports as those features
 * land).
 *
 * Rules:
 *  - context must hold identifiers and non-sensitive facts only — never
 *    decrypted personal data, secrets, tokens, or raw external payloads;
 *  - events are append-only; there is no update/delete path.
 */
class AuditLogger
{
    /** @param array<string, mixed> $context */
    public function record(string $action, ?Model $subject = null, array $context = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            // actor_id is duplicated into the immutable context on purpose:
            // the user_id foreign key nulls when the actor account is later
            // deleted (GDPR), but accountability for past events must survive
            // as an identifier-only snapshot.
            'context' => array_merge(['actor_id' => Auth::id()], $context),
            'request_id' => request()->attributes->get('request_id'),
            'created_at' => now(),
        ]);
    }
}
