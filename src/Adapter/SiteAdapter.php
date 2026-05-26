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

    abstract public function capturePlaylistUrl(RemoteWebDriver $driver, string $url, int $waitSeconds = 10): ?string;

    /**
     * @return array<int,int> season => episodeCount
     */
    abstract public function discoverSeasons(?RemoteWebDriver $driver = null): array;
}
