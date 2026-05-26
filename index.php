<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>popcornfilmz scraper</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<h1>vidnest.fun → mp4 scraper</h1>

<div class="controls">
    <label>series</label><input id="seriesId" type="number" min="1" value="<?= htmlspecialchars($_GET['seriesId'] ?? '155') ?>">
    <label>S</label><input id="season" type="number" min="1" value="1">
    <label>E</label><input id="episode" type="number" min="1" value="1">
    <button id="downloadEpisodeBtn">Episode</button>
    <button id="downloadSeasonBtn">Season</button>
    <button id="downloadSeriesBtn">Full Series</button>
    <button id="stopBtn" disabled>Stop</button>
    <span class="status" id="status">idle</span>
</div>

<div id="grid"></div>

<div class="progress-wrap">
    <div class="progress-row">
        <div class="progress-label">segments</div>
        <div class="progress-track"><div id="bar-segments" class="progress-fill"></div></div>
        <div id="pct-segments" class="progress-pct">0%</div>
    </div>
    <div class="progress-row">
        <div class="progress-label">ffmpeg</div>
        <div class="progress-track"><div id="bar-ffmpeg" class="progress-fill ffmpeg"></div></div>
        <div id="pct-ffmpeg" class="progress-pct">0%</div>
    </div>
</div>

<div class="log" id="log"></div>

<script src="assets/app.js"></script>
</body>
</html>
