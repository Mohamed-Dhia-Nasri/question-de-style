<?php

namespace App\Shared\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Generic Eloquent cast for the shared envelope value objects (Provenance,
 * ConfidenceAssessment, MetricValue, ReachEstimate). Stores the envelope as a
 * JSON column on the owning entity — envelopes are embedded, never standalone
 * tables (docs/30-data-model/00-data-model.md#envelopes).
 *
 * Usage in a model:
 *   protected function casts(): array
 *   {
 *       return ['provenance' => AsValueObject::class.':'.Provenance::class];
 *   }
 *
 * Because the value objects validate their invariants in their constructors,
 * hydrating or persisting a doctrine-violating envelope throws — this is the
 * persistence-layer enforcement required by the P0 roadmap (DP-001/002/003).
 *
 * @implements CastsAttributes<object, object|array<string, mixed>|null>
 */
class AsValueObject implements CastsAttributes
{
    /** @param class-string $valueObjectClass */
    public function __construct(protected string $valueObjectClass)
    {
        if (! method_exists($valueObjectClass, 'fromArray')) {
            throw new InvalidArgumentException("{$valueObjectClass} must implement fromArray()/toArray().");
        }
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?object
    {
        if ($value === null) {
            return null;
        }

        return $this->valueObjectClass::fromArray(json_decode($value, true, 512, JSON_THROW_ON_ERROR));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = $this->valueObjectClass::fromArray($value);
        }

        if (! $value instanceof $this->valueObjectClass) {
            throw new InvalidArgumentException(
                "Attribute [{$key}] must be a {$this->valueObjectClass} instance or array."
            );
        }

        return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
    }
}
