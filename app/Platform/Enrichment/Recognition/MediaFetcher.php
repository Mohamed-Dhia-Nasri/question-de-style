<?php

namespace App\Platform\Enrichment\Recognition;

use App\Modules\Monitoring\Models\Story;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fetches media bytes for recognition. Media is always DOWNLOADED here and
 * sent to AI providers as inline content — a URL (public or signed
 * private) never leaves the platform (DP-005, "minimize media sent
 * externally"). Failures yield null: recognition for that asset is skipped
 * and stays unavailable, never fabricated.
 *
 * SSRF guard: a scraped `media_urls` value is UNTRUSTED (copied verbatim
 * from third-party Apify JSON). We resolve the host ONCE, refuse it unless
 * every resolved address is a routable public IP, and then PIN the
 * connection to the validated IP (curl CURLOPT_RESOLVE) so the fetch cannot
 * re-resolve to a different address between the check and the request — the
 * classic DNS-rebinding / TOCTOU bypass. Redirects are followed manually so
 * every hop is re-validated and re-pinned the same way; without pinning an
 * attacker could point a short-TTL record at a public IP for the check and a
 * private one (e.g. the cloud metadata endpoint 169.254.169.254) for the
 * fetch.
 */
class MediaFetcher
{
    /** Cost/abuse guard for downloaded media. */
    private const MAX_BYTES = 20_000_000;

    private const MAX_REDIRECTS = 3;

    public function fromPublicUrl(string $url): ?string
    {
        $response = $this->fetchFollowingRedirects($url, self::MAX_REDIRECTS);

        if ($response === null || ! $response->successful()) {
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

    /**
     * Fetch $url, following up to $redirectsLeft hops. Each hop is resolved,
     * validated public, and pinned to the validated IP before the request —
     * so no hop can rebind to an internal address.
     */
    private function fetchFollowingRedirects(string $url, int $redirectsLeft): ?Response
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['https', 'http'], true) || $host === '') {
            return null;
        }

        $ip = $this->resolvePublicIp($host);

        if ($ip === null) {
            return null;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        try {
            $response = $this->pinnedGet($url, $host, $port, $ip);
        } catch (Throwable) {
            // Connection error or any transport failure — recognition for
            // this asset stays unavailable, never fabricated.
            return null;
        }

        if ($response->redirect()) {
            if ($redirectsLeft <= 0) {
                return null;
            }

            $next = $this->resolveRedirectTarget($url, (string) $response->header('Location'));

            return $next === null ? null : $this->fetchFollowingRedirects($next, $redirectsLeft - 1);
        }

        return $response;
    }

    /**
     * A single HTTP GET pinned to the pre-validated IP. curl connects to
     * $ip for $host (CURLOPT_RESOLVE) instead of re-resolving the hostname,
     * so the bytes come from exactly the address that passed the guard.
     * Redirects are disabled here — the caller follows them, re-pinning each.
     */
    protected function pinnedGet(string $url, string $host, int $port, string $ip): Response
    {
        return Http::withOptions([
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
        ])->timeout(30)->connectTimeout(10)->get($url);
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
     * One validated public IP for the host, or null. A literal IP is checked
     * directly; a hostname is resolved and REFUSED unless EVERY returned
     * address is a routable public IP (so a record that mixes a public and a
     * private answer is rejected outright). The returned IP is the one the
     * connection is pinned to.
     */
    private function resolvePublicIp(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->ipIsPublic($host) ? $host : null;
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
            return null;
        }

        foreach ($ips as $ip) {
            if (! $this->ipIsPublic($ip)) {
                return null;
            }
        }

        return $ips[0];
    }

    /**
     * The absolute URL a redirect points to, or null. Absolute http(s)
     * Locations are taken as-is (re-validated + re-pinned by the caller);
     * a root-relative Location keeps the current host (so the same pinned IP
     * applies). Anything else is refused rather than guessed.
     */
    private function resolveRedirectTarget(string $base, string $location): ?string
    {
        if ($location === '') {
            return null;
        }

        $target = parse_url($location);

        if ($target === false) {
            return null;
        }

        if (isset($target['scheme'], $target['host'])) {
            return $location;
        }

        if (! str_starts_with($location, '/')) {
            return null;
        }

        $baseParts = parse_url($base);

        if ($baseParts === false || ! isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        $port = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';

        return "{$baseParts['scheme']}://{$baseParts['host']}{$port}{$location}";
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
