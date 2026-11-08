import React, { useEffect, useState } from 'react';
import SelectInput from '../../components/SelectInput';
import { useLanguage } from '../../hooks/useLanguage';
import { apiFetch } from '../../lib/api';

const cardClass = 'min-w-0 rounded-lg border border-outline-variant bg-surface-container-lowest p-4 dark:border-tertiary-container dark:bg-inverse-surface';
const labelClass = 'flex flex-col gap-1.5 text-[13px] leading-5 font-medium text-on-surface dark:text-inverse-on-surface';
const inputClass = 'min-h-11 w-full rounded-md border border-outline-variant bg-surface-container-lowest px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:border-tertiary-container dark:bg-inverse-surface dark:text-inverse-on-surface';
const buttonClass = 'min-h-11 w-full rounded-md bg-primary px-4 text-[13px] font-semibold text-on-primary transition-colors hover:bg-primary-container disabled:cursor-not-allowed disabled:opacity-60 dark:bg-primary-container dark:text-on-primary-container sm:w-auto';

function firstErrorMessage(responseData) {
    const errors = responseData?.errors;
    if (!errors || typeof errors !== 'object') return null;

    const firstError = Object.values(errors).find((messages) => Array.isArray(messages) && messages.length > 0);
    return firstError?.[0] ?? null;
}

export default function MonProfil() {
    const { lang, setLang, t } = useLanguage();
    const [profile, setProfile] = useState(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [profileSaving, setProfileSaving] = useState(false);
    const [passwordSaving, setPasswordSaving] = useState(false);
    const [recoveryKeySaving, setRecoveryKeySaving] = useState(false);
    const [profileMessage, setProfileMessage] = useState(null);
    const [profileError, setProfileError] = useState(null);
    const [passwordMessage, setPasswordMessage] = useState(null);
    const [passwordError, setPasswordError] = useState(null);
    const [recoveryKey, setRecoveryKey] = useState('');
    const [recoveryKeyError, setRecoveryKeyError] = useState(null);
    const [password, setPassword] = useState({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    useEffect(() => {
        const controller = new AbortController();

        apiFetch('/profile', { signal: controller.signal })
            .then((response) => {
                if (!response.ok) throw new Error('Failed to load profile');
                return response.json();
            })
            .then((data) => {
                if (!controller.signal.aborted) setProfile(data.profile);
            })
            .catch((error) => {
                if (error.name !== 'AbortError') setLoadError(true);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
    }, []);

    const updateProfileField = (field, value) => {
        setProfile((current) => ({ ...current, [field]: value }));
        setProfileMessage(null);
        setProfileError(null);
    };

    const handleProfileSubmit = async (event) => {
        event.preventDefault();
        setProfileSaving(true);
        setProfileMessage(null);
        setProfileError(null);

        try {
            const response = await apiFetch('/profile', {
                method: 'PUT',
                body: JSON.stringify({
                    full_name: profile.full_name,
                    email: profile.email,
                    phone: profile.phone,
                    language: profile.language,
                }),
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(firstErrorMessage(data) ?? t.profileForm.profileError);
            }

            setProfile(data.profile);
            setLang(data.profile.language);
            window.dispatchEvent(new CustomEvent('tahfiz:profile-updated', {
                detail: { full_name: data.profile.full_name, email: data.profile.email },
            }));
            setProfileMessage(t.profileForm.profileSaved);
        } catch (error) {
            setProfileError(error.message || t.profileForm.profileError);
        } finally {
            setProfileSaving(false);
        }
    };

    const handlePasswordFieldChange = (field, value) => {
        setPassword((current) => ({ ...current, [field]: value }));
        setPasswordMessage(null);
        setPasswordError(null);
    };

    const handlePasswordSubmit = async (event) => {
        event.preventDefault();
        setPasswordSaving(true);
        setPasswordMessage(null);
        setPasswordError(null);

        try {
            const response = await apiFetch('/profile/password', {
                method: 'PUT',
                body: JSON.stringify(password),
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(firstErrorMessage(data) ?? t.profileForm.passwordError);
            }

            setPassword({ current_password: '', password: '', password_confirmation: '' });
            setPasswordMessage(t.profileForm.passwordSaved);
        } catch (error) {
            setPasswordError(error.message || t.profileForm.passwordError);
        } finally {
            setPasswordSaving(false);
        }
    };

    const handleRecoveryKeyGenerate = async () => {
        setRecoveryKeySaving(true);
        setRecoveryKeyError(null);
        setRecoveryKey('');

        try {
            const response = await apiFetch('/profile/recovery-key', { method: 'POST' });
            const data = await response.json();
            if (!response.ok) {
                throw new Error(firstErrorMessage(data) ?? t.profileForm.recoveryKeyError);
            }

            setRecoveryKey(data.recovery_key);
        } catch (error) {
            setRecoveryKeyError(error.message || t.profileForm.recoveryKeyError);
        } finally {
            setRecoveryKeySaving(false);
        }
    };

    const handleRecoveryKeyDownload = () => {
        const content = [
            t.profileForm.recoveryKeyFileTitle,
            `${t.profileForm.email}: ${profile.email}`,
            `${t.profileForm.recoveryKeyLabel}: ${recoveryKey}`,
            '',
            t.profileForm.recoveryKeyFileWarning,
        ].join('\r\n');
        const file = new Blob([content], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(file);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'imam-muslim-recovery-key.txt';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 0);
    };

    if (loading) {
        return <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">{t.profileForm.loading}</p>;
    }

    if (loadError || !profile) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.profileForm.loadError}</p>;
    }

    return (
        <div className="flex w-full max-w-3xl flex-col gap-5">
            <section className={`${cardClass} flex flex-col gap-4`}>
                <h2 className="text-[16px] leading-6 font-semibold text-on-surface dark:text-inverse-on-surface">
                    {t.profileForm.recoveryKeySection}
                </h2>
                <p className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">
                    {t.profileForm.recoveryKeyDescription}
                </p>
                <div className="flex flex-wrap gap-3">
                    <button className={buttonClass} disabled={recoveryKeySaving} onClick={handleRecoveryKeyGenerate} type="button">
                        {recoveryKeySaving ? t.profileForm.recoveryKeyGenerating : t.profileForm.recoveryKeyGenerate}
                    </button>
                    {recoveryKey && (
                        <button className={buttonClass} onClick={handleRecoveryKeyDownload} type="button">
                            {t.profileForm.recoveryKeyDownload}
                        </button>
                    )}
                </div>
                {recoveryKey && (
                    <div className="flex flex-col gap-2 rounded-md border border-outline-variant bg-surface-container-low p-3 dark:border-tertiary-container dark:bg-inverse-surface">
                        <p className="text-[13px] leading-5 text-on-surface dark:text-inverse-on-surface">
                            {t.profileForm.recoveryKeyOneTimeWarning}
                        </p>
                        <code dir="ltr" className="break-all font-mono text-[13px] text-on-surface dark:text-inverse-on-surface">{recoveryKey}</code>
                    </div>
                )}
                {recoveryKeyError && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{recoveryKeyError}</p>}
            </section>

            <section className={`${cardClass} flex flex-col gap-4`}>
                <h2 className="text-[16px] leading-6 font-semibold text-on-surface dark:text-inverse-on-surface">
                    {t.profileForm.personalInformation}
                </h2>
                <form className="flex flex-col gap-4" onSubmit={handleProfileSubmit}>
                    <label className={labelClass}>
                        {t.profileForm.fullName}
                        <input
                            autoComplete="name"
                            className={inputClass}
                            maxLength={120}
                            onChange={(event) => updateProfileField('full_name', event.target.value)}
                            required
                            value={profile.full_name}
                        />
                    </label>
                    <label className={labelClass}>
                        {t.profileForm.email}
                        <input
                            autoComplete="email"
                            className={inputClass}
                            dir="ltr"
                            maxLength={190}
                            onChange={(event) => updateProfileField('email', event.target.value)}
                            required
                            type="email"
                            value={profile.email}
                        />
                    </label>
                    <label className={labelClass}>
                        {t.profileForm.phone}
                        <input
                            autoComplete="tel"
                            className={inputClass}
                            dir="ltr"
                            maxLength={30}
                            onChange={(event) => updateProfileField('phone', event.target.value)}
                            type="tel"
                            value={profile.phone ?? ''}
                        />
                    </label>
                    <label className={labelClass}>
                        {t.profileForm.language}
                        <SelectInput
                            className={inputClass}
                            onChange={(event) => updateProfileField('language', event.target.value)}
                            value={profile.language}
                        >
                            <option value="fr">{t.profileForm.french}</option>
                            <option value="ar">{t.profileForm.arabic}</option>
                        </SelectInput>
                    </label>
                    {profileError && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{profileError}</p>}
                    {profileMessage && <p role="status" className="text-[13px] leading-5 text-primary dark:text-primary-fixed-dim">{profileMessage}</p>}
                    <button className={buttonClass} disabled={profileSaving} type="submit">
                        {profileSaving ? t.profileForm.saving : t.profileForm.saveProfile}
                    </button>
                </form>
            </section>

            <section className={`${cardClass} flex flex-col gap-4`}>
                <h2 className="text-[16px] leading-6 font-semibold text-on-surface dark:text-inverse-on-surface">
                    {t.profileForm.passwordSection}
                </h2>
                <form className="flex flex-col gap-4" onSubmit={handlePasswordSubmit}>
                    <label className={labelClass}>
                        {t.profileForm.currentPassword}
                        <input
                            autoComplete="current-password"
                            className={inputClass}
                            maxLength={255}
                            onChange={(event) => handlePasswordFieldChange('current_password', event.target.value)}
                            required
                            type="password"
                            value={password.current_password}
                        />
                    </label>
                    <label className={labelClass}>
                        {t.profileForm.newPassword}
                        <input
                            autoComplete="new-password"
                            className={inputClass}
                            maxLength={255}
                            minLength={12}
                            onChange={(event) => handlePasswordFieldChange('password', event.target.value)}
                            required
                            type="password"
                            value={password.password}
                        />
                        <span className="text-[12px] leading-5 font-normal text-on-surface-variant dark:text-tertiary-fixed-dim">
                            {t.profileForm.passwordHint}
                        </span>
                    </label>
                    <label className={labelClass}>
                        {t.profileForm.confirmPassword}
                        <input
                            autoComplete="new-password"
                            className={inputClass}
                            maxLength={255}
                            minLength={12}
                            onChange={(event) => handlePasswordFieldChange('password_confirmation', event.target.value)}
                            required
                            type="password"
                            value={password.password_confirmation}
                        />
                    </label>
                    {passwordError && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{passwordError}</p>}
                    {passwordMessage && <p role="status" className="text-[13px] leading-5 text-primary dark:text-primary-fixed-dim">{passwordMessage}</p>}
                    <button className={buttonClass} disabled={passwordSaving} type="submit">
                        {passwordSaving ? t.profileForm.saving : t.profileForm.savePassword}
                    </button>
                </form>
            </section>
        </div>
    );
}
