<?php

namespace App\Platform\Enrichment\Media;

/** Outcome of one streamed media download (sub-project B). */
enum StreamStatus: string
{
    case Ok = 'ok';
    /** Over the byte cap — maps to the media:too-large marker. */
    case TooLarge = 'too-large';
    /** 403/404/410 — an expired scraped URL; maps to media:too-old. */
    case Gone = 'gone';
    case Failed = 'failed';
}
