// ── YouTube casual play-along ────────────────────────────────────────────────
// Thin wrapper around the OFFICIAL YouTube IFrame Player API. We embed YouTube's
// own player (sanctioned) and read its live playback clock so the game can follow
// it. We never download or extract audio. Sync is looser than local/hosted audio
// (coarse clock, buffering, ads), so this is a "casual" mode only.

let apiReady    = null;   // Promise resolved when window.YT is available
let readyPlayer = null;   // Promise resolved with the created player
let player      = null;
let lastError   = null;

// Pull the 11-char video id out of any common YouTube URL form (or a bare id).
export function parseYouTubeId(url) {
  if (!url) return null;
  const m = String(url).match(
    /(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/
  );
  if (m) return m[1];
  return /^[A-Za-z0-9_-]{11}$/.test(url) ? url : null;
}

function loadApi() {
  if (apiReady) return apiReady;
  apiReady = new Promise(resolve => {
    if (window.YT && window.YT.Player) { resolve(); return; }
    // The API calls this global once www-widgetapi.js has loaded.
    const prev = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = () => { if (typeof prev === 'function') prev(); resolve(); };
    const s = document.createElement('script');
    s.src = 'https://www.youtube.com/iframe_api';
    document.head.appendChild(s);
  });
  return apiReady;
}

// Create the player once, bound to the #yt-player element (must exist in the DOM).
function ensurePlayer() {
  if (readyPlayer) return readyPlayer;
  readyPlayer = loadApi().then(() => new Promise(resolve => {
    player = new YT.Player('yt-player', {
      host: 'https://www.youtube-nocookie.com',
      width: '100%', height: '100%',
      playerVars: {
        autoplay: 0, controls: 0, disablekb: 1, fs: 0,
        modestbranding: 1, rel: 0, playsinline: 1, iv_load_policy: 3,
      },
      events: {
        onReady: () => resolve(player),
        onError: e => { lastError = e.data; },
      },
    });
  }));
  return readyPlayer;
}

// Load + play a video. Resolves once REAL content is playing — i.e. the content
// clock has been advancing for ~250ms, which means any pre-roll ad has finished.
// Rejects on player error or a 30s timeout.
export async function play(videoId) {
  const p = await ensurePlayer();
  lastError = null;
  p.loadVideoById(videoId);
  p.playVideo();

  return new Promise((resolve, reject) => {
    const t0 = performance.now();
    let lastT = -1, stableSince = 0;
    const poll = setInterval(() => {
      if (lastError !== null) { clearInterval(poll); reject(new Error('YouTube error ' + lastError)); return; }
      const t     = p.getCurrentTime ? p.getCurrentTime() : 0;
      const state = p.getPlayerState ? p.getPlayerState() : -1;
      if (state === YT.PlayerState.PLAYING && t > 0.1 && t > lastT) {
        if (!stableSince) stableSince = performance.now();
        if (performance.now() - stableSince > 250) { clearInterval(poll); resolve(p); return; }
      } else {
        stableSince = 0;   // stalled (ad / buffering) → keep waiting
      }
      lastT = t;
      if (performance.now() - t0 > 30000) { clearInterval(poll); reject(new Error('YouTube start timed out')); }
    }, 80);
  });
}

export function getTimeMs()     { return player && player.getCurrentTime ? player.getCurrentTime() * 1000 : 0; }
export function getDurationMs() { return player && player.getDuration    ? player.getDuration()    * 1000 : 0; }
export function getState()      { return player && player.getPlayerState ? player.getPlayerState() : -1; }
export function isPlaying()     { return !!(window.YT && getState() === YT.PlayerState.PLAYING); }
export function isEnded()       { return !!(window.YT && getState() === YT.PlayerState.ENDED); }
// Practice speeds (1 / 0.75 / 0.5) — supported natively by the player.
export function setRate(r) { try { if (player && player.setPlaybackRate) player.setPlaybackRate(r); } catch (e) {} }
// The embedded YouTube player has its own separate audio pipeline (an
// iframe, not decoded through our AudioContext), so the master gain node in
// audio-context.js can't reach it — this is the only way the volume slider
// can affect a YouTube-backed song. pct: 0-100.
export function setVolume(pct) { try { if (player && player.setVolume) player.setVolume(Math.max(0, Math.min(100, pct))); } catch (e) {} }
// Practice-mode scrubbing — standard IFrame API method, well-supported.
// `true` = allowSeekAhead, so it seeks even into not-yet-buffered parts.
export function seekTo(seconds) { try { if (player && player.seekTo) player.seekTo(seconds, true); } catch (e) {} }
export function pause()  { try { if (player) player.pauseVideo(); } catch (e) {} }
export function resume() { try { if (player) player.playVideo(); }  catch (e) {} }
export function stop()   { try { if (player) player.stopVideo(); }  catch (e) {} }
