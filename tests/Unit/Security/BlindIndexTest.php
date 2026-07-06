<?php

namespace Tests\Unit\Security;

use App\Shared\Security\BlindIndex;
use RuntimeException;
use Tests\TestCase;

class BlindIndexTest extends TestCase
{
    public function test_hash_is_deterministic_and_normalized(): void
    {
        $this->assertSame(
            BlindIndex::hash('creator@example.com'),
            BlindIndex::hash('  Creator@Example.COM '),
        );
    }

    public function test_hash_depends_on_the_key(): void
    {
        $first = BlindIndex::hash('creator@example.com');

        config(['qds.security.blind_index_key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertNotSame($first, BlindIndex::hash('creator@example.com'));
    }

    public function test_missing_key_throws_instead_of_hashing_unsalted(): void
    {
        config(['qds.security.blind_index_key' => null]);

        $this->expectException(RuntimeException::class);

        BlindIndex::hash('creator@example.com');
    }
}
