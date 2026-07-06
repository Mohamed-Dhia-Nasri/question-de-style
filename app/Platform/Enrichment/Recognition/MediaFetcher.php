<?php

namespace App\Platform\Enrichment\Recognition;

use App\Modules\Monitoring\Models\Story;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Fetches media bytes for recognition. Media is always DOWNLOADED here and
 * sent to AI providers as inline content — a URL (public or signed
 * private) never leaves the platform (DP-005, "minimize media sent
 * externally"). Failures yield null: recognition for that asset is skipped
 * and stays unavailable, never fabricated.
 *
 * SSRF guard: a scraped `media_urls` value is UNTRUSTED (copied verbatim
 * from third-party Apify JSON). Before fetching we reject any URL whose
 * host resolves to a loopback/link-local/private/reserved address (e.g. the
 * cloud metadata endpoint 169.254.169.254 or an internal service), and we
 * re-validate every redirect hop — otherwise an attacker-influenced URL
 * could make the enrichment worker read an internal resource and exfiltrate
 * it to Google via OCR.
 */
class MediaFetcher
{
    /** Cost/abuse guard for downloaded media. */
    private const MAX_BYTES = 20_000_000;

    private const MAX_REDIRECTS = 3;

    public function fromPublicUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['https', 'http'], true) || $host === '') {
            return null;
        }

        if (! $this->hostIsPublic($host)) {
            return null;
        }

        try {
            $response = Http::withOptions([
                'allow_redirects' => [
                    'max' => self::MAX_REDIRECTS,
                    'strict' => true,
                    'referer' => false,
                    'protocols' => ['http', 'https'],
                    // Re-validate every hop to defeat an open-redirect bounce
                    // into an internal host.
                    'on_redirect' => function ($request, $response, $uri): void {
                        if (! $this->hostIsPublic((string) $uri->getHost())) {
                            throw new RuntimeException('Blocked redirect to a non-public host (SSRF guard).');
                        }
                    },
                ],
            ])->timeout(30)->connectTimeout(10)->get($url);
        } catch (Throwable) {
            // Connection error, blocked redirect, or any transport failure —
            // recognition for this asset stays unavailable, never fabricated.
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        // Only image/video bytes are ever forwarded to the AI providers.
        $contentType = strtolower((string) $response->header('Content-Type'));

        if ($contentType !== ''
            && ! str_starts_with($contentType, 'image/')
            && ! str_starts_with($contentType, 'video/')
            && ! str_starts_with($contentType, 'application/octet-stream')) {
            return null;
        }

        $body = $response->body();

        return $body !== '' && strlen($body) <= self::MAX_BYTES ? $body : null;
    }

    /** Archived story media lives on the private disk (never re-exposed by URL). */
    public function fromStory(Story $story): ?string
    {
        if ($story->media_url === null || $story->media_url === '') {
            return null;
        }

        try {
            $bytes = Storage::disk((string) config('qds.ingestion.media_disk'))->get($story->media_url);
        } catch (Throwable) {
            return null;
        }

        return is_string($bytes) && $bytes !== '' && strlen($bytes) <= self::MAX_BYTES ? $bytes : null;
    }

    /**
     * True only when every address the host resolves to is a routable public
     * IP. An unresolvable host, or any private/reserved/loopback/link-local
     * address in the set, is refused.
     */
    private function hostIsPublic(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->ipIsPublic($host);
        }

        $ips = [];

        $a = gethostbynamel($host);

        if (is_array($a)) {
            $ips = $a;
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);

        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! $this->ipIsPublic($ip)) {
                return false;
            }
        }

        return true;
    }

    private function ipIsPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
