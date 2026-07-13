<?php

namespace App\Modules\Monitoring\Models;

use App\Shared\Casts\AsValueObject;
use App\Shared\Enums\RecognitionType;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\Provenance;
use Database\Factories\RecognitionDetectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ENT-RecognitionDetection — a brand-recognition hit (OCR / logo /
 * spoken-brand / on-screen text) inside content
 * (docs/30-data-model/00-data-model.md#ent-recognitiondetection, REQ-M1-008).
 *
 * Write-owner: Module 1 Monitoring (ownership matrix). Inferred → mandatory
 * ConfidenceAssessment (DP-003); produced by the Google AI sources →
 * mandatory Provenance (DP-002). Low-confidence detections route to the
 * human review queue (DP-004, AC-M1-009).
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $content_item_id
 * @property int|null $story_id
 * @property RecognitionType $recognition_type
 * @property string|null $detected_text
 * @property string|null $detected_brand
 * @property string|null $provider_label
 * @property ConfidenceAssessment $assessment
 * @property Provenance $provenance
 */
class RecognitionDetection extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RecognitionDetectionFactory> */
    use HasFactory;

    protected $fillable = [
        'content_item_id',
        'story_id',
        'recognition_type',
        'detected_text',
        'detected_brand',
        // Immutable raw provider-detected label — the stable upsert identity
        // so a human correction of detected_brand is never re-created as a
        // fresh AI detection (DP-004). FLAGGED DEVIATION: not in the canonical
        // ENT-RecognitionDetection shape; awaiting a data-model amendment.
        'provider_label',
        'assessment',
        'provenance',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recognition_type' => RecognitionType::class,
            'assessment' => AsValueObject::class.':'.ConfidenceAssessment::class,
            'provenance' => AsValueObject::class.':'.Provenance::class,
        ];
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}
