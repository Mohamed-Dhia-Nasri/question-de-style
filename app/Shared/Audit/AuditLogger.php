<?php

namespace App\Shared\Audit;

use App\Shared\Tenancy\TenantContext;
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
        $log = new AuditLog;

        // tenant_id and user_id are force-filled (not mass assigned) so the
        // audit trail's ownership/actor attribution comes ONLY from trusted
        // server state and can never be forged through a caller array.
        $log->forceFill([
            // ADR-0019: nullable ownership stamp — the active tenant when one
            // is set (HTTP, runAs pipeline units), null for platform-level
            // events (scheduler, global telemetry).
            'tenant_id' => app(TenantContext::class)->id(),
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

        $log->save();

        return $log;
    }
}
