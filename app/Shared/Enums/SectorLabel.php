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
}
