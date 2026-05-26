const grid = document.getElementById('grid');
const log = document.getElementById('log');
const statusEl = document.getElementById('status');
const seriesBtn = document.getElementById('downloadSeriesBtn');
const episodeBtn = document.getElementById('downloadEpisodeBtn');
const seasonBtn = document.getElementById('downloadSeasonBtn');
const stopBtn = document.getElementById('stopBtn');
const seriesIdInput = document.getElementById('seriesId');
const seasonInput = document.getElementById('season');
const epInput = document.getElementById('episode');

let seasons = {};
let stopped = false;
let running = false;

function $(id) { return document.getElementById(id); }
function key(s, e) { return `s${s}e${e}`; }

function append(line) {
    const atBottom = log.scrollTop + log.clientHeight >= log.scrollHeight - 4;
    log.textContent += line + '\n';
    if (atBottom) log.scrollTop = log.scrollHeight;
}

function setStatus(t) { statusEl.textContent = t; }

function setCell(s, e, cls) {
    const el = document.getElementById(key(s, e));
    if (!el) return;
    el.classList.remove('running', 'ok', 'fail', 'skip');
    if (cls) el.classList.add(cls);
}

function setBar(name, value, max) {
    const pct = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0;
    $(`bar-${name}`).style.width = pct + '%';
    $(`pct-${name}`).textContent = pct + '%';
}

function resetBars() {
    setBar('segments', 0, 1);
    setBar('ffmpeg', 0, 1);
}

function setBusy(b) {
    running = b;
    [seriesBtn, episodeBtn, seasonBtn].forEach(btn => btn.disabled = b);
    stopBtn.disabled = !b;
}

async function api(action, params = {}) {
    const qs = new URLSearchParams({ action, seriesId: seriesIdInput.value || '155', ...params });
    const r = await fetch(`actions.php?${qs}`);
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    return r.json();
}

async function buildGrid() {
    const data = await api('discover');
    seasons = data.seasons || {};
    grid.innerHTML = '';
    for (const [s, count] of Object.entries(seasons)) {
        const row = document.createElement('div');
        row.className = 'season-row';
        const label = document.createElement('div');
        label.className = 'season-label';
        label.textContent = 'S' + String(s).padStart(2, '0');
        row.appendChild(label);
        const seasonGo = document.createElement('button');
        seasonGo.className = 'season-btn';
        seasonGo.textContent = '▶';
        seasonGo.title = `Download season ${s}`;
        seasonGo.onclick = () => kick(() => downloadSeason(+s));
        row.appendChild(seasonGo);
        const cells = document.createElement('div');
        cells.className = 'cells';
        cells.style.gridTemplateColumns = `repeat(${count}, 22px)`;
        for (let e = 1; e <= count; e++) {
            const cell = document.createElement('div');
            cell.className = 'cell';
            cell.id = key(s, e);
            cell.textContent = e;
            cell.title = `S${s}E${e}`;
            cell.onclick = () => kick(() => downloadEpisode(+s, e));
            cells.appendChild(cell);
        }
        row.appendChild(cells);
        grid.appendChild(row);
    }
    // Mark already-downloaded
    for (const [s, count] of Object.entries(seasons)) {
        for (let e = 1; e <= count; e++) {
            api('exists', { s, e }).then(r => { if (r.exists) setCell(+s, e, 'skip'); });
        }
    }
}

async function downloadEpisode(s, e) {
    if (stopped) return false;
    setCell(s, e, 'running');
    setStatus(`S${s}E${e}: preparing`);
    resetBars();
    append(`S${s}E${e}: prepare`);
    const prep = await api('prepare', { s, e });
    if (!prep.ok) {
        append(`S${s}E${e}: prepare failed — ${prep.error}`);
        setCell(s, e, 'fail');
        return false;
    }
    if (prep.skipped) {
        append(`S${s}E${e}: already downloaded`);
        setCell(s, e, 'skip');
        return true;
    }
    const total = prep.manifest.totalSegments;
    append(`S${s}E${e}: ${total} segments`);
    setBar('segments', 0, total);
    for (let i = 0; i < total; i++) {
        if (stopped) { setCell(s, e, 'fail'); return false; }
        setStatus(`S${s}E${e}: segment ${i + 1}/${total}`);
        const r = await api('segment', { s, e, i });
        if (!r.ok) {
            append(`S${s}E${e}: segment ${i} failed — ${r.error}`);
            setCell(s, e, 'fail');
            return false;
        }
        setBar('segments', i + 1, total);
    }
    setStatus(`S${s}E${e}: muxing`);
    append(`S${s}E${e}: finalize`);
    const fin = await api('finalize', { s, e });
    if (!fin.ok) {
        append(`S${s}E${e}: finalize failed — ${fin.error}`);
        setCell(s, e, 'fail');
        return false;
    }
    while (true) {
        if (stopped) return false;
        await new Promise(r => setTimeout(r, 500));
        const st = await api('ffmpeg-status', { s, e });
        setBar('ffmpeg', st.percent, 100);
        if (st.done) {
            setCell(s, e, st.ok ? 'ok' : 'fail');
            append(`S${s}E${e}: ${st.ok ? 'OK' : 'FAIL'}`);
            return st.ok;
        }
    }
}

async function downloadSeason(s) {
    const count = seasons[s];
    if (!count) return;
    for (let e = 1; e <= count; e++) {
        if (stopped) break;
        await downloadEpisode(s, e);
    }
}

async function downloadSeries() {
    for (const s of Object.keys(seasons)) {
        if (stopped) break;
        await downloadSeason(+s);
    }
}

function kick(fn) {
    if (running) return;
    stopped = false;
    setBusy(true);
    setStatus('running');
    Promise.resolve()
        .then(fn)
        .catch(err => { append('ERROR: ' + err.message); })
        .finally(() => {
            setBusy(false);
            setStatus(stopped ? 'stopped' : 'idle');
        });
}

episodeBtn.onclick = () => {
    const s = +seasonInput.value || 1;
    const e = +epInput.value || 1;
    kick(() => downloadEpisode(s, e));
};
seasonBtn.onclick = () => {
    const s = +seasonInput.value || 1;
    kick(() => downloadSeason(s));
};
seriesBtn.onclick = () => kick(() => downloadSeries());
stopBtn.onclick = () => { stopped = true; setStatus('stopping…'); };

buildGrid().catch(err => append('grid load failed: ' + err.message));
