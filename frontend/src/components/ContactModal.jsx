import { useEffect, useRef, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { sendContact } from '../lib/api.js';

export default function ContactModal({ open, onClose }) {
  const [form, setForm] = useState({ name: '', email: '', message: '', website: '' });
  const [errors, setErrors] = useState({});
  const [status, setStatus] = useState('idle'); // idle | sending | sent | error
  const [errorMsg, setErrorMsg] = useState('');
  const firstFieldRef = useRef(null);

  // Reset to a clean slate each time the modal opens, and focus the first field.
  useEffect(() => {
    if (open) {
      setForm({ name: '', email: '', message: '', website: '' });
      setErrors({});
      setStatus('idle');
      setErrorMsg('');
      const t = setTimeout(() => firstFieldRef.current?.focus(), 120);
      return () => clearTimeout(t);
    }
  }, [open]);

  // Close on Escape; lock body scroll while open.
  useEffect(() => {
    if (!open) return;
    const onKey = (e) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      window.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [open, onClose]);

  function update(field, value) {
    setForm((f) => ({ ...f, [field]: value }));
    if (errors[field]) setErrors((e) => ({ ...e, [field]: undefined }));
  }

  async function onSubmit(e) {
    e.preventDefault();
    if (status === 'sending') return;
    setStatus('sending');
    setErrors({});
    setErrorMsg('');
    try {
      await sendContact(form);
      setStatus('sent');
    } catch (err) {
      setStatus('error');
      if (err.fields) setErrors(err.fields);
      setErrorMsg(err.message || 'Trimiterea a eșuat. Reîncearcă.');
    }
  }

  return (
    <AnimatePresence>
      {open && (
        <motion.div
          className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.18 }}
        >
          {/* Backdrop */}
          <div
            className="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"
            onClick={onClose}
            aria-hidden="true"
          />

          {/* Card */}
          <motion.div
            role="dialog"
            aria-modal="true"
            aria-labelledby="contact-title"
            className="relative w-full sm:max-w-md bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl ring-1 ring-slate-900/5 overflow-hidden"
            initial={{ opacity: 0, y: 40, scale: 0.96 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 30, scale: 0.97 }}
            transition={{ type: 'spring', stiffness: 320, damping: 30 }}
          >
            <div className="flex items-start justify-between px-6 pt-5 pb-3 border-b border-slate-100">
              <div>
                <h2 id="contact-title" className="text-base font-semibold text-slate-900">
                  Contact
                </h2>
                <p className="mt-0.5 text-xs text-slate-500">
                  Ai o întrebare sau o sugestie? Scrie-ne.
                </p>
              </div>
              <button
                type="button"
                onClick={onClose}
                className="-mr-2 -mt-1 p-2 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"
                aria-label="Închide"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                  <path d="M6 6l12 12M18 6L6 18" />
                </svg>
              </button>
            </div>

            <AnimatePresence mode="wait">
              {status === 'sent' ? (
                <motion.div
                  key="success"
                  className="px-6 py-10 text-center"
                  initial={{ opacity: 0, scale: 0.95 }}
                  animate={{ opacity: 1, scale: 1 }}
                  transition={{ type: 'spring', stiffness: 300, damping: 24 }}
                >
                  <span className="inline-flex w-12 h-12 rounded-full bg-emerald-100 items-center justify-center">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                      <path d="M20 6L9 17l-5-5" />
                    </svg>
                  </span>
                  <h3 className="mt-4 text-base font-semibold text-slate-900">Mesaj trimis!</h3>
                  <p className="mt-1 text-sm text-slate-500">
                    Mulțumim. Îți răspundem cât de curând.
                  </p>
                  <button
                    type="button"
                    onClick={onClose}
                    className="mt-6 inline-flex items-center justify-center rounded-full bg-slate-900 text-white text-sm font-medium px-5 py-2.5 hover:bg-slate-800 transition"
                  >
                    Închide
                  </button>
                </motion.div>
              ) : (
                <motion.form
                  key="form"
                  onSubmit={onSubmit}
                  className="px-6 py-5 space-y-4"
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                >
                  {/* Honeypot — hidden from humans, catches bots. */}
                  <div className="absolute -left-[9999px]" aria-hidden="true">
                    <label>
                      Website
                      <input
                        type="text"
                        tabIndex={-1}
                        autoComplete="off"
                        value={form.website}
                        onChange={(e) => update('website', e.target.value)}
                      />
                    </label>
                  </div>

                  <Field label="Nume" error={errors.name}>
                    <input
                      ref={firstFieldRef}
                      type="text"
                      value={form.name}
                      onChange={(e) => update('name', e.target.value)}
                      maxLength={120}
                      className={inputCls(errors.name)}
                      placeholder="Cum te cheamă"
                    />
                  </Field>

                  <Field label="Email" error={errors.email}>
                    <input
                      type="email"
                      value={form.email}
                      onChange={(e) => update('email', e.target.value)}
                      maxLength={254}
                      className={inputCls(errors.email)}
                      placeholder="adresa@exemplu.ro"
                    />
                  </Field>

                  <Field label="Mesaj" error={errors.message}>
                    <textarea
                      value={form.message}
                      onChange={(e) => update('message', e.target.value)}
                      maxLength={5000}
                      rows={4}
                      className={inputCls(errors.message) + ' resize-none'}
                      placeholder="Scrie-ne mesajul tău…"
                    />
                  </Field>

                  {status === 'error' && errorMsg && (
                    <p className="text-sm text-red-600">{errorMsg}</p>
                  )}

                  <button
                    type="submit"
                    disabled={status === 'sending'}
                    className="w-full inline-flex items-center justify-center gap-2 rounded-full bg-slate-900 text-white text-sm font-medium px-5 py-2.5 hover:bg-slate-800 transition disabled:opacity-60 disabled:cursor-not-allowed"
                  >
                    {status === 'sending' ? 'Se trimite…' : 'Trimite mesajul'}
                  </button>
                </motion.form>
              )}
            </AnimatePresence>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}

function Field({ label, error, children }) {
  return (
    <label className="block">
      <span className="block text-xs font-medium text-slate-600 mb-1">{label}</span>
      {children}
      {error && <span className="block mt-1 text-xs text-red-600">{error}</span>}
    </label>
  );
}

function inputCls(error) {
  return (
    'w-full rounded-lg border px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 ' +
    'focus:outline-none focus:ring-2 transition ' +
    (error
      ? 'border-red-300 focus:ring-red-200'
      : 'border-slate-200 focus:border-slate-400 focus:ring-slate-200')
  );
}
