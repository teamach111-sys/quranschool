import React from 'react';
import { createRoot } from 'react-dom/client';
import { LanguageProvider } from './persisters/languageswitch';
import { ToastProvider } from './components/ToastProvider';
import Login from './pages/Login';
import PasswordReset from './pages/PasswordReset';
import Dashboard from './pages/Dashboard';

const rootElement = document.getElementById('root');
const page = rootElement?.dataset?.page;

// Never let a malformed data attribute blank the whole app.
let user = null;
try {
    user = rootElement?.dataset?.user ? JSON.parse(rootElement.dataset.user) : null;
} catch (error) {
    console.error('Invalid user payload on #root:', error);
}

if (rootElement) {
    createRoot(rootElement).render(
        <React.StrictMode>
            <LanguageProvider>
                <ToastProvider>
                    {page === 'dashboard'
                        ? <Dashboard user={user} />
                        : page === 'forgot-password'
                            ? <PasswordReset />
                            : <Login />}
                </ToastProvider>
            </LanguageProvider>
        </React.StrictMode>
    );
}
