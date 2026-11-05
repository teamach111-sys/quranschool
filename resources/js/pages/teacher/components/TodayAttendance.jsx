import React, { useEffect, useRef, useState } from 'react';
import Icon from '../../../components/Icon';
import { useToast } from '../../../components/ToastProvider';
import { useLanguage } from '../../../hooks/useLanguage';
import { apiFetch } from '../../../lib/api';

export default function TodayAttendance({ lesson, todayDate, yearId, onBack }) {
    const { isRTL, lang, t } = useLanguage();
    const toast = useToast();
    const [students, setStudents] = useState(lesson.students);
    const [savingStudentIds, setSavingStudentIds] = useState([]);
    const [retryStatuses, setRetryStatuses] = useState({});
    const [rowErrors, setRowErrors] = useState({});
    const headingRef = useRef(null);
    const locale = lang === 'ar' ? 'ar-MA-u-nu-latn' : 'fr-MA';
    const numberFormat = new Intl.NumberFormat(locale);
    const dateFormat = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' });
    const markedCount = students.filter((student) => student.status !== null).length;
    const allMarked = savingStudentIds.length === 0 && students.every((student) => student.status !== null);
    const hasSuggestedLateStudents = lesson.late_suggestion
        && students.some((student) => student.status === null && student.can_edit);
    const attendanceDate = dateFormat.format(new Date(`${todayDate}T00:00:00Z`));
    const statuses = Object.entries(t.attendance.statuses);

    useEffect(() => {
        headingRef.current?.focus();
    }, []);

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
        updateStudent(studentId, { status });
        let failureMessage = t.teacherDashboard.attendanceSaveError;
        let isValidationFailure = false;

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
                isValidationFailure = response.status === 422;
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
            const undoAction = {
                studentId,
                before: student,
                attendanceId: data.attendance_id,
            };
            toast.success(t.teacherDashboard.attendanceSaved(student.student_name), {
                label: t.teacherDashboard.undoAttendance,
                onClick: () => undoLastAction(undoAction),
            });
        } catch {
            updateStudent(studentId, { status: student.status });
            setRetryStatuses((current) => ({ ...current, [studentId]: status }));
            if (isValidationFailure) {
                setRowErrors((current) => ({
                    ...current,
                    [studentId]: failureMessage,
                }));
            } else {
                toast.error(failureMessage);
            }
        } finally {
            setSavingStudentIds((current) => current.filter((id) => id !== studentId));
        }
    };

    const undoLastAction = async (action) => {
        try {
            const response = action.before.attendance_id === null
                ? await apiFetch(`/attendance/${action.attendanceId}`, { method: 'DELETE' })
                : await apiFetch('/attendance', {
                    method: 'POST',
                    body: JSON.stringify({
                        year_id: yearId,
                        student_id: action.studentId,
                        attendance_date: todayDate,
                        status: action.before.status,
                        note: action.before.note || null,
                    }),
                });

            if (!response.ok) {
                throw new Error('Unable to undo attendance');
            }

            updateStudent(action.studentId, action.before);
            return true;
        } catch {
            toast.error(t.teacherDashboard.undoAttendanceError);
            return false;
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

            {hasSuggestedLateStudents && (
                <p role="note" className="rounded-lg border border-primary/30 bg-primary-fixed/40 p-3 text-[13px] leading-5 text-on-surface dark:border-primary-fixed-dim/30 dark:bg-primary-container/30 dark:text-inverse-on-surface">
                    {t.teacherDashboard.lateSuggestion(lesson.starts_at, lesson.ends_at)}
                </p>
            )}

            {students.length > 0 ? (
                <ul className="flex flex-col gap-2" aria-label={t.teacherDashboard.studentAttendance}>
                    {students.map((student) => {
                        const isSaving = savingStudentIds.includes(student.student_id);
                        const retryStatus = retryStatuses[student.student_id];
                        const hasLateSuggestion = lesson.late_suggestion
                            && student.status === null
                            && student.can_edit;

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
                                        {hasLateSuggestion && (
                                            <p className="text-[12px] leading-5 font-semibold text-primary dark:text-primary-fixed-dim">
                                                {t.teacherDashboard.suggestedLate}
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

                                {(rowErrors[student.student_id] || retryStatus) && (
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        {rowErrors[student.student_id] && (
                                            <p role="alert" className="text-[12px] leading-5 text-error dark:text-error-container">
                                                {rowErrors[student.student_id]}
                                            </p>
                                        )}
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
