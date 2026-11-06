import React, { useEffect, useMemo, useState } from 'react';
import Icon from '../components/Icon';
import { useLanguage } from '../persisters/languageswitch';

function getValue(row, column) {
    return typeof column.value === 'function' ? column.value(row) : row[column.key];
}

function compareValues(left, right) {
    if (left == null) return right == null ? 0 : -1;
    if (right == null) return 1;

    const leftNumber = Number(left);
    const rightNumber = Number(right);

    if (String(left).trim() !== '' && String(right).trim() !== ''
        && Number.isFinite(leftNumber) && Number.isFinite(rightNumber)) {
        return leftNumber - rightNumber;
    }

    return String(left).localeCompare(String(right), undefined, {
        numeric: true,
        sensitivity: 'base',
    });
}

function pageNumbers(currentPage, totalPages) {
    const start = Math.max(1, Math.min(currentPage - 1, totalPages - 2));
    const end = Math.min(totalPages, start + 2);

    return Array.from({ length: end - start + 1 }, (_, index) => start + index);
}

export default function DesktopTable({
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
    onSortChange,
}) {
    const { t } = useLanguage();
    const [page, setPage] = useState(1);
    const [sortKey, setSortKey] = useState('');
    const [sortDirection, setSortDirection] = useState('asc');
    const [selectedIds, setSelectedIds] = useState([]);
    const textAlign = isRTL ? 'text-right' : 'text-left';
    const actionAlign = isRTL ? 'text-left' : 'text-right';

    const sortedRows = useMemo(() => {
        if (!sortKey) return rows;
        const column = columns.find((item) => item.key === sortKey);
        if (!column) return rows;

        return [...rows].sort((a, b) => {
            const result = compareValues(
                column.sortValue ? column.sortValue(a) : getValue(a, column),
                column.sortValue ? column.sortValue(b) : getValue(b, column),
            );
            return sortDirection === 'asc' ? result : -result;
        });
    }, [rows, columns, sortKey, sortDirection]);

    const totalPages = Math.max(1, Math.ceil(sortedRows.length / pageSize));
    const visiblePage = Math.min(page, totalPages);
    const pageRows = sortedRows.slice((visiblePage - 1) * pageSize, visiblePage * pageSize);
    const visibleIds = pageRows.map((row) => row[rowKey]);
    const allVisibleSelected = selectable && visibleIds.length > 0
        && visibleIds.every((id) => selectedIds.includes(id));

    useEffect(() => {
        setPage(1);
        setSelectedIds([]);
    }, [rows]);

    const toggleSort = (column) => {
        if (!column.sortable) return;
        const nextDirection = sortKey === column.key && sortDirection === 'asc' ? 'desc' : 'asc';
        setSortKey(column.key);
        setSortDirection(nextDirection);
        onSortChange?.({ key: column.key, direction: nextDirection });
        setPage(1);
    };

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

    const firstItem = sortedRows.length === 0 ? 0 : (visiblePage - 1) * pageSize + 1;
    const lastItem = Math.min(visiblePage * pageSize, sortedRows.length);

    return (
        <div aria-busy={loading} className="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-ambient dark:border-[#384c43] dark:bg-[#22302a]">
            {toolbar && (
                <div className="border-b border-outline-variant bg-surface-container-lowest p-3 dark:border-[#384c43] dark:bg-[#22302a] sm:p-4">
                    {toolbar}
                </div>
            )}

            {loading ? (
                <div role="status" aria-label={t.modal.loading} className="flex min-h-40 flex-col items-center justify-center gap-3 text-on-surface-variant dark:text-tertiary-fixed-dim">
                    <Icon name="progress_activity" className="animate-spin text-[32px] text-primary" />
                    <span className="text-[13px]">{t.modal.loading}</span>
                </div>
            ) : pageRows.length === 0 ? (
                <div className={`p-6 text-[13px] text-on-surface-variant dark:text-[#c2d1c8] ${textAlign}`}>
                    {emptyState}
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[700px] border-collapse text-[13px]" aria-label={ariaLabel}>
                        <thead className="bg-surface-container-low text-[11px] uppercase tracking-wide text-on-surface-variant dark:bg-[#1a2520] dark:text-[#9fd1bd]">
                            <tr>
                                {selectable && (
                                    <th scope="col" className="w-12 px-4 py-3 text-center">
                                        <input
                                            type="checkbox"
                                            checked={allVisibleSelected}
                                            onChange={toggleVisibleRows}
                                            aria-label={labels.selectPage}
                                            className="h-4 w-4 cursor-pointer accent-primary"
                                        />
                                    </th>
                                )}
                                {columns.map((column) => (
                                    <th key={column.key} scope="col" className={`px-4 py-3 font-semibold ${column.align === 'center' ? 'text-center' : textAlign}`}>
                                        {column.sortable ? (
                                            <button
                                                type="button"
                                                onClick={() => toggleSort(column)}
                                                className="inline-flex items-center gap-1 hover:text-primary dark:hover:text-[#baeed9]"
                                                aria-label={labels.sortBy(column.label)}
                                            >
                                                {column.label}
                                                <Icon
                                                    name={sortKey === column.key
                                                        ? sortDirection === 'asc' ? 'arrow_upward' : 'arrow_downward'
                                                        : 'unfold_more'}
                                                    className="text-[14px]"
                                                />
                                            </button>
                                        ) : column.label}
                                    </th>
                                ))}
                                {actions.length > 0 && (
                                    <th scope="col" className={`px-4 py-3 font-semibold ${actionAlign}`}>
                                        {labels.actions}
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-outline-variant text-on-surface dark:divide-[#273831] dark:text-[#f0fcf3]">
                            {pageRows.map((row) => (
                                <tr key={row[rowKey]} className="transition-colors hover:bg-surface-container-low/60 dark:hover:bg-[#273831]/60">
                                    {selectable && (
                                        <td className="px-4 py-3 text-center">
                                            <input
                                                type="checkbox"
                                                checked={selectedIds.includes(row[rowKey])}
                                                onChange={() => toggleRow(row[rowKey])}
                                                aria-label={labels.selectRow(row)}
                                                className="h-4 w-4 cursor-pointer accent-primary"
                                            />
                                        </td>
                                    )}
                                    {columns.map((column) => (
                                        <td key={column.key} className={`px-4 py-3 ${column.cellClassName ?? ''} ${column.align === 'center' ? 'text-center' : textAlign}`}>
                                            {column.render
                                                ? column.render(getValue(row, column), row)
                                                : getValue(row, column) ?? '—'}
                                        </td>
                                    ))}
                                    {actions.length > 0 && (
                                        <td className={`px-4 py-3 ${actionAlign}`}>
                                            <div className={`inline-flex items-center gap-1 ${isRTL ? 'flex-row-reverse' : ''}`}>
                                                {actions.filter((action) => !action.hidden?.(row)).map((action) => (
                                                    <button
                                                        key={action.key}
                                                        type="button"
                                                        onClick={() => action.onClick(row)}
                                                        disabled={action.disabled?.(row)}
                                                        aria-label={action.label}
                                                        title={action.label}
                                                        className={`flex h-11 w-11 items-center justify-center rounded-md text-on-surface-variant transition-colors hover:bg-surface-container hover:text-primary disabled:cursor-not-allowed disabled:opacity-50 dark:text-[#9fd1bd]/70 dark:hover:bg-[#273831] dark:hover:text-[#baeed9] ${action.className ?? ''}`}
                                                    >
                                                        <Icon name={action.icon} className="text-[18px]" />
                                                    </button>
                                                ))}
                                            </div>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <footer className={`flex flex-col items-center justify-between gap-3 border-t border-outline-variant bg-surface-container-lowest px-4 py-3 text-[11px] text-on-surface-variant dark:border-[#384c43] dark:bg-[#22302a] dark:text-[#c2d1c8] sm:flex-row ${isRTL ? 'sm:flex-row-reverse' : ''}`}>
                <span aria-live="polite">{labels.range(firstItem, lastItem, sortedRows.length)}</span>
                {totalPages > 1 && (
                    <nav aria-label={labels.pagination} className="flex items-center gap-1">
                        <button
                            type="button"
                            onClick={() => setPage((current) => Math.max(1, current - 1))}
                            disabled={visiblePage === 1}
                            aria-label={labels.previous}
                            className="flex h-8 w-8 items-center justify-center rounded-md hover:bg-surface-container disabled:opacity-40 dark:hover:bg-[#273831]"
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
                                    : 'text-on-surface hover:bg-surface-container dark:text-[#f0fcf3] dark:hover:bg-[#273831]'}`}
                            >
                                {pageNumber}
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() => setPage((current) => Math.min(totalPages, current + 1))}
                            disabled={visiblePage === totalPages}
                            aria-label={labels.next}
                            className="flex h-8 w-8 items-center justify-center rounded-md hover:bg-surface-container disabled:opacity-40 dark:hover:bg-[#273831]"
                        >
                            <Icon name={isRTL ? 'chevron_left' : 'chevron_right'} className="text-[18px]" />
                        </button>
                    </nav>
                )}
            </footer>
        </div>
    );
}
