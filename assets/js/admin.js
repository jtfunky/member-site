/* Admin song management */

// CSRF token injected by PHP into the page
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const form       = document.getElementById('song-form');
const saveBtn    = document.getElementById('dk-save-btn');
const statusEl   = document.getElementById('dk-status');
const editorTitle = document.getElementById('editor-title');

// Canonical 10-lane engine map (mirrors input.js getLaneNames + song-save.php).
const LANE_NAMES = ['Kick', 'Snare', 'Hi-Hat', 'Hi Tom 1', 'Hi Tom 2', 'Mid Tom', 'Floor Tom', '16" Crash', '18" Crash', '22" Ride'];
const LANE_COUNT = LANE_NAMES.length;

// Parse the notes textarea. Returns { notes, problems } where problems lists
// human-readable issues (same rules the server enforces in song-save.php).
function parseNotes(raw) {
  let arr;
  try {
    arr = JSON.parse((raw || '').trim() || '[]');
  } catch { return { notes: null, problems: ['Not valid JSON.'] }; }
  if (!Array.isArray(arr)) return { notes: null, problems: ['Notes must be a JSON array.'] };

  const problems = [];
  arr.forEach((n, i) => {
    if (!n || typeof n !== 'object' || !Number.isFinite(+n.time) || !Number.isFinite(+n.lane)) {
      problems.push(`#${i} malformed`);
      return;
    }
    const lane = Math.trunc(+n.lane);
    if (+n.time < 0 || lane < 0 || lane >= LANE_COUNT) problems.push(`#${i} out of range (lane ${n.lane})`);
  });
  return { notes: arr, problems };
}

function setStatus(msg, type = 'info') {
  statusEl.textContent = msg;
  statusEl.className = 'status-msg status-' + type;
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();

  const id       = document.getElementById('dk-edit-id').value;
  const title    = document.getElementById('dk-title').value.trim();
  const artist   = document.getElementById('dk-artist').value.trim();
  const bpm      = document.getElementById('dk-bpm').value;
  const duration = document.getElementById('dk-duration').value;
  const notesRaw = document.getElementById('dk-notes').value.trim();
  const audioFile = document.getElementById('dk-audio-file').files[0];

  if (!title) { setStatus('Title is required.', 'error'); return; }

  const { notes: notesArr, problems } = parseNotes(notesRaw);
  if (!notesArr) { setStatus(problems[0], 'error'); return; }
  if (problems.length) {
    setStatus(`Fix ${problems.length} invalid note${problems.length === 1 ? '' : 's'}: ${problems.slice(0, 4).join(', ')}${problems.length > 4 ? '…' : ''}`, 'error');
    return;
  }

  setStatus('Saving…', 'info');
  saveBtn.disabled = true;

  const fd = new FormData();
  if (id) fd.append('id', id);
  fd.append('title',    title);
  fd.append('artist',   artist);
  fd.append('category', document.getElementById('dk-category')?.value || 'kit');
  fd.append('bpm',      bpm);
  fd.append('duration', duration);
  fd.append('notes',    JSON.stringify(notesArr));
  if (audioFile) fd.append('audio', audioFile);

  fd.append('csrf_token', CSRF_TOKEN);

  try {
    const res  = await fetch('/api/song-save.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (!res.ok) {
      setStatus('Error: ' + (data.error || res.status), 'error');
      saveBtn.disabled = false;
      return;
    }

    document.getElementById('dk-edit-id').value = data.id;
    editorTitle.textContent = 'Edit Song';
    setStatus('Song saved (ID ' + data.id + ')!', 'success');

    // Reload page to refresh table
    setTimeout(() => window.location.href = '/admin/songs.php?edit=' + data.id, 800);
  } catch (err) {
    setStatus('Network error: ' + err.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
});

// ── Auto-generate notes when audio file is selected ──────────────────────────
const audioFileInput = document.getElementById('dk-audio-file');
const notesTextarea  = document.getElementById('dk-notes');
const analyzeStatus  = document.createElement('p');
analyzeStatus.className = 'field-hint';
notesTextarea.parentNode.insertBefore(analyzeStatus, notesTextarea.nextSibling);

// Live note summary: total + per-lane breakdown, updated as the JSON is edited.
const noteSummary = document.createElement('p');
noteSummary.className = 'field-hint';
noteSummary.style.marginTop = '.25rem';
notesTextarea.parentNode.insertBefore(noteSummary, analyzeStatus.nextSibling);

function summarizeNotes() {
  const { notes, problems } = parseNotes(notesTextarea.value);
  if (!notes) { noteSummary.style.color = '#b91c1c'; noteSummary.textContent = '⚠️ ' + problems[0]; return; }

  const counts = new Array(LANE_COUNT).fill(0);
  notes.forEach(n => {
    const lane = Math.trunc(+n?.lane);
    if (lane >= 0 && lane < LANE_COUNT) counts[lane]++;
  });
  const used = counts.map((c, l) => c ? `${LANE_NAMES[l]}: ${c}` : null).filter(Boolean);
  let text = `${notes.length} note${notes.length === 1 ? '' : 's'}` + (used.length ? ' · ' + used.join(' · ') : '');
  if (problems.length) {
    text += ` — ⚠️ ${problems.length} invalid: ${problems.slice(0, 5).join(', ')}${problems.length > 5 ? '…' : ''}`;
    noteSummary.style.color = '#b91c1c';
  } else {
    noteSummary.style.color = '';
  }
  noteSummary.textContent = text;
}

notesTextarea.addEventListener('input', summarizeNotes);
summarizeNotes(); // reflect any notes present on page load (e.g. when editing)

// When an audio file is picked we do NOT auto-detect notes anymore — charting
// the drum hits is done in the Chart Editor. Here we just read the exact
// duration (and title) so the stored metadata is correct.
audioFileInput.addEventListener('change', async () => {
  const file = audioFileInput.files[0];
  if (!file) return;

  if (!document.getElementById('dk-title').value) {
    document.getElementById('dk-title').value = file.name.replace(/\.[^.]+$/, '');
  }

  analyzeStatus.textContent = '🎵 Reading audio length…';
  try {
    const arrayBuf = await file.arrayBuffer();
    const audioCtx = new AudioContext();
    const audioBuf = await audioCtx.decodeAudioData(arrayBuf);
    await audioCtx.close();
    // Decoded-buffer duration is exact & finite (unlike <audio>.duration, which
    // is Infinity for VBR MP3s / blob URLs and would desync the game timeline).
    document.getElementById('dk-duration').value = Math.round(audioBuf.duration * 1000);
    analyzeStatus.textContent = `✅ Duration ${audioBuf.duration.toFixed(1)}s stored. Build the drum hits in the Chart Editor.`;
  } catch (err) {
    analyzeStatus.textContent = '⚠️ Could not read audio: ' + err.message;
  }
});

// Delete buttons
document.querySelectorAll('.delete-song-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const id    = btn.dataset.id;
    const title = btn.dataset.title;
    if (!confirm(`Delete "${title}"? This cannot be undone.`)) return;

    const form = document.getElementById('delete-form');
    document.getElementById('delete-song-id').value = id;
    form.submit();
  });
});
