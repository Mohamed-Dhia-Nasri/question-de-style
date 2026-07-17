<?php

namespace Tests\Feature\Ui;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Stage A guard (2026-07-16 CRM UX audit, F20-F23): internal spec IDs and
 * engineering vocabulary must never render to end users. Scans the
 * user-visible text of CRM-facing blades — blade comments, echo
 * expressions, @php blocks, and blade directives are stripped first, so
 * code and comments may still reference spec IDs.
 */
class CrmCopyLintTest extends TestCase
{
    private const BLADE_DIRS = [
        'resources/views/crm',
        'resources/views/livewire/crm',
        'resources/views/components/metric',
        'resources/views/components/states',
        'resources/views/components/crm',
    ];

    private const BANNED = [
        '/ADR-\d{4}/',
        '/REQ-M\d-\d{3}/',
        '/AC-M\d-\d{3}/',
        '/DP-\d{3}/',
        '/DEF-\d{3}/',
        '/XMC-\d{3}/',
        '/ENUM-[A-Z]/',
        '/GL-EMV/',
        '/QDS_INGESTION_ENABLED/',
        '/rollup/i',
        '/\boperator-managed\b/i',
        '/hard filters?/i',
        '/before commit\b/i',
        '/stored at tier/i',
        '/agency input/i',
        '/\bauthoritative\b/i',
        '/\(slice\)/',
        '/\bphase P\d\b/',
        '/\bModule \d\b/',
        '/\bStep \d\b/',
        '/\bv1\b/',
        '/Seeding campaign/i',
        '/(?<!Product )\bvariants?\b/i', // bans "variant(s)" as the seeding-type word; "Product variant" is the allowed product field label; variant="outline" dies with attribute stripping
    ];

    public function test_crm_blades_render_no_internal_jargon(): void
    {
        $violations = [];

        $finder = Finder::create()->files()->in(array_map(
            fn (string $dir) => dirname(__DIR__, 3).'/'.$dir,
            self::BLADE_DIRS
        ))->name('*.blade.php');

        foreach ($finder as $file) {
            $visible = $this->visibleText($file->getContents());

            foreach (self::BANNED as $pattern) {
                if (preg_match_all($pattern, $visible, $m) > 0) {
                    $violations[] = $file->getRelativePathname().': '.implode(', ', array_unique($m[0]))." ({$pattern})";
                }
            }
        }

        $this->assertSame([], $violations,
            "Internal jargon rendered to users:\n".implode("\n", $violations));
    }

    /** Strip everything that is not user-visible prose. */
    private function visibleText(string $blade): string
    {
        $s = preg_replace('/\{\{--.*?--\}\}/s', '', $blade);          // blade comments
        $s = preg_replace('/@php.*?@endphp/s', '', $s);               // php blocks
        $s = preg_replace('/\{\{.*?\}\}/s', '', $s);                  // echoes
        $s = preg_replace('/\{!!.*?!!\}/s', '', $s);                  // raw echoes

        // Attribute text that IS user-visible (title="…", reason="…",
        // placeholder, aria-label, label) is about to be stripped along
        // with the rest of each tag's attributes — pull it out first, on
        // this comment-stripped source, so tooltip/reason text stays covered.
        preg_match_all('/(?:title|reason|aria-label|placeholder|label)="([^"]*)"/', $s, $attrs);
        $s .= ' '.implode(' ', $attrs[1]);

        $s = preg_replace('/<[^>]*>/s', ' ', $s);                     // tags incl. attributes (kills variant="outline", wire:*, x-data maps)
        $s = preg_replace('/@\w+(\([^)]*\))?/', '', $s);              // blade directives

        return $s;
    }
}
