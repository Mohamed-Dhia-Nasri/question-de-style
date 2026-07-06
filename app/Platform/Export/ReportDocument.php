<?php

namespace App\Platform\Export;

/**
 * The normalized, format-independent report payload every writer consumes.
 *
 * Content rules (enforced upstream by the ReportBuilder, restated here for
 * writers): every metric cell already carries its tier label; deferred or
 * unmeasured values arrive as the literal string "Unavailable" — writers
 * must never turn an absent value into 0 or an empty cell; no personal
 * data beyond the public persona display name ever enters a document.
 */
final readonly class ReportDocument
{
    public function __construct(
        public string $title,
        public string $generatedAt,
        /** @var array<string, string> disclosed filter set (label => value) */
        public array $filters,
        /** @var list<string> disclosure lines (EMV model + rates, tier legend, …) */
        public array $disclosures,
        /** @var list<array{title: string, columns: list<string>, rows: list<list<string|int|float|null>>}> */
        public array $sections,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'generated_at' => $this->generatedAt,
            'filters' => $this->filters,
            'disclosures' => $this->disclosures,
            'sections' => $this->sections,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: (string) ($data['title'] ?? 'Report'),
            generatedAt: (string) ($data['generated_at'] ?? ''),
            filters: (array) ($data['filters'] ?? []),
            disclosures: array_values((array) ($data['disclosures'] ?? [])),
            sections: array_values((array) ($data['sections'] ?? [])),
        );
    }
}
