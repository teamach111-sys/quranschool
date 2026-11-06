import React, { useEffect, useState } from 'react';
import Icon from '../components/Icon';
import { useLanguage } from '../persisters/languageswitch';

function pageNumbers(currentPage, totalPages) {
    const start = Math.max(1, Math.min(currentPage - 1, totalPages - 2));
    const end = Math.min(totalPages, start + 2);

    return Array.from({ length: end - start + 1 }, (_, index) => start + index);
}

function getValue(row, column) {
    return typeof column.value === 'function' ? column.value(row) : row[column.key];
}

export default function MobileTable({
    rows = [],
    columns = [],
    rowKey = 'id',
    toolbar,
    emptyState,
    labels,
    isRTL = false,
    selectable = false,
    onSelectionChange,
    actions = [],
    pageSize = 10,
    ariaLabel,
    loading = false,
}) {
    const { t } = useLanguage();
    const [page, setPage] = useState(1);
    const [selectedIds, setSelectedIds] = useState([]);
    const totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
    const visiblePage = Math.min(page, totalPages);
    const pageRows = rows.slice((visiblePage - 1) * pageSize, visiblePage * pageSize);
    const visibleIds = pageRows.map((row) => row[rowKey]);
    const allVisibleSelected = selectable && visibleIds.length > 0
        && visibleIds.every((id) => selectedIds.includes(id));
    const identifierColumn = columns.find((column) => column.mobile === 'identifier');
    const titleColumn = columns.find((column) => column.mobile === 'title');
    const subtitleColumn = columns.find((column) => column.mobile === 'subtitle');
    const badgeColumn = columns.find((column) => column.mobile === 'badge');
    const detailColumns = columns.filter((column) => column.mobile === 'detail');
    const detailsGrid = detailColumns.length > 1 ? 'grid-cols-2' : 'grid-cols-1';

    useEffect(() => {
        setPage(1);
        setSelectedIds([]);
    }, [rows]);

    const toggleRow = (id) => {
        const next = selectedIds.includes(id)
            ? selectedIds.filter((selectedId) => selectedId !== id)
            : [...selectedIds, id];
        setSelectedIds(next);
        onSelectionChange?.(next);
    };

    const toggleVisibleRows = () => {
        const next = allVisibleSelected
            ? selectedIds.filter((id) => !visibleIds.includes(id))
            : [...new Set([...selectedIds, ...visibleIds])];
        setSelectedIds(next);
        onSelectionChange?.(next);
    };

    const firstItem = rows.length === 0 ? 0 : (visiblePage - 1) * pageSize + 1;
    const lastItem = Math.min(visiblePage * pageSize, rows.length);

    return (
        <div aria-busy={loading} className="flex flex-col gap-2.5">
            {toolbar && (
                <div className="rounded-xl bg-surface-container-low p-3 dark:bg-[#22302a]">
                    {toolbar}
                    {loading && (
                        <div aria-hidden="true" className="mt-3 inline-flex items-center gap-2 text-[12px] text-on-surface-variant dark:text-tertiary-fixed-dim">
                            <Icon name="progress_activity" className="animate-spin text-[16px] text-primary" />
                            <span>{t.modal.loading}</span>
                        </div>
                    )}
                </div>
            )}

            {loading ? (
                <div role="status" aria-label={t.modal.loading} className="flex min-h-40 flex-col items-center justify-center gap-3 rounded-xl border border-outline-variant bg-surface-container-lowest text-on-surface-variant dark:border-[#384c43] dark:bg-[#22302a] dark:text-tertiary-fixed-dim">
                    <Icon name="progress_activity" className="animate-spin text-[32px] text-primary" />
                    <span className="text-[13px]">{t.modal.loading}</span>
                </div>
            ) : pageRows.length === 0 ? (
                <div className="rounded-xl border border-outline-variant bg-surface-container-lowest p-6 text-[13px] text-on-surface-variant dark:border-[#384c43] dark:bg-[#22302a] dark:text-[#c2d1c8]">
                    {emptyState}
                </div>
            ) : (
                <ul aria-label={ariaLabel} className="flex flex-col gap-2.5">
                    {pageRows.map((row) => {
                        const identifier = identifierColumn ? getValue(row, identifierColumn) : row[rowKey];
                        const badge = badgeColumn
                            ? (badgeColumn.render ? badgeColumn.render(getValue(row, badgeColumn), row) : getValue(row, badgeColumn))
                            : null;

                        return (
                            <li key={row[rowKey]} className="flex flex-col gap-3 rounded-xl border border-outline-variant bg-surface-container-lowest p-3 shadow-ambient dark:border-[#384c43] dark:bg-[#22302a]">
                                <div className={`flex items-center justify-between gap-2 ${isRTL ? 'flex-row-reverse' : ''}`}>
                                    <div className={`flex min-w-0 flex-wrap items-center gap-1.5 ${isRTL ? 'flex-row-reverse' : ''}`}>
                                        {selectable && (
                                            <input
                                                type="checkbox"
                                                checked={selectedIds.includes(row[rowKey])}
                                                onChange={() => toggleRow(row[rowKey])}
                                                aria-label={labels.selectRow(row)}
                                                className="h-4 w-4 cursor-pointer accent-primary"
                                            />
                                        )}
                                        <span className="truncate text-[11px] font-semibold text-on-surface-variant dark:text-[#9fd1bd]/70">
                                            #{identifier}
                                        </span>
                                        {badge && <span className="max-w-full">{badge}</span>}
                                    </div>
                                    {actions.length > 0 && (
                                        <div className={`flex shrink-0 items-center gap-1 ${isRTL ? 'flex-row-reverse' : ''}`}>
                                            {actions.filter((action) => !action.hidden?.(row)).map((action) => (
                                                <button
                                                    key={action.key}
                                                    type="button"
                                                    onClick={() => action.onClick(row)}
                                                    disabled={action.disabled?.(row)}
                                                    aria-label={action.label}
                                                    title={action.label}
                                                    className={`flex h-11 w-11 items-center justify-center rounded-lg bg-surface-container-low text-primary transition-colors hover:bg-surface-container disabled:cursor-not-allowed disabled:opacity-50 dark:bg-[#273831] dark:text-[#9fd1bd] dark:hover:bg-[#34483f] ${action.className ?? ''}`}
                                                >
                                                    <Icon name={action.icon} className="text-[17px]" />
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                <div className="flex flex-col gap-1">
                                    {titleColumn && (
                                        <h2 className={`text-[14px] font-semibold leading-5 text-on-surface dark:text-[#f0fcf3] ${isRTL ? 'text-right' : 'text-left'}`}>
                                            {titleColumn.render
                                                ? titleColumn.render(getValue(row, titleColumn), row)
                                                : getValue(row, titleColumn) ?? '—'}
                                        </h2>
                                    )}
                                    {subtitleColumn && (
                                        <p className={`text-[12px] leading-4 text-on-surface-variant dark:text-[#c2d1c8] ${isRTL ? 'text-right' : 'text-left'}`}>
                                            {subtitleColumn.render
                                                ? subtitleColumn.render(getValue(row, subtitleColumn), row)
                                                : getValue(row, subtitleColumn) ?? '—'}
                                        </p>
                                    )}
                                </div>

                                {detailColumns.length > 0 && (
                                    <dl className={`grid ${detailsGrid} gap-3 border-t border-outline-variant pt-2 dark:border-[#384c43]`}>
                                        {detailColumns.map((column) => (
                                            <div key={column.key} className={`min-w-0 ${isRTL ? 'text-right' : 'text-left'}`}>
                                                <dt className="text-[10px] uppercase tracking-wide text-outline dark:text-[#9fd1bd]/60">{column.label}</dt>
                                                <dd className={`${column.wrap ? 'break-words' : 'truncate'} text-[12px] font-medium text-on-surface-variant dark:text-[#c2d1c8]`}>
                                                    {column.render
                                                        ? column.render(getValue(row, column), row)
                                                        : getValue(row, column) ?? '—'}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}

            <footer className={`flex flex-col items-center justify-between gap-2 rounded-md border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[11px] text-on-surface-variant dark:border-[#384c43] dark:bg-[#1b2621] dark:text-inverse-on-surface sm:flex-row ${isRTL ? 'sm:flex-row-reverse' : ''}`}>
                <div className={`flex w-full items-center justify-between gap-2 sm:w-auto ${isRTL ? 'flex-row-reverse' : ''}`}>
                    {selectable && pageRows.length > 0 && (
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={allVisibleSelected}
                                onChange={toggleVisibleRows}
                                aria-label={labels.selectPage}
                                className="h-4 w-4 cursor-pointer accent-primary"
                            />
                            <span>{labels.selectPage}</span>
                        </label>
                    )}
                    <span aria-live="polite">{labels.range(firstItem, lastItem, rows.length)}</span>
                </div>
                {totalPages > 1 && (
                    <nav aria-label={labels.pagination} className="flex items-center gap-1">
                        <button
                            type="button"
                            onClick={() => setPage((current) => Math.max(1, current - 1))}
                            disabled={visiblePage === 1}
                            aria-label={labels.previous}
                            className="flex h-8 w-8 items-center justify-center rounded-md text-on-surface-variant hover:bg-surface-container disabled:opacity-40 dark:bg-white/10 dark:text-inverse-on-surface dark:hover:bg-white/15"
                        >
                            <Icon name={isRTL ? 'chevron_right' : 'chevron_left'} className="text-[18px]" />
                        </button>
                        {pageNumbers(visiblePage, totalPages).map((pageNumber) => (
                            <button
                                key={pageNumber}
                                type="button"
                                onClick={() => setPage(pageNumber)}
                                aria-current={pageNumber === visiblePage ? 'page' : undefined}
                                aria-label={labels.page(pageNumber)}
                                className={`h-8 min-w-8 rounded-md px-2 ${pageNumber === visiblePage
                                    ? 'bg-primary-container font-semibold text-on-primary dark:bg-[#2e5e4e] dark:text-[#ecf3ef]'
                                    : 'text-on-surface hover:bg-surface-container dark:text-inverse-on-surface dark:hover:bg-white/10'}`}
                            >
                                {pageNumber}
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() => setPage((current) => Math.min(totalPages, current + 1))}
                            disabled={visiblePage === totalPages}
                            aria-label={labels.next}
                            className="flex h-8 w-8 items-center justify-center rounded-md text-on-surface-variant hover:bg-surface-container disabled:opacity-40 dark:bg-white/10 dark:text-inverse-on-surface dark:hover:bg-white/15"
                        >
                            <Icon name={isRTL ? 'chevron_left' : 'chevron_right'} className="text-[18px]" />
                        </button>
                    </nav>
                )}
            </footer>
        </div>
    );
}
