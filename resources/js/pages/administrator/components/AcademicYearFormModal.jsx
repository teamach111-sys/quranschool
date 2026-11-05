import React, { useEffect, useId, useRef, useState } from 'react';
import Icon from '../../../components/Icon';
import DateInput from '../../../components/DateInput';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';

function initialValues(year) {
    return {
        year_label: year?.label ?? '',
        start_date: year?.start_date ?? '',
        end_date: year?.end_date ?? '',
        is_active: Boolean(year?.is_active ?? true),
        is_current: Boolean(year?.is_current),
    };
}

export default function AcademicYearFormModal({ open, year = null, onClose, onSaved }) {
    const { isRTL, t } = useLanguage();
    const [form, setForm] = useState(() => initialValues(year));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const dialogId = useId();
    const dialogRef = useRef(null);
    const isEditing = Boolean(year);

    useEffect(() => {
        if (!open) return undefined;

        setForm(initialValues(year));
        setError(null);

        const previousOverflow = document.body.style.overflow;
        const onKeyDown = (event) => {
            if (event.key === 'Escape' && !saving) onClose();
        };

        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKeyDown);
        dialogRef.current?.focus();

        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [open, year, onClose, saving]);

    const handleChange = (event) => {
        const { name, type, checked, value } = event.target;
        const nextValue = type === 'checkbox' ? checked : value;

        setForm((current) => ({
            ...current,
            [name]: nextValue,
            ...(name === 'is_current' && checked ? { is_active: true } : {}),
        }));
        setError(null);
    };

    const handleSubmit = async (event) => {
        event.preventDefault();

        if (form.end_date < form.start_date) {
            setError(t.academicYears.dateOrderError);
            return;
        }

        setSaving(true);
        setError(null);

        try {
            const response = await apiFetch(
                isEditing ? `/admin/academic-years/${year.id}` : '/admin/academic-years',
                {
                    method: isEditing ? 'PUT' : 'POST',
                    body: JSON.stringify({
                        ...form,
                        is_active: form.is_active || form.is_current,
                    }),
                }
            );
            const data = await response.json();

            if (!response.ok) {
                const errors = data?.errors ?? {};
                if (errors.year_label) setError(t.academicYears.duplicateLabelError);
                else if (errors.start_date || errors.end_date) setError(t.academicYears.dateOrderError);
                else setError(t.academicYears.formSaveError);
                return;
            }

            onSaved(data);
        } catch {
            setError(t.academicYears.formSaveError);
        } finally {
            setSaving(false);
        }
    };

    if (!open) return null;

    const inputClassName = 'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] leading-5 text-on-surface outline-none transition-colors focus:border-primary focus:bg-surface-container-lowest focus:ring-1 focus:ring-primary dark:border-[#384d43] dark:bg-[#182620] dark:text-[#ecf3ef] dark:focus:border-primary-fixed-dim dark:focus:bg-[#202f29] dark:focus:ring-primary-fixed-dim';
    const title = isEditing ? t.academicYears.formEditTitle : t.academicYears.formCreateTitle;

    return (
        <div
            className="fixed inset-0 z-[70] flex items-center justify-center bg-on-background/40 p-3 sm:p-5"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget && !saving) onClose();
            }}
        >
            <section
                ref={dialogRef}
                id={dialogId}
                role="dialog"
                aria-modal="true"
                aria-labelledby={`${dialogId}-title`}
                tabIndex={-1}
                dir={isRTL ? 'rtl' : 'ltr'}
                className="max-h-[calc(100vh-1.5rem)] w-full max-w-[500px] overflow-y-auto rounded-xl border border-outline-variant bg-surface-container-lowest p-5 shadow-ambient outline-none dark:border-[#384d43] dark:bg-[#202f29] dark:shadow-2xl sm:p-7"
            >
                <form className="relative flex flex-col gap-5" onSubmit={handleSubmit} aria-busy={saving}>
                    <header className="flex items-start justify-between gap-4">
                        <div className="flex min-w-0 items-start gap-3">
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-fixed text-primary dark:bg-primary-container dark:text-on-primary-container">
                                <Icon name="calendar_month" className="text-[20px]" />
                            </span>
                            <h2 id={`${dialogId}-title`} className="pt-1 text-[18px] leading-6 font-semibold text-on-surface dark:text-inverse-on-surface">
                                {title}
                            </h2>
                        </div>
                        <button
                            type="button"
                            aria-label={t.academicYears.cancel}
                            disabled={saving}
                            onClick={onClose}
                            className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-surface-container-low text-on-surface-variant transition-colors hover:bg-surface-container dark:bg-[#182620] dark:text-[#9bb2a8] dark:hover:bg-[#2c3d36]"
                        >
                            <Icon name="close" className="text-[18px]" />
                        </button>
                    </header>

                    <div className="flex flex-col gap-4">
                        <div className="flex flex-col gap-1.5">
                            <label htmlFor={`${dialogId}-year-label`} className="text-[12px] leading-4 font-semibold text-on-surface dark:text-inverse-on-surface">
                                {t.academicYears.yearLabel}
                            </label>
                            <input
                                id={`${dialogId}-year-label`}
                                name="year_label"
                                type="text"
                                required
                                maxLength={20}
                                value={form.year_label}
                                onChange={handleChange}
                                className={inputClassName}
                            />
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <label htmlFor={`${dialogId}-start-date`} className="text-[12px] leading-4 font-semibold text-on-surface dark:text-inverse-on-surface">
                                    {t.academicYears.startDate}
                                </label>
                                <DateInput
                                    id={`${dialogId}-start-date`}
                                    name="start_date"
                                    required
                                    value={form.start_date}
                                    onChange={handleChange}
                                    className={inputClassName}
                                />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <label htmlFor={`${dialogId}-end-date`} className="text-[12px] leading-4 font-semibold text-on-surface dark:text-inverse-on-surface">
                                    {t.academicYears.endDate}
                                </label>
                                <DateInput
                                    id={`${dialogId}-end-date`}
                                    name="end_date"
                                    required
                                    value={form.end_date}
                                    onChange={handleChange}
                                    className={inputClassName}
                                />
                            </div>
                        </div>

                        <div className="flex flex-col gap-3 rounded-lg border border-outline-variant bg-surface-container-low p-3.5 dark:border-[#384d43] dark:bg-[#182620]">
                            <label className="flex min-h-11 items-center gap-3 text-[13px] leading-5 text-on-surface dark:text-inverse-on-surface">
                                <input
                                    name="is_active"
                                    type="checkbox"
                                    checked={form.is_active}
                                    disabled={form.is_current}
                                    onChange={handleChange}
                                    className="size-5 shrink-0 accent-primary dark:accent-primary-fixed-dim"
                                />
                                {t.academicYears.isActive}
                            </label>
                            <label className="flex min-h-11 items-center gap-3 text-[13px] leading-5 text-on-surface dark:text-inverse-on-surface">
                                <input
                                    name="is_current"
                                    type="checkbox"
                                    checked={form.is_current}
                                    disabled={Boolean(year?.is_current)}
                                    onChange={handleChange}
                                    className="size-5 shrink-0 accent-primary dark:accent-primary-fixed-dim"
                                />
                                {t.academicYears.isCurrent}
                            </label>
                        </div>

                        {error && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{error}</p>}
                    </div>

                    <footer className="flex flex-col gap-3 border-t border-outline-variant pt-4 dark:border-[#384d43] sm:flex-row">
                        <button
                            type="button"
                            disabled={saving}
                            onClick={onClose}
                            className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:cursor-not-allowed disabled:opacity-60 dark:bg-[#2c3d36] dark:text-[#ecf3ef] dark:hover:bg-[#344a41]"
                        >
                            {t.academicYears.cancel}
                        </button>
                        <button
                            type="submit"
                            disabled={saving}
                            className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-[#2e5e4e] dark:text-[#f0fcf3] dark:hover:bg-[#3e7b67]"
                        >
                            <Icon name={saving ? 'progress_activity' : 'save'} className={`text-[18px] ${saving ? 'animate-spin' : ''}`} />
                            {saving ? t.academicYears.saving : t.academicYears.save}
                        </button>
                    </footer>
                    {saving && (
                        <div
                            role="status"
                            aria-label={t.academicYears.saving}
                            className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 rounded-xl bg-surface-container-lowest/90 text-on-surface-variant dark:bg-[#202f29]/90 dark:text-tertiary-fixed-dim"
                        >
                            <Icon name="progress_activity" className="animate-spin text-[32px] text-primary" />
                            <span className="text-center text-[13px] leading-5">{t.academicYears.saving}</span>
                        </div>
                    )}
                </form>
            </section>
        </div>
    );
}