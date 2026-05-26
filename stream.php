<?php

use Mello\Scrabber\Adapter\VidnestAdapter;
use Mello\Scrabber\Downloader;

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'Off');
set_time_limit(0);
ignore_user_abort(false);

while (ob_get_level() > 0) {
    ob_end_flush();
}

ob_start(function ($chunk) {
    if ($chunk === '' || ctype_space($chunk)) {
        return '';
    }
    $lines = preg_split('/\R/', rtrim($chunk));
    $out = '';
    foreach ($lines as $line) {
        if ($line === '') continue;
        $out .= "event: log\ndata: " . json_encode(['msg' => $line]) . "\n\n";
    }
    return $out;
}, 1);

function emit(string $event, array $data): void
{
    @ob_flush();
    @flush();
    echo "event: $event\n";
    echo 'data: ' . json_encode($data) . "\n\n";
    @ob_flush();
    @flush();
}

require_once __DIR__ . '/vendor/autoload.php';

$seriesId = isset($_GET['seriesId']) ? max(1, (int) $_GET['seriesId']) : 155;
$startSeason = isset($_GET['s']) ? max(1, (int) $_GET['s']) : 1;
$startEp = isset($_GET['e']) ? max(1, (int) $_GET['e']) : 1;

$adapter = new VidnestAdapter($seriesId);
$downloader = new Downloader($adapter, __DIR__, getenv('SELENIUM_HOST') ?: 'http://127.0.0.1:4444/');

echo "Starting Selenium session…\n";
$driver = $downloader->createDriver();
$driver->manage()->window()->maximize();

$seasons = $adapter->discoverSeasons($driver);

foreach ($seasons as $season => $epCount) {
    if ($season < $startSeason) continue;
    for ($ep = ($season === $startSeason ? $startEp : 1); $ep <= $epCount; $ep++) {
        emit('episode-start', ['season' => $season, 'ep' => $ep]);
        if ($downloader->exists($season, $ep)) {
            echo "S{$season}E{$ep}: already exists, skipping\n";
            emit('episode-done', ['season' => $season, 'ep' => $ep, 'ok' => true, 'skipped' => true]);
            continue;
        }
        echo "S{$season}E{$ep}: starting\n";
        $ok = $downloader->runEpisode($season, $ep, $driver, function (string $stage, $data) use ($season, $ep) {
            if ($stage === 'segments' && ($data['done'] % 10 === 0 || $data['done'] === $data['total'])) {
                echo "  · seg {$data['done']}/{$data['total']}\n";
            } elseif ($stage === 'error') {
                echo "  ! $data\n";
            }
        });
        echo "S{$season}E{$ep}: " . ($ok ? 'OK' : 'FAIL') . "\n";
        emit('episode-done', ['season' => $season, 'ep' => $ep, 'ok' => $ok]);
    }
}

$driver->quit();
emit('done', []);
