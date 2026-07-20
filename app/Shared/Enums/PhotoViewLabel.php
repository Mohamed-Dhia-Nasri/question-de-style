<?php

namespace App\Shared\Enums;

/**
 * Which product view a reference photo shows (visual matching, sub-project
 * C). Optional metadata for the management UI's diverse-views guidance —
 * matching itself treats all views equally (best photo per frame wins).
 */
enum PhotoViewLabel: string
{
    case Front = 'front';
    case Back = 'back';
    case Side = 'side';
    case Packaging = 'packaging';
    case InUse = 'in_use';
    case Other = 'other';
}
