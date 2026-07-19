<?php

namespace App\Shared\Enums;

/** How a keyframe was derived (sub-project B). */
enum KeyframeKind: string
{
    /** Even-interval ffmpeg sample from a downloaded video. */
    case VideoSample = 'video_sample';
    /** The platform's poster image (YouTube — the only in-freeze visual). */
    case Thumbnail = 'thumbnail';
    /** A post/carousel/story image — the image IS the frame. */
    case SourceImage = 'source_image';
}
