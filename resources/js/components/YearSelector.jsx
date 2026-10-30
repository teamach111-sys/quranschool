import React, { useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { useLanguage } from '../hooks/useLanguage';

export default function YearSelector({ compact = false }) {
    const { isRTL } = useLanguage();
    const years = ['2025 / 1446-1447', '2024 / 1445-1446', '2023 / 1444-1445'];
    const [year, setYear] = useState(years[1]);
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        const onClick = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
        const onKey = (e) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('click', onClick);
        document.addEventListener('keydown', onKey);
        return () => { document.removeEventListener('click', onClick); document.removeEventListener('keydown', onKey); };
    }, []);

    const dropdownSide = isRTL ? 'right-0 origin-top-right' : 'left-0 origin-top-left';
    const textAlign = isRTL ? 'text-right' : 'text-left';

    return (
        <div ref={ref} className="relative shrink-0">
            <button type="button" aria-expanded={open} aria-haspopup="true" onClick={() => setOpen(o => !o)}
                className={`flex items-center border border-outline-variant dark:border-[#2f4239] bg-surface-container-low dark:bg-[#182620] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] rounded gap-1 h-6 sm:h-8 transition-colors cursor-pointer select-none ${compact ? 'px-1.5 py-0.5' : 'px-3'}`}
            >
                <Icon name="date_range" className="text-[13px] sm:text-[16px] text-on-surface-variant dark:text-[#9bb2a8]" />
                <span className="font-mono text-[10px] sm:text-[12px] leading-[16px] text-on-surface dark:text-[#ecf3ef] whitespace-nowrap">{year}</span>
                <Icon name="arrow_drop_down" className={`text-[12px] sm:text-[14px] text-outline dark:text-[#9bb2a8] transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
            </button>
            <div className={`absolute ${dropdownSide} top-full mt-1 w-48 bg-surface-container-lowest dark:bg-[#202f29] border border-outline-variant dark:border-[#2f4239] rounded shadow-lg z-50 py-1 flex flex-col transition-all duration-200 ease-out ${open ? 'opacity-100 pointer-events-auto scale-100 translate-y-0' : 'opacity-0 pointer-events-none scale-95 -translate-y-1'}`}>
                {years.map(y => (
                    <button key={y} type="button" onClick={() => { setYear(y); setOpen(false); }}
                        className={`flex items-center px-3 py-1.5 ${textAlign} font-mono text-[11px] leading-[16px] text-on-surface dark:text-[#ecf3ef] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] transition-colors ${y === year ? 'font-medium bg-surface-container-low dark:bg-[#182620]' : ''}`}
                    >{y}</button>
                ))}
            </div>
        </div>
    );
}
