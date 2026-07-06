<?php

namespace App\Shared\Enums;

/**
 * ENUM-SentimentLabel — canonical values:
 * docs/00-meta/03-glossary.md#enum-sentimentlabel.
 */
enum SentimentLabel: string
{
    case Positive = 'POSITIVE';
    case Neutral = 'NEUTRAL';
    case Negative = 'NEGATIVE';
    case Mixed = 'MIXED';
    case Unknown = 'UNKNOWN';
}
