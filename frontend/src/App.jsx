import { useEffect, useState } from 'react';
import { Outlet, Link, NavLink, useLocation } from 'react-router-dom';
import { hasSubmitted, resetSubmission, getSid, onAuthChange } from './lib/identity.js';

function readAuth() {
  return { sid: getSid(), loggedIn: hasSubmitted() || !!getSid() };
}

export default function App() {
  const location = useLocation();
  const [{ sid, loggedIn }, setAuth] = useState(readAuth);
  useEffect(() => onAuthChange(() => setAuth(readAuth())), []);
  return (
    <div className="min-h-screen flex flex-col">
      <header className="border-b border-slate-200 bg-white/80 backdrop-blur sticky top-0 z-10">
        <div className="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
          <Link to="/" className="flex items-center gap-2 group">
            <span className="inline-flex w-8 h-8 rounded-lg bg-slate-900 items-center justify-center shrink-0">
              <svg width="20" height="20" viewBox="0 0 64 64" fill="none">
                <path d="M16 44 L24 30 L34 36 L48 18" stroke="#10b981" strokeWidth="6" strokeLinecap="round" strokeLinejoin="round" />
                <circle cx="48" cy="18" r="4" fill="#f97316" />
              </svg>
            </span>
            <span className="flex flex-col leading-tight">
              <span className="font-semibold tracking-tight text-slate-900">Datorii<span className="text-slate-400 mx-1">vs</span>Asset-uri</span>
              <span className="text-[10px] sm:text-xs text-slate-400 font-normal tracking-wide">cumstaicubanii.ro</span>
            </span>
          </Link>
          {loggedIn && (
            <div className="flex items-center gap-3 text-sm">
              {location.pathname !== '/dashboard' && (
                <Link to="/dashboard" className="text-slate-600 hover:text-slate-900">Dashboard</Link>
              )}
              <button
                onClick={() => { resetSubmission(); window.location.href = '/'; }}
                className="text-slate-500 hover:text-slate-900"
              >
                Ieși din sesiune
              </button>
            </div>
          )}
        </div>
      </header>

      <main className="flex-1">
        <Outlet />
      </main>

      <footer className="border-t border-slate-200 bg-white">
        <div className="max-w-6xl mx-auto px-4 py-6 text-xs text-slate-500 space-y-2">
          <div className="flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
            <p>Nu colectăm date personale identificabile. Toate informațiile sunt anonime și agregate.</p>
            <div className="flex items-center gap-4">
              <NavLink
                to="/feedback"
                className={({ isActive }) =>
                  'inline-flex items-center gap-1.5 transition ' +
                  (isActive ? 'text-slate-900 font-medium' : 'text-slate-500 hover:text-emerald-700')
                }
                title="Verifică datele comunității"
              >
                <span aria-hidden="true">🔍</span>
                <span>Verifică datele</span>
              </NavLink>
              <a
                href="https://buymeacoffee.com/robbschmidt"
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 text-slate-500 hover:text-amber-700 transition"
                title="Susține proiectul"
              >
                <span aria-hidden="true">☕</span>
                <span>Buy me a coffee</span>
              </a>
              <a
                href="https://www.reddit.com/r/programare/comments/1to2mqc/voi_cum_stati_cu_banii/"
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 text-slate-500 hover:text-orange-600 transition"
                title="Discuție pe Reddit"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                  <path d="M12 0C5.373 0 0 5.373 0 12c0 6.628 5.373 12 12 12 6.628 0 12-5.372 12-12 0-6.627-5.372-12-12-12zm5.01 4.744c.688 0 1.25.561 1.25 1.249a1.25 1.25 0 0 1-2.498.056l-2.597-.547-.8 3.747c1.824.07 3.48.632 4.674 1.488.308-.309.73-.491 1.207-.491.968 0 1.754.786 1.754 1.754 0 .716-.435 1.333-1.01 1.614a3.111 3.111 0 0 1 .042.52c0 2.694-3.13 4.87-7.004 4.87-3.874 0-7.004-2.176-7.004-4.87 0-.183.015-.366.043-.534A1.748 1.748 0 0 1 4.028 12c0-.968.786-1.754 1.754-1.754.463 0 .898.196 1.207.49 1.207-.883 2.878-1.43 4.744-1.487l.885-4.182a.342.342 0 0 1 .14-.197.35.35 0 0 1 .238-.042l2.906.617a1.214 1.214 0 0 1 1.108-.701zM9.25 12C8.561 12 8 12.562 8 13.25c0 .687.561 1.248 1.25 1.248.687 0 1.248-.561 1.248-1.249 0-.688-.561-1.249-1.249-1.249zm5.5 0c-.687 0-1.248.561-1.248 1.25 0 .687.561 1.248 1.249 1.248.688 0 1.249-.561 1.249-1.249 0-.687-.562-1.249-1.25-1.249zm-5.466 3.99a.327.327 0 0 0-.231.094.33.33 0 0 0 0 .463c.842.842 2.484.913 2.961.913.477 0 2.105-.056 2.961-.913a.361.361 0 0 0 .029-.463.33.33 0 0 0-.464 0c-.547.533-1.684.73-2.512.73-.828 0-1.979-.196-2.512-.73a.326.326 0 0 0-.232-.095z"/>
                </svg>
                <span>Reddit</span>
              </a>
              <a
                href="https://github.com/robert-schmidt/cumstaicubanii.ro"
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 text-slate-500 hover:text-slate-900 transition"
                title="Cod sursă pe GitHub"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                  <path d="M12 .5C5.65.5.5 5.65.5 12c0 5.08 3.29 9.39 7.86 10.92.58.1.79-.25.79-.56 0-.28-.01-1.02-.02-2-3.2.7-3.87-1.54-3.87-1.54-.52-1.33-1.28-1.69-1.28-1.69-1.04-.71.08-.7.08-.7 1.15.08 1.76 1.18 1.76 1.18 1.03 1.76 2.69 1.25 3.35.96.1-.75.4-1.25.73-1.54-2.55-.29-5.24-1.28-5.24-5.7 0-1.26.45-2.29 1.18-3.1-.12-.29-.51-1.46.11-3.04 0 0 .97-.31 3.18 1.18.92-.26 1.91-.39 2.9-.39.99 0 1.98.13 2.9.39 2.21-1.49 3.18-1.18 3.18-1.18.62 1.58.23 2.75.11 3.04.74.81 1.18 1.84 1.18 3.1 0 4.43-2.69 5.41-5.25 5.69.41.36.78 1.06.78 2.14 0 1.55-.01 2.8-.01 3.18 0 .31.21.67.79.56 4.57-1.53 7.86-5.84 7.86-10.92C23.5 5.65 18.35.5 12 .5Z"/>
                </svg>
                <span>GitHub</span>
              </a>
            </div>
          </div>
          <p className="text-slate-400">
            © cumstaicubanii.ro — reproducerea fără acordul explicit sau menționarea în clar a sursei este interzisă.
          </p>
        </div>
      </footer>

      {sid && <SidBadge sid={sid} />}
    </div>
  );
}

function SidBadge({ sid }) {
  const [copied, setCopied] = useState(false);
  async function copy() {
    try {
      await navigator.clipboard.writeText(sid);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch {}
  }
  return (
    <button
      onClick={copy}
      title="Codul tău de sesiune. Copiază-l ca să te poți întoarce de pe alt dispozitiv."
      className="fixed bottom-3 left-3 z-20 inline-flex items-center gap-2 px-3 py-2 rounded-full bg-slate-900 text-white text-xs font-mono shadow-lg hover:bg-slate-800 transition"
    >
      <span className="opacity-60">sesiunea ta:</span>
      <span className="tracking-wider">{sid}</span>
      <span className="opacity-70">{copied ? '✓ copiat' : '⧉'}</span>
    </button>
  );
}
