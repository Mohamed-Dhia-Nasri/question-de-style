<?php

namespace App\Platform\Ingestion\Contracts;

use App\Platform\Ingestion\DTO\NormalizedBatch;

/**
 * A StoryProvider that can poll MANY handles in one actor run (cost plan
 * rec 3): the story actor bills a per-RUN start fee that dwarfs its
 * per-username fee, so one batched run per cycle amortizes it across the
 * roster. Items carry StoryData::$ownerHandle for per-account attribution.
 */
interface BatchStoryProvider extends StoryProvider
{
    /**
     * @param  list<string>  $handles
     * @return NormalizedBatch whose items are StoryData with ownerHandle set
     */
    public function fetchStoriesForHandles(array $handles): NormalizedBatch;
}
