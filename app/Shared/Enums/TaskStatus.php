<?php

namespace App\Shared\Enums;

/**
 * ENUM-TaskStatus — canonical values:
 * docs/00-meta/03-glossary.md#enum-taskstatus.
 */
enum TaskStatus: string
{
    case Open = 'OPEN';
    case InProgress = 'IN_PROGRESS';
    case Blocked = 'BLOCKED';
    case Done = 'DONE';
    case Cancelled = 'CANCELLED';

    /** Human-facing label (presentation only — same convention as RoleName). */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::Blocked => 'Blocked',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    /** One-line plain-language description (presentation only). */
    public function description(): string
    {
        return match ($this) {
            self::Open => 'Not started.',
            self::InProgress => 'Being worked on.',
            self::Blocked => 'Waiting on something else.',
            self::Done => 'Finished.',
            self::Cancelled => 'No longer needed.',
        };
    }
}
