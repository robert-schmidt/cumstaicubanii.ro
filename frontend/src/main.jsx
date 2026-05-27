import { StrictMode, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import './index.css';
import App from './App.jsx';
import FormPage from './pages/FormPage.jsx';
import DashboardPage from './pages/DashboardPage.jsx';
import FeedbackPage from './pages/FeedbackPage.jsx';
import { getUuid, hasSubmitted, getSid, onAuthChange } from './lib/identity.js';

function readAuth() {
  return { uuid: getUuid(), sid: getSid(), loggedIn: hasSubmitted() || !!getSid() };
}

function Root() {
  const [auth, setAuth] = useState(readAuth);
  useEffect(() => onAuthChange(() => setAuth(readAuth())), []);
  const { uuid, sid, loggedIn } = auth;
  return (
    <BrowserRouter>
      <Routes>
        <Route element={<App />}>
          <Route path="/" element={loggedIn ? <Navigate to="/dashboard" replace /> : <FormPage uuid={uuid} />} />
          <Route path="/dashboard" element={loggedIn ? <DashboardPage uuid={uuid} sid={sid} /> : <Navigate to="/" replace />} />
          <Route path="/feedback" element={<FeedbackPage />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <Root />
  </StrictMode>
);
