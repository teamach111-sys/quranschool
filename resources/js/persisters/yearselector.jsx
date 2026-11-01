import React, { useCallback, useEffect, useId, useRef, useState } from 'react';
import Icon from '../components/Icon';
import { useLanguage } from '../hooks/useLanguage';
import { apiFetch } from '../lib/api';

const STORAGE_KEY = 'tahfiz-academic-year';
const READ_ONLY_STORAGE_KEY = `${STORAGE_KEY}:read-only`;
const CHANGE_EVENT = 'tahfiz:academic-year-changed';

function readStoredId() {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const id = raw === null ? null : Number(raw);
        return Number.isFinite(id) ? id : null;
    } catch {
        return null;
    }
}

function readStoredReadOnly() {
    try {
        return window.localStorage.getItem(READ_ONLY_STORAGE_KEY) === 'true';
    } catch {
        return false;
    }
}

function writeStoredYear(year) {
    const isReadOnly = Boolean(year.is_read_only);
    try {
        window.localStorage.setItem(STORAGE_KEY, String(year.id));
        window.localStorage.setItem(READ_ONLY_STORAGE_KEY, String(isReadOnly));
    } catch {
        // Storage may be unavailable (private mode); the in-memory state still works.
    }
    window.dispatchEvent(new CustomEvent(CHANGE_EVENT, {
        detail: { id: year.id, isReadOnly },
    }));
}

/** Read the persisted academic year id (for pages that need to filter by year). */
export function getSelectedAcademicYearId() {
    return readStoredId();
}

/** Read whether the selected year is a read-only historical year. */
export function getSelectedAcademicYearIsReadOnly() {
    return readStoredReadOnly();
}

/**
 * Persistent academic year selector.
 *
 * Years come from the `academic_years` table (is_active rows, `is_current`
 * marks the default). The chosen year is stored in localStorage so it
 * survives reloads and is shared by every selector instance in the layout.
 */
export default function YearSelector({ compact = false }) {
    const { isRTL, t } = useLanguage();
    const [years, setYears] = useState([]);
    const [yearId, setYearId] = useState(readStoredId);
    const [isReadOnly, setIsReadOnly] = useState(readStoredReadOnly);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [open, setOpen] = useState(false);
    const ref = useRef(null);
    const listboxId = useId();

    useEffect(() => {
        const onClick = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
        const onKey = (e) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('click', onClick);
        document.addEventListener('keydown', onKey);
        return () => { document.removeEventListener('click', onClick); document.removeEventListener('keydown', onKey); };
    }, []);

    useEffect(() => {
        let cancelled = false;

        apiFetch('/academic-years')
            .then((res) => (res.ok ? res.json() : Promise.reject(new Error('Failed to load academic years'))))
            .then((data) => {
                if (cancelled) return;

                const list = Array.isArray(data?.years) ? data.years : [];
                const stored = readStoredId();
                const fallback = list.find((year) => year.id === Number(data?.current_id))
                    ?? list.find((year) => year.is_current)
                    ?? list[0]
                    ?? null;
                const selectedYear = list.find((year) => year.id === stored) ?? fallback;
                const next = selectedYear?.id ?? null;
                const nextIsReadOnly = Boolean(selectedYear?.is_read_only);

                setYears(list);
                setYearId(next);
                setIsReadOnly(nextIsReadOnly);
                if (next !== null && (next !== stored || nextIsReadOnly !== readStoredReadOnly())) {
                    writeStoredYear(selectedYear);
                }
            })
            .catch(() => {
                setLoadError(true);
            })
            .finally(() => { if (!cancelled) setLoading(false); });

        return () => { cancelled = true; };
    }, []);

    // Keep every selector instance in the layout (desktop + mobile) in sync.
    useEffect(() => {
        const sync = (event) => {
            const id = event?.detail?.id ?? readStoredId();
            if (id !== null && id !== undefined) {
                setYearId(Number(id));
                setIsReadOnly(typeof event?.detail?.isReadOnly === 'boolean'
                    ? event.detail.isReadOnly
                    : readStoredReadOnly());
            }
        };
        const onStorage = (event) => { if (event.key === STORAGE_KEY) sync(); };

        window.addEventListener(CHANGE_EVENT, sync);
        window.addEventListener('storage', onStorage);
        return () => {
            window.removeEventListener(CHANGE_EVENT, sync);
            window.removeEventListener('storage', onStorage);
        };
    }, []);

    const selectYear = useCallback((year) => {
        writeStoredYear(year);
        setYearId(year.id);
        setIsReadOnly(Boolean(year.is_read_only));
        setOpen(false);
    }, []);

    const selected = years.find((year) => year.id === yearId) ?? null;
    const label = selected?.label ?? (loading ? '…' : '—');
    const range = selected?.start_date && selected?.end_date ? `${selected.start_date} → ${selected.end_date}` : undefined;
    const buttonLabel = selected
        ? `${t.yearSelector.label}: ${label}${isReadOnly ? `, ${t.yearSelector.readOnly}` : ''}`
        : t.yearSelector.label;

    const dropdownSide = isRTL ? 'right-0 origin-top-right' : 'left-0 origin-top-left';
    const textAlign = isRTL ? 'text-right' : 'text-left';

    return (
        <div ref={ref} className="relative shrink-0">
            <button type="button" aria-label={buttonLabel} aria-controls={listboxId} aria-expanded={open} aria-haspopup="listbox" title={isReadOnly ? t.yearSelector.readOnly : range} onClick={() => setOpen(o => !o)}
                className={`flex items-center border border-outline-variant dark:border-[#2f4239] bg-surface-container-low dark:bg-[#182620] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] rounded gap-1 h-6 sm:h-8 transition-colors cursor-pointer select-none ${compact ? 'px-1.5 py-0.5' : 'px-3'}`}
            >
                <Icon name="date_range" className="text-[13px] sm:text-[16px] text-on-surface-variant dark:text-[#9bb2a8]" />
                <span className="font-mono text-[10px] sm:text-[12px] leading-4 text-on-surface dark:text-[#ecf3ef] whitespace-nowrap">{label}</span>
                {isReadOnly && <Icon name="lock" className="text-[11px] text-secondary dark:text-secondary-fixed-dim" />}
                <Icon name="arrow_drop_down" className={`text-[12px] sm:text-[14px] text-outline dark:text-[#9bb2a8] transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
            </button>
            <div id={listboxId} role="listbox" aria-label={t.yearSelector.label} aria-hidden={!open} className={`absolute ${dropdownSide} top-full mt-1 w-52 bg-surface-container-lowest dark:bg-[#202f29] border border-outline-variant dark:border-[#2f4239] rounded shadow-lg z-50 py-1 flex flex-col transition-all duration-200 ease-out ${open ? 'opacity-100 pointer-events-auto scale-100 translate-y-0' : 'opacity-0 pointer-events-none scale-95 -translate-y-1'}`}>
                {years.length === 0 && (
                    <div role={loadError ? 'alert' : 'status'} className={`px-3 py-2 ${textAlign} text-[11px] leading-4 text-on-surface-variant dark:text-[#9bb2a8]`}>
                        {loading ? t.yearSelector.loading : loadError ? t.yearSelector.unavailable : t.yearSelector.empty}
                    </div>
                )}
                {years.map((year) => (
                    <button key={year.id} type="button" role="option" tabIndex={open ? 0 : -1} aria-selected={year.id === selected?.id}
                        onClick={() => selectYear(year)}
                        className={`flex items-center justify-between gap-2 px-3 py-1.5 ${textAlign} font-mono text-[11px] leading-4 text-on-surface dark:text-[#ecf3ef] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] transition-colors ${year.id === selected?.id ? 'font-medium bg-surface-container-low dark:bg-[#182620]' : ''}`}
                    >
                        <span className="truncate">{year.label}</span>
                        <span className="flex items-center gap-1 shrink-0">
                            {year.is_current && <span className="text-[9px] text-primary dark:text-[#9fd1bd]">{t.yearSelector.current}</span>}
                            {year.is_read_only && <span className="text-[9px] text-secondary dark:text-secondary-fixed-dim">{t.yearSelector.readOnly}</span>}
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}
