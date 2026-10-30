import React, { useLayoutEffect, useRef, useState } from 'react';
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

    const indicatorSize = compact ? 'w-6' : 'w-7';
    const btnSize = compact ? 'w-6 h-6' : 'w-7 h-7';
    const iconSize = compact ? 'text-[14px]' : 'text-[16px]';
    const containerHeight = compact ? 'h-7' : 'h-8';

    useLayoutEffect(() => {
        const measure = () => {
            const activeBtn = theme === 'dark' ? darkRef.current : lightRef.current;
            const container = containerRef.current;
            if (!activeBtn || !container) return;

            if (isRTL) {
                setIndicatorStyle({
                    right: `${container.offsetWidth - activeBtn.offsetLeft - activeBtn.offsetWidth}px`,
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

        const id = requestAnimationFrame(measure);
        return () => cancelAnimationFrame(id);
    }, [theme, isRTL, compact]);

    const lightClasses = theme === 'light'
        ? 'text-on-surface dark:text-[#707974]'
        : 'text-on-surface-variant dark:text-[#707974] hover:text-on-surface dark:hover:text-[#ecf3ef]';

    const darkClasses = theme === 'dark'
        ? 'dark:text-[#f5bd58]'
        : 'text-on-surface-variant dark:text-[#707974] hover:text-on-surface dark:hover:text-[#ecf3ef]';

    return (
        <div
            ref={containerRef}
            className={`relative flex items-center border border-outline-variant dark:border-[#2f4239] bg-surface-container-low dark:bg-[#182620] rounded-full p-0.5 ${containerHeight} transition-colors duration-200`}
            role="group" aria-label="Theme Switcher"
        >
            <div
                className={`absolute top-0.5 bottom-0.5 ${indicatorSize} rounded-full bg-surface-container-lowest dark:bg-[#202f29] border border-outline-variant dark:border-[#475b52] shadow-sm transition-all duration-200 ${ready ? 'opacity-100' : 'opacity-0'}`}
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
