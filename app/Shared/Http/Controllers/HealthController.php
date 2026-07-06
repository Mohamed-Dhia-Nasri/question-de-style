<?php

namespace App\Shared\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Operational health endpoint (in addition to Laravel's built-in /up).
 * Reports database connectivity and build metadata. Deliberately contains no
 * sensitive detail; failures return 503 so load balancers can act on it.
 *
 * Queue health: with the database queue driver, a stuck worker shows up as a
 * growing `jobs` count and rows in `failed_jobs` — see README.md#queues.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
        ];

        $healthy = ! in_array(false, array_map(fn (array $c) => $c['ok'], $checks), true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'app' => [
                'name' => config('app.name'),
                'env' => config('app.env'),
                'build_sha' => config('qds.build.sha'),
                'build_time' => config('qds.build.time'),
            ],
        ], $healthy ? 200 : 503);
    }

    /** @return array{ok: bool} */
    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true];
        } catch (Throwable) {
            return ['ok' => false];
        }
    }
}
