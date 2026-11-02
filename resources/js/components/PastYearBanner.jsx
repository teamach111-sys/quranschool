import React, { useEffect, useState } from 'react';
import Icon from './Icon';
import { useLanguage } from '../hooks/useLanguage';
import { apiFetch } from '../lib/api';
import {
    ACADEMIC_YEAR_CHANGED_EVENT,
    ACADEMIC_YEARS_UPDATED_EVENT,
    getSelectedAcademicYearId,
    getSelectedAcademicYearIsReadOnly,
    selectAcademicYear,
} from '../persisters/yearselector';

export default function PastYearBanner({ visible }) {
    const { isRTL, t } = useLanguage();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [isReadOnly, setIsReadOnly] = useState(getSelectedAcademicYearIsReadOnly);
    const [years, setYears] = useState([]);
    const [loadError, setLoadError] = useState(false);

    useEffect(() => {
        const syncYear = (event) => {
            setYearId(Number(event?.detail?.id ?? getSelectedAcademicYearId()));
            setIsReadOnly(typeof event?.detail?.isReadOnly === 'boolean'
                ? event.detail.isReadOnly
                : getSelectedAcademicYearIsReadOnly());
        };
        const onStorage = (event) => {
            if (event.key === 'tahfiz-academic-year') syncYear();
        };

        window.addEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
        window.addEventListener('storage', onStorage);
        return () => {
            window.removeEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
            window.removeEventListener('storage', onStorage);
        };
    }, []);

    useEffect(() => {
        let cancelled = false;

        const loadYears = async () => {
            setLoadError(false);
            try {
                const response = await apiFetch('/academic-years');
                if (!response.ok) throw new Error('Failed to load academic years');
                const data = await response.json();

                if (!cancelled) setYears(Array.isArray(data.years) ? data.years : []);
            } catch {
                if (!cancelled) setLoadError(true);
            }
        };

        loadYears();
        window.addEventListener(ACADEMIC_YEARS_UPDATED_EVENT, loadYears);
        return () => {
            cancelled = true;
            window.removeEventListener(ACADEMIC_YEARS_UPDATED_EVENT, loadYears);
        };
    }, []);

    if (!visible || !isReadOnly) return null;

    const selectedYear = years.find((year) => Number(year.id) === yearId);
    const currentYear = years.find((year) => year.is_current);

    return (
        <aside
            role="status"
            dir={isRTL ? 'rtl' : 'ltr'}
            className="flex flex-col gap-3 rounded-lg border border-secondary/30 bg-secondary-container px-3 py-3 text-on-secondary-container dark:border-secondary-fixed-dim/30 dark:bg-[#3b321e] dark:text-[#ffdeaa] sm:flex-row sm:items-center sm:justify-between"
        >
            <div className="flex items-start gap-2">
                <Icon name="lock" className="mt-0.5 shrink-0 text-[18px]" />
                <p className="text-[13px] leading-5 font-medium">
                    {t.yearSelector.pastYearBanner(selectedYear?.label ?? '')}
                </p>
            </div>
            {currentYear ? (
                <button
                    type="button"
                    onClick={() => selectAcademicYear(currentYear)}
                    className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-md bg-primary-container px-3 py-2 text-[12px] font-semibold text-on-primary transition-colors hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:bg-primary-container dark:text-on-primary"
                >
                    <Icon name={isRTL ? 'arrow_forward' : 'arrow_back'} className="text-[17px]" />
                    {t.yearSelector.returnToCurrent}
                </button>
            ) : (
                <p role={loadError ? 'alert' : 'status'} className="text-[12px] leading-5">
                    {t.yearSelector.currentUnavailable}
                </p>
            )}
        </aside>
    );
}
