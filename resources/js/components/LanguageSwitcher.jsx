import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { useLanguage } from '../persisters/languageswitch';

export default function LanguageSwitcher({ compact = false }) {
    const { lang, setLang, isRTL } = useLanguage();
    const containerRef = useRef(null);
    const frRef = useRef(null);
    const arRef = useRef(null);
    const [indicatorStyle, setIndicatorStyle] = useState(
        isRTL ? { right: '2px', left: 'auto', width: 'auto' } : { left: '2px', right: 'auto', width: 'auto' }
    );
    const [ready, setReady] = useState(false);
    // Transitions stay off for the first paint so the persisted language does
    // not animate from FR to العربية on every reload.
    const [animate, setAnimate] = useState(false);
    const measuredOnce = useRef(false);

    // Only turn transitions on once the indicator sits in its real position,
    // so reloading with a persisted language never replays the slide animation.
    useEffect(() => {
        if (!ready) return undefined;
        const id = requestAnimationFrame(() => setAnimate(true));
        return () => cancelAnimationFrame(id);
    }, [ready]);

    useLayoutEffect(() => {
        const measure = () => {
            const activeBtn = lang === 'ar' ? arRef.current : frRef.current;
            const container = containerRef.current;
            if (!activeBtn || !container) return;

            // The other breakpoint's layout is display:none, so it has no box.
            // Skip it; the observer below measures it once it becomes visible.
            if (container.offsetWidth === 0) return;

            if (isRTL) {
                setIndicatorStyle({
                    // clientWidth (padding box) matches the absolutely
                    // positioned indicator's containing block; offsetWidth
                    // would add the border and shift it by 2px in RTL.
                    right: `${container.clientWidth - activeBtn.offsetLeft - activeBtn.offsetWidth}px`,
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

        // Re-measure without the slide transition. Crossing the responsive
        // breakpoint only changes CSS display (not React state), so the newly
        // visible switcher still holds the hidden layout's stale position and
        // width; animating from those values is what looked corrupted.
        let settleFrame = 0;
        const remeasure = () => {
            setAnimate(false);
            measure();
            cancelAnimationFrame(settleFrame);
            settleFrame = requestAnimationFrame(() => setAnimate(true));
        };

        // Measure synchronously on the first render: the pre-paint script has
        // already applied the persisted direction, so the indicator can paint
        // in the right place instead of starting at the placeholder.
        if (!measuredOnce.current) {
            measuredOnce.current = true;
            measure();
        }

        // rAF ensures the DOM has settled after a dir attribute change.
        let frame = requestAnimationFrame(measure);

        // A breakpoint switch only toggles display, so watch the container box:
        // it goes 0 → real size when this instance becomes visible.
        let observer;
        if (typeof ResizeObserver !== 'undefined' && containerRef.current) {
            observer = new ResizeObserver(remeasure);
            observer.observe(containerRef.current);
        }

        // Fallback for browsers without ResizeObserver.
        const onResize = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(remeasure);
        };
        window.addEventListener('resize', onResize);

        return () => {
            cancelAnimationFrame(frame);
            cancelAnimationFrame(settleFrame);
            observer?.disconnect();
            window.removeEventListener('resize', onResize);
        };
    }, [lang, isRTL, compact]);

    const btnText = compact ? 'text-[10px] px-1.5' : 'text-[10px] px-2';
    // Keep the same height as the theme toggle in each shell: 28px next to the
    // compact theme toggle in the mobile app bar, 32px in the desktop header.
    // (The old `h-7 sm:h-8` grew to 32px from 640px up, which is still the
    // mobile layout, so it ended up taller than the 28px theme toggle.)
    const containerHeight = compact ? 'h-7' : 'h-8';

    return (
        <div
            ref={containerRef}
            className={`relative flex items-center border border-outline-variant dark:border-[#2f4239] bg-surface-container-low dark:bg-[#182620] rounded p-0.5 ${containerHeight} transition-colors duration-200`}
            role="group" aria-label="Language"
        >
            <div
                className={`absolute top-0.5 bottom-0.5 rounded bg-primary dark:bg-[#baeed9] ${animate ? 'transition-all duration-200' : ''} shadow-xs ${ready ? 'opacity-100' : 'opacity-0'}`}
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
