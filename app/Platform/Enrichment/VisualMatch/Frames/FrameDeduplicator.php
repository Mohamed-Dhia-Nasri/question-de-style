<?php

namespace App\Platform\Enrichment\VisualMatch\Frames;

/**
 * Near-duplicate grouping via 64-bit difference-hash (spec §8 step 3):
 * each frame joins the FIRST earlier group within the hamming threshold,
 * else starts its own; only the earliest representative is embedded.
 * Undecodable bytes hash to null and never group (each stays a singleton
 * — the quality filter, not this class, decides their fate). Iteration
 * order is input order (ordinal), so grouping is fully deterministic.
 */
final class FrameDeduplicator
{
    /**
     * @param  list<array{keyframe: \App\Modules\Monitoring\Models\Keyframe, bytes: string, mimeType: string}>  $frames
     * @return list<PreparedFrame>
     */
    public function deduplicate(array $frames): array
    {
        $enabled = (bool) config('qds.enrichment.visual_match.dedup.enabled');
        $threshold = (int) config('qds.enrichment.visual_match.dedup.hamming_threshold');

        /** @var list<array{hash: int|null, members: list<array{keyframe: \App\Modules\Monitoring\Models\Keyframe, bytes: string, mimeType: string}>}> $groups */
        $groups = [];

        foreach ($frames as $frame) {
            $hash = $enabled ? $this->dHash($frame['bytes']) : null;

            if ($enabled && $hash !== null) {
                foreach ($groups as $index => $group) {
                    if ($group['hash'] !== null && $this->hammingDistance($group['hash'], $hash) <= $threshold) {
                        $groups[$index]['members'][] = $frame;

                        continue 2;
                    }
                }
            }

            $groups[] = ['hash' => $hash, 'members' => [$frame]];
        }

        return array_map(fn (array $group): PreparedFrame => $this->prepared($group['members']), $groups);
    }

    /** @param non-empty-list<array{keyframe: \App\Modules\Monitoring\Models\Keyframe, bytes: string, mimeType: string}> $members */
    private function prepared(array $members): PreparedFrame
    {
        $representative = $members[0];
        $timestamps = [];

        foreach ($members as $member) {
            if ($member['keyframe']->timestamp_ms !== null) {
                $timestamps[] = (int) $member['keyframe']->timestamp_ms;
            }
        }

        return new PreparedFrame(
            keyframe: $representative['keyframe'],
            bytes: $representative['bytes'],
            mimeType: $representative['mimeType'],
            representedFrames: count($members),
            spanStartMs: $timestamps === [] ? null : min($timestamps),
            spanEndMs: $timestamps === [] ? null : max($timestamps),
        );
    }

    /** 64-bit dHash of a 9×8 downsampled grayscale copy; null when undecodable. */
    private function dHash(string $bytes): ?int
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $sample = imagecreatetruecolor(9, 8);
        imagecopyresampled($sample, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image));
        imagedestroy($image);

        $hash = 0;
        $bit = 0;

        for ($y = 0; $y < 8; $y++) {
            $previous = $this->luminanceAt($sample, 0, $y);

            for ($x = 1; $x < 9; $x++) {
                $current = $this->luminanceAt($sample, $x, $y);

                if ($current > $previous) {
                    $hash |= 1 << $bit;
                }

                $bit++;
                $previous = $current;
            }
        }

        imagedestroy($sample);

        return $hash;
    }

    private function luminanceAt(\GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);

        return 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
    }

    /** Popcount of XOR — logical shift keeps bit 63 (a negative int) safe. */
    private function hammingDistance(int $a, int $b): int
    {
        $xor = $a ^ $b;
        $distance = 0;

        while ($xor !== 0) {
            $distance += $xor & 1;
            $xor = ($xor >> 1) & PHP_INT_MAX;
        }

        return $distance;
    }
}
