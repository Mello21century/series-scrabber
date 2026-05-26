<?php

namespace Mello\Scrabber\Adapter;

use Facebook\WebDriver\Remote\RemoteWebDriver;

abstract class SiteAdapter
{
    public function __construct(protected int $seriesId)
    {
    }

    public function seriesId(): int
    {
        return $this->seriesId;
    }

    abstract public function episodeUrl(int $season, int $ep): string;

    abstract public function cdnHeaders(): array;

    /**
     * Capture the stream source for an episode page.
     * @return ?array{type: 'hls'|'mp4', url: string}
     */
    abstract public function captureSource(RemoteWebDriver $driver, string $url, int $waitSeconds = 15): ?array;

    /**
     * @return array<int,int> season => episodeCount
     */
    abstract public function discoverSeasons(?RemoteWebDriver $driver = null): array;
}
