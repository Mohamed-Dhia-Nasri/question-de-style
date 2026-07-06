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
}
