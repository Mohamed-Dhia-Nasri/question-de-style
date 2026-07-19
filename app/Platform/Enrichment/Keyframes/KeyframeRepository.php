<?php

namespace App\Platform\Enrichment\Keyframes;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\Story;

class KeyframeRepository
{
    public function forOwner(ContentItem|Story $owner): KeyframeSet
    {
        $frames = Keyframe::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->id)
            ->orderBy('ordinal')
            ->get()
            ->all();

        return new KeyframeSet($frames, $frames === [] ? 'empty' : 'extracted');
    }
}
