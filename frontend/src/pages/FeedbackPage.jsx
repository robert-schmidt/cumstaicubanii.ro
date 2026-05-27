import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { fetchFeedback, fetchMeta, postFeedbackVote } from '../lib/api.js';
import { getUuid } from '../lib/identity.js';
import { formatRon, formatNumber } from '../lib/format.js';

const SORTS = [
  { v: 'recent',         label: 'Cele mai recente' },
  { v: 'most_flagged',   label: 'Cele mai semnalate' },
  { v: 'most_validated', label: 'Cele mai validate' },
];

const STATUSES = [
  { v: '',  label: 'Orice status' },
  { v: '1', label: 'Aprobate' },
  { v: '0', label: 'Flagged' },
];

export default function FeedbackPage() {
  const uuid = useMemo(() => getUuid(), []);
  const [meta, setMeta] = useState(null);
  const [filters, setFilters] = useState({ kind: '', type: '', status: '', sort: 'recent' });
  const [submissions, setSubmissions] = useState([]);
  const [myVotes, setMyVotes] = useState({});
  const [nextOffset, setNextOffset] = useState(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState(null);
  const [toast, setToast] = useState(null);
  const sentinelRef = useRef(null);

  useEffect(() => { fetchMeta().then(setMeta).catch(() => {}); }, []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    fetchFeedback({ uuid, ...filters })
      .then(data => {
        if (cancelled) return;
        setSubmissions(data.submissions);
        setMyVotes(data.my_votes || {});
        setNextOffset(data.next_offset);
        setError(null);
      })
      .catch(e => { if (!cancelled) setError(String(e.message || e)); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [uuid, filters.kind, filters.type, filters.status, filters.sort]);

  const loadMore = useCallback(async () => {
    if (nextOffset === null || loadingMore) return;
    setLoadingMore(true);
    try {
      const data = await fetchFeedback({ uuid, ...filters, offset: nextOffset });
      setSubmissions(prev => [...prev, ...data.submissions]);
      setMyVotes(prev => ({ ...prev, ...(data.my_votes || {}) }));
      setNextOffset(data.next_offset);
    } catch (e) {
      setError(String(e.message || e));
    } finally {
      setLoadingMore(false);
    }
  }, [nextOffset, loadingMore, uuid, filters]);

  useEffect(() => {
    const el = sentinelRef.current;
    if (!el || nextOffset === null) return;
    const io = new IntersectionObserver(entries => {
      if (entries[0].isIntersecting) loadMore();
    }, { rootMargin: '300px' });
    io.observe(el);
    return () => io.disconnect();
  }, [loadMore, nextOffset]);

  function flashToast(msg, kind = 'info') {
    setToast({ msg, kind });
    setTimeout(() => setToast(null), 4000);
  }

  async function castVote(submissionId, vote) {
    try {
      const res = await postFeedbackVote({ uuid, submission_id: submissionId, vote });
      setSubmissions(prev => prev.map(s =>
        s.id === submissionId
          ? { ...s, community_true: res.community_true, community_false: res.community_false }
          : s,
      ));
      setMyVotes(prev => ({ ...prev, [submissionId]: { vote: vote ? 1 : 0 } }));
      if (res.changed) flashToast('Vot înregistrat. Mulțumim.', 'ok');
    } catch (e) {
      flashToast(e.message || 'Eroare la vot', 'err');
    }
  }

  const typesAll = meta ? [...meta.datorii_types, ...meta.asset_types] : [];

  return (
    <div className="max-w-5xl mx-auto px-4 py-6 sm:py-10">
      <motion.div initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }}>
        <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">
          Verifică datele comunității
        </h1>
        <p className="mt-2 text-sm text-slate-600 max-w-2xl">
          Votează fiecare snapshot: <strong>▲</strong> dacă suma datoriilor și
          asset-urilor pare plauzibilă, <strong>▼</strong> dacă pare exagerată
          sau suspectă. Un vot per persoană, la fiecare 24h îți poți schimba
          părerea.
        </p>
      </motion.div>

      <Filters
        filters={filters}
        setFilters={setFilters}
        typesAll={typesAll}
      />

      {error && (
        <div className="mb-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 text-sm">
          {error}
        </div>
      )}

      {loading ? (
        <div className="py-16 text-center text-slate-400">Se încarcă…</div>
      ) : submissions.length === 0 ? (
        <div className="py-16 text-center text-slate-400 bg-white rounded-2xl border border-slate-200">
          Nicio intrare pentru filtrele alese.
        </div>
      ) : (
        <div className="space-y-4">
          {submissions.map(s => (
            <SubmissionCard
              key={s.id}
              s={s}
              myVote={myVotes[s.id]?.vote}
              onVote={castVote}
            />
          ))}
        </div>
      )}

      {nextOffset !== null && (
        <div ref={sentinelRef} className="py-8 text-center text-xs text-slate-400">
          {loadingMore ? 'Se încarcă…' : ' '}
        </div>
      )}

      <AnimatePresence>
        {toast && (
          <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: 12 }}
            className={
              'fixed left-1/2 -translate-x-1/2 bottom-4 z-30 px-4 py-2 rounded-full text-sm shadow-lg ' +
              (toast.kind === 'err'
                ? 'bg-rose-600 text-white'
                : 'bg-slate-900 text-white')
            }
          >
            {toast.msg}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

function Filters({ filters, setFilters, typesAll }) {
  const set = (patch) => setFilters({ ...filters, ...patch });
  const pill = 'shrink-0 rounded-full border border-slate-300 bg-white px-3 py-1.5 text-sm shadow-sm hover:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-900/15 focus:border-slate-500';
  return (
    <div className="my-6 -mx-4 px-4 sm:mx-0 sm:px-0 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
      <div className="flex items-center gap-2 sm:flex-wrap min-w-min">
        <select className={pill} value={filters.kind} onChange={e => set({ kind: e.target.value })}>
          <option value="">📂 Toate (datorii + asset-uri)</option>
          <option value="datorie">Datorii</option>
          <option value="asset">Asset-uri</option>
        </select>
        <select className={pill} value={filters.type} onChange={e => set({ type: e.target.value })}>
          <option value="">🏷️ Toate tipurile</option>
          {typesAll.map(t => <option key={t} value={t}>{t}</option>)}
        </select>
        <select className={pill} value={filters.status} onChange={e => set({ status: e.target.value })}>
          {STATUSES.map(o => <option key={o.v} value={o.v}>{o.label}</option>)}
        </select>
        <select className={pill} value={filters.sort} onChange={e => set({ sort: e.target.value })}>
          {SORTS.map(o => <option key={o.v} value={o.v}>↕ {o.label}</option>)}
        </select>
        {(filters.kind || filters.type || filters.status || filters.sort !== 'recent') && (
          <button
            onClick={() => setFilters({ kind: '', type: '', status: '', sort: 'recent' })}
            className="shrink-0 text-xs text-slate-500 hover:text-rose-600 px-2 py-1.5 rounded-full hover:bg-rose-50"
          >
            ✕ resetează
          </button>
        )}
      </div>
    </div>
  );
}

function SubmissionCard({ s, myVote, onVote }) {
  const nApproved = s.entries.filter(e => e.status === 1).length;
  const nTotal = s.entries.length;
  const ratio = nTotal === 0 ? 'bg-slate-100 text-slate-500'
    : nApproved === nTotal ? 'bg-emerald-100 text-emerald-700'
    : nApproved === 0     ? 'bg-rose-100 text-rose-700'
    : 'bg-amber-100 text-amber-700';

  return (
    <motion.section
      initial={{ opacity: 0, y: 4 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.2 }}
      className="bg-white rounded-2xl border border-slate-200 overflow-hidden"
    >
      <header className="bg-slate-50 px-4 py-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-slate-200">
        <span className="text-xs text-slate-400">#{s.id}</span>
        <span className="font-mono text-sm text-slate-800">{s.session_id || '—'}</span>
        <span className="text-xs text-slate-500 truncate">
          {(s.judet || '—')} · {s.varsta ? `${s.varsta} ani` : '—'} · {s.sex || '—'}
          {s.persoane_intretinere !== null ? ` · PI:${s.persoane_intretinere}` : ''}
          {s.domeniu ? ` · ${s.domeniu}` : ''} · {s.optimist ? '😊' : '😟'}
        </span>
        <span className="text-xs text-slate-400 ml-auto">{s.created_at}</span>
        <span className={'text-xs px-2 py-0.5 rounded-full ' + ratio}>
          {nApproved}/{nTotal} ok
        </span>
        <VoteCluster
          myVote={myVote}
          countUp={s.community_true}
          countDown={s.community_false}
          onVote={(v) => onVote(s.id, v)}
        />
      </header>
      <table className="w-full">
        <tbody>
          {s.entries.map(e => (
            <EntryRow key={e.id} e={e} />
          ))}
        </tbody>
      </table>
    </motion.section>
  );
}

function EntryRow({ e }) {
  const badge = e.status === 1
    ? <span className="inline-block px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-xs font-medium">ok</span>
    : <span className="inline-block px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 text-xs font-medium">flagged</span>;
  return (
    <tr className="border-t border-slate-100">
      <td className="px-2 py-2 text-xs text-slate-400 w-10 sm:w-12">#{e.id}</td>
      <td className="px-2 py-2 text-xs w-16 sm:w-20 text-slate-500">{e.kind}</td>
      <td className="px-2 py-2 text-sm text-slate-800">{e.type}</td>
      <td className="px-2 py-2 text-sm font-mono text-right whitespace-nowrap">{formatRon(e.amount)}</td>
      <td className="px-2 py-2 w-20 hidden sm:table-cell">{badge}</td>
    </tr>
  );
}

function VoteCluster({ myVote, countUp, countDown, onVote }) {
  const isUp   = myVote === 1;
  const isDown = myVote === 0;
  return (
    <div className="inline-flex items-center gap-1">
      <VoteArrow
        dir="up"
        active={isUp}
        count={countUp}
        onClick={() => onVote(true)}
      />
      <VoteArrow
        dir="down"
        active={isDown}
        count={countDown}
        onClick={() => onVote(false)}
      />
    </div>
  );
}

function VoteArrow({ dir, active, count, onClick }) {
  const up = dir === 'up';
  const base = 'inline-flex items-center gap-1 px-2 py-1 rounded-full border text-xs font-medium transition';
  const palette = up
    ? (active
        ? 'bg-emerald-600 text-white border-emerald-600'
        : 'bg-white text-slate-600 border-slate-300 hover:border-emerald-400 hover:text-emerald-700')
    : (active
        ? 'bg-rose-600 text-white border-rose-600'
        : 'bg-white text-slate-600 border-slate-300 hover:border-rose-400 hover:text-rose-700');
  return (
    <button
      type="button"
      title={up ? 'Pare plauzibil' : 'Pare suspect'}
      aria-label={up ? 'Votează plauzibil' : 'Votează suspect'}
      aria-pressed={active}
      onClick={onClick}
      className={base + ' ' + palette}
    >
      <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" fill="currentColor">
        {up
          ? <path d="M6 2 L10 8 H2 Z" />
          : <path d="M6 10 L2 4 H10 Z" />}
      </svg>
      <span className="tabular-nums">{formatNumber(count)}</span>
    </button>
  );
}
