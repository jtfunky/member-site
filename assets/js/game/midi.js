let midiAccess = null;
let activePort = null;
let onMessageCallback = null;

export async function initMidi() {
  if (!navigator.requestMIDIAccess) {
    return { supported: false, inputs: [] };
  }
  try {
    midiAccess = await navigator.requestMIDIAccess({ sysex: false });
    return { supported: true, inputs: getInputList() };
  } catch (e) {
    return { supported: false, inputs: [], error: e.message };
  }
}

export function getInputList() {
  if (!midiAccess) return [];
  const list = [];
  for (const [id, port] of midiAccess.inputs) {
    list.push({ id, name: port.name });
  }
  return list;
}

export function selectInput(portId, callback) {
  if (activePort) {
    activePort.onmidimessage = null;
    activePort = null;
  }
  onMessageCallback = callback;
  if (!portId || !midiAccess) return;

  activePort = midiAccess.inputs.get(portId);
  if (!activePort) return;

  activePort.onmidimessage = (event) => {
    const [status, note, velocity] = event.data;
    const type    = status & 0xF0;
    const channel = status & 0x0F;
    // Note On with velocity > 0
    if (type === 0x90 && velocity > 0) {
      onMessageCallback({ note, velocity, timestamp: event.timeStamp, channel });
    }
  };
}

export function onDeviceChange(callback) {
  if (!midiAccess) return;
  midiAccess.onstatechange = () => callback(getInputList());
}
