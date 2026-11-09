import React from 'react';
import Icon from './Icon';

const trendToneClasses = {
    positive: 'bg-primary-fixed text-on-primary-fixed-variant dark:bg-[#32302e] dark:text-[#918d88]',
    negative: 'bg-error-container text-on-error-container dark:bg-error dark:text-on-error',
    neutral: 'bg-surface-container text-on-surface-variant dark:bg-[#2a2928] dark:text-[#c6c2bd]',
    warning: 'bg-secondary-container text-on-secondary-container dark:bg-secondary dark:text-on-secondary',
};

/**
 * A single metric cell. It carries no border or container of its own — the
 * surrounding `StatStrip` supplies the segmented chrome.
 */
export default function StatCard({
    label,
    value,
    icon,
    trend,
    trendIcon,
    trendTone = 'neutral',
    className = '',
}) {
    const toneClasses = trendToneClasses[trendTone] ?? trendToneClasses.neutral;

    return (
        <article className={`min-w-0 bg-surface-container-lowest p-4 dark:bg-inverse-surface ${className}`}>
            <div className="flex items-start justify-between gap-3">
                <p className="min-w-0 text-[12px] leading-4 font-medium text-on-surface-variant dark:text-inverse-on-surface">
                    {label}
                </p>
                {icon && (
                    <Icon name={icon} className="mt-0.5 shrink-0 text-[20px] text-primary" />
                )}
            </div>
            <div className="mt-3 flex items-end justify-between gap-3">
                <p className="min-w-0 wrap-break-word text-[26px] leading-8 font-semibold tabular-nums text-on-surface dark:text-inverse-on-surface">
                    {value}
                </p>
                {trend !== undefined && trend !== null && trend !== '' && (
                    <span className={`inline-flex max-w-full shrink-0 items-center gap-1 rounded-md px-2 py-1 text-[11px] leading-4 font-medium ${toneClasses}`}>
                        {trendIcon && <Icon name={trendIcon} className="text-[14px]" />}
                        <span className="truncate">{trend}</span>
                    </span>
                )}
            </div>
        </article>
    );
}

/**
 * Segmented KPI row: a single bordered panel whose cells are split by 1px
 * divider lines (drawn by the grid gap over the divider colour).
 *
 * @param {Object} props
 * @param {Array<Object>} props.items StatCard props; pass `key` to override the React key.
 * @param {string} [props.ariaLabel]
 * @param {string} [props.className] Grid column classes, e.g. "sm:grid-cols-2 xl:grid-cols-4".
 */
export function StatStrip({ items, ariaLabel, className = '' }) {
    return (
        <section
            aria-label={ariaLabel}
            className={`grid grid-cols-1 gap-px overflow-hidden rounded-lg border border-outline-variant bg-outline-variant dark:border-[#3a3835] dark:bg-[#3a3835] ${className}`}
        >
            {items.map((item) => (
                <StatCard key={item.key ?? item.label} {...item} />
            ))}
        </section>
    );
}
