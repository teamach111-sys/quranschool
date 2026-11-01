import React, { useEffect, useState } from 'react';
import Icon from '../../components/Icon';
import { apiFetch } from '../../lib/api';
import { ACADEMIC_YEARS_UPDATED_EVENT } from '../../persisters/yearselector';
import { useLanguage } from '../../hooks/useLanguage';
import AcademicYearFormModal from './components/AcademicYearFormModal';

export default function AnneesScolaires() {
    const { lang, t } = useLanguage();
    const [years, setYears] = useState([]);
    const [canManage, setCanManage] = useState(false);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [updateError, setUpdateError] = useState(false);
    const [workingYearId, setWorkingYearId] = useState(null);
    const [formOpen, setFormOpen] = useState(false);
    const [editingYear, setEditingYear] = useState(null);
    const locale = lang === 'ar' ? 'ar-MA' : 'fr-MA';

    useEffect(() => {
        let cancelled = false;

        apiFetch('/academic-years/table')
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Failed to load academic years'))))
            .then((data) => {
                if (cancelled) return;
                setYears(Array.isArray(data.years) ? data.years : []);
                setCanManage(Boolean(data.can_manage));
            })
            .catch(() => {
                if (!cancelled) setLoadError(true);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => { cancelled = true; };
    }, []);

    const updateYear = async (year, action) => {
        setWorkingYearId(year.id);
        setUpdateError(false);

        try {
            const response = await apiFetch(`/admin/academic-years/${year.id}/status`, {
                method: 'PATCH',
                body: JSON.stringify({ action }),
            });
            const data = await response.json();

            if (!response.ok) throw new Error('Failed to update academic year');

            setYears(Array.isArray(data.years) ? data.years : []);
            setCanManage(Boolean(data.can_manage));
            window.dispatchEvent(new Event(ACADEMIC_YEARS_UPDATED_EVENT));
        } catch {
            setUpdateError(true);
        } finally {
            setWorkingYearId(null);
        }
    };

    const closeForm = () => {
        setFormOpen(false);
        setEditingYear(null);
    };

    const handleFormSaved = (data) => {
        setYears(Array.isArray(data.years) ? data.years : []);
        setCanManage(Boolean(data.can_manage));
        closeForm();
        window.dispatchEvent(new Event(ACADEMIC_YEARS_UPDATED_EVENT));
    };

    const formatDate = (date) => new Intl.DateTimeFormat(locale, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    }).format(new Date(`${date}T12:00:00`));

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.academicYears.loadError}</p>;
    }

    if (loading) {
        return <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">{t.academicYears.loading}</p>;
    }

    return (
        <div className="flex w-full flex-col gap-3">
            {updateError && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.academicYears.updateError}</p>}
            {canManage && (
                <div className="flex justify-end">
                    <button type="button" onClick={() => { setEditingYear(null); setFormOpen(true); }} className="inline-flex min-h-11 items-center gap-2 rounded-md bg-primary px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:bg-primary-container">
                        <Icon name="add" className="text-[18px]" />
                        {t.academicYears.create}
                    </button>
                </div>
            )}
            {years.length === 0 ? (
                <p className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">{t.academicYears.empty}</p>
            ) : (
                <ul className="flex w-full flex-col gap-2" aria-label={t.academicYears.listLabel}>
                    {years.map((year) => (
                        <li key={year.id} aria-current={year.is_current ? 'true' : undefined} className="flex min-w-0 flex-col gap-3 rounded-md border border-outline-variant bg-surface-container-lowest p-3 dark:border-tertiary-container dark:bg-inverse-surface sm:flex-row sm:items-center sm:justify-between sm:px-4">
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="text-[15px] leading-5 font-semibold text-on-surface dark:text-inverse-on-surface">{year.label}</h2>
                                    {year.is_current && <span className="rounded-sm bg-primary-fixed px-2 py-1 text-[11px] leading-4 font-medium text-on-primary-fixed-variant dark:bg-primary-container dark:text-on-primary-container">{t.academicYears.current}</span>}
                                    <span className={`rounded-sm px-2 py-1 text-[11px] leading-4 font-medium ${year.is_active ? 'bg-surface-container text-on-surface-variant dark:bg-tertiary-container dark:text-on-tertiary-container' : 'bg-secondary-container text-on-secondary-container dark:bg-secondary dark:text-on-secondary'}`}>
                                        {year.is_active ? t.academicYears.openState : t.academicYears.closedState}
                                    </span>
                                    {year.is_read_only && <span className="rounded-sm bg-surface-container-high px-2 py-1 text-[11px] leading-4 font-medium text-on-surface-variant dark:bg-tertiary-container dark:text-on-tertiary-container">{t.academicYears.readOnly}</span>}
                                </div>
                                <p className="mt-1 text-[12px] leading-4 text-on-surface-variant dark:text-tertiary-fixed-dim">
                                    {formatDate(year.start_date)} – {formatDate(year.end_date)}
                                </p>
                            </div>
                            {canManage && (
                                <div className="flex shrink-0 flex-wrap items-center gap-2">
                                    {year.is_active && (
                                        <button type="button" disabled={workingYearId !== null} onClick={() => { setEditingYear(year); setFormOpen(true); }} className="inline-flex min-h-10 items-center gap-1.5 rounded-md border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] leading-4 font-medium text-on-surface-variant transition-colors hover:bg-surface-container-high disabled:cursor-not-allowed disabled:opacity-60 dark:border-tertiary-container dark:bg-inverse-surface dark:text-on-tertiary-container dark:hover:bg-tertiary-container">
                                            <Icon name="edit" className="text-[16px]" />
                                            {t.academicYears.edit}
                                        </button>
                                    )}
                                    {!year.is_active && (
                                        <button type="button" disabled={workingYearId !== null} onClick={() => updateYear(year, 'open')} className="inline-flex min-h-10 items-center gap-1.5 rounded-md border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] leading-4 font-medium text-on-surface-variant transition-colors hover:bg-surface-container-high disabled:cursor-not-allowed disabled:opacity-60 dark:border-tertiary-container dark:bg-inverse-surface dark:text-on-tertiary-container dark:hover:bg-tertiary-container">
                                            <Icon name="lock_open" className="text-[16px]" />
                                            {t.academicYears.open}
                                        </button>
                                    )}
                                    {year.is_active && !year.is_current && (
                                        <button type="button" disabled={workingYearId !== null} onClick={() => updateYear(year, 'close')} className="inline-flex min-h-10 items-center gap-1.5 rounded-md border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] leading-4 font-medium text-on-surface-variant transition-colors hover:bg-surface-container-high disabled:cursor-not-allowed disabled:opacity-60 dark:border-tertiary-container dark:bg-inverse-surface dark:text-on-tertiary-container dark:hover:bg-tertiary-container">
                                            <Icon name="lock" className="text-[16px]" />
                                            {t.academicYears.close}
                                        </button>
                                    )}
                                    {!year.is_current && (
                                        <button type="button" disabled={workingYearId !== null} onClick={() => updateYear(year, 'set_current')} className="inline-flex min-h-10 items-center gap-1.5 rounded-md bg-primary px-3 py-2 text-[12px] leading-4 font-semibold text-on-primary transition-colors hover:bg-primary-container disabled:cursor-not-allowed disabled:opacity-60">
                                            <Icon name="event_available" className="text-[16px]" />
                                            {t.academicYears.setCurrent}
                                        </button>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
                {canManage && (
                    <AcademicYearFormModal
                        open={formOpen}
                        year={editingYear}
                        onClose={closeForm}
                        onSaved={handleFormSaved}
                    />
                )}
        </div>
    );
}
