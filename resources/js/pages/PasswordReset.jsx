import React, { useState } from 'react';
import { useLanguage } from '../hooks/useLanguage';
import { apiFetch } from '../lib/api';
import Logo from '../components/Logo';

export default function PasswordReset() {
    const { lang, t } = useLanguage();
    const copy = t.passwordRecovery;
    const [email, setEmail] = useState('');
    const [recoveryKey, setRecoveryKey] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [submitted, setSubmitted] = useState(false);
    const [loading, setLoading] = useState(false);

    const handleSubmit = async (event) => {
        event.preventDefault();
        if (loading) return;

        setError('');
        setMessage('');
        setLoading(true);

        try {
            const response = await apiFetch('/forgot-password', {
                method: 'POST',
                body: JSON.stringify({
                    email: email.trim(),
                    recovery_key: recoveryKey.trim(),
                    password,
                    password_confirmation: passwordConfirmation,
                }),
            });
            const data = await response.json();

            if (!response.ok) {
                setError(Object.values(data.errors || {}).flat().join(' ') || data.message || copy.error);
                return;
            }

            setMessage(data.message);
            setSubmitted(true);
        } catch (requestError) {
            console.error('Password recovery request failed:', requestError);
            setError(copy.connectionError);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div dir={lang === 'ar' ? 'rtl' : 'ltr'} className="bg-background dark:bg-[#182620] font-sans text-on-surface dark:text-[#ecf3ef] antialiased min-h-screen flex items-center justify-center p-4 sm:p-5 transition-colors duration-200">
            <main className="w-full max-w-[440px] flex flex-col items-center justify-center">
                <div className="flex flex-col w-full items-center justify-center py-6 sm:py-10">
                    <div className="flex items-center justify-center mb-6 w-full">
                        <Logo className="h-12 sm:h-14" />
                    </div>
                    <div className="w-full bg-surface-container-lowest dark:bg-[#202f29] rounded-xl shadow-md p-6 sm:p-10 flex flex-col transition-colors duration-200">
                        <div className="mb-6 text-center">
                            <h1 className="text-[22px] sm:text-[28px] leading-[30px] sm:leading-[36px] tracking-[-0.015em] font-semibold text-on-surface dark:text-[#ecf3ef]">
                                {copy.title}
                            </h1>
                            <p className="text-[13px] sm:text-[14px] leading-[20px] sm:leading-[22px] text-on-surface-variant dark:text-[#9bb2a8] mt-2">
                                {copy.description}
                            </p>
                        </div>

                        {error && (
                            <p role="alert" className="mb-5 rounded-lg bg-error-container px-3.5 py-3 text-[13px] leading-5 text-on-error-container">
                                {error}
                            </p>
                        )}
                        {message && (
                            <p role="status" className="mb-5 rounded-lg bg-primary-fixed px-3.5 py-3 text-[13px] leading-5 text-on-primary-fixed-variant">
                                {message}
                            </p>
                        )}

                        {!submitted && (
                            <form className="flex flex-col gap-5" onSubmit={handleSubmit}>
                                <label className="flex flex-col gap-1.5 text-[13px] font-medium" htmlFor="reset-email">
                                    {copy.email}
                                    <input
                                        id="reset-email"
                                        type="email"
                                        autoComplete="email"
                                        required
                                        value={email}
                                        onChange={(event) => setEmail(event.target.value)}
                                        className="w-full min-h-[44px] bg-surface-container-low dark:bg-[#182620] rounded-lg px-3 py-2.5 text-[14px] outline-none focus:ring-2 focus:ring-primary"
                                    />
                                </label>
                                <label className="flex flex-col gap-1.5 text-[13px] font-medium" htmlFor="recovery-key">
                                    {copy.recoveryKey}
                                    <input
                                        id="recovery-key"
                                        type="text"
                                        autoComplete="off"
                                        dir="ltr"
                                        maxLength={64}
                                        required
                                        value={recoveryKey}
                                        onChange={(event) => setRecoveryKey(event.target.value.trim())}
                                        className="w-full min-h-[44px] bg-surface-container-low dark:bg-[#182620] rounded-lg px-3 py-2.5 font-mono text-[14px] outline-none focus:ring-2 focus:ring-primary"
                                    />
                                </label>
                                <label className="flex flex-col gap-1.5 text-[13px] font-medium" htmlFor="reset-password">
                                    {copy.newPassword}
                                    <input
                                        id="reset-password"
                                        type="password"
                                        autoComplete="new-password"
                                        minLength={12}
                                        required
                                        value={password}
                                        onChange={(event) => setPassword(event.target.value)}
                                        className="w-full min-h-[44px] bg-surface-container-low dark:bg-[#182620] rounded-lg px-3 py-2.5 text-[14px] outline-none focus:ring-2 focus:ring-primary"
                                    />
                                </label>
                                <label className="flex flex-col gap-1.5 text-[13px] font-medium" htmlFor="reset-password-confirmation">
                                    {copy.confirmPassword}
                                    <input
                                        id="reset-password-confirmation"
                                        type="password"
                                        autoComplete="new-password"
                                        minLength={12}
                                        required
                                        value={passwordConfirmation}
                                        onChange={(event) => setPasswordConfirmation(event.target.value)}
                                        className="w-full min-h-[44px] bg-surface-container-low dark:bg-[#182620] rounded-lg px-3 py-2.5 text-[14px] outline-none focus:ring-2 focus:ring-primary"
                                    />
                                </label>
                                <button
                                    type="submit"
                                    disabled={loading}
                                    className="w-full min-h-[48px] bg-primary-container dark:bg-[#2e5e4e] text-on-primary dark:text-[#ecf3ef] font-semibold rounded-lg disabled:opacity-60"
                                >
                                    {loading ? copy.resetting : copy.reset}
                                </button>
                            </form>
                        )}

                        <a className="mt-5 min-h-11 inline-flex items-center justify-center text-[13px] font-semibold text-primary dark:text-[#9fd1bd]" href="/login">
                            {copy.backToLogin}
                        </a>
                    </div>
                </div>
            </main>
        </div>
    );
}
