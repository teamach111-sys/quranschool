import React, { useCallback, useEffect, useRef, useState } from 'react';
import Icon from '../components/Icon';
import { useLanguage } from '../hooks/useLanguage';
import { apiFetch } from '../lib/api';

const STORAGE_KEY = 'tahfiz-academic-year';
const CHANGE_EVENT = 'tahfiz:academic-year-changed';

function readStoredId() {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        const id = raw === null ? null : Number(raw);
        return Number.isFinite(id) ? id : null;
    } catch {
        return null;
    }
}

function writeStoredId(id) {
    try {
        window.localStorage.setItem(STORAGE_KEY, String(id));
    } catch {
        // Storage may be unavailable (private mode); the in-memory state still works.
    }
    window.dispatchEvent(new CustomEvent(CHANGE_EVENT, { detail: { id } }));
}

/** Read the persisted academic year id (for pages that need to filter by year). */
export function getSelectedAcademicYearId() {
    return readStoredId();
}

/**
 * Persistent academic year selector.
 *
 * Years come from the `academic_years` table (is_active rows, `is_current`
 * marks the default). The chosen year is stored in localStorage so it
 * survives reloads and is shared by every selector instance in the layout.
 */
export default function YearSelector({ compact = false }) {
    const { isRTL } = useLanguage();
    const [years, setYears] = useState([]);
    const [yearId, setYearId] = useState(readStoredId);
    const [loading, setLoading] = useState(true);
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

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
                const fallback = list.find((year) => year.is_current) ?? list[0] ?? null;
                const next = list.some((year) => year.id === stored) ? stored : fallback?.id ?? null;

                setYears(list);
                setYearId(next);
                if (next !== null && next !== stored) writeStoredId(next);
            })
            .catch(() => {
                // Keep the selector usable even if the request fails.
            })
            .finally(() => { if (!cancelled) setLoading(false); });

        return () => { cancelled = true; };
    }, []);

    // Keep every selector instance in the layout (desktop + mobile) in sync.
    useEffect(() => {
        const sync = (event) => {
            const id = event?.detail?.id ?? readStoredId();
            if (id !== null && id !== undefined) setYearId(Number(id));
        };
        const onStorage = (event) => { if (event.key === STORAGE_KEY) sync(); };

        window.addEventListener(CHANGE_EVENT, sync);
        window.addEventListener('storage', onStorage);
        return () => {
            window.removeEventListener(CHANGE_EVENT, sync);
            window.removeEventListener('storage', onStorage);
        };
    }, []);

    const selectYear = useCallback((id) => {
        writeStoredId(id);
        setYearId(id);
        setOpen(false);
    }, []);

    const selected = years.find((year) => year.id === yearId) ?? years.find((year) => year.is_current) ?? years[0] ?? null;
    const label = selected?.label ?? (loading ? '…' : '—');
    const range = selected?.start_date && selected?.end_date ? `${selected.start_date} → ${selected.end_date}` : undefined;

    const dropdownSide = isRTL ? 'right-0 origin-top-right' : 'left-0 origin-top-left';
    const textAlign = isRTL ? 'text-right' : 'text-left';

    return (
        <div ref={ref} className="relative shrink-0">
            <button type="button" aria-expanded={open} aria-haspopup="listbox" title={range} onClick={() => setOpen(o => !o)}
                className={`flex items-center border border-outline-variant dark:border-[#2f4239] bg-surface-container-low dark:bg-[#182620] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] rounded gap-1 h-6 sm:h-8 transition-colors cursor-pointer select-none ${compact ? 'px-1.5 py-0.5' : 'px-3'}`}
            >
                <Icon name="date_range" className="text-[13px] sm:text-[16px] text-on-surface-variant dark:text-[#9bb2a8]" />
                <span className="font-mono text-[10px] sm:text-[12px] leading-[16px] text-on-surface dark:text-[#ecf3ef] whitespace-nowrap">{label}</span>
                <Icon name="arrow_drop_down" className={`text-[12px] sm:text-[14px] text-outline dark:text-[#9bb2a8] transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
            </button>
            <div role="listbox" className={`absolute ${dropdownSide} top-full mt-1 w-48 bg-surface-container-lowest dark:bg-[#202f29] border border-outline-variant dark:border-[#2f4239] rounded shadow-lg z-50 py-1 flex flex-col transition-all duration-200 ease-out ${open ? 'opacity-100 pointer-events-auto scale-100 translate-y-0' : 'opacity-0 pointer-events-none scale-95 -translate-y-1'}`}>
                {years.map((year) => (
                    <button key={year.id} type="button" role="option" aria-selected={year.id === selected?.id}
                        onClick={() => selectYear(year.id)}
                        className={`flex items-center justify-between gap-2 px-3 py-1.5 ${textAlign} font-mono text-[11px] leading-[16px] text-on-surface dark:text-[#ecf3ef] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] transition-colors ${year.id === selected?.id ? 'font-medium bg-surface-container-low dark:bg-[#182620]' : ''}`}
                    >
                        <span>{year.label}</span>
                        {year.is_current && (
                            <Icon name="check" className="text-[13px] text-primary dark:text-[#9fd1bd] shrink-0" />
                        )}
                    </button>
                ))}
            </div>
        </div>
    );
}
