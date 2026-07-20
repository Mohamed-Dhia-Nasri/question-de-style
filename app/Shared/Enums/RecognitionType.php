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

    /**
     * Sub-project C (ADR-0029): the seeded PRODUCT itself, recognized
     * visually — keyframe embeddings matched against the tenant's product
     * reference photos. Carries product_id; written by VisualMatchWriter
     * with provider_label 'visual-product:<productId>'.
     */
    case VisualProduct = 'VISUAL_PRODUCT';
}
