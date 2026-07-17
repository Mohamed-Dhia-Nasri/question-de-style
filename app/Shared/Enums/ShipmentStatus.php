<?php

namespace App\Shared\Enums;

/**
 * ENUM-ShipmentStatus — canonical values:
 * docs/00-meta/03-glossary.md#enum-shipmentstatus.
 */
enum ShipmentStatus: string
{
    case Pending = 'PENDING';
    case Preparing = 'PREPARING';
    case Shipped = 'SHIPPED';
    case InTransit = 'IN_TRANSIT';
    case Delivered = 'DELIVERED';
    case Returned = 'RETURNED';
    case Failed = 'FAILED';

    /** Human-facing label (presentation only — same convention as RoleName). */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Preparing => 'Preparing',
            self::Shipped => 'Shipped',
            self::InTransit => 'In transit',
            self::Delivered => 'Delivered',
            self::Returned => 'Returned',
            self::Failed => 'Failed',
        };
    }

    /** One-line plain-language description (presentation only). */
    public function description(): string
    {
        return match ($this) {
            self::Pending => 'Not prepared yet.',
            self::Preparing => 'Being packed.',
            self::Shipped => 'Handed to the courier.',
            self::InTransit => 'On its way.',
            self::Delivered => 'Arrived at the creator.',
            self::Returned => 'Came back undelivered.',
            self::Failed => 'Could not be delivered.',
        };
    }
}
