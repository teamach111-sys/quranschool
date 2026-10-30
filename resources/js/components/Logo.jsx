import React from 'react';
import Icon from './Icon';

/**
 * Wireframe logo placeholder used across auth + dashboard layouts.
 */
export default function Logo({ variant = 'block', label = 'Quran Memo' }) {
    if (variant === 'compact') {
        return (
            <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded border border-dashed border-outline dark:border-[#475b52] flex items-center justify-center bg-surface-container dark:bg-[#182620]">
                    <Icon name="category" className="text-[18px] text-outline dark:text-[#9bb2a8]" />
                </div>
                <span className="font-headline-sm text-[18px] uppercase tracking-wider text-on-surface dark:text-[#ecf3ef] leading-none font-semibold">
                    {label}
                </span>
            </div>
        );
    }

    return (
        <div className="w-full h-16 rounded-xl bg-surface-container-high/40 flex items-center justify-center gap-2 px-4 shadow-sm">
            <Icon name="image" className="text-primary text-[24px]" />
            <span className="font-headline-sm text-[18px] text-primary uppercase tracking-widest font-bold">
                {label}
            </span>
        </div>
    );
}
