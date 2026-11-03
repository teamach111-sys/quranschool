import React, { useEffect, useState } from 'react';
import StatCard from '../../components/StatCard';
import { apiFetch } from '../../lib/api';
import { getSelectedAcademicYearId } from '../../persisters/yearselector';
import { useLanguage } from '../../hooks/useLanguage';
import AdminDashboardAlerts from './components/AdminDashboardAlerts';

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

            <AdminDashboardAlerts
                alerts={summary.alerts}
                latePaymentRollover={summary.late_payment_rollover}
            />
        </div>
    );
}
