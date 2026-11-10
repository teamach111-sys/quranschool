import React, { useState } from 'react';
import { apiFetch } from '../lib/api';
import Icon from '../components/Icon';
import Logo from '../components/Logo';
import LanguageSwitcher from '../components/LanguageSwitcher';
import { useLanguage } from '../hooks/useLanguage';

export default function Login() {
    const { isRTL, t } = useLanguage();
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

            const loginErrorMessages = {
                invalid_credentials: t.login.invalidCredentials,
                inactive_account: t.login.inactiveAccount,
                role_not_allowed: t.login.accessDenied,
            };

            if (data?.success) {
                window.location.href = data.redirect || '/dashboard';
                return;
            }

            if (data?.code && loginErrorMessages[data.code]) {
                setError(loginErrorMessages[data.code]);
                return;
            }

            if (data?.errors) {
                setError(t.login.validationError);
                return;
            }

            if (data?.message) {
                setError(data.message);
                return;
            }

            if (!res.ok) {
                setError(t.login.requestError(res.status));
                return;
            }

            // A 200 with a non-JSON body means the session is already valid.
            window.location.href = '/dashboard';
        } catch {
            setError(t.login.networkError);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div dir={isRTL ? 'rtl' : 'ltr'} className="bg-background dark:bg-[#1c1b1a] font-sans text-on-surface dark:text-[#e7e3de] antialiased min-h-screen flex items-center justify-center p-4 sm:p-5 transition-colors duration-200">
            <main className="w-full max-w-[440px] flex flex-col items-center justify-center">
                <div className="flex flex-col w-full items-center justify-center py-6 sm:py-10">
                    <div className="mb-4 flex w-full justify-end">
                        <LanguageSwitcher compact />
                    </div>
                    {/* Brand Logo */}
                    <div className="flex items-center justify-center mb-6 w-full">
                        <Logo className="h-12 sm:h-14" />
                    </div>
                    {/* Login Card Container */}
                    <div className="w-full bg-surface-container-lowest dark:bg-[#232221] rounded-xl shadow-md p-6 sm:p-10 flex flex-col transition-colors duration-200">
                        {/* Header Titles */}
                        <div className="mb-6 text-center">
                            <h1 className="text-[22px] sm:text-[28px] leading-[30px] sm:leading-[36px] tracking-[-0.015em] font-semibold text-on-surface dark:text-[#e7e3de]">{t.login.title}</h1>
                            <p className="text-[13px] sm:text-[14px] leading-[20px] sm:leading-[22px] text-on-surface-variant dark:text-[#918d88] mt-2">{t.login.description}</p>
                        </div>
                        {/* Error */}
                        {error && (
                            <div className="mb-5 flex items-start gap-2 rounded-lg bg-error-container dark:bg-[#3a2624]/40 px-3.5 py-3 text-[13px] leading-[20px] text-on-error-container dark:text-[#e59a92]">
                                <Icon name="error" className="text-[18px] mt-px shrink-0" />
                                <span>{error}</span>
                            </div>
                        )}
                        {/* Form */}
                        <form className="flex flex-col gap-5" onSubmit={handleSubmit}>
                            {/* Input Field: Identifier / Email */}
                            <div className="flex flex-col gap-1.5">
                                <label className="text-[12px] sm:text-[14px] leading-[18px] sm:leading-[20px] tracking-[0.02em] font-medium text-on-surface dark:text-[#e7e3de]" htmlFor="identifier">{t.login.identifier}</label>
                                <div className="relative flex items-center">
                                    <Icon name="person" className={`absolute ${isRTL ? 'right-3.5' : 'left-3.5'} text-outline dark:text-[#918d88] text-[20px] pointer-events-none select-none`} />
                                    <input
                                        id="identifier" name="identifier" type="text" required autoFocus
                                        autoComplete="username" dir="ltr" value={identifier}
                                        onChange={(e) => setIdentifier(e.target.value)}
                                        placeholder={t.login.identifierPlaceholder}
                                        className={`w-full min-h-[44px] bg-surface-container-low dark:bg-[#1c1b1a] focus:bg-surface-container-lowest text-on-surface dark:text-[#e7e3de] placeholder:text-outline dark:placeholder:text-[#6f6b66] rounded-lg py-2.5 font-sans text-[14px] leading-[22px] transition-all outline-none focus:shadow-sm ${isRTL ? 'pr-11 pl-3' : 'pl-11 pr-3'}`}
                                    />
                                </div>
                            </div>
                            {/* Input Field: Password */}
                            <div className="flex flex-col gap-1.5">
                                <div className="flex items-center justify-between">
                                    <label className="text-[12px] sm:text-[14px] leading-[18px] sm:leading-[20px] tracking-[0.02em] font-medium text-on-surface dark:text-[#e7e3de]" htmlFor="password">{t.login.password}</label>
                                    <a className="text-[11px] sm:text-[12px] leading-[16px] sm:leading-[18px] tracking-[0.03em] font-semibold text-primary dark:text-[#918d88] hover:text-primary-container transition-colors py-1 inline-flex items-center min-h-[44px]" href="/forgot-password">{t.passwordRecovery.forgotLink}</a>
                                </div>
                                <div className="relative flex items-center">
                                    <Icon name="lock" className={`absolute ${isRTL ? 'right-3.5' : 'left-3.5'} text-outline dark:text-[#918d88] text-[20px] pointer-events-none select-none`} />
                                    <input
                                        id="password" name="password" type={showPassword ? 'text' : 'password'} required
                                        autoComplete="current-password" dir="ltr" value={password}
                                        onChange={(e) => setPassword(e.target.value)}
                                        placeholder="••••••••"
                                        className={`w-full min-h-[44px] bg-surface-container-low dark:bg-[#1c1b1a] focus:bg-surface-container-lowest text-on-surface dark:text-[#e7e3de] placeholder:text-outline dark:placeholder:text-[#6f6b66] rounded-lg py-2.5 font-sans text-[14px] leading-[22px] transition-all outline-none focus:shadow-sm ${isRTL ? 'pr-11 pl-12' : 'pl-11 pr-12'}`}
                                    />
                                    <button type="button" aria-label={showPassword ? t.login.hidePassword : t.login.showPassword} onClick={() => setShowPassword((current) => !current)}
                                        className={`absolute ${isRTL ? 'left-1' : 'right-1'} text-outline dark:text-[#918d88] hover:text-on-surface dark:hover:text-[#e7e3de] flex items-center justify-center min-w-[44px] min-h-[44px] p-2 rounded cursor-pointer transition-colors`}>
                                        <Icon name={showPassword ? 'visibility' : 'visibility_off'} className="text-[20px]" />
                                    </button>
                                </div>
                            </div>
                            {/* Checkbox: Remember Me */}
                            <div className="flex items-center gap-3 select-none min-h-[44px]">
                                <label className="relative flex items-center cursor-pointer p-1 -m-1">
                                    <input id="remember" name="remember" type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="sr-only" />
                                    <div className={`w-5 h-5 bg-surface-container-lowest dark:bg-[#1c1b1a] ${remember ? 'border-primary' : 'border-outline'} rounded transition-all flex items-center justify-center shadow-sm border`}>
                                        <svg className="w-3.5 h-3.5 text-primary dark:text-[#918d88] transition-opacity" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" viewBox="0 0 24 24" style={{ opacity: remember ? 1 : 0 }}>
                                            <polyline points="20 6 9 17 4 12" />
                                        </svg>
                                    </div>
                                </label>
                                <label className="text-[13px] sm:text-[14px] leading-[20px] sm:leading-[22px] text-on-surface dark:text-[#e7e3de] cursor-pointer select-none py-2" htmlFor="remember">{t.login.remember}</label>
                            </div>
                            {/* Submit Button */}
                            <button type="submit" disabled={loading}
                                className="w-full min-h-[48px] mt-1 bg-primary-container dark:bg-[#2f7d4f] hover:bg-primary dark:hover:bg-[#37955a] text-on-primary dark:text-white font-semibold text-[14px] sm:text-[16px] leading-[20px] tracking-[0.01em] py-3 px-4 rounded-lg shadow-sm transition-all duration-150 active:scale-[0.99] flex items-center justify-center gap-2 cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed">
                                <span>{loading ? t.login.submitting : t.login.submit}</span>
                                <Icon name={isRTL ? 'arrow_back' : 'arrow_forward'} className="text-[18px]" />
                            </button>
                        </form>
                        <div className="mt-4 flex justify-center gap-2">
                            <button type="button" onClick={() => { setIdentifier('admin@example.com'); setPassword('password'); }} className="rounded-lg border border-outline-variant bg-surface-container-low px-4 py-2 text-[12px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:border-[#3a3835] dark:bg-[#2a2928] dark:text-[#e7e3de] dark:hover:bg-[#32302e]">{t.login.quickFill.admin}</button>
                            <button type="button" onClick={() => { setIdentifier('teacher@example.com'); setPassword('password'); }} className="rounded-lg border border-outline-variant bg-surface-container-low px-4 py-2 text-[12px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:border-[#3a3835] dark:bg-[#2a2928] dark:text-[#e7e3de] dark:hover:bg-[#32302e]">{t.login.quickFill.teacher}</button>
                            <button type="button" onClick={() => { setIdentifier('secretary@example.com'); setPassword('password'); }} className="rounded-lg border border-outline-variant bg-surface-container-low px-4 py-2 text-[12px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:border-[#3a3835] dark:bg-[#2a2928] dark:text-[#e7e3de] dark:hover:bg-[#32302e]">{t.login.quickFill.secretary}</button>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    );
}
