<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class VectorLiteralTest extends TestCase
{
    public function test_formats_a_float_list_as_a_pgvector_literal(): void
    {
        $this->assertSame('[0.1,0.2,0.3]', VectorLiteral::fromArray([0.1, 0.2, 0.3]));
        $this->assertSame('[1,-2.5,0]', VectorLiteral::fromArray([1.0, -2.5, 0.0]));
    }

    public function test_parses_a_literal_back_to_floats(): void
    {
        $this->assertSame([0.1, 0.2, 0.3], VectorLiteral::toArray('[0.1,0.2,0.3]'));
        $this->assertSame([1.0, -2.5, 0.0], VectorLiteral::toArray(' [1, -2.5, 0] '));
    }

    public function test_round_trip_preserves_values(): void
    {
        $vector = [0.123456789012345, -1.0, 2.5e-8];

        $this->assertSame($vector, VectorLiteral::toArray(VectorLiteral::fromArray($vector)));
    }

    public function test_rejects_nan_components(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VectorLiteral::fromArray([0.1, NAN]);
    }

    public function test_rejects_infinite_components(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VectorLiteral::fromArray([INF]);
    }

    public function test_rejects_empty_vectors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VectorLiteral::fromArray([]);
    }

    public function test_rejects_malformed_literals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VectorLiteral::toArray('0.1,0.2');
    }

    public function test_rejects_non_numeric_components(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VectorLiteral::toArray('[0.1,abc]');
    }
}
