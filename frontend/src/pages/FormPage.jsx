import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { fetchMeta, fetchStats, submitForm } from '../lib/api.js';
import { markSubmitted, setSid, isValidSid, hasSubmitted, getSid } from '../lib/identity.js';
import { formatInput, parseNumber, stripAmountChars, stripDigits, formatNumber, formatRon } from '../lib/format.js';
import FAQ from '../components/FAQ.jsx';

const VARSTA_MIN = 14, VARSTA_MAX = 110;
const PI_MIN = 0, PI_MAX = 20;

let nextId = 1;
const tmpId = () => `e${nextId++}`;

export default function FormPage({ uuid }) {
  const nav = useNavigate();
  const [meta, setMeta] = useState(null);
  const [datorii, setDatorii] = useState([]);
  const [asset, setAsset] = useState([]);
  const [optimist, setOptimist] = useState(null);
  const [showDemo, setShowDemo] = useState(true);
  const [demo, setDemo] = useState({ judet: '', varsta: '', sex: '', persoane_intretinere: '', domeniu: '', domeniuOther: '' });
  const [demoErrors, setDemoErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);

  function updateVarsta(raw) {
    const v = stripDigits(raw).slice(0, 3);
    setDemo(d => ({ ...d, varsta: v }));
    const n = Number(v);
    const err = v === '' ? null : (n < VARSTA_MIN || n > VARSTA_MAX) ? `Între ${VARSTA_MIN} și ${VARSTA_MAX} ani.` : null;
    setDemoErrors(e => ({ ...e, varsta: err }));
  }
  function updatePI(raw) {
    const v = stripDigits(raw).slice(0, 2);
    setDemo(d => ({ ...d, persoane_intretinere: v }));
    const n = Number(v);
    const err = v === '' ? null : (n < PI_MIN || n > PI_MAX) ? `Între ${PI_MIN} și ${PI_MAX}.` : null;
    setDemoErrors(e => ({ ...e, persoane_intretinere: err }));
  }

  useEffect(() => {
    fetchMeta().then(setMeta).catch(e => setError(String(e.message || e)));
  }, []);

  function addRow(kind) {
    const row = { id: tmpId(), type: '', amount: '', currency: 'RON' };
    if (kind === 'datorie') setDatorii(prev => [...prev, row]);
    else setAsset(prev => [...prev, row]);
  }

  function updateRow(kind, id, patch) {
    const setter = kind === 'datorie' ? setDatorii : setAsset;
    setter(prev => prev.map(r => r.id === id ? { ...r, ...patch } : r));
  }

  function removeRow(kind, id) {
    const setter = kind === 'datorie' ? setDatorii : setAsset;
    setter(prev => prev.filter(r => r.id !== id));
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError(null);

    if (optimist === null) {
      setError('Te rog răspunde dacă ești optimist financiar.');
      return;
    }
    const entries = [];
    for (const r of datorii) {
      const amt = parseNumber(r.amount);
      if (!r.type) continue;
      if (amt <= 0) { setError('Toate sumele trebuie să fie pozitive.'); return; }
      entries.push({ kind: 'datorie', type: r.type, amount: amt, currency: r.currency || 'RON' });
    }
    for (const r of asset) {
      const amt = parseNumber(r.amount);
      if (!r.type) continue;
      if (amt <= 0) { setError('Toate sumele trebuie să fie pozitive.'); return; }
      entries.push({ kind: 'asset', type: r.type, amount: amt, currency: r.currency || 'RON' });
    }
    if (entries.length === 0) {
      setError('Adaugă cel puțin o datorie sau un asset.');
      return;
    }

    const demoErr = {};
    if (!demo.judet) demoErr.judet = 'Obligatoriu.';
    if (demo.varsta === '') demoErr.varsta = 'Obligatoriu.';
    else {
      const v = Number(demo.varsta);
      if (v < VARSTA_MIN || v > VARSTA_MAX) demoErr.varsta = `Între ${VARSTA_MIN} și ${VARSTA_MAX} ani.`;
    }
    if (!demo.sex) demoErr.sex = 'Obligatoriu.';
    if (demo.persoane_intretinere === '') demoErr.persoane_intretinere = 'Obligatoriu.';
    else {
      const p = Number(demo.persoane_intretinere);
      if (p < PI_MIN || p > PI_MAX) demoErr.persoane_intretinere = `Între ${PI_MIN} și ${PI_MAX}.`;
    }
    if (!demo.domeniu) demoErr.domeniu = 'Obligatoriu.';
    else if (demo.domeniu === 'Altele' && !demo.domeniuOther.trim()) demoErr.domeniuOther = 'Specifică domeniul.';

    if (Object.keys(demoErr).length > 0) {
      setDemoErrors(demoErr);
      setShowDemo(true);
      setError('Completează toate câmpurile demografice.');
      return;
    }

    const domeniuOther = demo.domeniu === 'Altele' ? demo.domeniuOther.trim() : null;

    const payload = {
      uuid,
      optimist,
      judet: demo.judet,
      varsta: Number(demo.varsta),
      sex: demo.sex,
      persoane_intretinere: Number(demo.persoane_intretinere),
      domeniu: demo.domeniu,
      domeniu_other: domeniuOther,
      entries,
    };

    setSubmitting(true);
    try {
      const res = await submitForm(payload);
      if (res.session_id) setSid(res.session_id);
      markSubmitted();
      nav('/dashboard');
    } catch (err) {
      setError(String(err.message || err));
    } finally {
      setSubmitting(false);
    }
  }

  if (!meta) {
    return <div className="max-w-3xl mx-auto px-4 py-16 text-center text-slate-500">Se încarcă…</div>;
  }

  return (
    <div className="max-w-5xl mx-auto px-4 py-8 sm:py-12">
      {!hasSubmitted() && !getSid() && <SidLogin />}

      <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.35 }}>
        <h1 className="text-3xl sm:text-4xl font-bold tracking-tight text-slate-900">Care e situația ta financiară?</h1>
        <p className="mt-2 text-slate-600 max-w-2xl">
          Introdu datoriile și asset-urile tale. După submit, vei vedea cum stai față de media celor care au completat înaintea ta. Totul anonim.
        </p>
      </motion.div>

      <form onSubmit={handleSubmit} className="mt-8 space-y-8">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Column
            title="Datorii"
            color="rose"
            rows={datorii}
            types={meta.datorii_types}
            rate={meta.fx?.eur_ron ?? null}
            onAdd={() => addRow('datorie')}
            onUpdate={(id, patch) => updateRow('datorie', id, patch)}
            onRemove={(id) => removeRow('datorie', id)}
          />
          <Column
            title="Asset-uri"
            color="emerald"
            rows={asset}
            types={meta.asset_types}
            rate={meta.fx?.eur_ron ?? null}
            onAdd={() => addRow('asset')}
            onUpdate={(id, patch) => updateRow('asset', id, patch)}
            onRemove={(id) => removeRow('asset', id)}
          />
        </div>

        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="bg-white rounded-2xl border border-slate-200 p-5 sm:p-6">
          <h3 className="font-semibold text-slate-900">Ești optimist din punct de vedere financiar?</h3>
          <div className="mt-3 flex gap-2">
            {[
              { v: true, label: 'Da, sunt optimist' },
              { v: false, label: 'Nu, sunt pesimist' },
            ].map(opt => (
              <button
                key={String(opt.v)}
                type="button"
                onClick={() => setOptimist(opt.v)}
                className={
                  'px-4 py-2 rounded-full border text-sm transition ' +
                  (optimist === opt.v
                    ? 'bg-slate-900 text-white border-slate-900'
                    : 'bg-white text-slate-700 border-slate-300 hover:border-slate-500')
                }
              >
                {opt.label}
              </button>
            ))}
          </div>
        </motion.div>

        <div className="bg-white rounded-2xl border border-slate-200">
          <button
            type="button"
            onClick={() => setShowDemo(s => !s)}
            aria-expanded={showDemo}
            className="w-full flex items-start justify-between px-5 sm:px-6 py-4 text-left gap-3 cursor-pointer select-none hover:bg-slate-50/60 rounded-2xl transition"
          >
            <span>
              <span className="block font-semibold text-slate-900">Date demografice</span>
              <span className="block text-xs text-slate-500 font-normal mt-0.5">Toate câmpurile sunt obligatorii și ajută la acuratețea statisticilor.</span>
            </span>
            <Chevron open={showDemo} className="mt-0.5 shrink-0" />
          </button>
          <AnimatePresence initial={false}>
            {showDemo && (
              <motion.div
                key="demo"
                initial={{ height: 0, opacity: 0 }}
                animate={{ height: 'auto', opacity: 1 }}
                exit={{ height: 0, opacity: 0 }}
                transition={{ duration: 0.25 }}
                className="overflow-hidden"
              >
                <div className="px-5 sm:px-6 pb-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <Field label="Județ" error={demoErrors.judet}>
                    <select
                      value={demo.judet}
                      onChange={e => { setDemo({ ...demo, judet: e.target.value }); setDemoErrors(er => ({ ...er, judet: null })); }}
                      className={selectCls + (demoErrors.judet ? ' border-rose-400 focus:ring-rose-200' : '')}
                    >
                      <option value="">— alege —</option>
                      {meta.judete.map(j => <option key={j} value={j}>{j}</option>)}
                    </select>
                  </Field>
                  <Field label="Vârstă" error={demoErrors.varsta}>
                    <input
                      type="text"
                      inputMode="numeric"
                      value={demo.varsta}
                      onChange={e => updateVarsta(e.target.value)}
                      className={inputCls + (demoErrors.varsta ? ' border-rose-400 focus:ring-rose-200' : '')}
                      placeholder="ex. 34"
                    />
                  </Field>
                  <Field label="Sex" error={demoErrors.sex}>
                    <select
                      value={demo.sex}
                      onChange={e => { setDemo({ ...demo, sex: e.target.value }); setDemoErrors(er => ({ ...er, sex: null })); }}
                      className={selectCls + (demoErrors.sex ? ' border-rose-400 focus:ring-rose-200' : '')}
                    >
                      <option value="">— alege —</option>
                      <option value="M">Masculin</option>
                      <option value="F">Feminin</option>
                    </select>
                  </Field>
                  <Field label={<>Persoane în întreținere <span className="font-normal text-slate-400">(excluzându-te pe tine)</span></>} error={demoErrors.persoane_intretinere}>
                    <input
                      type="text"
                      inputMode="numeric"
                      value={demo.persoane_intretinere}
                      onChange={e => updatePI(e.target.value)}
                      className={inputCls + (demoErrors.persoane_intretinere ? ' border-rose-400 focus:ring-rose-200' : '')}
                      placeholder="ex. 1"
                    />
                  </Field>
                  <Field label="Domeniu de activitate" error={demoErrors.domeniu}>
                    <select
                      value={demo.domeniu}
                      onChange={e => { setDemo({ ...demo, domeniu: e.target.value }); setDemoErrors(er => ({ ...er, domeniu: null })); }}
                      className={selectCls + (demoErrors.domeniu ? ' border-rose-400 focus:ring-rose-200' : '')}
                    >
                      <option value="">— alege —</option>
                      {meta.domenii.map(d => <option key={d} value={d}>{d}</option>)}
                    </select>
                  </Field>
                  {demo.domeniu === 'Altele' && (
                    <Field label="Specifică domeniul" error={demoErrors.domeniuOther}>
                      <input
                        type="text"
                        value={demo.domeniuOther}
                        onChange={e => { setDemo({ ...demo, domeniuOther: e.target.value }); setDemoErrors(er => ({ ...er, domeniuOther: null })); }}
                        className={inputCls + (demoErrors.domeniuOther ? ' border-rose-400 focus:ring-rose-200' : '')}
                        placeholder="ex: Sport profesionist"
                      />
                    </Field>
                  )}
                </div>
              </motion.div>
            )}
          </AnimatePresence>
        </div>

        {error && <div className="rounded-lg bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 text-sm">{error}</div>}

        <div className="flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
          {meta.approved_submissions_count > 0 && (
            <span className="text-sm text-slate-500">
              📊 <strong className="text-slate-700">{formatNumber(meta.approved_submissions_count)}</strong> intrări aprobate până acum
            </span>
          )}
          <button
            type="submit"
            disabled={submitting}
            className="sm:ml-auto inline-flex items-center justify-center px-6 py-3 rounded-xl bg-emerald-600 text-white font-medium shadow-sm hover:bg-emerald-700 active:bg-emerald-800 disabled:opacity-60 transition"
          >
            {submitting ? 'Se trimite…' : 'Trimite și vezi statisticile'}
          </button>
        </div>
      </form>

      <section className="mt-10 sm:mt-12">
        <h2 className="text-lg font-semibold text-slate-900 mb-4">Întrebări frecvente</h2>
        <FAQ />
      </section>
    </div>
  );
}

function Chevron({ open, className = '' }) {
  return (
    <svg
      width="20" height="20" viewBox="0 0 20 20" fill="none"
      aria-hidden="true"
      className={'text-slate-400 chev-rotate ' + (open ? 'rotate-180 ' : '') + className}
    >
      <path d="M5 8l5 5 5-5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function SidLogin() {
  const nav = useNavigate();
  const [value, setValue] = useState('');
  const [checking, setChecking] = useState(false);
  const [err, setErr] = useState(null);

  async function login(e) {
    e.preventDefault();
    setErr(null);
    const candidate = value.trim().toLowerCase();
    if (!isValidSid(candidate)) {
      setErr('Codul are 8 caractere (a-z, 2-9).');
      return;
    }
    setChecking(true);
    try {
      const data = await fetchStats({ sid: candidate });
      if (!data.user) {
        setErr('Codul nu există. Verifică-l și reîncearcă.');
        return;
      }
      setSid(candidate);
      markSubmitted();
      nav('/dashboard');
    } catch (e2) {
      setErr(String(e2.message || e2));
    } finally {
      setChecking(false);
    }
  }

  return (
    <details className="mb-6 rounded-xl border border-slate-200 bg-white group/details">
      <summary className="cursor-pointer list-none px-4 py-3 flex items-center justify-between gap-3 text-sm select-none">
        <span className="text-slate-700 min-w-0 flex-1">
          <span className="font-medium">Ai un cod de sesiune?</span>
          <span className="text-slate-500 hidden sm:inline"> · Continuă de unde ai rămas</span>
        </span>
        <Chevron open={false} className="group-open/details:rotate-180" />
      </summary>
      <form onSubmit={login} className="px-4 pb-4 pt-1 flex flex-col sm:flex-row gap-2 items-start sm:items-center">
        <input
          type="text"
          value={value}
          onChange={e => setValue(e.target.value)}
          placeholder="ex: upbah96y"
          maxLength={8}
          spellCheck={false}
          autoCapitalize="none"
          autoComplete="off"
          className="font-mono tracking-wider rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900/20 focus:border-slate-500 w-44"
        />
        <button
          type="submit"
          disabled={checking}
          className="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:bg-slate-800 disabled:opacity-60"
        >
          {checking ? 'Verific…' : 'Intră'}
        </button>
        {err && <span className="text-rose-600 text-xs">{err}</span>}
      </form>
    </details>
  );
}

const baseField = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900/20 focus:border-slate-500';
const inputCls = baseField + ' w-full';
const selectCls = baseField + ' w-full pr-8';
const amountInputCls = baseField + ' w-28 text-right';
const currencySelectCls = 'rounded-lg border border-slate-300 bg-white py-2 pl-2.5 pr-7 text-sm shrink-0 w-[5.25rem] focus:outline-none focus:ring-2 focus:ring-slate-900/20 focus:border-slate-500';

function Field({ label, error, children }) {
  return (
    <label className="block">
      <span className="block text-xs font-medium text-slate-600 mb-1">{label}</span>
      {children}
      {error && <span className="block text-xs text-rose-600 mt-1">{error}</span>}
    </label>
  );
}

function Column({ title, color, rows, types, rate, onAdd, onUpdate, onRemove }) {
  const accent = color === 'rose'
    ? { ring: 'ring-rose-200', dot: 'bg-rose-500', btn: 'text-rose-700 bg-rose-50 hover:bg-rose-100 border-rose-200', total: 'text-rose-600' }
    : { ring: 'ring-emerald-200', dot: 'bg-emerald-500', btn: 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border-emerald-200', total: 'text-emerald-600' };

  // Total is shown in RON-equivalent — EUR rows are converted with the BNR rate
  // so a mixed-currency column still adds up to something meaningful.
  const total = rows.reduce((s, r) => {
    const amt = parseNumber(r.amount);
    return s + (r.currency === 'EUR' && rate ? amt * rate : amt);
  }, 0);

  return (
    <div className={'bg-white rounded-2xl border border-slate-200 p-5 sm:p-6 ring-4 ring-inset ' + accent.ring}>
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <span className={'inline-block w-2.5 h-2.5 rounded-full ' + accent.dot}></span>
          <h2 className="font-semibold text-slate-900">{title}</h2>
        </div>
        <div className={'text-sm font-semibold ' + accent.total}>{total > 0 ? new Intl.NumberFormat('ro-RO').format(total) + ' RON' : ' '}</div>
      </div>

      <div className="mt-4 space-y-2">
        <AnimatePresence initial={false}>
          {rows.map(r => {
            const cur = r.currency || 'RON';
            const amt = parseNumber(r.amount);
            const showPreview = cur === 'EUR' && rate && amt > 0;
            return (
            <motion.div
              key={r.id}
              initial={{ opacity: 0, y: -4 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, height: 0 }}
            >
              <div className="flex items-center gap-2">
                <div className="flex-1 min-w-0">
                  <select
                    value={r.type}
                    onChange={e => onUpdate(r.id, { type: e.target.value })}
                    className={selectCls}
                  >
                    <option value="">— alege tip —</option>
                    {types.map(t => <option key={t} value={t}>{t}</option>)}
                  </select>
                </div>
                <input
                  type="text"
                  inputMode="numeric"
                  placeholder="sumă"
                  value={r.amount}
                  onChange={e => onUpdate(r.id, { amount: stripAmountChars(e.target.value) })}
                  onBlur={e => onUpdate(r.id, { amount: formatInput(e.target.value) })}
                  className={amountInputCls}
                />
                <select
                  value={cur}
                  onChange={e => onUpdate(r.id, { currency: e.target.value })}
                  className={currencySelectCls}
                  aria-label="monedă"
                >
                  <option value="RON">RON</option>
                  <option value="EUR">EUR</option>
                </select>
                <button
                  type="button"
                  onClick={() => onRemove(r.id)}
                  aria-label="șterge"
                  className="text-slate-400 hover:text-slate-700 px-2 py-2 rounded-lg hover:bg-slate-100"
                >
                  ✕
                </button>
              </div>
              {showPreview && (
                <div className="text-xs text-slate-400 text-right mt-0.5 pr-9">
                  ≈ {formatRon(amt * rate)}
                </div>
              )}
            </motion.div>
            );
          })}
        </AnimatePresence>
      </div>

      <button
        type="button"
        onClick={onAdd}
        className={'mt-3 inline-flex items-center gap-1 px-3 py-1.5 rounded-full border text-sm transition ' + accent.btn}
      >
        + Adaugă
      </button>
    </div>
  );
}
