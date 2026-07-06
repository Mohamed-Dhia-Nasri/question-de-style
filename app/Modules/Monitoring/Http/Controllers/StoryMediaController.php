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

        $expiresAt = now()->addMinutes(
            max(1, (int) config('qds.ingestion.signed_url_ttl_minutes')),
        );

        return response()->json([
            'url' => URL::temporarySignedRoute('monitoring.stories.media', $expiresAt, ['story' => $story->id]),
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /** Serve the media for a valid, unexpired signature. */
    public function stream(Request $request, Story $story): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        abort_if($story->media_url === null, 404);

        $disk = Storage::disk((string) config('qds.ingestion.media_disk'));

        abort_unless($disk->exists($story->media_url), 404);

        return $disk->response($story->media_url);
    }
}
