import React, { useEffect, useState } from 'react';
import { StatStrip } from '../../components/StatCard';
import { BarsChart, ChartCard, DonutChart, useChartColors } from '../../components/DashboardCharts';
import StudentProgressCharts from '../../components/StudentProgressCharts';
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
    const currencyFormat = new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: summary?.currency ?? 'MAD',
    });
    const colors = useChartColors();
    const toneColor = {
        positive: colors.green,
        negative: colors.red,
        warning: colors.amber,
        neutral: colors.gray,
    };
    const attendanceChartData = attendanceStatuses.map((status) => ({
        key: status,
        name: t.dashboardStats.attendanceStatuses[status],
        value: summary?.stats.attendance[status].count ?? 0,
        color: toneColor[attendanceTones[status]],
    }));
    const attendanceTotal = attendanceChartData.reduce((sum, item) => sum + item.value, 0);
    const paymentChartData = paymentStatuses.map((status) => ({
        key: status,
        name: t.dashboardStats.paymentStatuses[status],
        value: summary?.stats.payments[status].amount ?? 0,
        color: toneColor[paymentTones[status]],
    }));
    if (hasError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-[#e59a92]">{t.dashboardStats.loadError}</p>;
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
                <StatStrip
                    ariaLabel={t.dashboardStats.keyIndicators}
                    className="sm:grid-cols-3"
                    items={[
                        { label: t.dashboardStats.activeStudents, value: numberFormat.format(summary.stats.active_students), icon: 'group' },
                        { label: t.dashboardStats.activeStaff, value: numberFormat.format(summary.stats.active_staff), icon: 'badge' },
                        { label: t.dashboardStats.activeRooms, value: numberFormat.format(summary.stats.active_rooms), icon: 'door_front' },
                    ]}
                />
            </section>

            <div className="grid w-full grid-cols-1 gap-4 xl:grid-cols-2">
                <ChartCard
                    title={t.dashboardStats.attendanceSummary}
                    subtitle={t.dashboardStats.charts.attendanceHint}
                >
                    <DonutChart
                        data={attendanceChartData}
                        centerValue={numberFormat.format(attendanceTotal)}
                        centerLabel={t.dashboardStats.charts.total}
                        valueFormatter={(value) => numberFormat.format(value)}
                    />
                </ChartCard>

                <ChartCard
                    title={t.dashboardStats.paymentSummary}
                    subtitle={t.dashboardStats.charts.paymentsHint}
                >
                    <BarsChart
                        data={paymentChartData}
                        colors={colors}
                        height={190}
                        valueFormatter={(value) => currencyFormat.format(value)}
                    />
                </ChartCard>
            </div>

            <StudentProgressCharts progress={summary.student_progress} />

            <AdminDashboardAlerts alerts={summary.alerts} />
        </div>
    );
}
