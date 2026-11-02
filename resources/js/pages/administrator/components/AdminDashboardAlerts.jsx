import React from 'react';
import Icon from '../../../components/Icon';
import { useLanguage } from '../../../hooks/useLanguage';

const alertCategories = [
    { key: 'missed_attendance', title: 'missedAttendance', icon: 'fact_check', tone: 'text-error dark:text-error-container' },
    { key: 'late_payments', title: 'latePayments', icon: 'payments', tone: 'text-error dark:text-error-container' },
    { key: 'repeated_absences', title: 'repeatedAbsences', icon: 'event_busy', tone: 'text-error dark:text-error-container' },
    { key: 'rooms_without_active_teacher', title: 'roomsWithoutActiveTeacher', icon: 'door_front', tone: 'text-secondary dark:text-secondary-fixed-dim' },
    { key: 'inactive_users_with_pending_items', title: 'inactiveUsersWithPendingItems', icon: 'person_off', tone: 'text-secondary dark:text-secondary-fixed-dim' },
];

function alertRecordUrl(category, item) {
    if (category === 'missed_attendance') {
        if (!item.room_id || !item.attendance_date) return null;

        const query = new URLSearchParams({
            room_id: String(item.room_id),
            attendance_date: item.attendance_date,
        });
        return `#/presences?${query.toString()}`;
    }

    const target = {
        late_payments: { page: 'paiements-finances', parameter: 'payment_id', id: item.id },
        repeated_absences: { page: 'presences', parameter: 'student_id', id: item.student_id },
        rooms_without_active_teacher: { page: 'groupes-attribution', parameter: 'room_id', id: item.room_id },
        inactive_users_with_pending_items: { page: 'utilisateurs-roles', parameter: 'user_id', id: item.user_id },
    }[category];

    if (!target?.id) return null;

    const query = new URLSearchParams({ [target.parameter]: String(target.id) });
    return `#/${target.page}?${query.toString()}`;
}

export default function AdminDashboardAlerts({ alerts }) {
    const { isRTL, lang, t } = useLanguage();
    const locale = lang === 'ar' ? 'ar-MA' : 'fr-MA';
    const numberFormat = new Intl.NumberFormat(locale);
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

        if (category === 'missed_attendance') {
            return (
                <>
                    <p className="font-medium text-on-surface dark:text-inverse-on-surface">
                        {item.room_name} · {dateFormat.format(new Date(`${item.attendance_date}T00:00:00Z`))}
                    </p>
                    <p className="text-on-surface-variant dark:text-tertiary-fixed-dim">
                        {item.starts_at}–{item.ends_at}
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

    return (
        <section aria-labelledby="dashboard-alerts-heading" className="flex flex-col gap-3">
            <h2 id="dashboard-alerts-heading" className="text-[16px] leading-6 font-semibold text-on-surface dark:text-inverse-on-surface">
                {t.dashboardStats.alerts.title}
            </h2>
            <div className="grid w-full grid-cols-1 gap-3 lg:grid-cols-2">
                {alertCategories.map((category) => {
                    const items = alerts[category.key];

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
                                    {items.map((item) => {
                                        const href = alertRecordUrl(category.key, item);
                                        const itemKey = category.key === 'missed_attendance'
                                            ? `${item.room_id}-${item.attendance_date}`
                                            : item.id ?? item.student_id ?? item.room_id ?? item.user_id;

                                        return (
                                            <li
                                                key={itemKey}
                                                className="border-t border-outline-variant pt-2 text-[12px] leading-5 dark:border-tertiary-container"
                                            >
                                                {href ? (
                                                    <a
                                                        href={href}
                                                        className="flex min-h-11 flex-col gap-1 rounded px-2 py-1 text-on-surface outline-none transition-colors hover:bg-surface-container focus-visible:ring-2 focus-visible:ring-primary dark:text-inverse-on-surface dark:hover:bg-tertiary-container"
                                                    >
                                                        {renderAlertDetails(category.key, item)}
                                                        <span className="inline-flex items-center gap-1 font-semibold text-primary dark:text-primary-fixed-dim">
                                                            {t.dashboardStats.alerts.openRecord}
                                                            <Icon name={isRTL ? 'arrow_back' : 'arrow_forward'} className="text-[15px]" />
                                                        </span>
                                                    </a>
                                                ) : (
                                                    <div className="flex flex-col gap-1 px-2 py-1">
                                                        {renderAlertDetails(category.key, item)}
                                                        <span role="status" className="text-error dark:text-error-container">
                                                            {t.dashboardStats.alerts.recordUnavailable}
                                                        </span>
                                                    </div>
                                                )}
                                            </li>
                                        );
                                    })}
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
    );
}
