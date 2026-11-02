import React, { useEffect, useRef, useState } from 'react';
import Icon from '../../../components/Icon';
import { useLanguage } from '../../../hooks/useLanguage';
import { apiFetch } from '../../../lib/api';

export default function TodayAttendance({ lesson, todayDate, yearId, onBack }) {
    const { isRTL, lang, t } = useLanguage();
    const [students, setStudents] = useState(lesson.students);
    const [savingStudentIds, setSavingStudentIds] = useState([]);
    const [retryStatuses, setRetryStatuses] = useState({});
    const [rowErrors, setRowErrors] = useState({});
    const [lastAction, setLastAction] = useState(null);
    const [undoing, setUndoing] = useState(false);
    const [undoError, setUndoError] = useState(false);
    const headingRef = useRef(null);
    const locale = lang === 'ar' ? 'ar-MA-u-nu-latn' : 'fr-MA';
    const numberFormat = new Intl.NumberFormat(locale);
    const dateFormat = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' });
    const markedCount = students.filter((student) => student.status !== null).length;
    const allMarked = savingStudentIds.length === 0 && students.every((student) => student.status !== null);
    const attendanceDate = dateFormat.format(new Date(`${todayDate}T00:00:00Z`));
    const statuses = Object.entries(t.attendance.statuses);

    useEffect(() => {
        headingRef.current?.focus();
    }, []);

    useEffect(() => {
        if (!lastAction || undoing) return undefined;

        const timeoutId = window.setTimeout(() => setLastAction(null), 5000);
        return () => window.clearTimeout(timeoutId);
    }, [lastAction, undoing]);

    const updateStudent = (studentId, update) => {
        setStudents((current) => current.map((student) => (
            student.student_id === studentId ? { ...student, ...update } : student
        )));
    };

    const getSaveError = (response, data) => {
        if (response.status === 403) return t.attendance.permissionError;
        if (response.status === 404) return t.attendance.recordUnavailableError;
        if (data?.errors?.attendance_date) return t.attendance.correctionWindowError;
        if (data?.errors?.student_id) return t.attendance.studentUnavailableError;
        if (data?.errors?.status) return t.attendance.statusError;
        if (data?.errors?.year_id) return t.attendance.yearError;
        if (response.status === 422) return t.attendance.saveError;
        return t.teacherDashboard.attendanceSaveError;
    };

    const saveStatus = async (studentId, status) => {
        const student = students.find((item) => item.student_id === studentId);
        if (!student || !student.can_edit || savingStudentIds.includes(studentId)) return;
        if (student.status === status && retryStatuses[studentId] !== status) return;

        setSavingStudentIds((current) => [...current, studentId]);
        setRowErrors((current) => ({ ...current, [studentId]: null }));
        setLastAction(null);
        setUndoError(false);
        updateStudent(studentId, { status });
        let failureMessage = t.teacherDashboard.attendanceSaveError;

        try {
            const response = await apiFetch('/attendance', {
                method: 'POST',
                body: JSON.stringify({
                    year_id: yearId,
                    student_id: studentId,
                    attendance_date: todayDate,
                    status,
                    note: student.note || null,
                }),
            });
            const data = await response.json();

            if (!response.ok) {
                failureMessage = getSaveError(response, data);
                throw new Error('attendance-save-failed');
            }

            updateStudent(studentId, {
                attendance_id: data.attendance_id,
                can_edit: true,
                status,
            });
            setRetryStatuses((current) => {
                const next = { ...current };
                delete next[studentId];
                return next;
            });
            setLastAction({
                studentId,
                before: student,
                attendanceId: data.attendance_id,
            });
            setUndoError(false);
        } catch {
            updateStudent(studentId, { status: student.status });
            setRetryStatuses((current) => ({ ...current, [studentId]: status }));
            setRowErrors((current) => ({
                ...current,
                [studentId]: failureMessage,
            }));
        } finally {
            setSavingStudentIds((current) => current.filter((id) => id !== studentId));
        }
    };

    const undoLastAction = async () => {
        if (!lastAction || undoing) return;

        setUndoing(true);
        setUndoError(false);

        try {
            const response = lastAction.before.attendance_id === null
                ? await apiFetch(`/attendance/${lastAction.attendanceId}`, { method: 'DELETE' })
                : await apiFetch('/attendance', {
                    method: 'POST',
                    body: JSON.stringify({
                        year_id: yearId,
                        student_id: lastAction.studentId,
                        attendance_date: todayDate,
                        status: lastAction.before.status,
                        note: lastAction.before.note || null,
                    }),
                });

            if (!response.ok) {
                throw new Error('Unable to undo attendance');
            }

            updateStudent(lastAction.studentId, lastAction.before);
            setLastAction(null);
        } catch {
            setUndoError(true);
        } finally {
            setUndoing(false);
        }
    };

    return (
        <section
            dir={isRTL ? 'rtl' : 'ltr'}
            className="flex w-full flex-col gap-4 pb-24"
            aria-labelledby="today-attendance-heading"
        >
            <header className="flex flex-col gap-3">
                <button
                    type="button"
                    onClick={onBack}
                    className="inline-flex min-h-11 w-fit items-center gap-2 rounded-lg px-3 text-[13px] font-semibold text-primary transition-colors hover:bg-surface-container focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-primary-fixed-dim dark:hover:bg-tertiary-container"
                >
                    <Icon name="arrow_back" className={`text-[18px] ${isRTL ? 'rotate-180' : ''}`} />
                    {t.teacherDashboard.backToDashboard}
                </button>
                <div>
                    <h1
                        id="today-attendance-heading"
                        ref={headingRef}
                        tabIndex="-1"
                        className="text-[20px] leading-7 font-semibold text-on-surface outline-none focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-primary dark:text-inverse-on-surface"
                    >
                        {t.teacherDashboard.attendanceForLesson(lesson.label)}
                    </h1>
                    <p className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">
                        {lesson.room_name} · {t.teacherDashboard.todayLabel} · {attendanceDate} · {lesson.starts_at}–{lesson.ends_at}
                    </p>
                </div>
            </header>

            <div className="flex items-center justify-between gap-3 rounded-lg border border-outline-variant bg-surface-container-lowest p-3 dark:border-tertiary-container dark:bg-inverse-surface">
                <p className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">
                    {t.teacherDashboard.attendanceProgress(
                        numberFormat.format(markedCount),
                        numberFormat.format(students.length),
                    )}
                </p>
                <span className="shrink-0 rounded-full bg-surface-container px-2.5 py-1 text-[12px] leading-5 font-semibold text-on-surface-variant dark:bg-tertiary-container dark:text-on-tertiary-container">
                    {numberFormat.format(students.length)}
                </span>
            </div>

            {students.length > 0 ? (
                <ul className="flex flex-col gap-2" aria-label={t.teacherDashboard.studentAttendance}>
                    {students.map((student) => {
                        const isSaving = savingStudentIds.includes(student.student_id);
                        const retryStatus = retryStatuses[student.student_id];

                        return (
                            <li
                                key={student.student_id}
                                aria-busy={isSaving}
                                className="flex min-w-0 flex-col gap-3 rounded-lg border border-outline-variant bg-surface-container-lowest p-3 dark:border-tertiary-container dark:bg-inverse-surface"
                            >
                                <div className="flex min-w-0 items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-[14px] leading-5 font-semibold text-on-surface dark:text-inverse-on-surface">
                                            {student.student_name}
                                        </p>
                                        {student.student_number && (
                                            <p className="text-[12px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">
                                                {student.student_number}
                                            </p>
                                        )}
                                    </div>
                                    {student.status && (
                                        <span className="shrink-0 rounded-full bg-primary-fixed px-2.5 py-1 text-[12px] leading-5 font-semibold text-on-primary-fixed-variant dark:bg-primary-container dark:text-on-primary-container">
                                            {t.attendance.statuses[student.status]}
                                        </span>
                                    )}
                                </div>

                                {student.can_edit ? (
                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                        {statuses.map(([status, label]) => (
                                            <button
                                                key={status}
                                                type="button"
                                                aria-label={t.teacherDashboard.markStudentStatus(student.student_name, label)}
                                                aria-pressed={student.status === status}
                                                disabled={isSaving}
                                                onClick={() => saveStatus(student.student_id, status)}
                                                className={`inline-flex min-h-11 items-center justify-center gap-1 rounded-lg border px-2 text-[12px] leading-4 font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-wait disabled:opacity-60 ${
                                                    student.status === status
                                                        ? 'border-primary bg-primary-container text-on-primary dark:border-primary-fixed-dim dark:bg-primary-container dark:text-on-primary-container'
                                                        : 'border-outline-variant bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high dark:border-tertiary-container dark:bg-inverse-surface dark:text-inverse-on-surface dark:hover:bg-tertiary-container'
                                                }`}
                                            >
                                                {isSaving && student.status === status
                                                    ? <Icon name="progress_activity" className="animate-spin text-[16px]" />
                                                    : null}
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-[12px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">
                                        {t.teacherDashboard.attendanceLocked}
                                    </p>
                                )}

                                {rowErrors[student.student_id] && (
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p role="alert" className="text-[12px] leading-5 text-error dark:text-error-container">
                                            {rowErrors[student.student_id]}
                                        </p>
                                        {retryStatus && (
                                            <button
                                                type="button"
                                                disabled={isSaving}
                                                onClick={() => saveStatus(student.student_id, retryStatus)}
                                                className="min-h-11 rounded-lg px-3 text-[12px] font-semibold text-primary underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-primary dark:text-primary-fixed-dim"
                                            >
                                                {t.teacherDashboard.retryAttendance}
                                            </button>
                                        )}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            ) : (
                <p className="rounded-lg border border-outline-variant bg-surface-container-lowest p-4 text-[13px] leading-5 text-on-surface-variant dark:border-tertiary-container dark:bg-inverse-surface dark:text-tertiary-fixed-dim">
                    {t.teacherDashboard.noStudentsInLesson}
                </p>
            )}

            {(lastAction || undoError) && (
                <div
                    role={undoError ? 'alert' : 'status'}
                    aria-live={undoError ? 'assertive' : 'polite'}
                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-outline-variant bg-surface-container-lowest p-3 dark:border-tertiary-container dark:bg-inverse-surface"
                >
                    <p className={`text-[13px] leading-5 ${undoError ? 'text-error dark:text-error-container' : 'text-on-surface dark:text-inverse-on-surface'}`}>
                        {undoError
                            ? t.teacherDashboard.undoAttendanceError
                            : t.teacherDashboard.attendanceSaved(
                                students.find((student) => student.student_id === lastAction.studentId)?.student_name ?? '',
                            )}
                    </p>
                    {lastAction && (
                        <button
                            type="button"
                            disabled={undoing}
                            onClick={undoLastAction}
                            className="inline-flex min-h-11 items-center gap-2 rounded-lg px-3 text-[13px] font-semibold text-primary hover:bg-surface-container focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-60 dark:text-primary-fixed-dim dark:hover:bg-tertiary-container"
                        >
                            {undoing && <Icon name="progress_activity" className="animate-spin text-[18px]" />}
                            {undoing ? t.teacherDashboard.undoingAttendance : t.teacherDashboard.undoAttendance}
                        </button>
                    )}
                </div>
            )}

            <div className="sticky bottom-0 z-10 -mx-4 mt-2 border-t border-outline-variant bg-surface-container-lowest/95 p-4 backdrop-blur dark:border-tertiary-container dark:bg-inverse-surface/95">
                <div className="mx-auto flex w-full max-w-3xl flex-col gap-2">
                    {allMarked && (
                        <p className="text-center text-[12px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">
                            {t.teacherDashboard.attendanceComplete}
                        </p>
                    )}
                    <button
                        type="button"
                        disabled={!allMarked}
                        onClick={() => {
                            window.location.hash = '/seances-memorisation';
                        }}
                        className="inline-flex min-h-12 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-3 text-[14px] font-semibold text-on-primary transition-colors hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:bg-surface-container disabled:text-on-surface-variant disabled:opacity-60 dark:bg-primary-container dark:text-on-primary-container dark:disabled:bg-tertiary-container dark:disabled:text-tertiary-fixed-dim"
                    >
                        <Icon name="menu_book" className="text-[20px]" />
                        {t.teacherDashboard.continueToMemorization}
                    </button>
                </div>
            </div>
        </section>
    );
}
