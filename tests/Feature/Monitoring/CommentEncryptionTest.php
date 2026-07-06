<?php

namespace Tests\Feature\Monitoring;

use App\Modules\Monitoring\Models\Comment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENT-Comment holds third-party personal data (author handle, comment text)
 * — encrypted at rest per the personal-data governance rule (DP-005).
 * The table itself is schema-only in v1 (DEF-005): no production path
 * populates it.
 */
class CommentEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_handle_and_text_are_encrypted_at_rest(): void
    {
        $comment = Comment::factory()->create([
            'author_handle' => 'synthetic_commenter',
            'text' => 'Synthetic comment body',
        ]);

        $raw = DB::table('comments')->where('id', $comment->id)->first();

        $this->assertNotSame('synthetic_commenter', $raw->author_handle);
        $this->assertStringNotContainsString('synthetic_commenter', $raw->author_handle);
        $this->assertNotSame('Synthetic comment body', $raw->text);
        $this->assertStringNotContainsString('Synthetic comment', $raw->text);
    }

    public function test_encrypted_fields_decrypt_transparently_on_read(): void
    {
        $comment = Comment::factory()->create([
            'author_handle' => 'synthetic_commenter',
            'text' => 'Synthetic comment body',
        ]);

        $fresh = $comment->fresh();

        $this->assertSame('synthetic_commenter', $fresh->author_handle);
        $this->assertSame('Synthetic comment body', $fresh->text);
    }
}
