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
    case CaptionText = 'CAPTION_TEXT';
    case Mention = 'MENTION';
    case ProductTag = 'PRODUCT_TAG';
}
