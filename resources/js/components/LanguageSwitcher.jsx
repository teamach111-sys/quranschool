import React, { useLayoutEffect, useRef, useState } from 'react';
import { useLanguage } from '../hooks/useLanguage';

export default function LanguageSwitcher({ compact = false }) {
    const { lang, setLang, isRTL } = useLanguage();
    const containerRef = useRef(null);
    const frRef = useRef(null);
    const arRef = useRef(null);
    const [indicatorStyle, setIndicatorStyle] = useState(
        isRTL ? { right: '2px', left: 'auto', width: 'auto' } : { left: '2px', right: 'auto', width: 'auto' }
    );
    const [ready, setReady] = useState(false);

    useLayoutEffect(() => {
        const measure = () => {
            const activeBtn = lang === 'ar' ? arRef.current : frRef.current;
            const container = containerRef.current;
            if (!activeBtn || !container) return;

            if (isRTL) {
                setIndicatorStyle({
                    right: `${container.offsetWidth - activeBtn.offsetLeft - activeBtn.offsetWidth}px`,
                    left: 'auto',
                    width: `${activeBtn.offsetWidth}px`,
                });
            } else {
                setIndicatorStyle({
                    left: `${activeBtn.offsetLeft}px`,
                    right: 'auto',
                    width: `${activeBtn.offsetWidth}px`,
                });
            }
            setReady(true);
        };

        // rAF ensures the DOM has settled after dir attribute change
        const id = requestAnimationFrame(measure);
        return () => cancelAnimationFrame(id);
    }, [lang, isRTL, compact]);

    const btnText = compact ? 'text-[10px] px-1.5' : 'text-[10px] px-2';

    return (
        <div
            ref={containerRef}
            className="relative flex items-center border border-outline-variant dark:border-[#2f4239] bg-surface-container-low dark:bg-[#182620] rounded p-0.5 h-7 sm:h-8 transition-colors duration-200"
            role="group" aria-label="Language"
        >
            <div
                className={`absolute top-0.5 bottom-0.5 rounded bg-primary dark:bg-[#baeed9] transition-all duration-200 shadow-xs ${ready ? 'opacity-100' : 'opacity-0'}`}
                style={indicatorStyle}
            />
            <button ref={frRef} type="button" onClick={() => setLang('fr')}
                className={`relative z-10 h-full ${btnText} font-mono leading-[14px] tracking-[0.02em] rounded font-medium flex items-center justify-center transition-colors duration-200 ${lang === 'fr' ? 'text-on-primary dark:text-[#002117]' : 'text-on-surface-variant dark:text-[#9bb2a8] hover:text-on-surface dark:hover:text-[#ecf3ef]'}`}
            >FR</button>
            <button ref={arRef} type="button" onClick={() => setLang('ar')}
                className={`relative z-10 h-full ${btnText} font-mono leading-[14px] tracking-[0.02em] rounded font-medium flex items-center justify-center transition-colors duration-200 ${lang === 'ar' ? 'text-on-primary dark:text-[#002117]' : 'text-on-surface-variant dark:text-[#9bb2a8] hover:text-on-surface dark:hover:text-[#ecf3ef]'}`}
            >العربية</button>
        </div>
    );
}
