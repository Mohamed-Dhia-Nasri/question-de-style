<?php

namespace App\Platform\Enrichment\Media;

use Closure;

/**
 * The per-target owner of all local media temp files for one enrichment
 * run (sub-project B): each asset is downloaded ONCE and shared by every
 * consumer (Vision/Video-Intelligence/Speech inline sends + keyframe
 * persistence). Acquisition is LAZY — nothing is downloaded until a
 * consumer asks — and close() is the single cleanup point (scraped bytes
 * are untrusted; they never outlive the run).
 */
class MediaWorkspace
{
    /** @var list<LocalMediaAsset> */
    private array $images = [];

    private ?LocalMediaAsset $video = null;

    /** @var list<string> acquisition skip markers (media:none, media:fetch-failed, media:too-large, media:too-old) */
    private array $markers = [];

    /** @var list<string> */
    private array $tempPaths = [];

    private bool $acquired = false;

    private ?Closure $acquirer;

    public function __construct(Closure $acquirer)
    {
        $this->acquirer = $acquirer;
    }

    /** @return list<LocalMediaAsset> */
    public function images(): array
    {
        $this->acquire();

        return $this->images;
    }

    public function video(): ?LocalMediaAsset
    {
        $this->acquire();

        return $this->video;
    }

    /** @return list<string> */
    public function markers(): array
    {
        $this->acquire();

        return $this->markers;
    }

    public function addImage(LocalMediaAsset $asset): void
    {
        $this->images[] = $asset;
    }

    public function setVideo(LocalMediaAsset $asset): void
    {
        $this->video = $asset;
    }

    public function addMarker(string $marker): void
    {
        $this->markers[] = $marker;
    }

    /** A workspace-owned temp path (cleaned up by close()), or null. */
    public function newTempPath(): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'qds-media-');

        if (is_string($path)) {
            $this->tempPaths[] = $path;

            return $path;
        }

        return null;
    }

    public function close(): void
    {
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }

        $this->tempPaths = [];
        $this->images = [];
        $this->video = null;
    }

    private function acquire(): void
    {
        if ($this->acquired) {
            return;
        }

        $this->acquired = true;
        $acquirer = $this->acquirer;
        $this->acquirer = null;

        if ($acquirer !== null) {
            $acquirer($this);
        }
    }
}
