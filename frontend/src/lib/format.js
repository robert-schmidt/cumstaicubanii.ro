const RON = new Intl.NumberFormat('ro-RO', { style: 'currency', currency: 'RON', maximumFractionDigits: 0 });
const NUM = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });

export function formatRon(n) {
  if (n === null || n === undefined || Number.isNaN(n)) return '—';
  return RON.format(Math.round(n));
}

export function formatNumber(n) {
  if (n === null || n === undefined || Number.isNaN(n)) return '—';
  return NUM.format(Math.round(n));
}

export function parseNumber(s) {
  if (s === null || s === undefined) return 0;
  const cleaned = String(s).replace(/\D/g, '');
  if (!cleaned) return 0;
  const n = Number(cleaned);
  return Number.isFinite(n) ? n : 0;
}

export function formatInput(s) {
  const n = parseNumber(s);
  if (!n) return '';
  return NUM.format(n);
}

// Strip everything that isn't a digit or thousand-separator char (so user can
// keep typing "1.234" without us erasing the dots between keystrokes).
export function stripAmountChars(s) {
  return String(s ?? '').replace(/[^\d.,\s]/g, '');
}

export function stripDigits(s) {
  return String(s ?? '').replace(/\D/g, '');
}
