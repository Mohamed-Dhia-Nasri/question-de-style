<?php

namespace App\Platform\AiBudget\Console;

use App\Platform\AiBudget\AiBudgetGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Emergency AI kill switch (spec §10): flips the cache-backed flag
 * AiBudgetGuard::readOnly() consults. ON = every allows() denies across
 * ALL capabilities — new AI spend stops instantly while everything
 * already computed stays served. The env default (QDS_AI_READ_ONLY) only
 * applies while no flag is cached; a cached value wins either way.
 */
class AiReadOnlyCommand extends Command
{
    protected $signature = 'qds:ai-read-only {mode : on|off|status}';

    protected $description = 'Toggle or inspect the emergency AI read-only mode (blocks all new AI spend)';

    public function handle(AiBudgetGuard $guard): int
    {
        $mode = strtolower((string) $this->argument('mode'));

        return match ($mode) {
            'on' => $this->set(true),
            'off' => $this->set(false),
            'status' => $this->status($guard),
            default => $this->invalid($mode),
        };
    }

    private function set(bool $enabled): int
    {
        Cache::forever(AiBudgetGuard::READ_ONLY_CACHE_KEY, $enabled);

        $this->info($enabled
            ? 'AI read-only mode is ON — all new AI spend is blocked.'
            : 'AI read-only mode is OFF — budget checks apply normally.');

        return self::SUCCESS;
    }

    private function status(AiBudgetGuard $guard): int
    {
        $source = Cache::get(AiBudgetGuard::READ_ONLY_CACHE_KEY) === null
            ? 'config default (QDS_AI_READ_ONLY)'
            : 'cache flag';

        $this->info(sprintf('AI read-only mode: %s (%s).', $guard->readOnly() ? 'ON' : 'OFF', $source));

        return self::SUCCESS;
    }

    private function invalid(string $mode): int
    {
        $this->error("Invalid mode '{$mode}' — use on, off, or status.");

        return self::FAILURE;
    }
}
