import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Icon from '../../../components/Icon';
import SearchInput from '../../../components/SearchInput';
import PaymentFormModal from './PaymentFormModal';
import PaymentPeriodGeneratorModal from './PaymentPeriodGeneratorModal';
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

export default function PaymentsTable({ routeParams = {} }) {
    const { isRTL, lang, t } = useLanguage();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [payments, setPayments] = useState([]);
    const [unpaidBalances, setUnpaidBalances] = useState([]);
    const [students, setStudents] = useState([]);
    const [filters, setFilters] = useState(emptyFilters);
    const [search, setSearch] = useState('');
    const [currency, setCurrency] = useState('MAD');
    const [canManage, setCanManage] = useState(false);
    const [canRecord, setCanRecord] = useState(false);
    const [canGenerate, setCanGenerate] = useState(false);
    const [isSecretary, setIsSecretary] = useState(false);
    const [copyFeedback, setCopyFeedback] = useState(null);
    const [entry, setEntry] = useState(null);
    const [isGeneratorOpen, setIsGeneratorOpen] = useState(false);
    const [focusedAlertPaymentId, setFocusedAlertPaymentId] = useState('');
    const openedAlertPaymentId = useRef(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);

    useEffect(() => {
        const syncYear = (event) => {
            const nextYearId = event?.detail?.id ?? getSelectedAcademicYearId();
            setYearId(nextYearId ? Number(nextYearId) : null);
            setFilters(emptyFilters());
            setEntry(null);
            setIsGeneratorOpen(false);
        };

        window.addEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
        return () => window.removeEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
    }, []);

    const loadPayments = useCallback(async (signal) => {
        setLoading(true);
        setLoadError(false);
        const query = new URLSearchParams();
        if (yearId) query.set('year_id', String(yearId));
        Object.entries(filters).forEach(([key, value]) => {
            if (value) query.set(key, value);
        });

        try {
            const response = await apiFetch(`/payments/table?${query.toString()}`, { signal });
            if (!response.ok) throw new Error('Failed to load payments');
            const data = await response.json();

            if (signal?.aborted) return;
            setYearId((currentYearId) => currentYearId ?? Number(data.year_id));
            setPayments(Array.isArray(data.payments) ? data.payments : []);
            setUnpaidBalances(Array.isArray(data.unpaid_balances) ? data.unpaid_balances : []);
            setStudents(Array.isArray(data.students) ? data.students : []);
            setCurrency(data.currency ?? 'MAD');
            setCanManage(Boolean(data.can_manage));
            setCanRecord(Boolean(data.can_record));
            setCanGenerate(Boolean(data.year_is_active && data.year_is_current && data.can_record));
            setIsSecretary(Boolean(data.is_secretary));
        } catch (error) {
            if (error.name !== 'AbortError') setLoadError(true);
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, [filters, yearId]);

    useEffect(() => {
        const controller = new AbortController();
        loadPayments(controller.signal);
        return () => controller.abort();
    }, [loadPayments]);

    useEffect(() => {
        const alertPaymentId = String(routeParams.payment_id ?? '');
        if (!alertPaymentId || loading || openedAlertPaymentId.current === alertPaymentId) return;

        const payment = payments.find((item) => String(item.id) === alertPaymentId);
        if (!payment) return;

        openedAlertPaymentId.current = alertPaymentId;
        if (canManage) {
            setEntry({ payment });
        } else {
            setFocusedAlertPaymentId(alertPaymentId);
        }
    }, [canManage, loading, payments, routeParams.payment_id]);

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

    const handlePaymentSaved = async () => {
        setEntry(null);
        await loadPayments();
    };
    const handlePeriodsGenerated = async () => {
        await loadPayments();
    };

    const locale = lang === 'ar' ? 'ar-MA-u-nu-latn' : 'fr-FR';
    const formatAmount = (payment) => (
        `${new Intl.NumberFormat(locale, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(Number(payment.amount))} ${payment.currency}`
    );
    const collectionPayments = isSecretary
        ? filteredPayments.filter((payment) => ['due', 'late'].includes(payment.status))
        : filteredPayments;
    const focusedAlertPayment = focusedAlertPaymentId
        ? payments.find((payment) => String(payment.id) === focusedAlertPaymentId)
        : null;
    const displayedPayments = focusedAlertPayment
        ? collectionPayments.filter((payment) => String(payment.id) === focusedAlertPaymentId)
        : collectionPayments;
    const collectionTotals = isSecretary
        ? Object.values(collectionPayments.reduce((totals, payment) => {
            const key = `${payment.currency}:${payment.status}`;
            totals[key] ??= {
                currency: payment.currency,
                status: payment.status,
                count: 0,
                amount: 0,
            };
            totals[key].count += 1;
            totals[key].amount += Number(payment.amount);
            return totals;
        }, {}))
        : [];
    const copyGuardianPhone = async (payment) => {
        try {
            if (!navigator.clipboard?.writeText) throw new Error('Clipboard access is unavailable');
            await navigator.clipboard.writeText(payment.guardian_phone);
            setCopyFeedback({ paymentId: payment.id, copied: true });
        } catch {
            setCopyFeedback({ paymentId: payment.id, copied: false });
        }
    };
    const columns = [
        {
            key: 'student_name',
            label: t.payments.columns.student,
            sortable: true,
            mobile: 'title',
        },
        ...(isSecretary ? [{
            key: 'guardian_phone',
            label: t.payments.columns.guardianPhone,
            mobile: 'detail',
            wrap: true,
            value: (payment) => payment.guardian_phone || t.payments.emptyValue,
            render: (phone, payment) => (
                <div className="flex flex-wrap items-center gap-2">
                    {phone
                        ? <a href={`tel:${phone.replace(/[^\d+]/g, '')}`} className="min-h-11 inline-flex items-center text-primary underline-offset-2 hover:underline dark:text-primary-fixed-dim">{phone}</a>
                        : <span>{t.payments.noGuardianPhone}</span>}
                    {phone && (
                        <button
                            type="button"
                            onClick={() => copyGuardianPhone(payment)}
                            aria-label={t.payments.copyGuardianPhone(payment.student_name)}
                            title={t.payments.copyGuardianPhone(payment.student_name)}
                            className="inline-flex min-h-11 items-center gap-1 rounded-md bg-surface-container px-2 text-[11px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high dark:bg-[#2c3d36] dark:text-inverse-on-surface dark:hover:bg-[#344a41]"
                        >
                            <Icon name="content_copy" className="text-[16px]" />
                            {t.payments.copyPhone}
                        </button>
                    )}
                    {copyFeedback?.paymentId === payment.id && (
                        <span role={copyFeedback.copied ? 'status' : 'alert'} className={`text-[11px] ${copyFeedback.copied ? 'text-primary dark:text-primary-fixed-dim' : 'text-error dark:text-error-container'}`}>
                            {copyFeedback.copied ? t.payments.phoneCopied : t.payments.phoneCopyError}
                        </span>
                    )}
                </div>
            ),
        }] : []),
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
                hidden: (payment) => !['due', 'late'].includes(payment.status),
                onClick: (payment) => setEntry({ payment }),
            }]
            : [];
    const controlClass = 'min-h-11 w-full rounded-md border border-outline-variant bg-surface-container-lowest px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:border-tertiary-container dark:bg-inverse-surface dark:text-inverse-on-surface';
    const toolbar = (
        <div className="flex flex-col gap-3">
            {isSecretary && (
                <div className="flex flex-col gap-1">
                    <h2 className="text-[14px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        {t.payments.collectionsTitle}
                    </h2>
                    <p className="text-[12px] leading-5 text-on-surface-variant dark:text-[#c2d1c8]">
                        {t.payments.collectionsDescription}
                    </p>
                </div>
            )}
            {isSecretary && unpaidBalances.length > 0 && (
                <div className="space-y-2">
                    <h3 className="text-[13px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        {t.payments.unpaidBalancesTitle}
                    </h3>
                    <div className="grid grid-cols-1 gap-2 xl:grid-cols-2">
                        {unpaidBalances.map((balance) => (
                            <div key={balance.student_id} className="rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 dark:border-[#384d43] dark:bg-[#182620]">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="truncate text-[13px] font-semibold text-on-surface dark:text-inverse-on-surface">{balance.student_name}</p>
                                        <p className="text-[11px] text-on-surface-variant dark:text-[#9bb2a8]">
                                            {t.payments.unpaidBalanceSummary(balance.period_count, formatAmount({ amount: balance.total_amount, currency: balance.currency }))}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => setEntry({
                                            payment: {
                                                id: balance.first_payment_id,
                                                student_id: balance.student_id,
                                                student_name: balance.student_name,
                                                amount: balance.total_amount,
                                                currency: balance.currency,
                                                status: 'due',
                                                period_start: balance.oldest_period_start,
                                                period_end: balance.latest_period_end,
                                                guardian_phone: balance.guardian_phone,
                                            },
                                        })}
                                        className="inline-flex min-h-9 items-center justify-center rounded-lg bg-primary-container px-2.5 py-1.5 text-[11px] font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-primary-container dark:text-on-primary"
                                    >
                                        {t.payments.record}
                                    </button>
                                </div>
                                <div className="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-on-surface-variant dark:text-[#9bb2a8]">
                                    {balance.guardian_phone ? (
                                        <>
                                            <a href={`tel:${balance.guardian_phone.replace(/[^\d+]/g, '')}`} className="underline-offset-2 hover:underline">{balance.guardian_phone}</a>
                                            <button
                                                type="button"
                                                onClick={() => copyGuardianPhone(balance)}
                                                className="inline-flex items-center rounded-md bg-surface-container px-2 py-1 font-semibold text-on-surface transition-colors hover:bg-surface-container-high dark:bg-[#2c3d36] dark:text-inverse-on-surface"
                                            >
                                                {t.payments.copyPhone}
                                            </button>
                                        </>
                                    ) : (
                                        <span>{t.payments.noGuardianPhone}</span>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
            {isSecretary && collectionTotals.length > 0 && (
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2" aria-label={t.payments.collectionSummary}>
                    {collectionTotals.map((total) => (
                        <div key={`${total.currency}:${total.status}`} className="flex items-center justify-between gap-3 rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 dark:border-[#384d43] dark:bg-[#182620]">
                            <div>
                                <p className="text-[11px] text-on-surface-variant dark:text-[#9bb2a8]">
                                    {t.payments.collectionTotal(t.payments.statuses[total.status], total.count)}
                                </p>
                                <p className="text-[16px] font-semibold text-on-surface dark:text-inverse-on-surface">
                                    {formatAmount({ amount: total.amount, currency: total.currency })}
                                </p>
                            </div>
                            <Icon name={total.status === 'late' ? 'schedule' : 'receipt_long'} className="text-[20px] text-primary dark:text-primary-fixed-dim" />
                        </div>
                    ))}
                </div>
            )}
            {(canManage || canGenerate) && (
                <div className="flex flex-wrap justify-end gap-2">
                    {canGenerate && (
                        <button
                            type="button"
                            onClick={() => setIsGeneratorOpen(true)}
                            className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg bg-primary-container px-3.5 py-2 text-[12px] font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-primary-container dark:text-on-primary"
                        >
                            <Icon name="autorenew" className="text-[17px]" />
                            {t.payments.generate}
                        </button>
                    )}
                    {canManage && (
                        <button
                            type="button"
                            onClick={() => setEntry({ payment: null })}
                            className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg bg-primary-container px-3.5 py-2 text-[12px] font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-primary-container dark:text-on-primary"
                        >
                            <Icon name="add" className="text-[17px]" />
                            {t.payments.create}
                        </button>
                    )}
                </div>
            )}
            <div className="flex flex-col gap-2 xl:flex-row xl:items-center">
                <div className="min-w-0 xl:flex-1">
                    <SearchInput
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        isRTL={isRTL}
                        placeholder={t.payments.search}
                        ariaLabel={t.payments.search}
                    />
                </div>
                <div className="min-w-0 xl:flex-1">
                    <select
                        name="student_id"
                        value={filters.student_id}
                        onChange={handleFilterChange}
                        aria-label={t.payments.filters.student}
                        className={controlClass}
                    >
                        <option value="">{t.payments.filters.allStudents}</option>
                        {students.map((student) => (
                            <option key={student.id} value={student.id}>
                                {student.name}{student.student_number ? ` (${student.student_number})` : ''}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="min-w-0 xl:flex-1">
                    <select
                        name="status"
                        value={filters.status}
                        onChange={handleFilterChange}
                        aria-label={t.payments.filters.status}
                        className={controlClass}
                    >
                        <option value="">{t.payments.filters.allStatuses}</option>
                        {Object.entries(isSecretary
                            ? { due: t.payments.statuses.due, late: t.payments.statuses.late }
                            : t.payments.statuses).map(([status, label]) => (
                            <option key={status} value={status}>{label}</option>
                        ))}
                    </select>
                </div>
                <div className="min-w-0 xl:flex-1">
                    <input
                        type="date"
                        name="period_date"
                        value={filters.period_date}
                        onChange={handleFilterChange}
                        aria-label={t.payments.filters.period}
                        className={controlClass}
                    />
                </div>
            </div>
        </div>
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
            {focusedAlertPayment && (
                <p role="status" className="rounded-lg bg-surface-container px-3 py-2 text-[12px] text-on-surface-variant dark:bg-inverse-surface dark:text-inverse-on-surface">
                    {t.payments.alertRecordFocus(focusedAlertPayment.student_name)}
                </p>
            )}
            <div className="hidden md:block">
                <DesktopTable
                    ariaLabel={t.payments.listLabel}
                    columns={columns}
                    emptyState={isSecretary ? t.payments.noOutstanding : t.payments.empty}
                    isRTL={isRTL}
                    labels={labels}
                    rows={displayedPayments}
                    toolbar={toolbar}
                    actions={actions}
                />
            </div>
            <div className="md:hidden">
                <MobileTable
                    ariaLabel={t.payments.listLabel}
                    columns={columns}
                    emptyState={isSecretary ? t.payments.noOutstanding : t.payments.empty}
                    isRTL={isRTL}
                    labels={labels}
                    rows={displayedPayments}
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
            {isGeneratorOpen && (
                <PaymentPeriodGeneratorModal
                    yearId={yearId}
                    onClose={() => setIsGeneratorOpen(false)}
                    onGenerated={handlePeriodsGenerated}
                />
            )}
        </section>
    );
}
