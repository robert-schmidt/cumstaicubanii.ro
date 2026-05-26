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
