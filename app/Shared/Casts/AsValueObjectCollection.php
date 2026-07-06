<?php

namespace App\Shared\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent cast for "list of <envelope>" fields (e.g. ENT-ContentItem
 * `publicMetrics`, ENT-MetricSnapshot `metrics` — both `list of MetricValue`,
 * docs/30-data-model/00-data-model.md). Stores the list as a JSON array on
 * the owning entity; each element round-trips through the value object so
 * envelope invariants (DP-001/002/003) are enforced on every element.
 *
 * Usage in a model:
 *   protected function casts(): array
 *   {
 *       return ['metrics' => AsValueObjectCollection::class.':'.MetricValue::class];
 *   }
 *
 * @implements CastsAttributes<list<object>, mixed>
 */
class AsValueObjectCollection implements CastsAttributes
{
    /** @param class-string $valueObjectClass */
    public function __construct(protected string $valueObjectClass)
    {
        if (! method_exists($valueObjectClass, 'fromArray')) {
            throw new InvalidArgumentException("{$valueObjectClass} must implement fromArray()/toArray().");
        }
    }

    /** @return list<object>|null */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $items = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return array_map(
            fn (array $item): object => $this->valueObjectClass::fromArray($item),
            array_values($items),
        );
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                "Attribute [{$key}] must be a list of {$this->valueObjectClass} instances or arrays."
            );
        }

        $serialized = array_map(function (mixed $item) use ($key): array {
            if (is_array($item)) {
                $item = $this->valueObjectClass::fromArray($item);
            }

            if (! $item instanceof $this->valueObjectClass) {
                throw new InvalidArgumentException(
                    "Every element of [{$key}] must be a {$this->valueObjectClass} instance or array."
                );
            }

            return $item->toArray();
        }, array_values($value));

        return json_encode($serialized, JSON_THROW_ON_ERROR);
    }
}
