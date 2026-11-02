import React, { useEffect, useState } from 'react';
import Icon from '../../components/Icon';
import StatCard from '../../components/StatCard';
import { useLanguage } from '../../hooks/useLanguage';
import { apiFetch } from '../../lib/api';
import {
    ACADEMIC_YEAR_CHANGED_EVENT,
    getSelectedAcademicYearId,
} from '../../persisters/yearselector';

const cardClass = 'min-w-0 rounded-lg border border-outline-variant bg-surface-container-lowest p-4 dark:border-tertiary-container dark:bg-inverse-surface';
const textClass = 'text-on-surface dark:text-inverse-on-surface';
const secondaryTextClass = 'text-on-surface-variant dark:text-tertiary-fixed-dim';
const alertCategories = ['late_payments', 'repeated_absences', 'incomplete_student_records'];
const paymentToneClasses = {
    paid: 'bg-primary-fixed text-on-primary-fixed-variant dark:bg-primary-container dark:text-on-primary-container',
    due: 'bg-surface-container text-on-surface-variant dark:bg-tertiary-container dark:text-on-tertiary-container',
    late: 'bg-secondary-container text-on-secondary-container dark:bg-secondary dark:text-on-secondary',
    waived: 'bg-surface-container text-on-surface-variant dark:bg-tertiary-container dark:text-on-tertiary-container',
};

export default function TableauDeBordSecretariat() {
    const { lang, t } = useLanguage();
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
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.secretaryDashboard.loadError}</p>;
    }

    if (loading && !dashboard) {
        return <p role="status" className={`text-[13px] leading-5 ${secondaryTextClass}`}>{t.secretaryDashboard.loading}</p>;
    }

    if (!dashboard) return null;

    const statCards = [
        { label: t.secretaryDashboard.activeStudents, value: numberFormat.format(dashboard.stats.active_students), icon: 'group' },
        { label: t.secretaryDashboard.newStudents, value: numberFormat.format(dashboard.stats.new_students), icon: 'person_add' },
        { label: t.secretaryDashboard.incompleteStudents, value: numberFormat.format(dashboard.stats.incomplete_students), icon: 'assignment_late', trendTone: dashboard.stats.incomplete_students > 0 ? 'warning' : 'positive' },
        { label: t.secretaryDashboard.attendanceFollowUp, value: numberFormat.format(dashboard.stats.attendance_follow_up), icon: 'fact_check', trendTone: dashboard.stats.attendance_follow_up > 0 ? 'warning' : 'positive' },
        { label: t.secretaryDashboard.dueBalance, value: currencyFormat.format(dashboard.stats.payment_balances.due.amount), icon: 'payments' },
        { label: t.secretaryDashboard.lateBalance, value: currencyFormat.format(dashboard.stats.payment_balances.late.amount), icon: 'warning', trendTone: dashboard.stats.payment_balances.late.amount > 0 ? 'warning' : 'positive' },
    ];

    return (
        <div className="flex w-full flex-col gap-5">
            <section aria-label={t.secretaryDashboard.overview} className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {statCards.map((card) => (
                    <StatCard
                        key={card.label}
                        label={card.label}
                        value={card.value}
                        icon={card.icon}
                        trendTone={card.trendTone}
                    />
                ))}
            </section>

            <div className="grid w-full grid-cols-1 gap-5 xl:grid-cols-2">
                <section className="flex min-w-0 flex-col gap-3">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className={`text-[16px] leading-6 font-semibold ${textClass}`}>{t.secretaryDashboard.students}</h2>
                        <a href="#/eleves" className="shrink-0 text-[12px] leading-5 font-semibold text-primary hover:underline dark:text-primary-fixed-dim">
                            {t.secretaryDashboard.viewStudents}
                        </a>
                    </div>
                    {dashboard.new_or_incomplete_students.length > 0 ? (
                        <ul className="flex flex-col gap-2">
                            {dashboard.new_or_incomplete_students.map((student) => (
                                <li key={student.id} className={cardClass}>
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
                                            <span className="rounded-md bg-surface-container px-2 py-1 text-[11px] leading-4 font-medium text-on-surface-variant dark:bg-tertiary-container dark:text-on-tertiary-container">
                                                {t.secretaryDashboard.studentStatuses[student.status]}
                                            </span>
                                            {student.is_new && (
                                                <span className="rounded-md bg-primary-fixed px-2 py-1 text-[11px] leading-4 font-medium text-on-primary-fixed-variant dark:bg-primary-container dark:text-on-primary-container">
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
                    ) : (
                        <p className={`${cardClass} text-[13px] leading-5 ${secondaryTextClass}`}>{t.secretaryDashboard.studentsEmpty}</p>
                    )}
                </section>

                <section className="flex min-w-0 flex-col gap-3">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className={`text-[16px] leading-6 font-semibold ${textClass}`}>{t.secretaryDashboard.attendance}</h2>
                        <a href="#/presences" className="shrink-0 text-[12px] leading-5 font-semibold text-primary hover:underline dark:text-primary-fixed-dim">
                            {t.secretaryDashboard.viewAttendance}
                        </a>
                    </div>
                    {dashboard.attendance_follow_up_items.length > 0 ? (
                        <ul className="flex flex-col gap-2">
                            {dashboard.attendance_follow_up_items.map((item) => (
                                <li key={item.id} className={`${cardClass} flex items-center justify-between gap-3`}>
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
                    ) : (
                        <p className={`${cardClass} text-[13px] leading-5 ${secondaryTextClass}`}>{t.secretaryDashboard.attendanceEmpty}</p>
                    )}
                </section>
            </div>

            <div className="grid w-full grid-cols-1 gap-5 xl:grid-cols-2">
                <section className="flex min-w-0 flex-col gap-3">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className={`text-[16px] leading-6 font-semibold ${textClass}`}>{t.secretaryDashboard.recentPayments}</h2>
                        <a href="#/paiements-finances" className="shrink-0 text-[12px] leading-5 font-semibold text-primary hover:underline dark:text-primary-fixed-dim">
                            {t.secretaryDashboard.viewPayments}
                        </a>
                    </div>
                    {dashboard.recent_payments.length > 0 ? (
                        <ul className="flex flex-col gap-2">
                            {dashboard.recent_payments.map((payment) => (
                                <li key={payment.id} className={`${cardClass} flex items-center justify-between gap-3`}>
                                    <div className="min-w-0">
                                        <p className={`truncate text-[13px] leading-5 font-semibold ${textClass}`}>{payment.student_name}</p>
                                        <p className={`text-[12px] leading-5 ${secondaryTextClass}`}>{formatDate(payment.created_at.slice(0, 10))}</p>
                                    </div>
                                    <div className="flex shrink-0 flex-col items-end gap-1">
                                        <span className={`rounded-md px-2 py-1 text-[11px] leading-4 font-medium ${paymentToneClasses[payment.status]}`}>
                                            {t.secretaryDashboard.paymentStatuses[payment.status]}
                                        </span>
                                        <span className={`text-[12px] leading-5 font-medium ${textClass}`}>
                                            {new Intl.NumberFormat(locale, { style: 'currency', currency: payment.currency }).format(payment.amount)}
                                        </span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className={`${cardClass} text-[13px] leading-5 ${secondaryTextClass}`}>{t.secretaryDashboard.paymentsEmpty}</p>
                    )}
                </section>

                <section className="flex min-w-0 flex-col gap-3">
                    <h2 className={`text-[16px] leading-6 font-semibold ${textClass}`}>{t.secretaryDashboard.alertsTitle}</h2>
                    {alertCategories.some((category) => dashboard.alerts[category] > 0) ? (
                        <ul className="flex flex-col gap-2">
                            {alertCategories
                                .filter((category) => dashboard.alerts[category] > 0)
                                .map((category) => (
                                    <li key={category} className={`${cardClass} flex items-center gap-3`}>
                                        <Icon name="warning" className="shrink-0 text-[18px] text-secondary dark:text-secondary-fixed-dim" />
                                        <p className={`text-[13px] leading-5 font-medium ${textClass}`}>
                                            {t.secretaryDashboard.alerts[category](numberFormat.format(dashboard.alerts[category]))}
                                        </p>
                                    </li>
                                ))}
                        </ul>
                    ) : (
                        <p className={`${cardClass} text-[13px] leading-5 ${secondaryTextClass}`}>{t.secretaryDashboard.alertsEmpty}</p>
                    )}
                </section>
            </div>
        </div>
    );
}
