<?php

namespace Mello\Scrabber\Adapter;

use Facebook\WebDriver\Remote\RemoteWebDriver;

class VidnestAdapter extends SiteAdapter
{
    private const KNOWN_SEASONS = [
        155 => [1 => 20, 2 => 26, 3 => 27, 4 => 24, 5 => 22, 6 => 20],
    ];

    public function episodeUrl(int $season, int $ep): string
    {
        return "https://vidnest.fun/tv/{$this->seriesId}/{$season}/{$ep}";
    }

    public function cdnHeaders(): array
    {
        return [
            'Accept: */*',
            'Accept-Language: en-US,en;q=0.9',
            'Origin: https://vidnest.fun',
            'Referer: https://vidnest.fun/',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: cross-site',
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
        ];
    }

    public function capturePlaylistUrl(RemoteWebDriver $driver, string $url, int $waitSeconds = 10): ?string
    {
        $driver->manage()->getLog('performance');
        $driver->get($url);

        $deadline = microtime(true) + $waitSeconds;
        while (microtime(true) < $deadline) {
            $entries = $driver->manage()->getLog('performance');
            foreach ($entries as $entry) {
                $msg = json_decode($entry['message'] ?? '', true);
                if (!is_array($msg)) {
                    continue;
                }
                $method = $msg['message']['method'] ?? null;
                if ($method !== 'Network.requestWillBeSent' && $method !== 'Network.responseReceived') {
                    continue;
                }
                $reqUrl = $msg['message']['params']['request']['url']
                    ?? $msg['message']['params']['response']['url']
                    ?? null;
                if (is_string($reqUrl) && preg_match('/\.txt(\?|$)/i', $reqUrl)) {
                    return $reqUrl;
                }
            }
            usleep(500_000);
        }
        return null;
    }

    public function discoverSeasons(?RemoteWebDriver $driver = null): array
    {
        return self::KNOWN_SEASONS[$this->seriesId] ?? [];
    }
}
