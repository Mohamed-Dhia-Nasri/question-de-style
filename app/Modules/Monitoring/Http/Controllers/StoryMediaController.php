<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Modules\Monitoring\Models\Story;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorized access to archived story media (REQ-M1-004; ingestion spec
 * requirement 11): media lives in PRIVATE object storage and is served
 * only through SHORT-LIVED SIGNED URLs.
 *
 * `issue` (session-authenticated, policy-checked) mints a signed URL;
 * `stream` (signature-checked) serves the bytes for the URL's lifetime.
 * The media path is storage-relative — no provider CDN URL, no public disk.
 */
class StoryMediaController
{
    /** Mint a short-lived signed URL for one story's archived media. */
    public function issue(Request $request, Story $story): JsonResponse
    {
        Gate::authorize('view', $story);

        abort_if($story->media_url === null, 404, 'No archived media for this story.');

        // Mirror stream()'s existence check so a story whose file was pruned
        // (retention delete committed but the media_url=null update lost to a
        // crash, or any archival gap) yields a clean 404 here instead of a
        // signed URL that 404s only when the bytes are fetched.
        abort_unless(
            Storage::disk((string) config('qds.ingestion.media_disk'))->exists($story->media_url),
            404,
            'No archived media for this story.',
        );

        $expiresAt = now()->addMinutes(
            max(1, (int) config('qds.ingestion.signed_url_ttl_minutes')),
        );

        return response()->json([
            'url' => URL::temporarySignedRoute('monitoring.stories.media', $expiresAt, ['story' => $story->id]),
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Serve the media. The route enforces `signed` (valid, unexpired
     * signature) and `auth`; here we re-authorize via StoryPolicy so the
     * byte stream is not the single point that trusts the signature alone —
     * the tenant backstop denies a story owned by another tenant even if a
     * signed URL leaks, and the route binding already resolves the story
     * tenant-scoped.
     */
    public function stream(Request $request, Story $story): StreamedResponse
    {
        Gate::authorize('view', $story);

        abort_if($story->media_url === null, 404);

        $disk = Storage::disk((string) config('qds.ingestion.media_disk'));

        abort_unless($disk->exists($story->media_url), 404);

        return $disk->response($story->media_url);
    }
}
