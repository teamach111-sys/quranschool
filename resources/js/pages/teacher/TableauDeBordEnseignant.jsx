import React, { useEffect, useState } from 'react';
import Icon from '../../components/Icon';
import { StatStrip } from '../../components/StatCard';
import StudentProgressCharts from '../../components/StudentProgressCharts';
import { Panel, SectionHeader } from '../../components/DashboardPanels';
import { useLanguage } from '../../hooks/useLanguage';
import { apiFetch } from '../../lib/api';
import {
    ACADEMIC_YEAR_CHANGED_EVENT,
    getSelectedAcademicYearId,
} from '../../persisters/yearselector';

const cardClass = 'min-w-0 rounded-lg border border-outline-variant bg-surface-container-lowest p-4 dark:border-[#3a3835] dark:bg-inverse-surface';
const textClass = 'text-on-surface dark:text-inverse-on-surface';
const secondaryTextClass = 'text-on-surface-variant dark:text-tertiary-fixed-dim';
const linkClass = 'shrink-0 text-[12px] leading-5 font-semibold text-primary hover:underline dark:text-primary-fixed-dim';
const quickLinkClass = 'group flex min-w-0 items-center gap-2 rounded-lg border border-outline-variant bg-surface-container-lowest p-3 text-on-surface transition-[color,transform,border-color] duration-200 hover:border-primary/40 hover:bg-surface-container-low active:translate-y-px dark:border-[#3a3835] dark:bg-inverse-surface dark:text-inverse-on-surface dark:hover:bg-[#2a2928]';
const DASHBOARD_LIST_PREVIEW_LIMIT = 5;
const dayKeys = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

export default function TableauDeBordEnseignant() {
    const { isRTL, lang, t } = useLanguage();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [dashboard, setDashboard] = useState(null);
    const [loading, setLoading] = useState(true);
    const [hasError, setHasError] = useState(false);

    useEffect(() => {
        const syncYear = (event) => {
            const nextId = Number(event?.detail?.id ?? getSelectedAcademicYearId());
            setYearId(Number.isInteger(nextId) && nextId > 0 ? nextId : null);
            setDashboard(null);
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

        apiFetch(`/teacher/dashboard-summary${query}`, { signal: controller.signal })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Failed to load teacher dashboard'))))
            .then((data) => {
                if (!cancelled) {
                    setDashboard(data);
                }
            })
            .catch((error) => {
                if (!cancelled && error.name !== 'AbortError') {
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

    const locale = lang === 'ar' ? 'ar-MA-u-nu-latn' : 'fr-MA';
    const numberFormat = new Intl.NumberFormat(locale);
    const pendingAttendanceSlotIds = new Set(
        dashboard?.attendance_tasks?.slots?.map((slot) => slot.id) ?? [],
    );
    const todayLessons = dashboard?.today_lessons ?? [];
    const visibleTodayLessons = todayLessons.slice(0, DASHBOARD_LIST_PREVIEW_LIMIT);
    const actionableLesson = todayLessons.find((lesson) => (
        lesson.id === dashboard?.today_focus_lesson_id || pendingAttendanceSlotIds.has(lesson.id)
    ));
    if (actionableLesson && !visibleTodayLessons.some((lesson) => lesson.id === actionableLesson.id)) {
        visibleTodayLessons[visibleTodayLessons.length < DASHBOARD_LIST_PREVIEW_LIMIT
            ? visibleTodayLessons.length
            : DASHBOARD_LIST_PREVIEW_LIMIT - 1] = actionableLesson;
        visibleTodayLessons.sort((first, second) => first.starts_at.localeCompare(second.starts_at));
    }
    const visibleAttendanceStudents = dashboard?.attendance_tasks?.students
        ?.slice(0, DASHBOARD_LIST_PREVIEW_LIMIT) ?? [];
    const quickLinks = [
        { key: 'groups', icon: 'groups', label: t.teacherDashboard.quickLinks.groups, href: '#/mes-groupes-eleves' },
        { key: 'schedule', icon: 'event', label: t.teacherDashboard.quickLinks.schedule, href: '#/mon-emploi-du-temps' },
        { key: 'attendance', icon: 'fact_check', label: t.teacherDashboard.quickLinks.attendance, href: '#/presences' },
        { key: 'sessions', icon: 'menu_book', label: t.teacherDashboard.quickLinks.sessions, href: '#/seances-memorisation' },
    ];
    if (loading && !dashboard) {
        return (
            <div role="status" aria-label={t.teacherDashboard.loading} className="flex w-full flex-col gap-5">
                <div className="grid w-full grid-cols-1 gap-3 sm:grid-cols-3">
                    {dayKeys.slice(0, 3).map((day) => (
                        <div key={day} className={`${cardClass} h-24 animate-pulse bg-surface-container dark:bg-[#2a2928]`} />
                    ))}
                </div>
                <div className={`${cardClass} h-64 animate-pulse bg-surface-container dark:bg-[#2a2928]`} />
                <div className="grid w-full grid-cols-1 gap-5 xl:grid-cols-2">
                    {dayKeys.slice(0, 2).map((day) => (
                        <div key={day} className={`${cardClass} h-56 animate-pulse bg-surface-container dark:bg-[#2a2928]`} />
                    ))}
                </div>
            </div>
        );
    }

    if (!dashboard) {
        return (
            <p role="alert" className="text-[13px] leading-5 text-error dark:text-[#e59a92]">{t.teacherDashboard.loadError}</p>
        );
    }

    return (
        <div dir={isRTL ? 'rtl' : 'ltr'} className="flex w-full flex-col gap-5">
            {hasError && (
                <div className="rounded-lg border border-error/40 bg-error-container/40 p-3 dark:bg-[#3a2624]/40">
                    <p role="alert" className="text-[13px] leading-5 text-error dark:text-[#e59a92]">{t.teacherDashboard.loadError}</p>
                </div>
            )}
            <StatStrip
                ariaLabel={t.teacherDashboard.overview}
                className="sm:grid-cols-3"
                items={[
                    { label: t.teacherDashboard.groups, value: numberFormat.format(dashboard.groups.length), icon: 'groups' },
                    { label: t.teacherDashboard.todayLessons, value: numberFormat.format(dashboard.today_lessons.length), icon: 'event' },
                    { label: t.teacherDashboard.pendingAttendance, value: numberFormat.format(dashboard.attendance_tasks.count), icon: 'fact_check' },
                ]}
            />

            <section
                aria-label={t.teacherDashboard.quickLinksTitle}
                className="grid w-full grid-cols-2 gap-2 sm:grid-cols-4"
            >
                {quickLinks.map((link) => (
                    <a key={link.key} href={link.href} className={quickLinkClass}>
                        <Icon name={link.icon} className="shrink-0 text-[18px] text-primary" />
                        <span className="min-w-0 flex-1 truncate text-[12px] leading-4 font-semibold">
                            {link.label}
                        </span>
                        <Icon
                            name={isRTL ? 'arrow_back' : 'arrow_forward'}
                            className="shrink-0 text-[14px] text-on-surface-variant dark:text-[#918d88]"
                        />
                    </a>
                ))}
            </section>

            <StudentProgressCharts progress={dashboard.student_progress} />

            <div className="grid w-full grid-cols-1 gap-5 xl:grid-cols-2">
                <section className="flex min-w-0 flex-col gap-3">
                    <SectionHeader
                        title={t.teacherDashboard.todayLessons}
                        action={<a href="#/mon-emploi-du-temps" className={linkClass}>{t.teacherDashboard.viewSchedule}</a>}
                    />
                    {dashboard.today_lessons.length > 0 ? (
                        <Panel>
                            <ul className="divide-y divide-outline-variant dark:divide-[#3a3835]">
                                {visibleTodayLessons.map((lesson) => (
                                    <li key={lesson.id} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="flex min-w-0 items-center justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className={`truncate text-[14px] leading-5 font-semibold ${textClass}`}>{lesson.label}</p>
                                                <p className={`text-[12px] leading-5 ${secondaryTextClass}`}>{lesson.room_name}</p>
                                            </div>
                                            <span className="shrink-0 rounded-md bg-primary-fixed px-2 py-1 text-[12px] leading-5 font-medium text-on-primary-fixed-variant dark:bg-[#32302e] dark:text-[#918d88] sm:hidden">
                                                {lesson.starts_at} – {lesson.ends_at}
                                            </span>
                                        </div>
                                        <span className="hidden shrink-0 rounded-md bg-primary-fixed px-2 py-1 text-[12px] leading-5 font-medium text-on-primary-fixed-variant dark:bg-[#32302e] dark:text-[#918d88] sm:inline-flex">
                                            {lesson.starts_at} – {lesson.ends_at}
                                        </span>
                                        {lesson.students.some((student) => student.status === null && student.can_edit)
                                            && (lesson.id === dashboard.today_focus_lesson_id || pendingAttendanceSlotIds.has(lesson.id))
                                            && (
                                            <a
                                                href={`#/presences?room_id=${lesson.room_id}&attendance_date=${dashboard.today}`}
                                                className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:w-auto dark:bg-[#2f7d4f] dark:text-white"
                                            >
                                                <Icon name="fact_check" className="text-[18px]" />
                                                {t.teacherDashboard.startTodayAttendance}
                                            </a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    ) : (
                        <Panel className="p-4">
                            <p className={`text-[13px] leading-5 ${secondaryTextClass}`}>{t.teacherDashboard.noTodayLessons}</p>
                        </Panel>
                    )}
                </section>

                <section className="flex min-w-0 flex-col gap-3">
                    <SectionHeader
                        title={t.teacherDashboard.pendingAttendance}
                        action={<a href="#/presences" className={linkClass}>{t.teacherDashboard.takeAttendance}</a>}
                    />
                    {dashboard.attendance_tasks.students.length > 0 ? (
                        <Panel>
                            <ul className="divide-y divide-outline-variant dark:divide-[#3a3835]">
                                {visibleAttendanceStudents.map((student) => (
                                    <li key={student.student_id} className="flex items-center justify-between gap-3 px-4 py-3">
                                        <p className={`min-w-0 truncate text-[13px] leading-5 font-medium ${textClass}`}>{student.student_name}</p>
                                        <span className={`shrink-0 text-[12px] leading-5 ${secondaryTextClass}`}>{student.room_name}</span>
                                    </li>
                                ))}
                            </ul>
                            {dashboard.attendance_tasks.count > visibleAttendanceStudents.length && (
                                <p className={`border-t border-outline-variant px-4 py-3 text-[12px] leading-5 dark:border-[#3a3835] ${secondaryTextClass}`}>
                                    {t.teacherDashboard.moreAttendanceTasks(numberFormat.format(dashboard.attendance_tasks.count - visibleAttendanceStudents.length))}
                                </p>
                            )}
                        </Panel>
                    ) : (
                        <Panel className="p-4">
                            <p className={`text-[13px] leading-5 ${secondaryTextClass}`}>{t.teacherDashboard.noPendingAttendance}</p>
                        </Panel>
                    )}
                </section>
            </div>
        </div>
    );
}
