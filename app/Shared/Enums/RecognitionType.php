<?php

namespace App\Shared\Enums;

/**
 * ENUM-RecognitionType — canonical values:
 * docs/00-meta/03-glossary.md#enum-recognitiontype.
 */
enum RecognitionType: string
{
    case ImageTextOcr = 'IMAGE_TEXT_OCR';
    case Logo = 'LOGO';
    case SpokenBrand = 'SPOKEN_BRAND';
    case OnScreenText = 'ON_SCREEN_TEXT';
}
