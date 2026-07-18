<?php

namespace Tests\Unit\Shared\ValueObjects;

use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\MetricValue;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * MetricValue invariant (M05 central backstop): a metric amount must be a
 * finite number. A non-finite amount (INF from an overflow like 1e400, or
 * NaN) cannot be JSON-encoded by the AsValueObject cast, so it must be
 * rejected loudly at construction rather than crashing at persist time.
 */
class MetricValueTest extends TestCase
{
    public function test_a_finite_amount_is_accepted(): void
    {
        $value = new MetricValue(49.90, MetricTier::Confirmed);

        $this->assertSame(49.90, $value->amount);
    }

    public function test_an_infinite_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricValue(INF, MetricTier::Confirmed);
    }

    public function test_a_nan_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricValue(NAN, MetricTier::Confirmed);
    }
}
