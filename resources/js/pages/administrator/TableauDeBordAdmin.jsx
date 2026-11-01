import React, { useEffect, useState } from 'react';
import Icon from '../../components/Icon';
import StatCard from '../../components/StatCard';
import { apiFetch } from '../../lib/api';
import { getSelectedAcademicYearId } from '../../persisters/yearselector';
import { useLanguage } from '../../hooks/useLanguage';

const attendanceStatuses = ['present', 'absent', 'late', 'excused'];
const paymentStatuses = ['paid', 'due', 'late', 'waived'];
const attendanceTones = {
    present: 'positive',
    absent: 'negative',
    late: 'warning',
    excused: 'neutral',
};
const paymentTones = {
    paid: 'positive',
    due: 'neutral',
    late: 'warning',
    waived: 'neutral',
};
const alertCategories = [
    { key: 'late_payments', title: 'latePayments', icon: 'payments', tone: 'text-error dark:text-error-container' },
    { key: 'repeated_absences', title: 'repeatedAbsences', icon: 'event_busy', tone: 'text-error dark:text-error-container' },
    { key: 'rooms_without_active_teacher', title: 'roomsWithoutActiveTeacher', icon: 'door_front', tone: 'text-secondary dark:text-secondary-fixed-dim' },
    { key: 'inactive_users_with_pending_items', title: 'inactiveUsersWithPendingItems', icon: 'person_off', tone: 'text-secondary dark:text-secondary-fixed-dim' },
];

export default function TableauDeBordAdmin() {
    const { lang, t } = useLanguage();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [summary, setSummary] = useState(null);
    const [loading, setLoading] = useState(true);
    const [hasError, setHasError] = useState(false);

    useEffect(() => {
        const syncYear = (event) => {
            if (event?.type === 'storage' && event.key !== 'tahfiz-academic-year') return;

            const nextId = Number(event?.detail?.id ?? getSelectedAcademicYearId());
            if (Number.isInteger(nextId) && nextId > 0) setYearId(nextId);
        };

        window.addEventListener('tahfiz:academic-year-changed', syncYear);
        window.addEventListener('storage', syncYear);
        return () => {
            window.removeEventListener('tahfiz:academic-year-changed', syncYear);
            window.removeEventListener('storage', syncYear);
        };
    }, []);

    useEffect(() => {
        if (!yearId) {
            setLoading(false);
            return undefined;
        }

        const controller = new AbortController();
        let cancelled = false;

        setLoading(true);
        setHasError(false);

        apiFetch(`/admin/dashboard-summary?year_id=${encodeURIComponent(yearId)}`, {
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Failed to load dashboard summary'))))
            .then((data) => {
                if (!cancelled) setSummary(data);
            })
            .catch((error) => {
                if (!cancelled && error.name !== 'AbortError') {
                    setSummary(null);
                    setHasError(true);
                }
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [yearId]);

    const locale = lang === 'ar' ? 'ar-MA' : 'fr-MA';
    const numberFormat = new Intl.NumberFormat(locale);
    const percentFormat = new Intl.NumberFormat(locale, {
        style: 'percent',
        maximumFractionDigits: 0,
    });
    const currencyFormat = new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: summary?.currency ?? 'MAD',
    });
    const dateFormat = new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeZone: 'UTC',
    });

    const renderAlertDetails = (category, item) => {
        if (category === 'late_payments') {
            return (
                <>
                    <p className="font-medium text-on-surface dark:text-inverse-on-surface">{item.student_name}</p>
                    <p className="text-on-surface-variant dark:text-tertiary-fixed-dim">
                        {dateFormat.format(new Date(`${item.period_end}T00:00:00Z`))}
                        {' · '}
                        {new Intl.NumberFormat(locale, {
                            style: 'currency',
                            currency: item.currency,
                        }).format(Number(item.amount))}
                    </p>
                </>
            );
        }

        if (category === 'repeated_absences') {
            return (
                <>
                    <p className="font-medium text-on-surface dark:text-inverse-on-surface">{item.student_name}</p>
                    <p className="text-on-surface-variant dark:text-tertiary-fixed-dim">
                        {t.dashboardStats.alerts.absenceCount(numberFormat.format(item.absence_count))}
                        {item.room_name ? ` · ${item.room_name}` : ''}
                    </p>
                </>
            );
        }

        if (category === 'rooms_without_active_teacher') {
            return <p className="font-medium text-on-surface dark:text-inverse-on-surface">{item.room_name}</p>;
        }

        return (
            <>
                <p className="font-medium text-on-surface dark:text-inverse-on-surface">{item.user_name}</p>
                <p className="text-on-surface-variant dark:text-tertiary-fixed-dim">
                    {t.dashboardStats.alerts.pendingItems(item.active_assignments, item.future_schedules)}
                </p>
            </>
        );
    };

    if (hasError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.dashboardStats.loadError}</p>;
    }

    if (loading && !summary) {
        return <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">{t.dashboardStats.loading}</p>;
    }

    if (!summary) return null;

    return (
        <div className="flex w-full flex-col gap-5">
            <section className="flex flex-col gap-3">
                <h2 className="text-[16px] leading-6 font-semibold text-on-surface dark:text-inverse-on-surface">
                    {t.dashboardStats.keyIndicators}
                </h2>
                <div className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <StatCard label={t.dashboardStats.activeStudents} value={numberFormat.format(summary.stats.active_students)} icon="group" />
                    <StatCard label={t.dashboardStats.activeStaff} value={numberFormat.format(summary.stats.active_staff)} icon="badge" />
                    <StatCard label={t.dashboardStats.activeRooms} value={numberFormat.format(summary.stats.active_rooms)} icon="door_front" />
                </div>
            </section>

            <section className="flex flex-col gap-3">
                <h2 className="text-[16px] leading-6 font-semibold text-on-surface dark:text-inverse-on-surface">
                    {t.dashboardStats.attendanceSummary}
                </h2>
                <div className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {attendanceStatuses.map((status) => (
                        <StatCard
                            key={status}
                            label={t.dashboardStats.attendanceStatuses[status]}
                            value={numberFormat.format(summary.stats.attendance[status].count)}
                            trend={percentFormat.format(summary.stats.attendance[status].share)}
                            trendTone={attendanceTones[status]}
                            icon="fact_check"
                        />
                    ))}
                </div>
            </section>

            <section className="flex flex-col gap-3">
                <h2 className="text-[16px] leading-6 font-semibold text-on-surface dark:text-inverse-on-surface">
                    {t.dashboardStats.paymentSummary}
                </h2>
                <div className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {paymentStatuses.map((status) => (
                        <StatCard
                            key={status}
                            label={t.dashboardStats.paymentStatuses[status]}
                            value={currencyFormat.format(summary.stats.payments[status].amount)}
                            trendTone={paymentTones[status]}
                            icon="payments"
                        />
                    ))}
                </div>
            </section>

            <section aria-labelledby="dashboard-alerts-heading" className="flex flex-col gap-3">
                <h2 id="dashboard-alerts-heading" className="text-[16px] leading-6 font-semibold text-on-surface dark:text-inverse-on-surface">
                    {t.dashboardStats.alerts.title}
                </h2>
                <div className="grid w-full grid-cols-1 gap-3 lg:grid-cols-2">
                    {alertCategories.map((category) => {
                        const items = summary.alerts[category.key];

                        return (
                            <article
                                key={category.key}
                                className="min-w-0 rounded-lg border border-outline-variant bg-surface-container-lowest p-4 dark:border-tertiary-container dark:bg-inverse-surface"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <h3 className="text-[13px] leading-5 font-semibold text-on-surface dark:text-inverse-on-surface">
                                        {t.dashboardStats.alerts[category.title]}
                                    </h3>
                                    <div className={`flex shrink-0 items-center gap-1 ${category.tone}`}>
                                        <Icon name={category.icon} className="text-[18px]" />
                                        <span className="text-[12px] leading-4 font-semibold tabular-nums">{numberFormat.format(items.length)}</span>
                                    </div>
                                </div>
                                {items.length > 0 ? (
                                    <ul className="mt-3 flex flex-col gap-2">
                                        {items.map((item) => (
                                            <li
                                                key={item.id ?? item.student_id ?? item.room_id ?? item.user_id}
                                                className="border-t border-outline-variant pt-2 text-[12px] leading-5 dark:border-tertiary-container"
                                            >
                                                {renderAlertDetails(category.key, item)}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="mt-3 text-[12px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">
                                        {t.dashboardStats.alerts.empty}
                                    </p>
                                )}
                            </article>
                        );
                    })}
                </div>
            </section>
        </div>
    );
}
