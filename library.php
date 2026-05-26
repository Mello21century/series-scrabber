<?php

use Mello\Scrabber\Adapter\VidnestAdapter;
use Mello\Scrabber\Downloader;

require_once __DIR__ . '/vendor/autoload.php';

$seriesId = isset($_GET['seriesId']) ? max(1, (int) $_GET['seriesId']) : 155;
$adapter = new VidnestAdapter($seriesId);
$downloader = new Downloader($adapter, __DIR__, getenv('SELENIUM_HOST') ?: 'http://127.0.0.1:4444/');
$downloads = $downloader->listDownloads();

function fmtBytes(int $b): string
{
    if ($b < 1024) return "$b B";
    if ($b < 1024 ** 2) return number_format($b / 1024, 1) . ' KB';
    if ($b < 1024 ** 3) return number_format($b / 1024 ** 2, 1) . ' MB';
    return number_format($b / 1024 ** 3, 2) . ' GB';
}

$totalCount = 0;
$totalBytes = 0;
foreach ($downloads as $eps) {
    foreach ($eps as $ep) {
        $totalCount++;
        $totalBytes += $ep['size'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Library — popcornfilmz scraper</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.lib-header { display: flex; align-items: baseline; gap: 16px; margin-bottom: 16px; }
.lib-stats { color: #98a2ad; font-size: 12px; }
.lib-season { margin-bottom: 18px; }
.lib-season h2 { font-size: 13px; color: #d6d8da; margin: 0 0 6px; }
.lib-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.lib-table th, .lib-table td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #1e2329; }
.lib-table th { color: #98a2ad; font-weight: normal; }
.lib-table tr.deleting { opacity: 0.4; }
.lib-table a { color: #3a86ff; text-decoration: none; }
.lib-table a:hover { text-decoration: underline; }
.lib-actions { display: flex; gap: 6px; }
.lib-actions button { background: #2a3038; padding: 4px 10px; font-size: 11px; }
.lib-actions button.danger { background: #5a1f1f; }
.lib-empty { color: #6c7480; padding: 20px 0; }
.nav { display: flex; gap: 12px; margin-bottom: 16px; font-size: 12px; }
.nav a { color: #98a2ad; text-decoration: none; padding: 4px 10px; border: 1px solid #2a3038; border-radius: 3px; }
.nav a:hover { color: #fff; border-color: #3a86ff; }
.nav a.active { color: #fff; border-color: #3a86ff; }
</style>
</head>
<body>
<div class="nav">
    <a href="index.php?seriesId=<?= $seriesId ?>">← Scraper</a>
    <a href="library.php?seriesId=<?= $seriesId ?>" class="active">Library</a>
</div>

<div class="lib-header">
    <h1 style="margin:0">Library</h1>
    <span class="lib-stats"><?= $totalCount ?> episodes · <?= fmtBytes($totalBytes) ?></span>
</div>

<?php if ($totalCount === 0): ?>
    <div class="lib-empty">No episodes downloaded yet.</div>
<?php else: foreach ($downloads as $season => $eps): ?>
    <div class="lib-season">
        <h2>S<?= str_pad((string)$season, 2, '0', STR_PAD_LEFT) ?> · <?= count($eps) ?> episode<?= count($eps) === 1 ? '' : 's' ?></h2>
        <table class="lib-table">
            <thead><tr><th>Episode</th><th>Size</th><th>Downloaded</th><th>File</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($eps as $ep): ?>
                <tr data-season="<?= $season ?>" data-ep="<?= $ep['ep'] ?>">
                    <td>S<?= str_pad((string)$season, 2, '0', STR_PAD_LEFT) ?>E<?= str_pad((string)$ep['ep'], 2, '0', STR_PAD_LEFT) ?></td>
                    <td><?= fmtBytes($ep['size']) ?></td>
                    <td><?= date('Y-m-d H:i', $ep['mtime']) ?></td>
                    <td><a href="<?= htmlspecialchars($ep['path']) ?>" target="_blank">play</a></td>
                    <td class="lib-actions">
                        <button class="danger" data-action="delete">Delete</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; endif; ?>

<script>
document.querySelectorAll('button[data-action="delete"]').forEach(btn => {
    btn.addEventListener('click', async () => {
        const row = btn.closest('tr');
        const s = row.dataset.season;
        const e = row.dataset.ep;
        if (!confirm(`Delete S${s}E${e}?`)) return;
        row.classList.add('deleting');
        try {
            const r = await fetch(`actions.php?action=delete&seriesId=<?= $seriesId ?>&s=${s}&e=${e}`);
            const j = await r.json();
            if (j.ok) row.remove();
            else { row.classList.remove('deleting'); alert('delete failed: ' + (j.error || 'unknown')); }
        } catch (err) {
            row.classList.remove('deleting');
            alert('delete failed: ' + err.message);
        }
    });
});
</script>
</body>
</html>
