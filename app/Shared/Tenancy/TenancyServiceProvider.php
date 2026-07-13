<?php

namespace App\Shared\Tenancy;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use WeakMap;

/**
 * Wires the centralized tenant context (ADR-0019).
 *
 * - TenantContext is a SCOPED binding: one instance per request / per job,
 *   flushed by the framework between lifecycles (no cross-request or
 *   cross-job leakage on long-running workers).
 * - Every queued job payload records the dispatcher's tenant id; workers
 *   restore it before handle() runs and restore the previous context after
 *   (push/pop, so inline sync-queue dispatch cannot clobber the request's
 *   own context).
 * - A single job can emit TWO terminal events (an escaping exception fires
 *   JobExceptionOccurred then JobFailed; a job calling $this->fail() and
 *   returning fires JobFailed then JobProcessed), so the pop is deduped
 *   per job instance — exactly one pop per JobProcessing push, on every
 *   path. Without this, the second pop would walk past the caller's frame
 *   and null the dispatching request's context (adversarial-review fix).
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Queue::createPayloadUsing(fn (): array => [
            'tenantId' => $this->app->make(TenantContext::class)->id(),
        ]);

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $tenantId = $event->job->payload()['tenantId'] ?? null;

            $this->app->make(TenantContext::class)
                ->pushJobContext($tenantId === null ? null : (int) $tenantId);
        });

        $popped = new WeakMap;

        Event::listen(
            [JobProcessed::class, JobFailed::class, JobExceptionOccurred::class],
            function (JobProcessed|JobFailed|JobExceptionOccurred $event) use ($popped): void {
                if (isset($popped[$event->job])) {
                    return;
                }

                $popped[$event->job] = true;

                $this->app->make(TenantContext::class)->popJobContext();
            }
        );
    }
}
