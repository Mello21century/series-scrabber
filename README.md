# series-scrabber

PHP-based scraper that captures HLS streams from `vidnest.fun` (and other adapter-pluggable sites), downloads them segment-by-segment, and muxes them into MP4 files via ffmpeg.

## Features

- **Three download actions** — single episode, whole season, or full series, driven from a web UI grid.
- **Two live progress bars** — one fills as HLS segments are downloaded one-by-one over AJAX; the second tracks ffmpeg mux progress via `-progress`.
- **Site adapter abstraction** — `SiteAdapter` contract isolates site-specific logic (URL shape, playlist capture regex, CDN headers, season layout). `VidnestAdapter` is the reference implementation.
- **Resumable** — segments already on disk are skipped; partial episodes can be resumed.
- **Dockerized** — one `docker compose up` brings up PHP/Apache + Selenium/Chrome.

## Quick start (Docker)

```bash
docker compose up --build -d
```

- UI:        http://localhost:8080/
- Selenium:  http://localhost:4444/
- Live VNC:  http://localhost:7900/ (password: `secret`)

CLI inside the container:

```bash
docker compose exec app php cli.php 155 1 1   # seriesId season episode
```

Downloaded MP4s land in `./output/sNN/epNN.mp4` on the host (bind-mounted).

## Quick start (local)

Requires PHP 8.2+, `ffmpeg`, `chromedriver` (or Selenium server) on `:4444`, and Composer.

```bash
composer install
chromedriver --port=4444 &
php -S 127.0.0.1:8000
```

Open http://127.0.0.1:8000/?seriesId=155.

## Architecture

```
src/
  Adapter/
    SiteAdapter.php       abstract contract: episodeUrl, cdnHeaders, capturePlaylistUrl, discoverSeasons
    VidnestAdapter.php    vidnest.fun implementation
  Downloader.php          prepare / downloadSegment / finalize / ffmpegStatus / runEpisode
  helpers.php             dd, dump, get, getCdn, resolveUrl

actions.php               JSON front controller (?action=discover|exists|prepare|segment|finalize|ffmpeg-status)
stream.php                legacy SSE batch endpoint
cli.php                   batch downloader for terminal use
index.php                 thin HTML shell linking assets/app.{css,js}
assets/app.{css,js}       extracted frontend
```

### Per-episode flow

1. **`prepare`** — Selenium loads the episode page, captures the `.txt` playlist URL from the performance log, resolves master → highest-bandwidth variant, parses segments + `#EXT-X-MAP` init, writes a `manifest.json` and the init segment to `temp/sNN/epNN/`.
2. **`segment&i=N`** — client loops once per segment over AJAX; PHP downloads exactly one HLS segment per request. Idempotent.
3. **`finalize`** — writes a local `index.m3u8`, spawns `ffmpeg -y -allowed_extensions ALL -i ... -c copy -progress ...` in the background, returns the PID.
4. **`ffmpeg-status`** — client polls; reads `out_time_ms` from the progress file, returns percent. On completion the temp directory is cleaned up.

### Adding a new site

1. Subclass `Mello\Scrabber\Adapter\SiteAdapter`.
2. Implement `episodeUrl`, `cdnHeaders`, `capturePlaylistUrl`, `discoverSeasons`.
3. Swap the adapter construction in [actions.php](actions.php) (or accept a `?site=` param and dispatch).

## Environment

| Variable        | Default                       | Purpose                              |
|-----------------|-------------------------------|--------------------------------------|
| `SELENIUM_HOST` | `http://127.0.0.1:4444/`      | Selenium / chromedriver endpoint     |

## Requirements

- PHP 8.2+ with `curl`, `gd`, `dom`, `libxml`, `json`
- ffmpeg
- Selenium 4 or chromedriver
- Composer dependencies: guzzle, php-webdriver, symfony/dom-crawler

## License

No license specified — all rights reserved by the author.
