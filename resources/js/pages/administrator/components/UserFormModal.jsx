import React, { useCallback, useEffect, useState } from 'react';
import Icon from '../../../components/Icon';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';

function initialValues(user) {
    return {
        full_name: user?.full_name ?? '',
        email: user?.email ?? '',
        password: '',
        password_confirmation: '',
        phone: user?.phone ?? '',
        language: user?.language ?? 'fr',
        role_id: user ? String(user.role_id) : '',
        is_active: user?.is_active ?? true,
        reason: '',
    };
}

export default function UserFormModal({ open, user = null, roles = [], onClose, onSaved }) {
    const { isRTL, lang, t } = useLanguage();
    const [form, setForm] = useState(() => initialValues(user));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const isEditing = Boolean(user?.id);
    const roleChanged = isEditing && String(user.role_id) !== form.role_id;
    const handleClose = useCallback(() => {
        if (!saving) onClose();
    }, [onClose, saving]);

    useEffect(() => {
        if (!open) return;

        setForm(initialValues(user));
        setError('');
    }, [open, user]);

    const handleChange = (event) => {
        const { name, value, checked, type } = event.target;
        setForm((current) => ({
            ...current,
            [name]: type === 'checkbox' ? checked : value,
            ...(name === 'role_id' ? { reason: '' } : {}),
        }));
        setError('');
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        if (form.password && form.password !== form.password_confirmation) {
            setError(t.users.passwordValidationError);
            return;
        }

        setSaving(true);
        setError('');

        const payload = {
            full_name: form.full_name,
            email: form.email,
            phone: form.phone || null,
            language: form.language,
            role_id: Number(form.role_id),
            is_active: form.is_active,
            reason: form.reason || null,
        };
        if (form.password) {
            payload.password = form.password;
            payload.password_confirmation = form.password_confirmation;
        }

        try {
            const response = await apiFetch(
                isEditing ? `/admin/users/${user.id}` : '/admin/users',
                {
                    method: isEditing ? 'PUT' : 'POST',
                    body: JSON.stringify(payload),
                },
            );
            const data = await response.json();

            if (!response.ok) {
                const errors = data?.errors ?? {};
                if (errors.full_name) setError(t.users.nameValidationError);
                else if (errors.email) setError(t.users.emailValidationError);
                else if (errors.password || errors.password_confirmation) setError(t.users.passwordValidationError);
                else if (errors.phone) setError(t.users.phoneValidationError);
                else if (errors.language) setError(t.users.languageValidationError);
                else if (errors.role_id) setError(t.users.roleValidationError);
                else if (errors.reason) setError(t.users.reasonValidationError);
                else if (response.status === 422) setError(t.users.protectedAdminError);
                else setError(t.users.saveError);
                return;
            }

            setForm(initialValues(null));
            await onSaved(data.user_id);
        } catch {
            setError(t.users.saveError);
        } finally {
            setSaving(false);
        }
    };

    const locale = lang === 'ar' ? 'ar' : 'fr';
    const inputClass = 'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] leading-5 text-on-surface outline-none transition-colors focus:border-primary focus:bg-surface-container-lowest focus:ring-1 focus:ring-primary disabled:cursor-not-allowed disabled:opacity-70 dark:border-[#384d43] dark:bg-[#182620] dark:text-[#ecf3ef] dark:focus:border-primary-fixed-dim dark:focus:bg-[#202f29] dark:focus:ring-primary-fixed-dim';
    const labelClass = 'flex flex-col gap-1.5 text-[12px] leading-4 font-semibold text-on-surface dark:text-inverse-on-surface';
    const title = isEditing ? t.users.editTitle : t.users.createTitle;

    return (
        <Modal
            open={open}
            title={title}
            icon="manage_accounts"
            onClose={handleClose}
            isRTL={isRTL}
            closeLabel={t.users.cancel}
            maxWidth="max-w-[680px]"
        >
            <form onSubmit={handleSubmit} className="flex flex-col gap-5">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <label className={labelClass}>
                        {t.users.columns.name}
                        <input
                            required
                            maxLength={120}
                            autoComplete="name"
                            value={form.full_name}
                            name="full_name"
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        {t.users.columns.email}
                        <input
                            required
                            type="email"
                            maxLength={190}
                            autoComplete="email"
                            value={form.email}
                            name="email"
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        {t.users.columns.phone}
                        <input
                            type="tel"
                            maxLength={30}
                            autoComplete="tel"
                            value={form.phone}
                            name="phone"
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        {t.users.columns.language}
                        <select
                            required
                            value={form.language}
                            name="language"
                            onChange={handleChange}
                            className={inputClass}
                        >
                            {Object.entries(t.users.languages).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </select>
                    </label>
                    <label className={labelClass}>
                        {t.users.columns.role}
                        <select
                            required
                            disabled={user?.is_self}
                            value={form.role_id}
                            name="role_id"
                            onChange={handleChange}
                            className={inputClass}
                        >
                            {!isEditing && <option value="">{t.users.selectRole}</option>}
                            {roles.map((role) => (
                                <option key={role.id} value={role.id}>
                                    {locale === 'ar' ? role.name_ar : role.name_fr}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex min-h-10 items-center gap-2 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        <input
                            type="checkbox"
                            name="is_active"
                            checked={form.is_active}
                            disabled={user?.is_self}
                            onChange={handleChange}
                            className="size-4 accent-primary"
                        />
                        {t.users.active}
                    </label>
                    <label className={labelClass}>
                        {isEditing ? t.users.newPasswordOptional : t.users.newPassword}
                        <input
                            required={!isEditing}
                            minLength={12}
                            maxLength={255}
                            type="password"
                            autoComplete="new-password"
                            value={form.password}
                            name="password"
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        {t.users.confirmPassword}
                        <input
                            required={!isEditing || form.password !== ''}
                            minLength={12}
                            maxLength={255}
                            type="password"
                            autoComplete="new-password"
                            value={form.password_confirmation}
                            name="password_confirmation"
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </label>
                    {roleChanged && !user?.is_self && (
                        <label className={`${labelClass} sm:col-span-2`}>
                            {t.users.reason}
                            <textarea
                                required
                                maxLength={255}
                                rows={2}
                                value={form.reason}
                                name="reason"
                                onChange={handleChange}
                                className={`${inputClass} resize-y`}
                            />
                        </label>
                    )}
                </div>
                <p className="text-[11px] leading-4 text-on-surface-variant dark:text-inverse-on-surface">
                    {t.users.passwordHint}
                </p>
                {error && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{error}</p>}
                <footer className="flex flex-col-reverse gap-3 border-t border-outline-variant pt-4 dark:border-outline-variant sm:flex-row">
                    <button
                        type="button"
                        disabled={saving}
                        onClick={handleClose}
                        className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:opacity-60 dark:bg-inverse-surface dark:text-inverse-on-surface dark:hover:bg-tertiary-container"
                    >
                        {t.users.cancel}
                    </button>
                    <button
                        type="submit"
                        disabled={saving || !form.role_id || (form.password !== '' && !form.password_confirmation)}
                        className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-primary-container dark:text-on-primary"
                    >
                        <Icon name={saving ? 'progress_activity' : 'save'} className={`text-[18px] ${saving ? 'animate-spin' : ''}`} />
                        {saving ? t.users.saving : t.users.save}
                    </button>
                </footer>
            </form>
        </Modal>
    );
}
