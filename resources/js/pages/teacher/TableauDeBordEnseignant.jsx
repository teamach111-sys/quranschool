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
    const dateFormat = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' });
    const dayNames = dayKeys.map((day) => t.schedules.days[day]);
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
    const formatDate = (date) => dateFormat.format(new Date(`${date}T00:00:00Z`));
    if (loading && !dashboard) {
        return (
            <div role="status" aria-label={t.teacherDashboard.loading} className="flex w-full flex-col gap-5">
                <div className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {dayKeys.slice(0, 4).map((day) => (
                        <div key={day} className={`${cardClass} h-24 animate-pulse bg-surface-container dark:bg-tertiary-container`} />
                    ))}
                </div>
                <div className="flex flex-col gap-3">
                    <div className="h-6 w-32 animate-pulse rounded bg-surface-container dark:bg-tertiary-container" />
                    <div className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {dayKeys.slice(0, 3).map((day) => (
                            <div key={day} className={`${cardClass} h-16 animate-pulse bg-surface-container dark:bg-tertiary-container`} />
                        ))}
                    </div>
                </div>
                <div className="grid w-full grid-cols-1 gap-5 xl:grid-cols-2">
                    {dayKeys.slice(0, 2).map((day) => (
                        <div key={day} className={`${cardClass} h-56 animate-pulse bg-surface-container dark:bg-tertiary-container`} />
                    ))}
                </div>
                <div className="grid w-full grid-cols-1 gap-5 xl:grid-cols-2">
                    {dayKeys.slice(2, 4).map((day) => (
                        <div key={day} className={`${cardClass} h-56 animate-pulse bg-surface-container dark:bg-tertiary-container`} />
                    ))}
                </div>
            </div>
        );
    }

    if (!dashboard) {
        return (
            <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.teacherDashboard.loadError}</p>
        );
    }

    return (
        <div dir={isRTL ? 'rtl' : 'ltr'} className="flex w-full flex-col gap-5">
            {hasError && (
                <div className="rounded-lg border border-error/40 bg-error-container/40 p-3">
                    <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.teacherDashboard.loadError}</p>
                </div>
            )}
            <section aria-label={t.teacherDashboard.overview} className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label={t.teacherDashboard.groups} value={numberFormat.format(dashboard.groups.length)} icon="groups" />
                <StatCard label={t.teacherDashboard.todayLessons} value={numberFormat.format(dashboard.today_lessons.length)} icon="event" />
                <StatCard label={t.teacherDashboard.pendingAttendance} value={numberFormat.format(dashboard.attendance_tasks.count)} icon="fact_check" trendTone={dashboard.attendance_tasks.count > 0 ? 'warning' : 'positive'} />
                <StatCard label={t.teacherDashboard.recentSessions} value={numberFormat.format(dashboard.recent_sessions.length)} icon="menu_book" />
            </section>

            <section className="flex flex-col gap-3">
                <div className="flex items-center justify-between gap-3">
                    <h2 className={`text-[16px] leading-6 font-semibold ${textClass}`}>{t.teacherDashboard.groups}</h2>
                    <a href="#/mes-groupes-eleves" className="text-[12px] leading-5 font-semibold text-primary hover:underline dark:text-primary-fixed-dim">
                        {t.teacherDashboard.viewGroups}
                    </a>
                </div>
                {dashboard.groups.length > 0 ? (
                    <ul className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {dashboard.groups.slice(0, DASHBOARD_LIST_PREVIEW_LIMIT).map((group) => (
                            <li key={group.id} className={cardClass}>
                                <div className="flex items-center justify-between gap-3">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <span className="flex size-10 shrink-0 items-center justify-center rounded-md bg-primary-fixed text-primary dark:bg-primary-container dark:text-on-primary-container">
                                            <Icon name="school" className="text-[20px]" />
                                        </span>
                                        <p className={`truncate text-[14px] leading-5 font-semibold ${textClass}`}>{group.name}</p>
                                    </div>
                                    <span className={`shrink-0 text-[12px] leading-5 ${secondaryTextClass}`}>
                                        {t.teacherDashboard.activeStudents(numberFormat.format(group.active_student_count))}
                                    </span>
                                </div>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className={`rounded-lg border border-outline-variant bg-surface-container-lowest p-4 text-[13px] leading-5 dark:border-tertiary-container dark:bg-inverse-surface ${secondaryTextClass}`}>
                        {t.teacherDashboard.noGroups}
                    </p>
                )}
            </section>

            <div className="grid w-full grid-cols-1 gap-5 xl:grid-cols-2">
                <section className="flex min-w-0 flex-col gap-3">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className={`text-[16px] leading-6 font-semibold ${textClass}`}>{t.teacherDashboard.todayLessons}</h2>
                        <a href="#/mon-emploi-du-temps" className="shrink-0 text-[12px] leading-5 font-semibold text-primary hover:underline dark:text-primary-fixed-dim">
                            {t.teacherDashboard.viewSchedule}
                        </a>
                    </div>
                    {dashboard.today_lessons.length > 0 ? (
                        <ul className="flex flex-col gap-2">
                            {visibleTodayLessons.map((lesson) => (
                                <li key={lesson.id} className={`${cardClass} flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between`}>
                                    <div className="flex min-w-0 items-center justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className={`truncate text-[14px] leading-5 font-semibold ${textClass}`}>{lesson.label}</p>
                                            <p className={`text-[12px] leading-5 ${secondaryTextClass}`}>{lesson.room_name}</p>
                                        </div>
                                        <span className="shrink-0 rounded-md bg-primary-fixed px-2 py-1 text-[12px] leading-5 font-medium text-on-primary-fixed-variant dark:bg-primary-container dark:text-on-primary-container sm:hidden">
                                            {lesson.starts_at} – {lesson.ends_at}
                                        </span>
                                    </div>
                                    <span className="hidden shrink-0 rounded-md bg-primary-fixed px-2 py-1 text-[12px] leading-5 font-medium text-on-primary-fixed-variant dark:bg-primary-container dark:text-on-primary-container sm:inline-flex">
                                        {lesson.starts_at} – {lesson.ends_at}
                                    </span>
                                    {(lesson.id === dashboard.today_focus_lesson_id || pendingAttendanceSlotIds.has(lesson.id)) && (
                                        <a
                                            href={`#/presences?room_id=${lesson.room_id}&attendance_date=${dashboard.today}`}
                                            className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:w-auto dark:bg-primary-container dark:text-on-primary-container"
                                        >
                                            <Icon name="fact_check" className="text-[18px]" />
                                            {t.teacherDashboard.startTodayAttendance}
                                        </a>
                                    )}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className={`${cardClass} text-[13px] leading-5 ${secondaryTextClass}`}>{t.teacherDashboard.noTodayLessons}</p>
                    )}
                </section>

                <section className="flex min-w-0 flex-col gap-3">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className={`text-[16px] leading-6 font-semibold ${textClass}`}>{t.teacherDashboard.upcomingLessons}</h2>
                        <a href="#/mon-emploi-du-temps" className="text-[12px] leading-5 font-semibold text-primary hover:underline dark:text-primary-fixed-dim">
                            {t.teacherDashboard.viewSchedule}
                        </a>
                    </div>
                    {dashboard.upcoming_lessons.length > 0 ? (
                        <ul className="flex flex-col gap-2">
                            {dashboard.upcoming_lessons.slice(0, DASHBOARD_LIST_PREVIEW_LIMIT).map((lesson) => (
                                <li key={`${lesson.id}-${lesson.lesson_date}`} className={`${cardClass} flex items-center justify-between gap-3`}>
                                    <div className="min-w-0">
                                        <p className={`truncate text-[14px] leading-5 font-semibold ${textClass}`}>{lesson.label}</p>
                                        <p className={`text-[12px] leading-5 ${secondaryTextClass}`}>{dayNames[lesson.day_of_week - 1]} · {lesson.room_name}</p>
                                    </div>
                                    <span className={`shrink-0 text-[12px] leading-5 ${secondaryTextClass}`}>
                                        {formatDate(lesson.lesson_date)} · {lesson.starts_at}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className={`${cardClass} text-[13px] leading-5 ${secondaryTextClass}`}>{t.teacherDashboard.noUpcomingLessons}</p>
                    )}
                </section>
            </div>

            <div className="grid w-full grid-cols-1 gap-5 xl:grid-cols-2">
                <section className="flex min-w-0 flex-col gap-3">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className={`text-[16px] leading-6 font-semibold ${textClass}`}>{t.teacherDashboard.pendingAttendance}</h2>
                        <a href="#/presences" className="text-[12px] leading-5 font-semibold text-primary hover:underline dark:text-primary-fixed-dim">
                            {t.teacherDashboard.takeAttendance}
                        </a>
                    </div>
                    {dashboard.attendance_tasks.students.length > 0 ? (
                        <>
                            <ul className="flex flex-col gap-2">
                                {visibleAttendanceStudents.map((student) => (
                                    <li key={student.student_id} className={`${cardClass} flex items-center justify-between gap-3`}>
                                        <p className={`min-w-0 truncate text-[13px] leading-5 font-medium ${textClass}`}>{student.student_name}</p>
                                        <span className={`shrink-0 text-[12px] leading-5 ${secondaryTextClass}`}>{student.room_name}</span>
                                    </li>
                                ))}
                            </ul>
                            {dashboard.attendance_tasks.count > visibleAttendanceStudents.length && (
                                <p className={`text-[12px] leading-5 ${secondaryTextClass}`}>
                                    {t.teacherDashboard.moreAttendanceTasks(numberFormat.format(dashboard.attendance_tasks.count - visibleAttendanceStudents.length))}
                                </p>
                            )}
                        </>
                    ) : (
                        <p className={`${cardClass} text-[13px] leading-5 ${secondaryTextClass}`}>{t.teacherDashboard.noPendingAttendance}</p>
                    )}
                </section>

                <section className="flex min-w-0 flex-col gap-3">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className={`text-[16px] leading-6 font-semibold ${textClass}`}>{t.teacherDashboard.recentSessions}</h2>
                        <a href="#/seances-memorisation" className="text-[12px] leading-5 font-semibold text-primary hover:underline dark:text-primary-fixed-dim">
                            {t.teacherDashboard.viewSessions}
                        </a>
                    </div>
                    {dashboard.recent_sessions.length > 0 ? (
                        <ul className="flex flex-col gap-2">
                            {dashboard.recent_sessions.slice(0, DASHBOARD_LIST_PREVIEW_LIMIT).map((session) => (
                                <li key={session.id} className={cardClass}>
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className={`truncate text-[13px] leading-5 font-semibold ${textClass}`}>{session.student_name}</p>
                                            <p className={`text-[12px] leading-5 ${secondaryTextClass}`}>
                                                {session.room_name ?? t.teacherDashboard.noGroup}
                                                {' · '}
                                                {formatDate(session.session_date)}
                                            </p>
                                        </div>
                                        <span className="shrink-0 rounded-md bg-surface-container px-2 py-1 text-[11px] leading-4 font-medium text-on-surface-variant dark:bg-tertiary-container dark:text-on-tertiary-container">
                                            {t.memorization.evaluations[session.evaluation]}
                                        </span>
                                    </div>
                                    <p className={`mt-2 text-[12px] leading-5 ${secondaryTextClass}`}>{session.portion_label}</p>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className={`${cardClass} text-[13px] leading-5 ${secondaryTextClass}`}>{t.teacherDashboard.noRecentSessions}</p>
                    )}
                </section>
            </div>
        </div>
    );
}
