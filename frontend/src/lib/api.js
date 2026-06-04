const BASE = '/api';

export async function fetchMeta() {
  const r = await fetch(`${BASE}/meta.php`);
  if (!r.ok) throw new Error('meta failed');
  return r.json();
}

export async function fetchStats({ uuid, sid, judet, sex, ageGroup } = {}) {
  const p = new URLSearchParams();
  if (sid) p.set('sid', sid);
  else if (uuid) p.set('uuid', uuid);
  if (judet) p.set('judet', judet);
  if (sex) p.set('sex', sex);
  if (ageGroup) p.set('age_group', ageGroup);
  const r = await fetch(`${BASE}/stats.php?${p.toString()}`);
  if (!r.ok) throw new Error('stats failed');
  return r.json();
}

export async function submitForm(payload) {
  const r = await fetch(`${BASE}/submit.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const j = await r.json();
  if (!r.ok) throw new Error(j.error || 'submit failed');
  return j;
}

export async function fetchFeedback({ uuid, kind, type, judet, status, sort, offset } = {}) {
  const p = new URLSearchParams();
  if (uuid) p.set('uuid', uuid);
  if (kind) p.set('kind', kind);
  if (type) p.set('type', type);
  if (judet) p.set('judet', judet);
  if (status !== '' && status !== undefined && status !== null) p.set('status', String(status));
  if (sort) p.set('sort', sort);
  if (offset) p.set('offset', String(offset));
  const r = await fetch(`${BASE}/feedback.php?${p.toString()}`);
  if (!r.ok) throw new Error('feedback list failed');
  return r.json();
}

export async function sendContact({ name, email, message, website }) {
  const r = await fetch(`${BASE}/contact.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, email, message, website }),
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) {
    const err = new Error(j.error || 'Trimiterea a eșuat.');
    err.fields = j.fields;
    throw err;
  }
  return j;
}

export async function postFeedbackVote({ uuid, submission_id, vote }) {
  const r = await fetch(`${BASE}/feedback.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ uuid, submission_id, vote }),
  });
  const j = await r.json();
  if (!r.ok) {
    const err = new Error(j.error || 'vote failed');
    err.cooldown_hours = j.cooldown_hours;
    throw err;
  }
  return j;
}
