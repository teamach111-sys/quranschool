import React from 'react';
import {
    Bar,
    BarChart as RechartsBarChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import useTheme from '../hooks/useTheme';

/**
 * Semantic chart palette. Light mode follows the brand greens/golds,
 * dark mode follows the GitHub Dark scale so charts blend with the shell.
 *
 * @typedef {{ green: string, red: string, amber: string, blue: string, purple: string, gray: string, grid: string, axis: string }} ChartColors
 */

/** @type {{ light: ChartColors, dark: ChartColors }} */
const paletteByTheme = {
    light: {
        green: '#2e7d5b',
        red: '#ba1a1a',
        amber: '#b8860b',
        blue: '#4f8ad9',
        purple: '#6f42c1',
        gray: '#707974',
        grid: '#e5f1e8',
        axis: '#707974',
    },
    dark: {
        green: '#37955a',
        red: '#d96055',
        amber: '#c2922c',
        blue: '#4f8ad9',
        purple: '#7c6ac0',
        gray: '#918d88',
        grid: '#32302e',
        axis: '#918d88',
    },
};

/**
 * Resolve the chart palette for the active theme.
 *
 * @returns {ChartColors}
 */
export function useChartColors() {
    const { theme } = useTheme();

    return paletteByTheme[theme === 'dark' ? 'dark' : 'light'];
}

const tooltipClass = 'rounded-md border border-outline-variant bg-surface-container-lowest px-2.5 py-1.5 text-[12px] leading-4 text-on-surface shadow-lg dark:border-[#3a3835] dark:bg-[#232221] dark:text-[#e7e3de]';

/**
 * Reusable card shell for a single chart.
 */
export function ChartCard({ title, subtitle, action, children, className = '' }) {
    return (
        <section className={`min-w-0 rounded-lg border border-outline-variant bg-surface-container-lowest p-4 dark:border-[#3a3835] dark:bg-inverse-surface ${className}`}>
            <div className="mb-3 flex items-start justify-between gap-3">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h3 className="text-[14px] leading-5 font-semibold text-on-surface dark:text-inverse-on-surface">
                        {title}
                    </h3>
                    {subtitle && (
                        <p className="text-[12px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">
                            {subtitle}
                        </p>
                    )}
                </div>
                {action && <div className="shrink-0">{action}</div>}
            </div>
            {children}
        </section>
    );
}

function EmptyState({ height }) {
    return (
        <div
            className="flex items-center justify-center text-[12px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim"
            style={{ height }}
        >
            —
        </div>
    );
}

function DonutTooltip({ active, payload, valueFormatter }) {
    if (!active || !payload || payload.length === 0) {
        return null;
    }

    const item = payload[0];

    return (
        <div className={tooltipClass}>
            <span className="flex items-center gap-2">
                <span className="inline-block size-2 rounded-full" style={{ backgroundColor: item.payload?.color }} />
                {item.payload?.name}
                <span className="font-semibold tabular-nums">
                    {valueFormatter ? valueFormatter(item.value, item.payload) : item.value}
                </span>
            </span>
        </div>
    );
}

/**
 * Donut / ring chart with a side legend.
 *
 * @param {Object} props
 * @param {Array<{ key: string, name: string, value: number, color: string }>} props.data
 * @param {string} [props.centerLabel]
 * @param {string} [props.centerValue]
 * @param {(value: number, item: object) => string} [props.valueFormatter]
 * @param {number} [props.height]
 */
export function DonutChart({ data, centerLabel, centerValue, valueFormatter, height = 190 }) {
    const total = data.reduce((sum, item) => sum + (Number(item.value) || 0), 0);

    if (total <= 0) {
        return <EmptyState height={height} />;
    }

    return (
        <div className="flex flex-col items-center gap-4 sm:flex-row sm:gap-5">
            <div className="relative shrink-0" style={{ width: height, height }}>
                <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                        <Pie
                            data={data}
                            dataKey="value"
                            nameKey="name"
                            innerRadius="64%"
                            outerRadius="100%"
                            paddingAngle={2}
                            stroke="none"
                            isAnimationActive={false}
                        >
                            {data.map((item) => (
                                <Cell key={item.key} fill={item.color} />
                            ))}
                        </Pie>
                        <Tooltip content={<DonutTooltip valueFormatter={valueFormatter} />} />
                    </PieChart>
                </ResponsiveContainer>
                {(centerValue !== undefined || centerLabel) && (
                    <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <span className="text-[20px] leading-6 font-semibold tabular-nums text-on-surface dark:text-inverse-on-surface">
                            {centerValue}
                        </span>
                        {centerLabel && (
                            <span className="text-[11px] leading-4 text-on-surface-variant dark:text-tertiary-fixed-dim">
                                {centerLabel}
                            </span>
                        )}
                    </div>
                )}
            </div>
            <ul className="flex w-full min-w-0 flex-col gap-2">
                {data.map((item) => (
                    <li key={item.key} className="flex items-center justify-between gap-3 text-[12px] leading-5">
                        <span className="flex min-w-0 items-center gap-2">
                            <span className="inline-block size-2.5 shrink-0 rounded-full" style={{ backgroundColor: item.color }} />
                            <span className="truncate text-on-surface dark:text-inverse-on-surface">{item.name}</span>
                        </span>
                        <span className="shrink-0 tabular-nums font-medium text-on-surface-variant dark:text-tertiary-fixed-dim">
                            {valueFormatter ? valueFormatter(item.value, item) : item.value}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function BarTooltip({ active, payload, valueFormatter }) {
    if (!active || !payload || payload.length === 0) {
        return null;
    }

    const item = payload[0];

    return (
        <div className={tooltipClass}>
            <p className="mb-0.5 font-semibold">{item.payload?.name}</p>
            <span className="flex items-center gap-2">
                <span className="inline-block size-2 rounded-full" style={{ backgroundColor: item.payload?.color }} />
                <span className="font-semibold tabular-nums">
                    {valueFormatter ? valueFormatter(item.value, item.payload) : item.value}
                </span>
            </span>
        </div>
    );
}

/**
 * Bar chart. Vertical columns by default; pass `horizontal` for ranked bars
 * (category on the Y axis) which reads better with long labels.
 *
 * @param {Object} props
 * @param {Array<{ key: string, name: string, value: number, color: string }>} props.data
 * @param {ChartColors} props.colors
 * @param {boolean} [props.horizontal]
 * @param {boolean} [props.currency] Format tooltips with the currency formatter from the caller.
 * @param {(value: number, item: object) => string} [props.valueFormatter]
 * @param {number} [props.height]
 */
export function BarsChart({ data, colors, horizontal = false, valueFormatter, height = 240, categoryWidth = 110 }) {
    const total = data.reduce((sum, item) => sum + (Number(item.value) || 0), 0);

    if (total <= 0) {
        return <EmptyState height={height} />;
    }

    const axisTick = { fill: colors.axis, fontSize: 11 };

    return (
        <div style={{ height }}>
            <ResponsiveContainer width="100%" height="100%">
                <RechartsBarChart
                    data={data}
                    layout={horizontal ? 'vertical' : 'horizontal'}
                    margin={{ top: 4, right: 8, bottom: 0, left: 0 }}
                >
                    <CartesianGrid
                        horizontal={!horizontal}
                        vertical={horizontal}
                        stroke={colors.grid}
                        strokeDasharray="3 3"
                    />
                    {horizontal ? (
                        <>
                            <XAxis type="number" stroke={colors.axis} tick={axisTick} axisLine={false} tickLine={false} />
                            <YAxis
                                type="category"
                                dataKey="name"
                                width={categoryWidth}
                                stroke={colors.axis}
                                tick={axisTick}
                                axisLine={false}
                                tickLine={false}
                            />
                        </>
                    ) : (
                        <>
                            <XAxis dataKey="name" stroke={colors.axis} tick={axisTick} axisLine={false} tickLine={false} />
                            <YAxis stroke={colors.axis} tick={axisTick} axisLine={false} tickLine={false} width={72} />
                        </>
                    )}
                    <Tooltip cursor={{ fill: colors.grid, opacity: 0.4 }} content={<BarTooltip valueFormatter={valueFormatter} />} />
                    <Bar dataKey="value" maxBarSize={horizontal ? 22 : 48} radius={horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]} isAnimationActive={false}>
                        {data.map((item) => (
                            <Cell key={item.key} fill={item.color} />
                        ))}
                    </Bar>
                </RechartsBarChart>
            </ResponsiveContainer>
        </div>
    );
}
