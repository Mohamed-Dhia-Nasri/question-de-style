<?php

namespace Tests\Unit\Casts;

use App\Shared\Casts\AsValueObjectCollection;
use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AsValueObjectCollectionTest extends TestCase
{
    private AsValueObjectCollection $cast;

    private Model $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cast = new AsValueObjectCollection(MetricValue::class);
        $this->model = new class extends Model {};
    }

    public function test_rejects_classes_without_from_array(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AsValueObjectCollection(\stdClass::class);
    }

    public function test_get_hydrates_a_list_of_value_objects(): void
    {
        $json = json_encode([
            ['amount' => 100.0, 'tier' => 'PUBLIC'],
            ['amount' => 2.5, 'tier' => 'DERIVED'],
        ]);

        $result = $this->cast->get($this->model, 'metrics', $json, []);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(MetricValue::class, $result[0]);
        $this->assertSame(100.0, $result[0]->amount);
        $this->assertSame(MetricTier::Public, $result[0]->tier);
        $this->assertSame(MetricTier::Derived, $result[1]->tier);
    }

    public function test_set_serializes_instances_and_arrays(): void
    {
        $stored = $this->cast->set($this->model, 'metrics', [
            new MetricValue(10.0, MetricTier::Public),
            ['amount' => 20.0, 'tier' => 'ESTIMATED'],
        ], []);

        // json_encode collapses 10.0 to 10; MetricValue::fromArray restores
        // the float, so equality (not identity) is the correct contract here.
        $this->assertEquals(
            [
                ['amount' => 10.0, 'tier' => 'PUBLIC'],
                ['amount' => 20.0, 'tier' => 'ESTIMATED'],
            ],
            json_decode($stored, true),
        );
    }

    public function test_null_round_trips(): void
    {
        $this->assertNull($this->cast->get($this->model, 'metrics', null, []));
        $this->assertNull($this->cast->set($this->model, 'metrics', null, []));
    }

    public function test_set_rejects_non_list_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cast->set($this->model, 'metrics', 'not-a-list', []);
    }

    public function test_set_rejects_foreign_elements(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cast->set($this->model, 'metrics', [new \stdClass], []);
    }

    public function test_every_element_enforces_value_object_invariants(): void
    {
        $this->expectException(\ValueError::class);

        // Invalid tier value: the MetricValue enum rehydration must throw.
        $this->cast->get($this->model, 'metrics', json_encode([
            ['amount' => 1.0, 'tier' => 'NOT_A_TIER'],
        ]), []);
    }
}
