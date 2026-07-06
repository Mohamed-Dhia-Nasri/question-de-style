<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\Support\AiPayloadGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Outbound AI payload guard (DP-005): personal data, credentials, and
 * signed URLs must never leave the platform toward an AI provider. The
 * guard throws (never redacts) — a hit is a programming error upstream.
 */
class AiPayloadGuardTest extends TestCase
{
    public function test_rejects_an_email_key_at_any_depth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('forbidden field [$.requests.0.metadata.creator.email]');

        AiPayloadGuard::assertSafe([
            'requests' => [[
                'metadata' => [
                    'creator' => ['email' => 'internal-ref-1'],
                ],
            ]],
        ]);
    }

    public function test_rejects_a_phone_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DP-005');

        AiPayloadGuard::assertSafe(['phone' => '+49 30 1234567']);
    }

    public function test_rejects_a_recipient_name_key_regardless_of_casing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AiPayloadGuard::assertSafe(['recipientName' => 'Jane Doe']);
    }

    public function test_rejects_a_notes_key(): void
    {
        // Free-text CRM/campaign notes are never AI input.
        $this->expectException(InvalidArgumentException::class);

        AiPayloadGuard::assertSafe(['notes' => 'call the creator before shipping']);
    }

    public function test_rejects_a_signed_url_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AiPayloadGuard::assertSafe(['signed_url' => 'https://storage.internal/media/1']);
    }

    public function test_rejects_a_string_value_containing_an_email_address(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('personal-data/credential pattern');

        AiPayloadGuard::assertSafe([
            'caption' => 'DM me at jane.doe@example.com for collabs',
        ]);
    }

    public function test_rejects_a_string_value_with_a_token_query_parameter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AiPayloadGuard::assertSafe([
            'media' => 'https://cdn.example.com/img.jpg?token=abc',
        ]);
    }

    public function test_rejects_a_string_value_with_an_amz_signature_parameter(): void
    {
        // A signed private URL must never reach an AI provider.
        $this->expectException(InvalidArgumentException::class);

        AiPayloadGuard::assertSafe([
            'media' => 'https://bucket.s3.amazonaws.com/img.jpg?X-Amz-Signature=deadbeef',
        ]);
    }

    public function test_rejects_a_string_value_carrying_a_bearer_token(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AiPayloadGuard::assertSafe([
            'debug' => 'request used Bearer xyz123',
        ]);
    }

    public function test_accepts_a_clean_inline_image_annotation_payload(): void
    {
        $this->expectNotToPerformAssertions();

        // Every base64 character (A-Z a-z 0-9 + / =) appears here: the
        // standard alphabet must never trip the string patterns.
        $allBytes = implode('', array_map('chr', range(0, 255)));

        AiPayloadGuard::assertSafe([
            'requests' => [[
                'image' => ['content' => base64_encode($allBytes)],
                'features' => [
                    ['type' => 'TEXT_DETECTION'],
                    ['type' => 'LOGO_DETECTION'],
                ],
            ]],
        ]);
    }
}
