import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { useLanguage } from '../hooks/useLanguage';

export default function ThemeToggle({ theme, onToggle, compact = false }) {
    const { isRTL, t } = useLanguage();
    const lightRef = useRef(null);
    const darkRef = useRef(null);
    const containerRef = useRef(null);
    const [indicatorStyle, setIndicatorStyle] = useState(
        isRTL ? { right: '2px', left: 'auto' } : { left: '2px', right: 'auto' }
    );
    const [ready, setReady] = useState(false);
    // Transitions stay off for the first paint so the persisted theme does not
    // animate from the placeholder position on every reload.
    const [animate, setAnimate] = useState(false);
    const measuredOnce = useRef(false);

    // Only turn transitions on once the indicator sits in its real position,
    // so reloading with a persisted theme never replays the slide animation.
    useEffect(() => {
        if (!ready) return undefined;
        const id = requestAnimationFrame(() => setAnimate(true));
        return () => cancelAnimationFrame(id);
    }, [ready]);

    const indicatorSize = compact ? 'w-6' : 'w-7';
    const btnSize = compact ? 'w-6 h-6' : 'w-7 h-7';
    const iconSize = compact ? 'text-[14px]' : 'text-[16px]';
    const containerHeight = compact ? 'h-7' : 'h-8';

    useLayoutEffect(() => {
        const measure = () => {
            const activeBtn = theme === 'dark' ? darkRef.current : lightRef.current;
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
                });
            } else {
                setIndicatorStyle({
                    left: `${activeBtn.offsetLeft}px`,
                    right: 'auto',
                });
            }
            setReady(true);
        };

        // Re-measure without the slide transition. Crossing the responsive
        // breakpoint only changes CSS display (not React state), so the newly
        // visible switcher still holds the hidden layout's stale position;
        // animating from it looked corrupted.
        let settleFrame = 0;
        const remeasure = () => {
            setAnimate(false);
            measure();
            cancelAnimationFrame(settleFrame);
            settleFrame = requestAnimationFrame(() => setAnimate(true));
        };

        // Measure synchronously on the first render: the pre-paint script has
        // already applied the persisted theme/direction, so the indicator can
        // paint in the right place instead of starting at the placeholder.
        if (!measuredOnce.current) {
            measuredOnce.current = true;
            measure();
        }

        // Re-measure after the browser settles (handles a direction change).
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
    }, [theme, isRTL, compact]);

    const lightClasses = theme === 'light'
        ? 'text-on-surface dark:text-[#6f6b66]'
        : 'text-on-surface-variant dark:text-[#6f6b66] hover:text-on-surface dark:hover:text-[#e7e3de]';

    const darkClasses = theme === 'dark'
        ? 'dark:text-[#918d88]'
        : 'text-on-surface-variant dark:text-[#6f6b66] hover:text-on-surface dark:hover:text-[#e7e3de]';

    return (
        <div
            ref={containerRef}
            className={`relative flex items-center border border-outline-variant dark:border-[#3a3835] bg-surface-container-low dark:bg-[#1c1b1a] rounded-full p-0.5 ${containerHeight} transition-colors duration-200`}
            role="group" aria-label="Theme Switcher"
        >
            <div
                className={`absolute top-0.5 bottom-0.5 ${indicatorSize} rounded-full bg-surface-container-lowest dark:bg-[#232221] border border-outline-variant dark:border-[#3a3835] shadow-sm ${animate ? 'transition-all duration-200' : ''} ${ready ? 'opacity-100' : 'opacity-0'}`}
                style={indicatorStyle}
            />
            <button ref={lightRef} type="button" onClick={() => theme !== 'light' && onToggle()} title={t.themeLight}
                className={`relative z-10 flex items-center justify-center ${btnSize} rounded-full transition-colors duration-200 ${lightClasses}`}
            >
                <Icon name="light_mode" className={iconSize} />
            </button>
            <button ref={darkRef} type="button" onClick={() => theme !== 'dark' && onToggle()} title={t.themeDark}
                className={`relative z-10 flex items-center justify-center ${btnSize} rounded-full transition-colors duration-200 ${darkClasses}`}
            >
                <Icon name="dark_mode" className={iconSize} />
            </button>
        </div>
    );
}
