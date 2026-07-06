<?php

namespace App\Shared\Enums;

/**
 * ENUM-MonitoredSubjectType — canonical values:
 * docs/00-meta/03-glossary.md#enum-mentiontype (heading ENUM-MonitoredSubjectType).
 *
 * v1 is roster-first (ADR-0011): only CREATOR subjects are active; the
 * open-web types (BRAND/KEYWORD/HASHTAG/HANDLE) are deferred (DEF-006) and
 * their surfaces render "unavailable".
 */
enum MonitoredSubjectType: string
{
    case Creator = 'CREATOR';
    case Brand = 'BRAND';
    case Keyword = 'KEYWORD';
    case Hashtag = 'HASHTAG';
    case Handle = 'HANDLE';

    /** Whether this subject type is buildable/usable in v1 (ADR-0011). */
    public function isActiveInV1(): bool
    {
        return $this === self::Creator;
    }
}
