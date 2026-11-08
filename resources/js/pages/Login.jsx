import React, { useState } from 'react';
import { apiFetch } from '../lib/api';
import Icon from '../components/Icon';
import Logo from '../components/Logo';
import { useLanguage } from '../hooks/useLanguage';

export default function Login() {
    const { t } = useLanguage();
    const [identifier, setIdentifier] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(true);
    const [showPassword, setShowPassword] = useState(false);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    const handleSubmit = async (e) => {
        e.preventDefault();
        if (loading) return;
        setError('');
        setLoading(true);
        try {
            const res = await apiFetch('/login', {
                method: 'POST',
                body: JSON.stringify({ email: identifier.trim(), password, remember }),
            });

            // Read the body as text first: a redirect or an error page returns
            // HTML, and blindly calling res.json() threw the generic error.
            const raw = await res.text();
            let data = null;
            try {
                data = raw ? JSON.parse(raw) : null;
            } catch {
                data = null;
            }

            // Laravel answered with a redirect (e.g. an old tab that is already
            // signed in). Follow it instead of failing.
            if (res.redirected) {
                window.location.href = res.url;
                return;
            }

            if (data?.success) {
                window.location.href = data.redirect || '/dashboard';
                return;
            }

            if (data?.errors) {
                setError(Object.values(data.errors).flat().join(' '));
                return;
            }

            if (data?.message) {
                setError(data.message);
                return;
            }

            if (!res.ok) {
                setError(`Connexion impossible (erreur ${res.status}). Veuillez réessayer.`);
                return;
            }

            // A 200 with a non-JSON body means the session is already valid.
            window.location.href = '/dashboard';
        } catch (err) {
            console.error('Login request failed:', err);
            setError('Impossible de contacter le serveur. Vérifiez votre connexion puis réessayez.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="bg-background dark:bg-[#182620] font-sans text-on-surface dark:text-[#ecf3ef] antialiased min-h-screen flex items-center justify-center p-4 sm:p-5 transition-colors duration-200">
            <main className="w-full max-w-[440px] flex flex-col items-center justify-center">
                <div className="flex flex-col w-full items-center justify-center py-6 sm:py-10">
                    {/* Brand Logo */}
                    <div className="flex items-center justify-center mb-6 w-full">
                        <Logo className="h-12 sm:h-14" />
                    </div>
                    {/* Login Card Container */}
                    <div className="w-full bg-surface-container-lowest dark:bg-[#202f29] rounded-xl shadow-md p-6 sm:p-10 flex flex-col transition-colors duration-200">
                        {/* Header Titles */}
                        <div className="mb-6 text-center">
                            <h1 className="text-[22px] sm:text-[28px] leading-[30px] sm:leading-[36px] tracking-[-0.015em] font-semibold text-on-surface dark:text-[#ecf3ef]">Connexion</h1>
                            <p className="text-[13px] sm:text-[14px] leading-[20px] sm:leading-[22px] text-on-surface-variant dark:text-[#9bb2a8] mt-2">Veuillez entrer vos identifiants pour continuer</p>
                        </div>
                        {/* Error */}
                        {error && (
                            <div className="mb-5 flex items-start gap-2 rounded-lg bg-error-container dark:bg-[#4a2626]/40 px-3.5 py-3 text-[13px] leading-[20px] text-on-error-container dark:text-[#fca5a5]">
                                <Icon name="error" className="text-[18px] mt-px shrink-0" />
                                <span>{error}</span>
                            </div>
                        )}
                        {/* Form */}
                        <form className="flex flex-col gap-5" onSubmit={handleSubmit}>
                            {/* Input Field: Identifier / Email */}
                            <div className="flex flex-col gap-1.5">
                                <label className="text-[12px] sm:text-[14px] leading-[18px] sm:leading-[20px] tracking-[0.02em] font-medium text-on-surface dark:text-[#ecf3ef]" htmlFor="identifier">Identifiant ou Email</label>
                                <div className="relative flex items-center">
                                    <Icon name="person" className="absolute left-3.5 text-outline dark:text-[#9bb2a8] text-[20px] pointer-events-none select-none" />
                                    <input
                                        id="identifier" name="identifier" type="text" required autoFocus
                                        autoComplete="username" value={identifier}
                                        onChange={(e) => setIdentifier(e.target.value)}
                                        placeholder="nom@exemple.com"
                                        className="w-full min-h-[44px] bg-surface-container-low dark:bg-[#182620] focus:bg-surface-container-lowest text-on-surface dark:text-[#ecf3ef] placeholder:text-outline dark:placeholder:text-[#707974] rounded-lg pl-11 pr-3 py-2.5 font-sans text-[14px] leading-[22px] transition-all outline-none focus:shadow-sm"
                                    />
                                </div>
                            </div>
                            {/* Input Field: Password */}
                            <div className="flex flex-col gap-1.5">
                                <div className="flex items-center justify-between">
                                    <label className="text-[12px] sm:text-[14px] leading-[18px] sm:leading-[20px] tracking-[0.02em] font-medium text-on-surface dark:text-[#ecf3ef]" htmlFor="password">Mot de passe</label>
                                    <a className="text-[11px] sm:text-[12px] leading-[16px] sm:leading-[18px] tracking-[0.03em] font-semibold text-primary dark:text-[#9fd1bd] hover:text-primary-container transition-colors py-1 inline-flex items-center min-h-[44px]" href="/forgot-password">{t.passwordRecovery.forgotLink}</a>
                                </div>
                                <div className="relative flex items-center">
                                    <Icon name="lock" className="absolute left-3.5 text-outline dark:text-[#9bb2a8] text-[20px] pointer-events-none select-none" />
                                    <input
                                        id="password" name="password" type={showPassword ? 'text' : 'password'} required
                                        autoComplete="current-password" value={password}
                                        onChange={(e) => setPassword(e.target.value)}
                                        placeholder="••••••••"
                                        className="w-full min-h-[44px] bg-surface-container-low dark:bg-[#182620] focus:bg-surface-container-lowest text-on-surface dark:text-[#ecf3ef] placeholder:text-outline dark:placeholder:text-[#707974] rounded-lg pl-11 pr-12 py-2.5 font-sans text-[14px] leading-[22px] transition-all outline-none focus:shadow-sm"
                                    />
                                    <button type="button" aria-label="Afficher le mot de passe" onClick={() => setShowPassword(s => !s)}
                                        className="absolute right-1 text-outline dark:text-[#9bb2a8] hover:text-on-surface dark:hover:text-[#ecf3ef] flex items-center justify-center min-w-[44px] min-h-[44px] p-2 rounded cursor-pointer transition-colors">
                                        <Icon name={showPassword ? 'visibility' : 'visibility_off'} className="text-[20px]" />
                                    </button>
                                </div>
                            </div>
                            {/* Checkbox: Remember Me */}
                            <div className="flex items-center gap-3 select-none min-h-[44px]">
                                <label className="relative flex items-center cursor-pointer p-1 -m-1">
                                    <input id="remember" name="remember" type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="sr-only" />
                                    <div className={`w-5 h-5 bg-surface-container-lowest dark:bg-[#182620] ${remember ? 'border-primary' : 'border-outline'} rounded transition-all flex items-center justify-center shadow-sm border`}>
                                        <svg className="w-3.5 h-3.5 text-primary dark:text-[#9fd1bd] transition-opacity" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" viewBox="0 0 24 24" style={{ opacity: remember ? 1 : 0 }}>
                                            <polyline points="20 6 9 17 4 12" />
                                        </svg>
                                    </div>
                                </label>
                                <label className="text-[13px] sm:text-[14px] leading-[20px] sm:leading-[22px] text-on-surface dark:text-[#ecf3ef] cursor-pointer select-none py-2" htmlFor="remember">Se souvenir de moi</label>
                            </div>
                            {/* Submit Button */}
                            <button type="submit" disabled={loading}
                                className="w-full min-h-[48px] mt-1 bg-primary-container dark:bg-[#2e5e4e] hover:bg-primary dark:hover:bg-[#376757] text-on-primary dark:text-[#ecf3ef] font-semibold text-[14px] sm:text-[16px] leading-[20px] tracking-[0.01em] py-3 px-4 rounded-lg shadow-sm transition-all duration-150 active:scale-[0.99] flex items-center justify-center gap-2 cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed">
                                <span>{loading ? 'Connexion…' : 'Se connecter'}</span>
                                <Icon name="arrow_forward" className="text-[18px]" />
                            </button>
                        </form>
                        <div className="mt-4 flex justify-center gap-2">
                            <button type="button" onClick={() => { setIdentifier('admin@example.com'); setPassword('password'); }} className="rounded-lg border border-outline-variant bg-surface-container-low px-4 py-2 text-[12px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:border-[#384c43] dark:bg-[#273831] dark:text-[#ecf3ef] dark:hover:bg-[#34483f]">Admin</button>
                            <button type="button" onClick={() => { setIdentifier('teacher@example.com'); setPassword('password'); }} className="rounded-lg border border-outline-variant bg-surface-container-low px-4 py-2 text-[12px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:border-[#384c43] dark:bg-[#273831] dark:text-[#ecf3ef] dark:hover:bg-[#34483f]">Teacher</button>
                            <button type="button" onClick={() => { setIdentifier('secretary@example.com'); setPassword('password'); }} className="rounded-lg border border-outline-variant bg-surface-container-low px-4 py-2 text-[12px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:border-[#384c43] dark:bg-[#273831] dark:text-[#ecf3ef] dark:hover:bg-[#34483f]">Secretary</button>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    );
}