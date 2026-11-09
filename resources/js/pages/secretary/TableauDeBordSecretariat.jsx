import React, { useEffect, useState } from 'react';
import { StatStrip } from '../../components/StatCard';
import { BarsChart, ChartCard, useChartColors } from '../../components/DashboardCharts';
import { Panel, SectionHeader } from '../../components/DashboardPanels';
import { useLanguage } from '../../hooks/useLanguage';
import { apiFetch } from '../../lib/api';
import {
    ACADEMIC_YEAR_CHANGED_EVENT,
    getSelectedAcademicYearId,
} from '../../persisters/yearselector';

const textClass = 'text-on-surface dark:text-inverse-on-surface';
const secondaryTextClass = 'text-on-surface-variant dark:text-tertiary-fixed-dim';
const linkClass = 'shrink-0 text-[12px] leading-5 font-semibold text-primary hover:underline dark:text-primary-fixed-dim';
const DASHBOARD_LIST_PREVIEW_LIMIT = 5;

export default function TableauDeBordSecretariat() {
    const { lang, t } = useLanguage();
    const colors = useChartColors();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [dashboard, setDashboard] = useState(null);
    const [loading, setLoading] = useState(true);
    const [hasError, setHasError] = useState(false);

    useEffect(() => {
        const syncYear = (event) => {
            const nextId = Number(event?.detail?.id ?? getSelectedAcademicYearId());
            setYearId(Number.isInteger(nextId) && nextId > 0 ? nextId : null);
        };

        window.addEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
        return () => window.removeEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        const query = yearId ? `?year_id=${encodeURIComponent(yearId)}` : '';
        let cancelled = false;

        setLoading(true);
        setHasError(false);

        apiFetch(`/secretary/dashboard-summary${query}`, { signal: controller.signal })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Failed to load secretary dashboard'))))
            .then((data) => {
                if (!cancelled) setDashboard(data);
            })
            .catch((error) => {
                if (!cancelled && error.name !== 'AbortError') {
                    setDashboard(null);
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
    const dateFormat = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' });
    const currencyFormat = new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: dashboard?.currency ?? 'MAD',
    });
    const formatDate = (date) => dateFormat.format(new Date(`${date}T00:00:00Z`));

    if (hasError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-[#e59a92]">{t.secretaryDashboard.loadError}</p>;
    }

    if (loading && !dashboard) {
        return <p role="status" className={`text-[13px] leading-5 ${secondaryTextClass}`}>{t.secretaryDashboard.loading}</p>;
    }

    if (!dashboard) return null;

    const overviewCards = [
        { label: t.secretaryDashboard.activeStudents, value: numberFormat.format(dashboard.stats.active_students), icon: 'group' },
        { label: t.secretaryDashboard.newStudents, value: numberFormat.format(dashboard.stats.new_students), icon: 'person_add' },
        { label: t.secretaryDashboard.incompleteStudents, value: numberFormat.format(dashboard.stats.incomplete_students), icon: 'assignment_late', trendTone: dashboard.stats.incomplete_students > 0 ? 'warning' : 'positive' },
        { label: t.secretaryDashboard.attendanceFollowUp, value: numberFormat.format(dashboard.stats.attendance_follow_up), icon: 'fact_check', trendTone: dashboard.stats.attendance_follow_up > 0 ? 'warning' : 'positive' },
    ];
    const paymentChartData = [
        { key: 'due', name: t.secretaryDashboard.dueBalance, value: dashboard.stats.payment_balances.due.amount, color: colors.blue },
        { key: 'late', name: t.secretaryDashboard.lateBalance, value: dashboard.stats.payment_balances.late.amount, color: colors.amber },
        { key: 'waived', name: t.secretaryDashboard.waivedBalance, value: dashboard.stats.payment_balances.waived.amount, color: colors.gray },
    ];

    return (
        <div className="flex w-full flex-col gap-5">
            <StatStrip
                ariaLabel={t.secretaryDashboard.overview}
                className="sm:grid-cols-2 xl:grid-cols-4"
                items={overviewCards}
            />

            <ChartCard
                title={t.secretaryDashboard.paymentSummary}
                subtitle={t.secretaryDashboard.charts.paymentBalancesHint}
            >
                <BarsChart
                    data={paymentChartData}
                    colors={colors}
                    height={200}
                    valueFormatter={(value) => currencyFormat.format(value)}
                />
            </ChartCard>

            <div className="grid w-full grid-cols-1 gap-5 xl:grid-cols-2">
                <section className="flex min-w-0 flex-col gap-3">
                    <SectionHeader
                        title={t.secretaryDashboard.students}
                        action={<a href="#/eleves" className={linkClass}>{t.secretaryDashboard.viewStudents}</a>}
                    />
                    {dashboard.new_or_incomplete_students.length > 0 ? (
                        <Panel>
                            <ul className="divide-y divide-outline-variant dark:divide-[#3a3835]">
                                {dashboard.new_or_incomplete_students.slice(0, DASHBOARD_LIST_PREVIEW_LIMIT).map((student) => (
                                    <li key={student.id} className="p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className={`truncate text-[13px] leading-5 font-semibold ${textClass}`}>{student.student_name}</p>
                                                <p className={`text-[12px] leading-5 ${secondaryTextClass}`}>
                                                    {student.student_number || t.secretaryDashboard.missingFields.student_number}
                                                    {' · '}
                                                    {formatDate(student.created_at.slice(0, 10))}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 flex-wrap justify-end gap-1">
                                                <span className="rounded-md bg-surface-container px-2 py-1 text-[11px] leading-4 font-medium text-on-surface-variant dark:bg-[#2a2928] dark:text-[#c6c2bd]">
                                                    {t.secretaryDashboard.studentStatuses[student.status]}
                                                </span>
                                                {student.is_new && (
                                                    <span className="rounded-md bg-primary-fixed px-2 py-1 text-[11px] leading-4 font-medium text-on-primary-fixed-variant dark:bg-[#32302e] dark:text-[#918d88]">
                                                        {t.secretaryDashboard.newBadge}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        {student.missing_fields.length > 0 && (
                                            <div className="mt-2 flex flex-wrap gap-1.5">
                                                {student.missing_fields.map((field) => (
                                                    <span key={field} className="rounded-md bg-secondary-container px-2 py-1 text-[11px] leading-4 font-medium text-on-secondary-container dark:bg-secondary dark:text-on-secondary">
                                                        {t.secretaryDashboard.missingFields[field]}
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    ) : (
                        <Panel className="p-4">
                            <p className={`text-[13px] leading-5 ${secondaryTextClass}`}>{t.secretaryDashboard.studentsEmpty}</p>
                        </Panel>
                    )}
                </section>

                <section className="flex min-w-0 flex-col gap-3">
                    <SectionHeader
                        title={t.secretaryDashboard.attendance}
                        action={<a href="#/presences" className={linkClass}>{t.secretaryDashboard.viewAttendance}</a>}
                    />
                    {dashboard.attendance_follow_up_items.length > 0 ? (
                        <Panel>
                            <ul className="divide-y divide-outline-variant dark:divide-[#3a3835]">
                                {dashboard.attendance_follow_up_items.slice(0, DASHBOARD_LIST_PREVIEW_LIMIT).map((item) => (
                                    <li key={item.id} className="flex items-center justify-between gap-3 p-4">
                                        <div className="min-w-0">
                                            <p className={`truncate text-[13px] leading-5 font-semibold ${textClass}`}>{item.student_name}</p>
                                            <p className={`text-[12px] leading-5 ${secondaryTextClass}`}>
                                                {item.room_name ?? t.secretaryDashboard.noGroup}
                                                {' · '}
                                                {formatDate(item.attendance_date)}
                                            </p>
                                        </div>
                                        <span className={`shrink-0 rounded-md px-2 py-1 text-[11px] leading-4 font-medium ${item.status === 'absent' ? 'bg-error-container text-on-error-container dark:bg-error dark:text-on-error' : 'bg-secondary-container text-on-secondary-container dark:bg-secondary dark:text-on-secondary'}`}>
                                            {t.secretaryDashboard.attendanceStatuses[item.status]}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    ) : (
                        <Panel className="p-4">
                            <p className={`text-[13px] leading-5 ${secondaryTextClass}`}>{t.secretaryDashboard.attendanceEmpty}</p>
                        </Panel>
                    )}
                </section>
            </div>
        </div>
    );
}
