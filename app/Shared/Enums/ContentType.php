<?php

namespace App\Shared\Enums;

/**
 * ENUM-ContentType — canonical values: docs/00-meta/03-glossary.md#enum-contenttype.
 *
 * Rule (F8): STORY is NOT a ContentType value. Ephemeral stories are always
 * modelled as ENT-Story, never as a ContentItem with a story type.
 */
enum ContentType: string
{
    case ImagePost = 'IMAGE_POST';
    case Carousel = 'CAROUSEL';
    case Reel = 'REEL';
    case Video = 'VIDEO';
    case Short = 'SHORT';
    case Live = 'LIVE';

    /**
     * Human-facing label (presentation only — same convention as RoleName).
     * Mirrors EmvSettings::FORMAT_LABELS.
     */
    public function label(): string
    {
        return match ($this) {
            self::ImagePost => 'Image post',
            self::Carousel => 'Carousel',
            self::Reel => 'Reel',
            self::Video => 'Video',
            self::Short => 'Short',
            self::Live => 'Live',
        };
    }
}
