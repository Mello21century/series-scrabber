<?php

namespace Mello\Scrabber;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Mello\Scrabber\Adapter\SiteAdapter;

class Downloader
{
    private string $ffmpegBin;

    public function __construct(
        private SiteAdapter $adapter,
        private string $projectDir,
        private string $seleniumHost = 'http://127.0.0.1:4444/',
        ?string $ffmpegBin = null
    ) {
        @mkdir($this->projectDir . '/output', 0775, true);
        @mkdir($this->projectDir . '/temp', 0775, true);
        $this->ffmpegBin = $ffmpegBin ?? $this->resolveFfmpeg();
    }

    private function resolveFfmpeg(): string
    {
        $candidates = ['/opt/homebrew/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg'];
        foreach ($candidates as $c) {
            if (is_executable($c)) return $c;
        }
        $which = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
        return $which !== '' ? $which : 'ffmpeg';
    }

    public function outputFile(int $season, int $ep): string
    {
        return sprintf('%s/output/s%02d/ep%02d.mp4', $this->projectDir, $season, $ep);
    }

    public function tempDir(int $season, int $ep): string
    {
        return sprintf('%s/temp/s%02d/ep%02d', $this->projectDir, $season, $ep);
    }

    public function exists(int $season, int $ep): bool
    {
        return file_exists($this->outputFile($season, $ep));
    }

    public function createDriver(): RemoteWebDriver
    {
        $capabilities = DesiredCapabilities::chrome();
        $chromeOptions = new ChromeOptions();
        $chromeOptions->addArguments([
            '--headless=new',
            '--autoplay-policy=no-user-gesture-required',
            '--mute-audio',
            '--disable-blink-features=AutomationControlled',
        ]);
        $chromeOptions->setExperimentalOption('perfLoggingPrefs', ['enableNetwork' => true]);
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $chromeOptions);
        $capabilities->setCapability('goog:loggingPrefs', ['performance' => 'ALL']);
        return RemoteWebDriver::create($this->seleniumHost, $capabilities);
    }

    /**
     * Capture playlist, resolve master->variant, parse segments, write manifest + init segment.
     */
    public function prepare(int $season, int $ep, ?RemoteWebDriver $driver = null): array
    {
        $tempDir = $this->tempDir($season, $ep);
        @mkdir($tempDir, 0775, true);
        $manifestPath = "$tempDir/manifest.json";

        if (file_exists($manifestPath)) {
            $existing = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($existing) && !empty($existing['segments'])) {
                return ['ok' => true, 'manifest' => $existing, 'cached' => true];
            }
        }

        $ownDriver = false;
        if ($driver === null) {
            $driver = $this->createDriver();
            $ownDriver = true;
        }

        try {
            $pageUrl = $this->adapter->episodeUrl($season, $ep);
            $source = $this->adapter->captureSource($driver, $pageUrl);
        } finally {
            if ($ownDriver) {
                $driver->quit();
            }
        }

        if ($source === null) {
            return ['ok' => false, 'error' => 'no stream source captured'];
        }

        $headers = $this->adapter->cdnHeaders();

        if (($source['type'] ?? 'hls') === 'mp4') {
            return $this->prepareMp4($source['url'], $tempDir, $manifestPath, $headers);
        }

        $playlistUrl = $source['url'];
        $playlist = getCdn($playlistUrl, $headers);
        if ($playlist === false || $playlist === '') {
            return ['ok' => false, 'error' => 'playlist download failed'];
        }

        if (str_contains($playlist, '#EXT-X-STREAM-INF')) {
            $lines = preg_split('/\R/', $playlist);
            $bestBw = -1;
            $bestUri = null;
            for ($i = 0, $n = count($lines); $i < $n; $i++) {
                if (str_starts_with($lines[$i], '#EXT-X-STREAM-INF')) {
                    if (preg_match('/BANDWIDTH=(\d+)/', $lines[$i], $m)) {
                        $bw = (int) $m[1];
                        $uri = trim($lines[$i + 1] ?? '');
                        if ($uri !== '' && $bw > $bestBw) {
                            $bestBw = $bw;
                            $bestUri = $uri;
                        }
                    }
                }
            }
            if ($bestUri === null) {
                return ['ok' => false, 'error' => 'no variant in master playlist'];
            }
            $playlistUrl = resolveUrl($playlistUrl, $bestUri);
            $playlist = getCdn($playlistUrl, $headers);
            if ($playlist === false || $playlist === '') {
                return ['ok' => false, 'error' => 'variant playlist download failed'];
            }
        }

        $lines = preg_split('/\R/', $playlist);
        $init = null;
        $segments = [];
        $duration = 0.0;
        $nextDur = null;
        $segIdx = 0;
        $rawLines = [];

        foreach ($lines as $line) {
            $trim = trim($line);

            if (str_starts_with($trim, '#EXT-X-MAP')) {
                if (preg_match('/URI="([^"]+)"/', $trim, $m)) {
                    $init = [
                        'url' => resolveUrl($playlistUrl, $m[1]),
                        'local' => 'init.mp4',
                    ];
                    $rawLines[] = '#EXT-X-MAP:URI="init.mp4"';
                    continue;
                }
            }

            if (str_starts_with($trim, '#EXTINF:')) {
                if (preg_match('/#EXTINF:([0-9.]+)/', $trim, $m)) {
                    $nextDur = (float) $m[1];
                }
                $rawLines[] = $line;
                continue;
            }

            if ($trim === '' || str_starts_with($trim, '#')) {
                $rawLines[] = $line;
                continue;
            }

            $localName = sprintf('seg-%05d.m4s', $segIdx);
            $segments[] = [
                'url' => resolveUrl($playlistUrl, $trim),
                'local' => $localName,
            ];
            if ($nextDur !== null) {
                $duration += $nextDur;
                $nextDur = null;
            }
            $rawLines[] = $localName;
            $segIdx++;
        }

        if ($init !== null) {
            $bytes = getCdn($init['url'], $headers);
            if ($bytes === false || $bytes === '') {
                return ['ok' => false, 'error' => 'init segment download failed'];
            }
            file_put_contents("$tempDir/{$init['local']}", $bytes);
        }

        $manifest = [
            'playlistUrl' => $playlistUrl,
            'init' => $init,
            'segments' => $segments,
            'totalSegments' => count($segments),
            'duration' => $duration,
            'playlistLines' => $rawLines,
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));

        return ['ok' => true, 'manifest' => $manifest];
    }

    public function downloadSegment(int $season, int $ep, int $segIdx): array
    {
        $tempDir = $this->tempDir($season, $ep);
        $manifestPath = "$tempDir/manifest.json";
        if (!file_exists($manifestPath)) {
            return ['ok' => false, 'error' => 'manifest missing — call prepare first'];
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $total = (int) ($manifest['totalSegments'] ?? 0);
        if ($segIdx < 0 || $segIdx >= $total) {
            return ['ok' => false, 'error' => "segment $segIdx out of range (0..$total)"];
        }
        $seg = $manifest['segments'][$segIdx];
        $path = "$tempDir/{$seg['local']}";
        if (file_exists($path) && filesize($path) > 0) {
            return ['ok' => true, 'segIdx' => $segIdx, 'total' => $total, 'cached' => true];
        }

        $type = $manifest['type'] ?? 'hls';
        $headers = $this->adapter->cdnHeaders();
        $url = $type === 'mp4' ? $manifest['sourceUrl'] : $seg['url'];
        if ($type === 'mp4' && isset($seg['range'])) {
            $headers[] = 'Range: bytes=' . $seg['range'][0] . '-' . $seg['range'][1];
        }

        $bytes = false;
        $lastErr = '';
        $maxAttempts = 4;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $bytes = getCdn($url, $headers);
            if ($bytes !== false && $bytes !== '') break;
            $lastErr = 'CDN fetch failed';
            if ($attempt < $maxAttempts) {
                usleep((1 << ($attempt - 1)) * 500_000); // 0.5s, 1s, 2s
            }
        }

        if ($bytes === false || $bytes === '') {
            return ['ok' => false, 'error' => "segment $segIdx: $lastErr after $maxAttempts attempts", 'segIdx' => $segIdx, 'total' => $total];
        }
        file_put_contents($path, $bytes);
        return ['ok' => true, 'segIdx' => $segIdx, 'total' => $total, 'done' => $segIdx + 1 === $total];
    }

    private function prepareMp4(string $sourceUrl, string $tempDir, string $manifestPath, array $headers): array
    {
        $size = $this->probeSize($sourceUrl, $headers);
        $chunk = 4 * 1024 * 1024;
        $segments = [];
        if ($size > 0) {
            $total = (int) max(1, ceil($size / $chunk));
            for ($i = 0; $i < $total; $i++) {
                $start = $i * $chunk;
                $end = min($start + $chunk - 1, $size - 1);
                $segments[] = [
                    'local' => sprintf('seg-%05d.bin', $i),
                    'range' => [$start, $end],
                ];
            }
        } else {
            // Unknown size — single segment, no Range header.
            $segments[] = ['local' => 'seg-00000.bin'];
        }

        $manifest = [
            'type' => 'mp4',
            'sourceUrl' => $sourceUrl,
            'segments' => $segments,
            'totalSegments' => count($segments),
            'totalBytes' => $size,
            'duration' => 0.0,
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));
        return ['ok' => true, 'manifest' => $manifest];
    }

    private function probeSize(string $url, array $headers): int
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_NOBODY, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_exec($curl);
        $size = (int) curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($curl);
        return $size > 0 ? $size : 0;
    }

    public function finalize(int $season, int $ep): array
    {
        $tempDir = $this->tempDir($season, $ep);
        $manifestPath = "$tempDir/manifest.json";
        if (!file_exists($manifestPath)) {
            return ['ok' => false, 'error' => 'manifest missing'];
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        $outDir = sprintf('%s/output/s%02d', $this->projectDir, $season);
        @mkdir($outDir, 0775, true);
        $outFile = $this->outputFile($season, $ep);

        $progressFile = "$tempDir/ffmpeg.progress";
        $logFile = "$tempDir/ffmpeg.log";
        @unlink($progressFile);
        @unlink($logFile);

        if (($manifest['type'] ?? 'hls') === 'mp4') {
            $fh = fopen($outFile, 'wb');
            if ($fh === false) {
                return ['ok' => false, 'error' => "cannot open $outFile for writing"];
            }
            foreach ($manifest['segments'] as $seg) {
                $path = "$tempDir/{$seg['local']}";
                if (!file_exists($path)) {
                    fclose($fh);
                    @unlink($outFile);
                    return ['ok' => false, 'error' => "missing segment {$seg['local']}"];
                }
                $sh = fopen($path, 'rb');
                stream_copy_to_stream($sh, $fh);
                fclose($sh);
            }
            fclose($fh);
            // Signal "ffmpeg done" so ffmpegStatus reports 100% and cleans up temp.
            file_put_contents($progressFile, "progress=end\n");
            return ['ok' => true, 'pid' => 0, 'duration' => 0.0, 'outFile' => $outFile];
        }

        $localPlaylist = "$tempDir/index.m3u8";
        file_put_contents($localPlaylist, implode("\n", $manifest['playlistLines'] ?? []));

        $cmd = sprintf(
            '%s -y -allowed_extensions ALL -i %s -c copy -progress %s %s > %s 2>&1 & echo $!',
            escapeshellarg($this->ffmpegBin),
            escapeshellarg($localPlaylist),
            escapeshellarg($progressFile),
            escapeshellarg($outFile),
            escapeshellarg($logFile)
        );
        $pid = (int) trim((string) shell_exec($cmd));
        file_put_contents("$tempDir/ffmpeg.pid", (string) $pid);

        return [
            'ok' => true,
            'pid' => $pid,
            'duration' => $manifest['duration'] ?? 0.0,
            'outFile' => $outFile,
        ];
    }

    /**
     * Synchronous end-to-end download for CLI/SSE callers.
     */
    public function runEpisode(int $season, int $ep, ?RemoteWebDriver $driver = null, ?callable $onProgress = null): bool
    {
        $prep = $this->prepare($season, $ep, $driver);
        if (!$prep['ok']) {
            $onProgress && $onProgress('error', $prep['error'] ?? 'prepare failed');
            return false;
        }
        $total = $prep['manifest']['totalSegments'];
        $onProgress && $onProgress('segments', ['done' => 0, 'total' => $total]);
        for ($i = 0; $i < $total; $i++) {
            $r = $this->downloadSegment($season, $ep, $i);
            if (!$r['ok']) {
                $onProgress && $onProgress('error', $r['error'] ?? "segment $i failed");
                return false;
            }
            $onProgress && $onProgress('segments', ['done' => $i + 1, 'total' => $total]);
        }
        $fin = $this->finalize($season, $ep);
        if (!$fin['ok']) {
            $onProgress && $onProgress('error', $fin['error'] ?? 'finalize failed');
            return false;
        }
        while (true) {
            usleep(500_000);
            $st = $this->ffmpegStatus($season, $ep);
            $onProgress && $onProgress('ffmpeg', $st['percent']);
            if ($st['done']) {
                return $st['ok'];
            }
        }
    }

    public function ffmpegStatus(int $season, int $ep): array
    {
        $tempDir = $this->tempDir($season, $ep);
        $progressFile = "$tempDir/ffmpeg.progress";
        $manifestPath = "$tempDir/manifest.json";
        $manifest = file_exists($manifestPath)
            ? json_decode((string) file_get_contents($manifestPath), true)
            : [];
        $duration = (float) ($manifest['duration'] ?? 0.0);
        $outFile = $this->outputFile($season, $ep);

        $percent = 0;
        $done = false;
        $ok = false;

        if (file_exists($progressFile)) {
            $contents = (string) file_get_contents($progressFile);
            if ($contents !== '') {
                preg_match_all('/^out_time_ms=(\d+)/m', $contents, $m);
                $outTimeMs = $m[1] ? (int) end($m[1]) : 0;
                $current = $outTimeMs / 1_000_000.0;
                if ($duration > 0) {
                    $percent = (int) min(100, round(($current / $duration) * 100));
                }
                if (preg_match('/^progress=end/m', $contents)) {
                    $done = true;
                    $ok = file_exists($outFile) && filesize($outFile) > 0;
                    $percent = $ok ? 100 : $percent;
                }
            }
        }

        if (!$done) {
            $pidFile = "$tempDir/ffmpeg.pid";
            if (file_exists($pidFile)) {
                $pid = (int) trim((string) file_get_contents($pidFile));
                if ($pid > 0 && !$this->processAlive($pid)) {
                    $done = true;
                    $ok = file_exists($outFile) && filesize($outFile) > 0;
                    $percent = $ok ? 100 : $percent;
                }
            }
        }

        if ($done && $ok) {
            $this->cleanupTemp($tempDir);
        }

        return ['ok' => $ok, 'done' => $done, 'percent' => $percent];
    }

    private function processAlive(int $pid): bool
    {
        if ($pid <= 0) return false;
        return posix_kill($pid, 0);
    }

    private function cleanupTemp(string $tempDir): void
    {
        foreach (glob("$tempDir/*") as $f) {
            @unlink($f);
        }
        @rmdir($tempDir);
    }
}
