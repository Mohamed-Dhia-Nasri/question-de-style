<?php

namespace App\Shared\Enums;

/**
 * ENUM-ConfidenceLevel — canonical values:
 * docs/00-meta/03-glossary.md#enum-confidencelevel.
 */
enum ConfidenceLevel: string
{
    case High = 'HIGH';
    case Medium = 'MEDIUM';
    case Low = 'LOW';
    case Unknown = 'UNKNOWN';
}
