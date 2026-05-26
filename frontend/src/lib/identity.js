const UUID_KEY = 'datorii:uuid';
const SUBMITTED_KEY = 'datorii:submitted';
const SID_KEY = 'datorii:sid';
const AUTH_EVENT = 'datorii:auth-change';

function emitAuthChange() {
  if (typeof window !== 'undefined') window.dispatchEvent(new Event(AUTH_EVENT));
}

export function onAuthChange(cb) {
  window.addEventListener(AUTH_EVENT, cb);
  return () => window.removeEventListener(AUTH_EVENT, cb);
}

function uuidv4() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

export function getUuid() {
  let u = localStorage.getItem(UUID_KEY);
  if (!u) {
    u = uuidv4();
    localStorage.setItem(UUID_KEY, u);
  }
  return u;
}

export function hasSubmitted() {
  return localStorage.getItem(SUBMITTED_KEY) === '1';
}

export function markSubmitted() {
  localStorage.setItem(SUBMITTED_KEY, '1');
  emitAuthChange();
}

export function resetSubmission() {
  localStorage.removeItem(SUBMITTED_KEY);
  localStorage.removeItem(SID_KEY);
  emitAuthChange();
}

export function getSid() {
  return localStorage.getItem(SID_KEY) || null;
}

export function setSid(sid) {
  if (sid) {
    localStorage.setItem(SID_KEY, sid);
    emitAuthChange();
  }
}

export function clearSid() {
  localStorage.removeItem(SID_KEY);
  emitAuthChange();
}

export function isValidSid(s) {
  return typeof s === 'string' && /^[a-z2-9]{8}$/.test(s);
}
