<?php

namespace App\Shared\Enums;

/**
 * ENUM-ExportFormat — canonical values:
 * docs/00-meta/03-glossary.md#enum-exportformat.
 */
enum ExportFormat: string
{
    case Pdf = 'PDF';
    case Excel = 'EXCEL';
    case Csv = 'CSV';
}
