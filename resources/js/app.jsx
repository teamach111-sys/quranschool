import React from 'react';
import { createRoot } from 'react-dom/client';
import { LanguageProvider } from './hooks/useLanguage';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';

const rootElement = document.getElementById('root');
const page = rootElement?.dataset?.page;
const user = rootElement?.dataset?.user ? JSON.parse(rootElement.dataset.user) : null;

if (rootElement) {
    createRoot(rootElement).render(
        <React.StrictMode>
            <LanguageProvider>
                {page === 'dashboard' ? <Dashboard user={user} /> : <Login />}
            </LanguageProvider>
        </React.StrictMode>
    );
}
