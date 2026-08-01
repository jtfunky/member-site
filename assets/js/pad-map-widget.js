import { initMidi, selectInput, getInputList, onDeviceChange } from './game/midi.js';
import { setNoteMap, getDefaultMap, getLaneNames, getLaneColors } from './game/input.js';

// Standalone pad-mapping widget for placement-test.php — shares the same
// localStorage key as game.php's in-game mapping panel, so a mapping made
// here carries straight into a session without the student leaving this page.
const MIDI_MAP_KEY = 'drumkit_game_midi_map';
let customNoteMap = {};
try { customNoteMap = JSON.parse(localStorage.getItem(MIDI_MAP_KEY) || '{}') || {}; } catch (e) {}
setNoteMap(customNoteMap);

let learnLane = null;
let opened = false;

function escHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function notesForLane(lane) {
  const def = getDefaultMap();
  const notes = [];
  for (const n in def)           { if (def[n] === lane && customNoteMap[n] === undefined) notes.push(+n); }
  for (const n in customNoteMap) { if (customNoteMap[n] === lane) notes.push(+n); }
  return notes.sort((a, b) => a - b);
}

function save() {
  try { localStorage.setItem(MIDI_MAP_KEY, JSON.stringify(customNoteMap)); } catch (e) {}
  setNoteMap(customNoteMap);
}

function buildList() {
  const list = document.getElementById('pt-padmap-list');
  if (!list) return;
  const names  = getLaneNames();
  const colors = getLaneColors();
  list.innerHTML = '';
  names.forEach((name, lane) => {
    const customNotes = Object.keys(customNoteMap).filter(n => customNoteMap[n] === lane).map(Number);
    const learning = learnLane === lane;
    const row = document.createElement('div');
    row.className = 'cal-row' + (learning ? ' cal-listening' : (customNotes.length ? ' cal-done' : ''));
    row.innerHTML = `
      <span class="cal-name" style="color:${colors[lane]}">${escHtml(name)}</span>
      <span class="cal-state${customNotes.length ? ' ok' : ''}">${
        learning ? 'Hit that pad now…'
                 : 'notes ' + notesForLane(lane).join(', ') + (customNotes.length ? ' (custom)' : '')
      }</span>
      <button type="button" class="cal-btn padmap-assign">${learning ? 'Cancel' : 'Assign'}</button>
      ${customNotes.length ? '<button type="button" class="cal-btn cal-btn-clear padmap-clear">Clear</button>' : ''}
    `;
    row.querySelector('.padmap-assign').addEventListener('click', () => {
      learnLane = learning ? null : lane;
      buildList();
    });
    row.querySelector('.padmap-clear')?.addEventListener('click', () => {
      for (const n of customNotes) delete customNoteMap[n];
      save();
      learnLane = null;
      buildList();
    });
    list.appendChild(row);
  });
}

function midiHandler(msg) {
  if (learnLane === null) return;
  customNoteMap[msg.note] = learnLane;
  save();
  learnLane = null;
  buildList();
}

function populateDevices() {
  const sel = document.getElementById('pt-midi-device-select');
  if (!sel) return;
  const list = getInputList();
  sel.innerHTML = list.length
    ? list.map(d => `<option value="${escHtml(d.id)}">${escHtml(d.name)}</option>`).join('')
    : '<option value="">No MIDI devices found</option>';
  if (list.length) {
    sel.value = list[0].id;
    selectInput(list[0].id, midiHandler);
  } else {
    selectInput(null, midiHandler);
  }
}

async function openPanel() {
  const panel = document.getElementById('pt-pad-map');
  if (!panel) return;
  panel.style.display = '';
  if (opened) return;
  opened = true;
  buildList();
  try {
    await initMidi();
    populateDevices();
    onDeviceChange(() => populateDevices());
  } catch (e) { /* MIDI unsupported — select stays "No MIDI detected" */ }
}

document.getElementById('pt-padmap-toggle')?.addEventListener('click', () => {
  const panel = document.getElementById('pt-pad-map');
  if (!panel) return;
  if (panel.style.display === 'none') { openPanel(); } else { panel.style.display = 'none'; }
  learnLane = null;
});

document.getElementById('pt-midi-device-select')?.addEventListener('change', e => {
  selectInput(e.target.value, midiHandler);
});

document.getElementById('pt-padmap-reset')?.addEventListener('click', () => {
  customNoteMap = {};
  try { localStorage.removeItem(MIDI_MAP_KEY); } catch (e) {}
  setNoteMap({});
  learnLane = null;
  buildList();
});

// First-time "map your pads?" prompt on the way to a full-kit test. Only
// interrupts students who've never mapped a pad; once they've mapped one (or
// dismissed the prompt this session) the button behaves like a normal link.
const PROMPT_SEEN_KEY = 'pt_padmap_prompt_seen';
const startFullLink = document.getElementById('pt-start-full');
const promptModal    = document.getElementById('pt-padmap-modal');

function dismissPrompt() {
  try { sessionStorage.setItem(PROMPT_SEEN_KEY, '1'); } catch (e) {}
  if (promptModal) promptModal.style.display = 'none';
}

if (startFullLink && promptModal) {
  startFullLink.addEventListener('click', e => {
    if (Object.keys(customNoteMap).length > 0) return;         // already mapped — let it through
    let seen = false;
    try { seen = !!sessionStorage.getItem(PROMPT_SEEN_KEY); } catch (e) {}
    if (seen) return;
    e.preventDefault();
    promptModal.style.display = '';
  });

  document.getElementById('pt-padmap-modal-map')?.addEventListener('click', () => {
    dismissPrompt();
    openPanel();
    document.getElementById('pt-pad-map')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  document.getElementById('pt-padmap-modal-skip')?.addEventListener('click', () => {
    dismissPrompt();
    window.location.href = startFullLink.href;
  });

  document.getElementById('pt-padmap-modal-close')?.addEventListener('click', () => {
    promptModal.style.display = 'none'; // just closes — doesn't mark as seen, so it can prompt again
  });

  promptModal.addEventListener('click', e => {
    if (e.target === promptModal) promptModal.style.display = 'none';
  });
}
