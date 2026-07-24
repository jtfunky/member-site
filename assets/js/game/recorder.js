// Gameplay recording — canvas video + game audio (backing track + hit/
// metronome SFX), saved straight to the student's computer. No server
// involvement at all; everything happens in the browser via MediaRecorder.
//
// True .mp4 output only works where the browser's MediaRecorder actually
// supports it (Safari). Elsewhere (Chrome, Firefox, most Android browsers)
// it records .webm instead — there's no way to force real .mp4 encoding from
// client-side JS without a heavy wasm transcoder, so we're honest about the
// extension we actually produce rather than mislabeling a .webm as .mp4.
import { getRecordDestination } from './audio-context.js?v=20260720c';

let mediaRecorder = null;
let chunks        = [];
let recording     = false;

function pickMimeType() {
  if (typeof MediaRecorder === 'undefined' || !MediaRecorder.isTypeSupported) return '';
  const candidates = [
    'video/mp4;codecs=avc1,mp4a.40.2',   // Safari
    'video/mp4',
    'video/webm;codecs=vp9,opus',
    'video/webm;codecs=vp8,opus',
    'video/webm',
  ];
  return candidates.find(t => MediaRecorder.isTypeSupported(t)) || '';
}

export function isRecordingSupported() {
  return typeof MediaRecorder !== 'undefined' && typeof HTMLCanvasElement.prototype.captureStream === 'function';
}

export function isRecording() {
  return recording;
}

export function startRecording(canvas) {
  if (recording || !canvas || !isRecordingSupported()) return false;

  let videoTracks, audioTracks;
  try {
    videoTracks = canvas.captureStream(30).getVideoTracks();
    audioTracks = getRecordDestination().stream.getAudioTracks();
  } catch (e) {
    console.warn('DrumKit: recording unsupported:', e.message);
    return false;
  }
  const combined = new MediaStream([...videoTracks, ...audioTracks]);

  const mimeType = pickMimeType();
  try {
    mediaRecorder = new MediaRecorder(combined, mimeType ? { mimeType } : undefined);
  } catch (e) {
    console.warn('DrumKit: recording unsupported:', e.message);
    mediaRecorder = null;
    return false;
  }

  chunks = [];
  mediaRecorder.ondataavailable = e => { if (e.data.size) chunks.push(e.data); };
  mediaRecorder.start();
  recording = true;
  console.log('[startRecording] started OK'); // TEMPORARY
  return true;
}

// Stops recording and triggers a save-to-computer download. Resolves with
// the file extension actually used ('mp4' or 'webm'), or false if nothing
// was recording.
export function stopRecording(filenameBase = 'groove-quest-play') {
  return new Promise(resolve => {
    if (!mediaRecorder || !recording) { resolve(false); return; }

    mediaRecorder.onstop = () => {
      recording   = false;
      const type  = mediaRecorder.mimeType || 'video/webm';
      const ext   = type.includes('mp4') ? 'mp4' : 'webm';
      const blob  = new Blob(chunks, { type });
      const url   = URL.createObjectURL(blob);
      const a     = document.createElement('a');
      a.href      = url;
      a.download  = `${filenameBase}.${ext}`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 4000);
      mediaRecorder = null;
      chunks = [];
      resolve(ext);
    };
    mediaRecorder.stop();
  });
}
