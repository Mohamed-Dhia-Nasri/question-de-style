<?php

namespace App\Shared\Exceptions;

use RuntimeException;

/**
 * Thrown by service boundaries whose implementation belongs to a later
 * roadmap phase (docs/80-delivery/00-roadmap.md). Distinct from a deferred
 * capability (DEF-*): deferred features are out of v1 entirely and render
 * "unavailable" in the UI; a NotYetImplemented service is planned v1 work
 * whose phase has not been built yet.
 */
class NotYetImplemented extends RuntimeException
{
    public static function service(string $service, string $phase): self
    {
        return new self(
            "{$service} is a declared boundary; its implementation is {$phase} work "
            .'(docs/80-delivery/00-roadmap.md). Calling it before then is a wiring error.'
        );
    }
}
