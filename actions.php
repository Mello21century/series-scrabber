<?php

use Mello\Scrabber\Adapter\VidnestAdapter;
use Mello\Scrabber\Downloader;

set_time_limit(0);
require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json');

function fail(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$action = $_GET['action'] ?? '';
$seriesId = isset($_GET['seriesId']) ? max(1, (int) $_GET['seriesId']) : 155;
$season = isset($_GET['s']) ? max(1, (int) $_GET['s']) : 0;
$ep = isset($_GET['e']) ? max(1, (int) $_GET['e']) : 0;

$adapter = new VidnestAdapter($seriesId);
$downloader = new Downloader($adapter, __DIR__, getenv('SELENIUM_HOST') ?: 'http://127.0.0.1:4444/');

try {
    switch ($action) {
        case 'discover':
            echo json_encode([
                'ok' => true,
                'seriesId' => $seriesId,
                'seasons' => $adapter->discoverSeasons(),
            ]);
            break;

        case 'exists':
            if (!$season || !$ep) fail('s and e required');
            echo json_encode(['ok' => true, 'exists' => $downloader->exists($season, $ep)]);
            break;

        case 'prepare':
            if (!$season || !$ep) fail('s and e required');
            if ($downloader->exists($season, $ep)) {
                echo json_encode(['ok' => true, 'skipped' => true, 'manifest' => ['totalSegments' => 0]]);
                break;
            }
            echo json_encode($downloader->prepare($season, $ep));
            break;

        case 'segment':
            if (!$season || !$ep) fail('s and e required');
            $i = isset($_GET['i']) ? (int) $_GET['i'] : -1;
            if ($i < 0) fail('i required');
            echo json_encode($downloader->downloadSegment($season, $ep, $i));
            break;

        case 'finalize':
            if (!$season || !$ep) fail('s and e required');
            echo json_encode($downloader->finalize($season, $ep));
            break;

        case 'ffmpeg-status':
            if (!$season || !$ep) fail('s and e required');
            echo json_encode($downloader->ffmpegStatus($season, $ep));
            break;

        default:
            fail("unknown action: $action", 404);
    }
} catch (Throwable $e) {
    fail($e->getMessage(), 500);
}
