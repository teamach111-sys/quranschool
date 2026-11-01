import React, { useCallback, useEffect, useMemo, useState } from 'react';
import Icon from '../../../components/Icon';
import PaymentFormModal from './PaymentFormModal';
import {
    ACADEMIC_YEAR_CHANGED_EVENT,
    getSelectedAcademicYearId,
} from '../../../persisters/yearselector';
import { useLanguage } from '../../../hooks/useLanguage';
import { apiFetch } from '../../../lib/api';
import DesktopTable from '../../../tables/desktoptable';
import MobileTable from '../../../tables/mobiletable';

function emptyFilters() {
    return {
        student_id: '',
        status: '',
        period_date: '',
    };
}

function displayDate(value, locale, withTime = false) {
    if (!value) return null;

    return new Intl.DateTimeFormat(locale, withTime
        ? { dateStyle: 'medium', timeStyle: 'short' }
        : { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(value));
}

export default function PaymentsTable() {
    const { isRTL, lang, t } = useLanguage();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [payments, setPayments] = useState([]);
    const [students, setStudents] = useState([]);
    const [filters, setFilters] = useState(emptyFilters);
    const [appliedFilters, setAppliedFilters] = useState(emptyFilters);
    const [search, setSearch] = useState('');
    const [currency, setCurrency] = useState('MAD');
    const [canManage, setCanManage] = useState(false);
    const [canRecord, setCanRecord] = useState(false);
    const [entry, setEntry] = useState(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);

    useEffect(() => {
        const syncYear = (event) => {
            const nextYearId = event?.detail?.id ?? getSelectedAcademicYearId();
            setYearId(nextYearId ? Number(nextYearId) : null);
            setFilters(emptyFilters());
            setAppliedFilters(emptyFilters());
            setEntry(null);
        };

        window.addEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
        return () => window.removeEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
    }, []);

    const loadPayments = useCallback(async (signal) => {
        setLoading(true);
        setLoadError(false);
        const query = new URLSearchParams();
        if (yearId) query.set('year_id', String(yearId));
        Object.entries(appliedFilters).forEach(([key, value]) => {
            if (value) query.set(key, value);
        });

        try {
            const response = await apiFetch(`/payments/table?${query.toString()}`, { signal });
            if (!response.ok) throw new Error('Failed to load payments');
            const data = await response.json();

            if (signal?.aborted) return;
            setYearId((currentYearId) => currentYearId ?? Number(data.year_id));
            setPayments(Array.isArray(data.payments) ? data.payments : []);
            setStudents(Array.isArray(data.students) ? data.students : []);
            setCurrency(data.currency ?? 'MAD');
            setCanManage(Boolean(data.can_manage));
            setCanRecord(Boolean(data.can_record));
        } catch (error) {
            if (error.name !== 'AbortError') setLoadError(true);
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, [appliedFilters, yearId]);

    useEffect(() => {
        const controller = new AbortController();
        loadPayments(controller.signal);
        return () => controller.abort();
    }, [loadPayments]);

    const filteredPayments = useMemo(() => {
        const query = search.trim().toLocaleLowerCase();

        if (!query) return payments;

        return payments.filter((payment) => [
            payment.student_name,
            payment.student_number,
            payment.payment_method,
            payment.reference,
            payment.notes,
        ].some((value) => String(value ?? '').toLocaleLowerCase().includes(query)));
    }, [payments, search]);

    const handleFilterChange = (event) => {
        const { name, value } = event.target;
        setFilters((current) => ({ ...current, [name]: value }));
    };

    const applyFilters = (event) => {
        event.preventDefault();
        setAppliedFilters({ ...filters });
    };

    const clearFilters = () => {
        const clearedFilters = emptyFilters();
        setFilters(clearedFilters);
        setAppliedFilters(clearedFilters);
        setSearch('');
    };

    const handlePaymentSaved = async () => {
        setEntry(null);
        await loadPayments();
    };

    const locale = lang === 'ar' ? 'ar' : 'fr';
    const formatAmount = (payment) => (
        `${new Intl.NumberFormat(locale, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(Number(payment.amount))} ${payment.currency}`
    );
    const columns = [
        {
            key: 'student_name',
            label: t.payments.columns.student,
            sortable: true,
            mobile: 'title',
        },
        {
            key: 'period_start',
            label: t.payments.columns.period,
            value: (payment) => `${displayDate(payment.period_start, locale)} – ${displayDate(payment.period_end, locale)}`,
            sortValue: (payment) => payment.period_start,
            sortable: true,
            mobile: 'subtitle',
        },
        {
            key: 'amount',
            label: t.payments.columns.amount,
            value: formatAmount,
            sortValue: (payment) => Number(payment.amount),
            sortable: true,
            mobile: 'detail',
        },
        {
            key: 'status',
            label: t.payments.columns.status,
            sortable: true,
            mobile: 'badge',
            value: (payment) => t.payments.statuses[payment.status] ?? payment.status,
            render: (status, payment) => (
                <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ${
                    payment.status === 'paid'
                        ? 'bg-primary-fixed text-on-primary-fixed-variant dark:bg-primary-container dark:text-on-primary-container'
                        : payment.status === 'waived'
                            ? 'bg-secondary-container text-on-secondary-container dark:bg-secondary-container dark:text-on-secondary-container'
                            : 'bg-tertiary-container text-on-tertiary-container dark:bg-tertiary-container dark:text-on-tertiary-container'
                }`}>
                    {status}
                </span>
            ),
        },
        {
            key: 'paid_at',
            label: t.payments.columns.paidAt,
            value: (payment) => displayDate(payment.paid_at, locale, true) ?? t.payments.emptyValue,
            sortable: true,
            mobile: 'detail',
        },
        {
            key: 'payment_method',
            label: t.payments.columns.method,
            sortable: true,
            mobile: 'detail',
            value: (payment) => payment.payment_method || t.payments.emptyValue,
        },
        {
            key: 'reference',
            label: t.payments.columns.reference,
            sortable: true,
            mobile: 'detail',
            value: (payment) => payment.reference || t.payments.emptyValue,
        },
        {
            key: 'recorded_by_name',
            label: t.payments.columns.recordedBy,
            sortable: true,
            mobile: 'detail',
            value: (payment) => payment.recorded_by_name || t.payments.emptyValue,
        },
    ];
    const labels = {
        actions: t.payments.table.actions,
        pagination: t.payments.table.pagination,
        previous: t.payments.table.previous,
        next: t.payments.table.next,
        page: (page) => t.payments.table.page(page),
        selectPage: t.payments.table.selectPage,
        selectRow: (payment) => t.payments.table.selectRow(payment.student_name),
        sortBy: (label) => t.payments.table.sortBy(label),
        range: (first, last, total) => t.payments.table.range(first, last, total),
    };
    const actions = canManage
        ? [{
            key: 'edit',
            icon: 'edit',
            label: t.payments.edit,
            onClick: (payment) => setEntry({ payment }),
        }]
        : canRecord
            ? [{
                key: 'record',
                icon: 'payments',
                label: t.payments.record,
                hidden: (payment) => payment.status !== 'due',
                onClick: (payment) => setEntry({ payment }),
            }]
            : [];
    const controlClass = 'min-h-11 w-full rounded-md border border-outline-variant bg-surface-container-lowest px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:border-tertiary-container dark:bg-inverse-surface dark:text-inverse-on-surface';
    const labelClass = 'flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface';
    const toolbar = (
        <form onSubmit={applyFilters} className="flex flex-col gap-3">
            {canManage && (
                <div className="flex justify-end">
                    <button
                        type="button"
                        onClick={() => setEntry({ payment: null })}
                        className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg bg-primary-container px-3.5 py-2 text-[12px] font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-primary-container dark:text-on-primary"
                    >
                        <Icon name="add" className="text-[17px]" />
                        {t.payments.create}
                    </button>
                </div>
            )}
            <label className={labelClass}>
                {t.payments.search}
                <input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    className={controlClass}
                />
            </label>
            <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label className={labelClass}>
                    {t.payments.filters.student}
                    <select name="student_id" value={filters.student_id} onChange={handleFilterChange} className={controlClass}>
                        <option value="">{t.payments.filters.allStudents}</option>
                        {students.map((student) => (
                            <option key={student.id} value={student.id}>
                                {student.name}{student.student_number ? ` (${student.student_number})` : ''}
                            </option>
                        ))}
                    </select>
                </label>
                <label className={labelClass}>
                    {t.payments.filters.status}
                    <select name="status" value={filters.status} onChange={handleFilterChange} className={controlClass}>
                        <option value="">{t.payments.filters.allStatuses}</option>
                        {Object.entries(t.payments.statuses).map(([status, label]) => (
                            <option key={status} value={status}>{label}</option>
                        ))}
                    </select>
                </label>
                <label className={labelClass}>
                    {t.payments.filters.period}
                    <input
                        type="date"
                        name="period_date"
                        value={filters.period_date}
                        onChange={handleFilterChange}
                        className={controlClass}
                    />
                </label>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <button
                        type="submit"
                        className="min-h-11 flex-1 rounded-md bg-primary-container px-3 py-2 text-[12px] font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-primary-container dark:text-on-primary"
                    >
                        {t.payments.filters.apply}
                    </button>
                    <button
                        type="button"
                        onClick={clearFilters}
                        className="min-h-11 flex-1 rounded-md bg-surface-container px-3 py-2 text-[12px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high dark:bg-inverse-surface dark:text-inverse-on-surface dark:hover:bg-tertiary-container"
                    >
                        {t.payments.filters.clear}
                    </button>
                </div>
            </div>
        </form>
    );

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.payments.loadError}</p>;
    }

    if (loading) {
        return <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">{t.payments.loading}</p>;
    }

    return (
        <section className="flex w-full flex-col gap-3" dir={isRTL ? 'rtl' : 'ltr'}>
            {!canRecord && (
                <p className="rounded-lg bg-surface-container px-3 py-2 text-[12px] text-on-surface-variant dark:bg-inverse-surface dark:text-inverse-on-surface">
                    {t.payments.readOnly}
                </p>
            )}
            <div className="hidden md:block">
                <DesktopTable
                    ariaLabel={t.payments.listLabel}
                    columns={columns}
                    emptyState={t.payments.empty}
                    isRTL={isRTL}
                    labels={labels}
                    rows={filteredPayments}
                    toolbar={toolbar}
                    actions={actions}
                />
            </div>
            <div className="md:hidden">
                <MobileTable
                    ariaLabel={t.payments.listLabel}
                    columns={columns}
                    emptyState={t.payments.empty}
                    isRTL={isRTL}
                    labels={labels}
                    rows={filteredPayments}
                    toolbar={toolbar}
                    actions={actions}
                />
            </div>
            {entry && (
                <PaymentFormModal
                    payment={entry.payment}
                    yearId={yearId}
                    currency={currency}
                    students={students}
                    canManage={canManage}
                    onClose={() => setEntry(null)}
                    onSaved={handlePaymentSaved}
                />
            )}
        </section>
    );
}
