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
            'Accept-Encoding: identity;q=1, *;q=0',
            'Origin: https://vidnest.fun',
            'Referer: https://vidnest.fun/',
            'Sec-Fetch-Dest: video',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: cross-site',
            'Sec-Ch-Ua: "Chromium";v="138", "Not(A:Brand";v="8"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "macOS"',
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
        ];
    }

    public function captureSource(RemoteWebDriver $driver, string $url, int $waitSeconds = 15): ?array
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
                if (!is_string($reqUrl)) {
                    continue;
                }
                if (preg_match('/\.txt(\?|$)/i', $reqUrl)) {
                    return ['type' => 'hls', 'url' => $reqUrl];
                }
                if (str_contains($reqUrl, '/mp4-proxy?url=')) {
                    // Bypass the worker proxy — fetch upstream MP4 directly with the
                    // headers the worker would have forwarded.
                    $q = parse_url($reqUrl, PHP_URL_QUERY) ?? '';
                    $params = [];
                    parse_str($q, $params);
                    $inner = $params['url'] ?? '';
                    $hdrs = [];
                    if (!empty($params['headers'])) {
                        $j = json_decode($params['headers'], true);
                        if (is_array($j)) {
                            foreach ($j as $k => $v) {
                                $hdrs[] = "$k: $v";
                            }
                        }
                    }
                    if ($inner !== '') {
                        return ['type' => 'mp4', 'url' => $inner, 'headers' => $hdrs];
                    }
                    return ['type' => 'mp4', 'url' => $reqUrl];
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
