<?php

namespace App\Shared\Enums;

/**
 * ENUM-Platform — canonical values: docs/00-meta/03-glossary.md#enum-platform.
 * Closed set; do not add values without a documentation change.
 */
enum Platform: string
{
    case Instagram = 'INSTAGRAM';
    case TikTok = 'TIKTOK';
    case YouTube = 'YOUTUBE';
}
