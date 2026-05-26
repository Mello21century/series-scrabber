<?php

use Mello\Scrabber\Adapter\VidnestAdapter;
use Mello\Scrabber\Downloader;

set_time_limit(0);
require_once __DIR__ . '/vendor/autoload.php';

$seriesId = (int) ($argv[1] ?? 155);
$startSeason = (int) ($argv[2] ?? 1);
$startEp = (int) ($argv[3] ?? 1);

$adapter = new VidnestAdapter($seriesId);
$downloader = new Downloader($adapter, __DIR__, getenv('SELENIUM_HOST') ?: 'http://127.0.0.1:4444/');
$driver = $downloader->createDriver();
$driver->manage()->window()->maximize();

$seasons = $adapter->discoverSeasons($driver);

foreach ($seasons as $season => $epCount) {
    if ($season < $startSeason) continue;
    for ($ep = ($season === $startSeason ? $startEp : 1); $ep <= $epCount; $ep++) {
        if ($downloader->exists($season, $ep)) {
            echo sprintf("S%02dE%02d: already exists, skipping\n", $season, $ep);
            continue;
        }
        echo sprintf("S%02dE%02d: starting\n", $season, $ep);
        $ok = $downloader->runEpisode($season, $ep, $driver, function (string $stage, $data) use ($season, $ep) {
            if ($stage === 'segments' && $data['done'] % 10 === 0) {
                echo sprintf("  · seg %d/%d\n", $data['done'], $data['total']);
            } elseif ($stage === 'ffmpeg') {
                echo "  · ffmpeg $data%\n";
            } elseif ($stage === 'error') {
                echo "  ! $data\n";
            }
        });
        echo sprintf("S%02dE%02d: %s\n", $season, $ep, $ok ? 'OK' : 'FAIL');
    }
}

$driver->quit();
