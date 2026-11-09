import React from 'react';

/**
 * Segmented content panel used across the dashboards. Put rows inside with
 * `divide-y divide-outline-variant dark:divide-[#3a3835]` so they read as one
 * surface rather than a stack of separate cards.
 */
export function Panel({ children, className = '' }) {
    return (
        <div className={`overflow-hidden rounded-lg border border-outline-variant bg-surface-container-lowest dark:border-[#3a3835] dark:bg-inverse-surface ${className}`}>
            {children}
        </div>
    );
}

/**
 * Section title with an optional trailing action (e.g. a "view all" link).
 */
export function SectionHeader({ title, action, className = '' }) {
    return (
        <div className={`flex items-center justify-between gap-3 ${className}`}>
            <h2 className="text-[16px] leading-6 font-semibold text-on-surface dark:text-inverse-on-surface">{title}</h2>
            {action}
        </div>
    );
}
