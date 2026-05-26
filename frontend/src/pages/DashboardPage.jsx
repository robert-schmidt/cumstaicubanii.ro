import { useEffect, useMemo, useState } from 'react';
import { motion } from 'framer-motion';
import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip, Legend } from 'recharts';
import { fetchStats } from '../lib/api.js';
import { formatRon, formatNumber } from '../lib/format.js';

const DEBT_PALETTE = ['#ef4444', '#f97316', '#f59e0b', '#dc2626', '#b91c1c', '#9a3412'];
const ASSET_PALETTE = ['#10b981', '#14b8a6', '#0ea5e9', '#22c55e', '#059669', '#0d9488', '#3b82f6'];

export default function DashboardPage({ uuid, sid }) {
  const [filters, setFilters] = useState({ judet: '', sex: '', ageGroup: '' });
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancel = false;
    setLoading(true);
    fetchStats({ uuid, sid, ...filters })
      .then(d => { if (!cancel) setData(d); })
      .catch(e => { if (!cancel) setError(String(e.message || e)); })
      .finally(() => { if (!cancel) setLoading(false); });
    return () => { cancel = true; };
  }, [uuid, sid, filters.judet, filters.sex, filters.ageGroup]);

  if (loading && !data) return <div className="max-w-6xl mx-auto px-4 py-16 text-center text-slate-500">Se încarcă…</div>;
  if (error) return <div className="max-w-3xl mx-auto px-4 py-16 text-rose-600">{error}</div>;
  if (!data) return null;

  const { user, population, breakdown, by_judet, by_domeniu, by_persoane_intretinere, optimism, meta } = data;

  return (
    <div className="max-w-6xl mx-auto px-4 py-8 sm:py-10 space-y-8">
      <header className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight text-slate-900">Cum stai față de ceilalți</h1>
          <p className="text-slate-600 mt-1">Bazat pe <strong>{formatNumber(population.count)}</strong> submit-uri{filters.judet || filters.sex || filters.ageGroup ? ' (filtrate)' : ''}.</p>
        </div>
        <Filters meta={meta} filters={filters} onChange={setFilters} />
      </header>

      {user && <ShareCard user={user} />}
      {user && <PersonalSummary user={user} />}
      {user && <Comparison user={user} population={population} />}
      {user && <Percentiles user={user} />}

      <section className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {user && user.total_datorii > 0 && (
          <Card title="Distribuția datoriilor tale">
            <PieBlock data={Object.entries(user.by_type_datorii).map(([n, v]) => ({ name: n, value: v }))} palette={DEBT_PALETTE} />
          </Card>
        )}
        {user && user.total_asset > 0 && (
          <Card title="Distribuția asset-urilor tale">
            <PieBlock data={Object.entries(user.by_type_asset).map(([n, v]) => ({ name: n, value: v }))} palette={ASSET_PALETTE} />
          </Card>
        )}
      </section>

      <section className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card title="Datorii pe tipuri — utilizatorul median">
          <TypeBars data={breakdown.datorii} color="#ef4444" userByType={user ? user.by_type_datorii : null} />
        </Card>
        <Card title="Asset-uri pe tipuri — utilizatorul median">
          <TypeBars data={breakdown.asset} color="#10b981" userByType={user ? user.by_type_asset : null} />
        </Card>
      </section>

      <Card title="Net worth mediu pe județ">
        <JudetList rows={by_judet} userJudet={filters.judet || (user && user.judet) || null} />
      </Card>

      <Card title="Net worth mediu pe domeniu ocupațional">
        <DomeniuList rows={by_domeniu} />
      </Card>

      <Card title="Net worth mediu după persoane în întreținere">
        <PersoaneStats rows={by_persoane_intretinere} />
      </Card>

      <section className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card title="Optimiști vs pesimiști — distribuție">
          <OptimismDistribution optimism={optimism} />
        </Card>
        <Card title="Net worth median pe grup">
          <OptimismMedians optimism={optimism} />
        </Card>
      </section>
    </div>
  );
}

function Filters({ meta, filters, onChange }) {
  const active = !!(filters.judet || filters.sex || filters.ageGroup);
  const base = 'shrink-0 rounded-full border bg-white pl-3.5 pr-3 py-1.5 text-sm shadow-sm hover:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-900/15 focus:border-slate-500 transition cursor-pointer';
  const pill = (isSet) => base + ' ' + (isSet ? 'border-slate-900 text-slate-900 bg-slate-50' : 'border-slate-300 text-slate-700');
  return (
    <div className="-mx-4 px-4 sm:mx-0 sm:px-0 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
      <div className="flex items-center gap-2 sm:flex-wrap min-w-min">
        <select className={pill(!!filters.judet)} value={filters.judet} onChange={e => onChange({ ...filters, judet: e.target.value })}>
          <option value="">📍 Toate județele</option>
          {meta.judete.map(j => <option key={j} value={j}>{j}</option>)}
        </select>
        <select className={pill(!!filters.sex)} value={filters.sex} onChange={e => onChange({ ...filters, sex: e.target.value })}>
          <option value="">👤 Orice sex</option>
          <option value="M">Masculin</option>
          <option value="F">Feminin</option>
        </select>
        <select className={pill(!!filters.ageGroup)} value={filters.ageGroup} onChange={e => onChange({ ...filters, ageGroup: e.target.value })}>
          <option value="">🎂 Orice vârstă</option>
          {meta.age_groups.map(g => <option key={g} value={g}>{g} ani</option>)}
        </select>
        {active && (
          <button
            onClick={() => onChange({ judet: '', sex: '', ageGroup: '' })}
            className="shrink-0 text-xs text-slate-500 hover:text-rose-600 px-2 py-1.5 rounded-full hover:bg-rose-50 transition cursor-pointer"
          >
            ✕ resetează
          </button>
        )}
      </div>
    </div>
  );
}

function Card({ title, children, className = '' }) {
  return (
    <motion.section initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.3 }}
      className={'bg-white rounded-2xl border border-slate-200 p-5 sm:p-6 ' + className}>
      {title && <h2 className="font-semibold text-slate-900 mb-4">{title}</h2>}
      {children}
    </motion.section>
  );
}

function PersonalSummary({ user }) {
  const netPositive = user.net_worth >= 0;
  return (
    <motion.section
      initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}
      className="rounded-2xl bg-gradient-to-br from-slate-900 to-slate-700 text-white p-6 sm:p-8 shadow-lg"
    >
      <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
        <div>
          <p className="text-slate-300 text-sm uppercase tracking-wider">Situația ta</p>
          <p className={'mt-1 text-4xl sm:text-5xl font-bold ' + (netPositive ? 'text-emerald-300' : 'text-rose-300')}>
            {formatRon(user.net_worth)}
          </p>
          <p className="text-slate-300 text-sm mt-1">Net worth (asset-uri − datorii)</p>
        </div>
        <span className={'inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium ' +
          (user.optimist ? 'bg-emerald-500/20 text-emerald-200' : 'bg-amber-500/20 text-amber-200')}>
          {user.optimist ? '😊 Optimist' : '😟 Pesimist'}
        </span>
      </div>
      <div className="mt-6 grid grid-cols-2 sm:grid-cols-3 gap-4">
        <Stat label="Total datorii" value={formatRon(user.total_datorii)} accent="text-rose-300" />
        <Stat label="Total asset-uri" value={formatRon(user.total_asset)} accent="text-emerald-300" />
        <Stat
          label="Raport datorii / asset-uri"
          value={user.ratio_datorii_asset !== null ? (user.ratio_datorii_asset * 100).toFixed(0) + '%' : '—'}
          accent="text-slate-100"
        />
      </div>
    </motion.section>
  );
}

function Stat({ label, value, accent = '' }) {
  return (
    <div>
      <p className="text-slate-300 text-xs">{label}</p>
      <p className={'text-xl sm:text-2xl font-semibold ' + accent}>{value}</p>
    </div>
  );
}

function Comparison({ user, population }) {
  // Use medians, not means. Means are heavily skewed by a few high-net-worth
  // outliers in financial data; the median user is the more honest baseline.
  const rows = [
    { label: 'Datorii',   mine: user.total_datorii, avg: population.median_datorii,   color: '#ef4444' },
    { label: 'Asset-uri', mine: user.total_asset,   avg: population.median_asset,     color: '#10b981' },
    { label: 'Net worth', mine: user.net_worth,     avg: population.median_net_worth, color: '#0ea5e9' },
  ];
  return (
    <Card title="Tu vs utilizatorul median">
      <div className="space-y-5">
        {rows.map(r => <CompareBar key={r.label} {...r} />)}
      </div>
      <p className="mt-4 text-xs text-slate-500">
        Folosim mediana (utilizatorul „tipic") în loc de medie. Media e umflată de câteva valori extreme; mediana arată cu cine te compari de fapt.
      </p>
    </Card>
  );
}

function CompareBar({ label, mine, avg, color }) {
  const max = Math.max(Math.abs(mine), Math.abs(avg), 1);
  const minePct = Math.min(100, (Math.abs(mine) / max) * 100);
  const avgPct = Math.min(100, (Math.abs(avg) / max) * 100);
  const diff = mine - avg;
  const better = (label === 'Datorii') ? diff < 0 : diff > 0;
  const position = diff === 0 ? 'la median' : (diff > 0 ? 'peste median' : 'sub median');
  const sign = diff > 0 ? '+' : (diff < 0 ? '−' : '');
  return (
    <div>
      <div className="flex items-baseline justify-between mb-1">
        <span className="font-medium text-slate-700">{label}</span>
        <span className={'text-xs px-2 py-0.5 rounded-full ' + (better ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800')}>
          {sign}{formatRon(Math.abs(diff))} {position}
        </span>
      </div>
      <div className="space-y-1.5">
        <Row label="tu" value={mine} pct={minePct} color={color} bold />
        <Row label="median" value={avg} pct={avgPct} color="#cbd5e1" />
      </div>
    </div>
  );
}

function Row({ label, value, pct, color, bold = false }) {
  // label e fie "tu" fie "median" (cu cele 6 chars o lăsăm la w-14 din mai sus)
  return (
    <div className="flex items-center gap-3">
      <span className="w-14 text-xs text-slate-500">{label}</span>
      <div className="flex-1 h-2.5 rounded-full bg-slate-100 overflow-hidden">
        <motion.div
          initial={{ width: 0 }} animate={{ width: pct + '%' }} transition={{ duration: 0.6, ease: 'easeOut' }}
          className="h-full rounded-full" style={{ background: color }}
        />
      </div>
      <span className={'w-32 text-right text-sm ' + (bold ? 'font-semibold text-slate-900' : 'text-slate-500')}>{formatRon(value)}</span>
    </div>
  );
}

function Percentiles({ user }) {
  return (
    <Card title="Unde te plasezi">
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <PercentileCard label="Net worth" pct={user.percentile_net_worth} betterHigh />
        <PercentileCard label="Asset-uri" pct={user.percentile_asset} betterHigh />
        <PercentileCard label="Datorii" pct={user.percentile_datorii} betterHigh={false} />
      </div>
    </Card>
  );
}

function PercentileCard({ label, pct, betterHigh }) {
  const pctR = Math.round(pct);
  const topR = Math.round(100 - pct);
  const headline = betterHigh
    ? (pct >= 50 ? `Top ${topR}%` : `Sub medie`)
    : (pct <= 50 ? `Sub medie 🎉` : `Top ${topR}% (datorii mari)`);

  // Plain-Romanian comparator under the bar — no statistics jargon.
  // pct = % din populație care au valori mai mici decât tine.
  let compare;
  if (betterHigh) {
    compare = `mai mult decât ${pctR}% dintre cei care au răspuns`;
  } else {
    compare = `mai mult decât ${pctR}% dintre cei care au răspuns (datorii)`;
  }

  return (
    <div className="rounded-xl border border-slate-200 p-4">
      <p className="text-xs uppercase tracking-wider text-slate-500">{label}</p>
      <p className="mt-1 text-2xl font-bold text-slate-900">{headline}</p>
      <div className="mt-3 h-2 rounded-full bg-slate-100 overflow-hidden">
        <motion.div
          initial={{ width: 0 }} animate={{ width: pct + '%' }} transition={{ duration: 0.7 }}
          className={'h-full ' + (betterHigh
            ? (pct >= 75 ? 'bg-emerald-500' : pct >= 50 ? 'bg-emerald-400' : pct >= 25 ? 'bg-amber-400' : 'bg-rose-400')
            : (pct <= 25 ? 'bg-emerald-500' : pct <= 50 ? 'bg-emerald-400' : pct <= 75 ? 'bg-amber-400' : 'bg-rose-400'))} />
      </div>
      <p className="mt-2 text-xs text-slate-500">{compare}</p>
    </div>
  );
}

function PieBlock({ data, palette }) {
  if (!data.length) return <p className="text-sm text-slate-500">Fără date.</p>;
  return (
    <div className="h-72">
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Pie data={data} dataKey="value" nameKey="name" innerRadius={50} outerRadius={90} paddingAngle={2}>
            {data.map((_, i) => <Cell key={i} fill={palette[i % palette.length]} />)}
          </Pie>
          <Tooltip formatter={(v) => formatRon(v)} />
          <Legend />
        </PieChart>
      </ResponsiveContainer>
    </div>
  );
}

function JudetList({ rows, userJudet }) {
  const sorted = useMemo(() => [...rows].sort((a, b) => b.avg_net - a.avg_net), [rows]);
  const max = Math.max(1, ...sorted.map(r => Math.abs(r.avg_net)));
  if (!sorted.length) return <p className="text-sm text-slate-500">Fără date.</p>;
  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
      {sorted.map(r => {
        const pct = (Math.abs(r.avg_net) / max) * 100;
        const isUser = userJudet && r.judet === userJudet;
        return (
          <div key={r.judet} className={'flex items-center gap-3 py-1 ' + (isUser ? 'bg-amber-50 -mx-2 px-2 rounded' : '')}>
            <span className={'w-28 text-sm truncate ' + (isUser ? 'font-semibold text-amber-900' : 'text-slate-700')}>
              {r.judet}{isUser && ' ★'}
            </span>
            <div className="flex-1 h-2 rounded-full bg-slate-100 overflow-hidden">
              <div className={'h-full ' + (r.avg_net >= 0 ? 'bg-emerald-500' : 'bg-rose-500')} style={{ width: pct + '%' }} />
            </div>
            <span className="w-28 text-right text-sm text-slate-600">{formatRon(r.avg_net)}</span>
            <span className="w-10 text-right text-xs text-slate-400">n={r.count}</span>
          </div>
        );
      })}
    </div>
  );
}

function TypeBars({ data, color, userByType }) {
  if (!data || !data.length) return <p className="text-sm text-slate-500">Fără date.</p>;
  // Sort and chart by MEDIAN, not mean. Fall back to avg if median field is missing.
  const m = (r) => (r.median ?? r.avg ?? 0);
  const sorted = [...data].sort((a, b) => m(b) - m(a));
  const max = Math.max(1, ...sorted.map(m));
  return (
    <div className="space-y-2">
      {sorted.map(r => {
        const ref = m(r);
        const pct = (ref / max) * 100;
        const mine = userByType ? userByType[r.type] : null;
        const minePct = mine ? Math.min(100, (mine / max) * 100) : 0;
        return (
          <div key={r.type}>
            <div className="flex items-baseline justify-between text-sm mb-1">
              <span className="text-slate-700">{r.type}</span>
              <span className="text-slate-500">
                median <span className="font-medium text-slate-800">{formatRon(ref)}</span>
                <span className="ml-2 text-xs text-slate-400">n={r.count}</span>
              </span>
            </div>
            <div className="relative h-2.5 rounded-full bg-slate-100 overflow-hidden">
              <motion.div
                initial={{ width: 0 }} animate={{ width: pct + '%' }} transition={{ duration: 0.5 }}
                className="h-full rounded-full opacity-60" style={{ background: color }}
              />
              {mine ? (
                <motion.div
                  initial={{ width: 0 }} animate={{ width: minePct + '%' }} transition={{ duration: 0.6 }}
                  className="absolute inset-y-0 left-0 h-full rounded-full"
                  style={{ background: color, boxShadow: '0 0 0 1px white' }}
                  title={'tu: ' + formatRon(mine)}
                />
              ) : null}
            </div>
            {mine ? (
              <div className="text-xs text-slate-500 mt-0.5">
                tu: <span className="font-medium text-slate-800">{formatRon(mine)}</span>
              </div>
            ) : null}
          </div>
        );
      })}
    </div>
  );
}

function DomeniuList({ rows }) {
  if (!rows || !rows.length) return <p className="text-sm text-slate-500">Fără date.</p>;
  const max = Math.max(1, ...rows.map(r => Math.abs(r.avg_net)));
  return (
    <div className="space-y-1.5">
      {rows.map(r => {
        const pct = (Math.abs(r.avg_net) / max) * 100;
        return (
          <div key={r.domeniu} className="flex items-center gap-3 py-1">
            <span className="w-40 text-sm text-slate-700 truncate" title={r.domeniu}>{r.domeniu}</span>
            <div className="flex-1 h-2 rounded-full bg-slate-100 overflow-hidden">
              <div className={'h-full ' + (r.avg_net >= 0 ? 'bg-emerald-500' : 'bg-rose-500')} style={{ width: pct + '%' }} />
            </div>
            <span className="w-28 text-right text-sm text-slate-600">{formatRon(r.avg_net)}</span>
            <span className="w-10 text-right text-xs text-slate-400">n={r.count}</span>
          </div>
        );
      })}
    </div>
  );
}

function useIsTouchViewport() {
  const [isTouch, setIsTouch] = useState(() => {
    if (typeof window === 'undefined') return false;
    return window.matchMedia('(max-width: 1023.98px)').matches;
  });
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const mq = window.matchMedia('(max-width: 1023.98px)');
    const handler = (e) => setIsTouch(e.matches);
    mq.addEventListener('change', handler);
    return () => mq.removeEventListener('change', handler);
  }, []);
  return isTouch;
}

function ShareCard({ user }) {
  const [toast, setToast] = useState(null);
  const isTouchViewport = useIsTouchViewport();
  // Web Share API exists on Chrome desktop too, but UX-wise we only want it on mobile/tablet
  // (touch viewport). On desktop, prefer explicit platform buttons.
  const canNativeShare = isTouchViewport && typeof navigator !== 'undefined' && typeof navigator.share === 'function';
  const tD = Math.round(100 - user.percentile_datorii);
  const tA = Math.round(100 - user.percentile_asset);
  const tN = Math.round(100 - user.percentile_net_worth);
  const origin = typeof window !== 'undefined' ? window.location.origin : '';
  // Personalized share URL — backend renders OG meta tags from these params so
  // FB / LinkedIn previews actually show the user's stats.
  const shareUrl = `${origin}/share?d=${tD}&v=${tA}&n=${tN}`;
  const message = `💰 Mă situez în top ${tD}% ca datorii, top ${tA}% ca asset-uri, top ${tN}% ca net worth. Tu cum stai cu banii? Află aici →`;
  const full = `${message} ${shareUrl}`;

  const enc = encodeURIComponent;
  const fbUrl = `https://www.facebook.com/sharer/sharer.php?u=${enc(shareUrl)}`;
  const twUrl = `https://twitter.com/intent/tweet?text=${enc(message)}&url=${enc(shareUrl)}`;
  const liUrl = `https://www.linkedin.com/sharing/share-offsite/?url=${enc(shareUrl)}`;
  const waUrl = `https://api.whatsapp.com/send?text=${enc(full)}`;

  function flash(msg) {
    setToast(msg);
    setTimeout(() => setToast(null), 3500);
  }

  async function copyText() {
    try {
      await navigator.clipboard.writeText(full);
      return true;
    } catch { return false; }
  }

  async function nativeShare() {
    try {
      await navigator.share({ title: 'Datorii vs Asset-uri', text: message, url: shareUrl });
      return true;
    } catch (e) {
      // User cancel = AbortError, ignore
      return false;
    }
  }

  // For FB/LinkedIn: prefer Web Share API (the only way text actually gets pre-filled).
  // Fall back to copy-to-clipboard + open the platform's URL-only sharer.
  async function shareToFacebook() {
    if (canNativeShare) { await nativeShare(); return; }
    const ok = await copyText();
    if (ok) flash('Textul e copiat — Facebook nu permite prefill din browser, lipește-l în fereastra de share.');
    window.open(fbUrl, '_blank', 'noopener,noreferrer');
  }
  async function shareToLinkedIn() {
    if (canNativeShare) { await nativeShare(); return; }
    const ok = await copyText();
    if (ok) flash('Textul e copiat — LinkedIn nu permite prefill din browser, lipește-l în fereastra de share.');
    window.open(liUrl, '_blank', 'noopener,noreferrer');
  }

  async function justCopy() {
    const ok = await copyText();
    flash(ok ? '✓ Copiat în clipboard' : 'Nu am putut copia. Selectează manual.');
  }

  return (
    <motion.section
      initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.3 }}
      className="rounded-2xl bg-gradient-to-r from-sky-50 via-white to-emerald-50 border border-slate-200 p-5 sm:p-6"
    >
      <div className="flex flex-col lg:flex-row gap-5 lg:items-center">
        <div className="flex-1 min-w-0">
          <p className="text-xs uppercase tracking-wider text-slate-500">Share-uiește rezultatele</p>
          <p className="mt-1.5 text-slate-800 italic leading-relaxed">"{message}"</p>
        </div>
        <div className="flex flex-wrap gap-2 shrink-0">
          {canNativeShare && (
            <ShareBtnButton onClick={nativeShare} label="Share" bg="bg-slate-900" hover="hover:bg-slate-800">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
            </ShareBtnButton>
          )}
          <ShareBtnButton onClick={shareToFacebook} label="Facebook" bg="bg-[#1877F2]" hover="hover:bg-[#0e63d4]">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.79c0-2.5 1.5-3.89 3.78-3.89 1.1 0 2.24.2 2.24.2v2.47h-1.27c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12Z"/></svg>
          </ShareBtnButton>
          <ShareBtnLink href={twUrl} label="X / Twitter" bg="bg-slate-900" hover="hover:bg-slate-800">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2H21.5l-7.5 8.57L23 22h-6.99l-5.21-6.79L4.8 22H1.54l8.04-9.18L1 2h7.13l4.7 6.21L18.24 2Zm-1.22 18h1.82L7.06 4H5.14l11.88 16Z"/></svg>
          </ShareBtnLink>
          <ShareBtnButton onClick={shareToLinkedIn} label="LinkedIn" bg="bg-[#0A66C2]" hover="hover:bg-[#08539e]">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M20.45 20.45h-3.55v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.13 1.45-2.13 2.94v5.67H9.36V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13ZM7.12 20.45H3.56V9h3.56v11.45ZM22.22 0H1.77C.79 0 0 .77 0 1.72v20.55C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.72C24 .77 23.2 0 22.22 0Z"/></svg>
          </ShareBtnButton>
          <ShareBtnLink href={waUrl} label="WhatsApp" bg="bg-[#25D366]" hover="hover:bg-[#1eb558]">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.5 14.4c-.3-.15-1.77-.87-2.05-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51l-.57-.01c-.2 0-.52.07-.79.37-.27.3-1.04 1.01-1.04 2.47s1.07 2.87 1.22 3.07c.15.2 2.1 3.2 5.1 4.49.71.31 1.27.49 1.7.63.71.23 1.36.2 1.87.12.57-.08 1.77-.72 2.02-1.42.25-.7.25-1.3.18-1.42-.07-.13-.27-.2-.57-.35ZM12 21.32c-1.69 0-3.34-.45-4.78-1.31l-.34-.2-3.55.93.95-3.46-.22-.36A9.34 9.34 0 1 1 21.34 12 9.35 9.35 0 0 1 12 21.32Zm7.95-17.27A11.27 11.27 0 0 0 12 .72C5.78.72.72 5.78.72 12c0 1.99.52 3.93 1.52 5.65L.6 23.28l5.78-1.52a11.26 11.26 0 0 0 5.62 1.43h.01c6.22 0 11.27-5.06 11.27-11.28 0-3.02-1.17-5.85-3.31-7.97Z"/></svg>
          </ShareBtnLink>
          <button onClick={justCopy} className="inline-flex items-center gap-1.5 px-3 py-2 rounded-full text-sm font-medium text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 transition">
            ⧉ Copiază
          </button>
        </div>
      </div>
      {toast && (
        <motion.div
          initial={{ opacity: 0, y: -4 }} animate={{ opacity: 1, y: 0 }}
          className="mt-3 text-xs rounded-lg bg-slate-900 text-slate-100 px-3 py-2"
        >
          {toast}
        </motion.div>
      )}
    </motion.section>
  );
}

const shareBtnBase = 'inline-flex items-center gap-1.5 px-3 py-2 rounded-full text-white text-sm font-medium transition';

function ShareBtnLink({ href, label, bg, hover, children }) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" aria-label={label} title={label} className={`${shareBtnBase} ${bg} ${hover}`}>
      {children}<span className="hidden sm:inline">{label}</span>
    </a>
  );
}

function ShareBtnButton({ onClick, label, bg, hover, children }) {
  return (
    <button type="button" onClick={onClick} aria-label={label} title={label} className={`${shareBtnBase} ${bg} ${hover}`}>
      {children}<span className="hidden sm:inline">{label}</span>
    </button>
  );
}

function PersoaneStats({ rows }) {
  if (!rows || !rows.length) return <p className="text-sm text-slate-500">Fără date.</p>;
  const max = Math.max(1, ...rows.map(r => Math.abs(r.avg_net)));
  return (
    <div className="space-y-3">
      {rows.map(r => {
        const pct = (Math.abs(r.avg_net) / max) * 100;
        const negative = r.avg_net < 0;
        return (
          <div key={r.bucket}>
            <div className="flex items-baseline justify-between text-sm mb-1">
              <span className="font-medium text-slate-800">
                {r.bucket === '0' ? 'Fără persoane în întreținere'
                  : r.bucket === '1' ? '1 persoană'
                  : r.bucket === '4+' ? '4 sau mai multe persoane'
                  : `${r.bucket} persoane`}
              </span>
              <span>
                <span className={'font-semibold ' + (negative ? 'text-rose-600' : 'text-emerald-600')}>{formatRon(r.avg_net)}</span>
                <span className="ml-2 text-xs text-slate-400">n={r.count}</span>
              </span>
            </div>
            <div className="h-2.5 rounded-full bg-slate-100 overflow-hidden">
              <motion.div
                initial={{ width: 0 }} animate={{ width: pct + '%' }} transition={{ duration: 0.6 }}
                className={'h-full rounded-full ' + (negative ? 'bg-rose-500' : 'bg-emerald-500')}
              />
            </div>
          </div>
        );
      })}
      <p className="text-xs text-slate-500 pt-1">
        Doar utilizatorii care au completat secțiunea demografică. Bară roșie = net worth negativ (mai multe datorii decât asset-uri).
      </p>
    </div>
  );
}

function OptimismDistribution({ optimism }) {
  const o = optimism.optimist.count;
  const p = optimism.pesimist.count;
  const total = o + p;
  if (total === 0) return <p className="text-sm text-slate-500">Fără date.</p>;
  const oPct = Math.round((o / total) * 100);
  const pPct = 100 - oPct;
  const data = [
    { name: 'Optimiști', value: o, fill: '#10b981' },
    { name: 'Pesimiști', value: p, fill: '#f59e0b' },
  ];
  return (
    <div className="flex flex-col sm:flex-row items-center gap-6">
      <div className="w-44 h-44 shrink-0">
        <ResponsiveContainer width="100%" height="100%">
          <PieChart>
            <Pie data={data} dataKey="value" nameKey="name" innerRadius={46} outerRadius={78} paddingAngle={2}>
              {data.map((d, i) => <Cell key={i} fill={d.fill} />)}
            </Pie>
            <Tooltip formatter={(v, n) => [`${v} oameni`, n]} />
          </PieChart>
        </ResponsiveContainer>
      </div>
      <div className="flex-1 w-full space-y-4">
        <DistRow color="bg-emerald-500" label="😊 Optimiști" count={o} pct={oPct} accent="text-emerald-600" />
        <DistRow color="bg-amber-500"   label="😟 Pesimiști" count={p} pct={pPct} accent="text-amber-600" />
        <p className="text-xs text-slate-500 pt-2 border-t border-slate-100">
          Total: <strong className="text-slate-700">{total}</strong> răspunsuri.
        </p>
      </div>
    </div>
  );
}

function DistRow({ color, label, count, pct, accent }) {
  return (
    <div>
      <div className="flex items-baseline gap-2">
        <span className={'inline-block w-2.5 h-2.5 rounded-full ' + color}></span>
        <span className="font-medium text-slate-800">{label}</span>
        <span className={'ml-auto text-2xl font-bold ' + accent}>{pct}%</span>
        <span className="text-sm text-slate-500 w-12 text-right">({count})</span>
      </div>
      <div className="mt-1 h-1.5 rounded-full bg-slate-100 overflow-hidden">
        <motion.div initial={{ width: 0 }} animate={{ width: pct + '%' }} transition={{ duration: 0.5 }} className={'h-full ' + color} />
      </div>
    </div>
  );
}

function OptimismMedians({ optimism }) {
  const rows = [
    { label: '😊 Optimiști', median: optimism.optimist.median_net, count: optimism.optimist.count, color: '#10b981' },
    { label: '😟 Pesimiști', median: optimism.pesimist.median_net, count: optimism.pesimist.count, color: '#f59e0b' },
  ];
  if (rows.every(r => r.count === 0)) return <p className="text-sm text-slate-500">Fără date.</p>;
  const max = Math.max(1, ...rows.map(r => Math.abs(r.median)));
  const diff = rows[0].median - rows[1].median;
  return (
    <div className="space-y-5">
      {rows.map(r => {
        const pct = Math.min(100, (Math.abs(r.median) / max) * 100);
        return (
          <div key={r.label}>
            <div className="flex items-baseline justify-between mb-1.5">
              <span className="font-medium text-slate-800">{r.label}</span>
              <span className="text-sm">
                <span className="font-semibold text-slate-900">{formatRon(r.median)}</span>
                <span className="ml-2 text-xs text-slate-400">n={r.count}</span>
              </span>
            </div>
            <div className="h-3 rounded-full bg-slate-100 overflow-hidden">
              <motion.div
                initial={{ width: 0 }} animate={{ width: pct + '%' }} transition={{ duration: 0.6 }}
                className="h-full rounded-full" style={{ background: r.color }}
              />
            </div>
          </div>
        );
      })}
      <p className="text-xs text-slate-500 pt-1">
        Folosim <strong>mediana</strong> (nu media), mai puțin influențată de valori extreme.
        {diff !== 0 && rows.every(r => r.count > 0) && (
          <> Diferență: <strong className={diff > 0 ? 'text-emerald-600' : 'text-amber-600'}>
            {diff > 0 ? 'optimiștii au cu ' : 'pesimiștii au cu '}{formatRon(Math.abs(diff))}{' '}
            {diff > 0 ? 'mai mult decât pesimiștii' : 'mai mult decât optimiștii'}
          </strong>.</>
        )}
      </p>
    </div>
  );
}
