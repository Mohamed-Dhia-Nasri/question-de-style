<?php

namespace App\Shared\Tenancy;

use RuntimeException;

/** Thrown when a code path requires a tenant context but none is set. */
final class MissingTenantContext extends RuntimeException {}
