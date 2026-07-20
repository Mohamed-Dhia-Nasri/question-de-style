<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Modules\CRM\Models\ProductReferencePhoto;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a product reference photo to its authorized requester
 * (spec §6) — the DocumentDownloadController precedent: authenticated
 * session + ProductPolicy::view on the OWNING product + a valid,
 * unexpired signature. The private media disk is never exposed through a
 * public URL. Deliberately unaudited: grid renders would flood the
 * append-only log with reads of low-sensitivity catalog data (document
 * downloads stay audited; catalog thumbnails are not documents).
 */
class ProductPhotoController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, ProductReferencePhoto $productReferencePhoto): StreamedResponse
    {
        $this->authorize('view', $productReferencePhoto->product);

        $disk = Storage::disk((string) $productReferencePhoto->storage_disk);

        abort_unless($disk->exists((string) $productReferencePhoto->storage_path), 404, 'The stored product photo is missing.');

        return $disk->response((string) $productReferencePhoto->storage_path);
    }
}
