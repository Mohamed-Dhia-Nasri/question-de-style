<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PgvectorRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_vector_extension_is_installed(): void
    {
        $row = DB::selectOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'");

        $this->assertNotNull(
            $row,
            'pgvector extension missing — recreate the local container from pgvector/pgvector:pg17-bookworm (README#testing)'
        );
        // Neon ships 0.8.0 on PG17; local may be newer. The code pins itself
        // to 0.8.0 semantics, so 0.8.0+ is the only requirement here.
        $this->assertTrue(version_compare($row->extversion, '0.8.0', '>='));
    }

    public function test_vectors_round_trip_through_a_vector_column(): void
    {
        DB::statement('CREATE TABLE vector_probe (id bigserial PRIMARY KEY, embedding vector(3) NOT NULL)');

        // Exactly float32-representable components: pgvector stores
        // single-precision, so these survive the round trip bit-exact.
        $vector = [0.25, -1.5, 0.5];
        DB::insert('INSERT INTO vector_probe (embedding) VALUES (?::vector)', [VectorLiteral::fromArray($vector)]);

        $row = DB::selectOne('SELECT embedding::text AS embedding FROM vector_probe');

        $this->assertSame($vector, VectorLiteral::toArray($row->embedding));
    }

    public function test_cosine_distance_operator_matches_known_geometry(): void
    {
        DB::statement('CREATE TABLE vector_probe (id bigserial PRIMARY KEY, embedding vector(3) NOT NULL)');
        DB::insert('INSERT INTO vector_probe (embedding) VALUES (?::vector), (?::vector)', [
            VectorLiteral::fromArray([1.0, 0.0, 0.0]),
            VectorLiteral::fromArray([0.0, 1.0, 0.0]),
        ]);

        // The pgvector-prescribed similarity spelling: `<=>` is cosine
        // DISTANCE, similarity = 1 - (a <=> b); ORDER BY <=> is nearest-first.
        // Same direction (scale-invariant) => 1.0; orthogonal => 0.0.
        $rows = DB::select(
            'SELECT 1 - (embedding <=> ?::vector) AS similarity FROM vector_probe ORDER BY embedding <=> ?::vector',
            [VectorLiteral::fromArray([2.0, 0.0, 0.0]), VectorLiteral::fromArray([2.0, 0.0, 0.0])]
        );

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(1.0, (float) $rows[0]->similarity, 1e-6);
        $this->assertEqualsWithDelta(0.0, (float) $rows[1]->similarity, 1e-6);
    }
}
