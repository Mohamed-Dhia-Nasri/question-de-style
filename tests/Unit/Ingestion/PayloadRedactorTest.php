<?php

namespace Tests\Unit\Ingestion;

use App\Platform\Ingestion\Support\PayloadRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Redaction guarantees (ingestion spec, Security + sampling): no
 * credentials, personal contact data, media blobs, or signed URLs survive
 * into stored samples, quarantine payloads, or error messages.
 */
class PayloadRedactorTest extends TestCase
{
    private PayloadRedactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new PayloadRedactor;
    }

    public function test_credential_keys_are_redacted_at_any_depth(): void
    {
        $clean = $this->redactor->redact([
            'token' => 'secret-token',
            'nested' => ['api_key' => 'k', 'Authorization' => 'Bearer abc'],
            'username' => 'styleicon',
        ]);

        $this->assertSame('[REDACTED]', $clean['token']);
        $this->assertSame('[REDACTED]', $clean['nested']['api_key']);
        $this->assertSame('[REDACTED]', $clean['nested']['Authorization']);
        $this->assertSame('styleicon', $clean['username']);
    }

    public function test_personal_contact_keys_are_redacted(): void
    {
        $clean = $this->redactor->redact(['email' => 'a@b.de', 'phone' => '+49123', 'bio' => 'hi']);

        $this->assertSame('[REDACTED]', $clean['email']);
        $this->assertSame('[REDACTED]', $clean['phone']);
        $this->assertSame('hi', $clean['bio']);
    }

    public function test_media_blob_keys_are_dropped_entirely(): void
    {
        $clean = $this->redactor->redact(['imageBase64' => 'AAAA', 'caption' => 'ok']);

        $this->assertArrayNotHasKey('imageBase64', $clean);
        $this->assertSame('ok', $clean['caption']);
    }

    public function test_string_values_lose_query_credentials_and_emails(): void
    {
        $clean = $this->redactor->redactString(
            'call https://api.example/x?token=abc123&expires=99 failed for user me@example.com with Bearer xyz.abc',
        );

        $this->assertStringNotContainsString('abc123', $clean);
        $this->assertStringNotContainsString('me@example.com', $clean);
        $this->assertStringNotContainsString('xyz.abc', $clean);
        $this->assertStringContainsString('https://api.example/x?token=[REDACTED]', $clean);
    }
}
