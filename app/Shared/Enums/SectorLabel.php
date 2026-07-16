<?php

namespace App\Shared\Enums;

/**
 * ENUM-SectorLabel — canonical values:
 * docs/00-meta/03-glossary.md#enum-sectorlabel.
 */
enum SectorLabel: string
{
    case Fashion = 'FASHION';
    case Beauty = 'BEAUTY';
    case Fitness = 'FITNESS';
    case FoodBeverage = 'FOOD_BEVERAGE';
    case Travel = 'TRAVEL';
    case Lifestyle = 'LIFESTYLE';
    case Tech = 'TECH';
    case Gaming = 'GAMING';
    case ParentingFamily = 'PARENTING_FAMILY';
    case HomeInterior = 'HOME_INTERIOR';
    case HealthWellness = 'HEALTH_WELLNESS';
    case Finance = 'FINANCE';
    case Automotive = 'AUTOMOTIVE';
    case Entertainment = 'ENTERTAINMENT';
    case Sports = 'SPORTS';
    case Education = 'EDUCATION';
    case Business = 'BUSINESS';
    case ArtDesign = 'ART_DESIGN';
    case Music = 'MUSIC';
    case Other = 'OTHER';

    /** Human-facing label (presentation only — same convention as RoleName). */
    public function label(): string
    {
        return match ($this) {
            self::Fashion => 'Fashion',
            self::Beauty => 'Beauty',
            self::Fitness => 'Fitness',
            self::FoodBeverage => 'Food & beverage',
            self::Travel => 'Travel',
            self::Lifestyle => 'Lifestyle',
            self::Tech => 'Tech',
            self::Gaming => 'Gaming',
            self::ParentingFamily => 'Parenting & family',
            self::HomeInterior => 'Home & interior',
            self::HealthWellness => 'Health & wellness',
            self::Finance => 'Finance',
            self::Automotive => 'Automotive',
            self::Entertainment => 'Entertainment',
            self::Sports => 'Sports',
            self::Education => 'Education',
            self::Business => 'Business',
            self::ArtDesign => 'Art & design',
            self::Music => 'Music',
            self::Other => 'Other',
        };
    }
}
