import React, { useEffect, useId, useState } from 'react';
import Icon from '../../../components/Icon';
import ConfirmDeleteModal from '../../../modals/confirmdelete';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';
import {
    getProgressPreview,
    quranMetadata,
    surahDisplayName,
} from '../../../lib/quranProgress';

function today() {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 10);
}

function formatNumber(value, language) {
    return new Intl.NumberFormat(language === 'ar' ? 'ar-MA-u-nu-latn' : 'fr-FR').format(Number(value));
}

function formatProfileDate(value, language) {
    if (!value) return '—';

    return new Intl.DateTimeFormat(language === 'ar' ? 'ar-MA-u-nu-latn' : 'fr-FR', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(`${value}T00:00:00`));
}

function formatCurrency(value, currency, language) {
    return new Intl.NumberFormat(language === 'ar' ? 'ar-MA-u-nu-latn' : 'fr-MA', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value));
}

const attendanceStyles = {
    present: {
        icon: 'check',
        className: 'border-primary/30 bg-primary-fixed text-primary dark:bg-primary-container dark:text-on-primary-container',
    },
    absent: {
        icon: 'close',
        className: 'border-error/30 bg-error-container text-on-error-container dark:bg-[#4a2626]/50 dark:text-[#ffdad6]',
    },
    late: {
        icon: 'schedule',
        className: 'border-secondary/30 bg-secondary-container text-on-secondary-container dark:bg-[#5f4100]/40 dark:text-[#ffdeaa]',
    },
    excused: {
        icon: 'event_available',
        className: 'border-outline-variant bg-surface-container text-on-surface-variant dark:border-[#384d43] dark:bg-[#2c3d36] dark:text-[#c2d1c8]',
    },
    none: {
        icon: 'remove',
        className: 'border-outline-variant bg-surface-container-low text-outline dark:border-[#384d43] dark:bg-[#182620] dark:text-[#9bb2a8]',
    },
};

function initialValues(student) {
    return {
        full_name: student?.full_name ?? '',
        student_number: student?.student_number ?? '',
        birth_date: student?.birth_date ?? '',
        guardian_name: student?.guardian_name ?? '',
        guardian_phone: student?.guardian_phone ?? '',
        guardian_email: student?.guardian_email ?? '',
        room_id: student?.room_id ? String(student.room_id) : '',
        enrollment_date: student?.enrollment_date ?? today(),
        status: student?.status ?? 'active',
        notes: student?.notes ?? '',
    };
}

export default function StudentFormModal({
    open,
    student = null,
    yearId,
    readOnly = false,
    onClose,
    onSaved,
}) {
    const { isRTL, lang, t } = useLanguage();
    const [form, setForm] = useState(() => initialValues(student));
    const [rooms, setRooms] = useState([]);
    const [detail, setDetail] = useState(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [confirmingStatus, setConfirmingStatus] = useState(null);
    const [canEditYear, setCanEditYear] = useState(false);
    const [error, setError] = useState(null);
    const [retryKey, setRetryKey] = useState(0);
    const [selectedSurahId, setSelectedSurahId] = useState(null);
    const [reEnrollmentStudents, setReEnrollmentStudents] = useState([]);
    const [reEnrollmentSelection, setReEnrollmentSelection] = useState('');
    const [lastAutoFilledReEnrollment, setLastAutoFilledReEnrollment] = useState(null);
    const formId = useId();
    const isEditing = Boolean(student?.id);

    useEffect(() => {
        if (!open) return undefined;

        const controller = new AbortController();
        setForm(initialValues(student));
        setConfirmingStatus(null);
        setDetail(null);
        setRooms([]);
        setCanEditYear(false);
        setError(null);
        setSelectedSurahId(null);
        setReEnrollmentStudents([]);
        setReEnrollmentSelection('');
        setLastAutoFilledReEnrollment(null);
        setLoading(true);

        const query = yearId ? `?year_id=${encodeURIComponent(yearId)}` : '';
        const endpoint = readOnly && student?.id
            ? `/students/${student.id}${query}`
            : `/students/options${query}`;

        apiFetch(endpoint, { signal: controller.signal })
            .then(async (response) => {
                const data = await response.json();
                if (!response.ok) throw new Error('Failed to load student form');

                if (readOnly) {
                    setDetail(data.student);
                } else {
                    setRooms(Array.isArray(data.rooms) ? data.rooms : []);
                    setCanEditYear(Boolean(data.is_editable));
                    setReEnrollmentStudents(Array.isArray(data.previous_year_students) ? data.previous_year_students : []);

                    const previousYearStudent = data.previous_year_student
                        ?? data.re_enrollment_student
                        ?? (Array.isArray(data.previous_year_students) ? data.previous_year_students[0] : null)
                        ?? (Array.isArray(data.re_enrollment_candidates) ? data.re_enrollment_candidates[0] : null);

                    if (!student?.id) {
                        setForm((current) => {
                            const nextValues = { ...current };

                            if (!current.student_number && data.next_student_number) {
                                nextValues.student_number = data.next_student_number;
                            }

                            if (previousYearStudent && !current.full_name && !current.guardian_name && !current.guardian_phone && !current.guardian_email && !current.birth_date && !current.room_id) {
                                nextValues.full_name = previousYearStudent.full_name ?? current.full_name;
                                nextValues.birth_date = previousYearStudent.birth_date ?? current.birth_date;
                                nextValues.guardian_name = previousYearStudent.guardian_name ?? current.guardian_name;
                                nextValues.guardian_phone = previousYearStudent.guardian_phone ?? current.guardian_phone;
                                nextValues.guardian_email = previousYearStudent.guardian_email ?? current.guardian_email;
                                const previousRoomId = previousYearStudent.room_id ? String(previousYearStudent.room_id) : '';
                                const validCurrentYearRoom = previousRoomId && Array.isArray(data.rooms)
                                    ? data.rooms.some((room) => String(room.id) === previousRoomId)
                                    : false;
                                nextValues.room_id = validCurrentYearRoom ? previousRoomId : current.room_id;
                                nextValues.status = current.status && current.status !== 'active' ? current.status : 'active';
                                nextValues.enrollment_date = previousYearStudent.enrollment_date ?? current.enrollment_date;
                                setLastAutoFilledReEnrollment({
                                    id: previousYearStudent.id ?? '',
                                    values: {
                                        full_name: nextValues.full_name ?? '',
                                        birth_date: nextValues.birth_date ?? '',
                                        guardian_name: nextValues.guardian_name ?? '',
                                        guardian_phone: nextValues.guardian_phone ?? '',
                                        guardian_email: nextValues.guardian_email ?? '',
                                        room_id: nextValues.room_id ?? '',
                                        status: nextValues.status ?? 'active',
                                        enrollment_date: nextValues.enrollment_date ?? '',
                                    },
                                });
                            }

                            return nextValues;
                        });
                    }
                }
            })
            .catch((loadError) => {
                if (loadError.name !== 'AbortError') setError(t.students.form.loadError);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
    }, [open, student, yearId, readOnly, retryKey, t.students.form.loadError]);

    const handleChange = (event) => {
        const { name, value } = event.target;
        if (name === 'status' && value !== form.status && ['paused', 'withdrawn'].includes(value)) {
            setConfirmingStatus(value);
            return;
        }

        setForm((current) => ({ ...current, [event.target.name]: event.target.value }));
        setError(null);
    };

    const applyReEnrollmentSelection = (studentId) => {
        if (!studentId || !Array.isArray(reEnrollmentStudents) || !reEnrollmentStudents.length) {
            setReEnrollmentSelection('');
            setLastAutoFilledReEnrollment(null);
            setForm((current) => ({
                ...initialValues(student),
                ...current,
                student_number: current.student_number || '',
            }));
            setError(null);
            return;
        }

        const candidate = reEnrollmentStudents.find((item) => String(item.id) === String(studentId));
        if (!candidate) return;

        setReEnrollmentSelection(studentId);
        const previousAutoValues = lastAutoFilledReEnrollment?.values ?? {};
        const candidateRoomId = candidate.room_id && rooms.some((room) => String(room.id) === String(candidate.room_id))
            ? String(candidate.room_id)
            : '';
        setForm((current) => ({
            ...current,
            full_name: current.full_name === previousAutoValues.full_name && candidate.full_name ? candidate.full_name : (candidate.full_name || current.full_name),
            birth_date: current.birth_date === previousAutoValues.birth_date && candidate.birth_date ? candidate.birth_date : (candidate.birth_date || current.birth_date),
            guardian_name: current.guardian_name === previousAutoValues.guardian_name && candidate.guardian_name ? candidate.guardian_name : (candidate.guardian_name || current.guardian_name),
            guardian_phone: current.guardian_phone === previousAutoValues.guardian_phone && candidate.guardian_phone ? candidate.guardian_phone : (candidate.guardian_phone || current.guardian_phone),
            guardian_email: current.guardian_email === previousAutoValues.guardian_email && candidate.guardian_email ? candidate.guardian_email : (candidate.guardian_email || current.guardian_email),
            room_id: current.room_id === previousAutoValues.room_id && candidateRoomId ? candidateRoomId : (candidateRoomId || current.room_id),
            status: current.status === previousAutoValues.status && candidate.status ? candidate.status : (current.status && current.status !== 'active' ? current.status : 'active'),
            enrollment_date: current.enrollment_date === previousAutoValues.enrollment_date && candidate.enrollment_date ? candidate.enrollment_date : (candidate.enrollment_date || current.enrollment_date),
        }));
        setLastAutoFilledReEnrollment({
            id: candidate.id ?? studentId,
            values: {
                full_name: candidate.full_name ?? '',
                birth_date: candidate.birth_date ?? '',
                guardian_name: candidate.guardian_name ?? '',
                guardian_phone: candidate.guardian_phone ?? '',
                guardian_email: candidate.guardian_email ?? '',
                room_id: candidateRoomId,
                status: 'active',
                enrollment_date: candidate.enrollment_date ?? '',
            },
        });
        setError(null);
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        setSaving(true);
        setError(null);

        try {
            const response = await apiFetch(
                isEditing ? `/students/${student.id}` : '/students',
                {
                    method: isEditing ? 'PUT' : 'POST',
                    body: JSON.stringify({
                        ...form,
                        room_id: form.room_id || null,
                        year_id: yearId,
                        re_enrollment_student_id: reEnrollmentSelection || undefined,
                    }),
                },
            );
            const data = await response.json();

            if (!response.ok) {
                const errors = data?.errors ?? {};
                if (errors.year_id) setError(t.students.form.closedYearError);
                else if (errors.student_number) setError(t.students.form.duplicateNumberError);
                else if (errors.room_capacity) setError(t.students.form.roomFullError);
                else if (errors.room_id) setError(t.students.form.roomError);
                else setError(t.students.form.saveError);
                return;
            }

            onSaved(data.student);
        } catch {
            setError(t.students.form.saveError);
        } finally {
            setSaving(false);
        }
    };

    const title = readOnly
        ? t.students.form.profileTitle
        : isEditing ? t.students.form.editTitle : t.students.form.createTitle;
    const inputClass = 'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] leading-5 text-on-surface outline-none transition-colors focus:border-primary focus:bg-surface-container-lowest focus:ring-1 focus:ring-primary disabled:cursor-not-allowed disabled:opacity-70 dark:border-[#384d43] dark:bg-[#182620] dark:text-[#ecf3ef] dark:focus:border-primary-fixed-dim dark:focus:bg-[#202f29] dark:focus:ring-primary-fixed-dim';
    const labelClass = 'text-[12px] leading-4 font-semibold text-on-surface dark:text-inverse-on-surface';

    let content;
    if (readOnly && loading && !detail) {
        content = (
            <div className="flex flex-col gap-4" aria-busy="true">
                <span role="status" className="sr-only">{t.students.form.loading}</span>
                <div className="h-24 animate-pulse rounded-lg bg-surface-container dark:bg-[#2c3d36]" />
                <div className="h-36 animate-pulse rounded-lg bg-surface-container dark:bg-[#2c3d36]" />
                <div className="h-48 animate-pulse rounded-lg bg-surface-container dark:bg-[#2c3d36]" />
            </div>
        );
    } else if (readOnly && error && !detail) {
        content = (
            <div className="flex flex-col gap-3">
                <p role="alert" className="text-[13px] text-error dark:text-error-container">{error}</p>
                <button
                    type="button"
                    onClick={() => setRetryKey((current) => current + 1)}
                    className="min-h-11 self-start rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high dark:bg-[#2c3d36] dark:text-[#ecf3ef] dark:hover:bg-[#344a41]"
                >
                    {t.students.profile.retry}
                </button>
            </div>
        );
    } else if (readOnly) {
        const profile = detail ?? student;
        const teachingProfile = Boolean(profile?.is_read_only);
        const attendanceDays = Array.isArray(profile?.attendance_last_30_days)
            ? profile.attendance_last_30_days
            : [];
        const memorizationSessions = Array.isArray(profile?.memorization_sessions)
            ? profile.memorization_sessions
            : [];
        const progress = getProgressPreview(memorizationSessions);
        const progressBySurah = new Map(
            progress.surahProgress.map((surahProgress) => [surahProgress.surah.id, surahProgress]),
        );
        const selectedSurah = progressBySurah.get(selectedSurahId) ?? null;
        const selectedJuz = progress.currentJuz
            ? (lang === 'ar' ? progress.currentJuz.label_ar : progress.currentJuz.label)
                .replace(/\d+/g, (number) => formatNumber(number, lang))
            : null;
        const guardianPhone = profile?.guardian_phone;
        const guardianEmail = profile?.guardian_email;
        const profileField = (label, value) => (
            <div className="flex min-w-0 flex-col gap-1">
                <dt className={labelClass}>{label}</dt>
                <dd className="break-words text-[13px] leading-5 text-on-surface-variant dark:text-[#c2d1c8]">
                    {value || '—'}
                </dd>
            </div>
        );
        const attendanceLabel = (day) => {
            const date = formatProfileDate(day.date, lang);
            const status = day.status
                ? t.students.profile.attendanceStatuses[day.status] ?? day.status
                : t.students.profile.noAttendance;

            return t.students.profile.attendanceDay(date, status);
        };
        const surahStateLabel = (surahProgress) => (
            surahProgress.isComplete
                ? t.students.profile.surahStates.complete
                : surahProgress.coveredAyahs > 0
                    ? t.students.profile.surahStates.inProgress
                    : t.students.profile.surahStates.untouched
        );

        content = (
            <div className="flex flex-col gap-5">
                <section className="rounded-lg border border-outline-variant bg-surface-container-low p-4 dark:border-[#384d43] dark:bg-[#182620]">
                    <h3 className="mb-3 text-[14px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        {t.students.profile.identity}
                    </h3>
                    <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {profileField(t.students.columns.name, profile?.full_name)}
                        {!teachingProfile && profileField(t.students.columns.number, profile?.student_number)}
                        {profileField(t.students.form.birthDate, profile?.birth_date
                            ? formatProfileDate(profile.birth_date, lang)
                            : null)}
                        {profileField(t.students.columns.room, profile?.room_name || t.students.unassigned)}
                        {!teachingProfile && profileField(t.students.form.enrollmentDate, profile?.enrollment_date
                            ? formatProfileDate(profile.enrollment_date, lang)
                            : null)}
                        {!teachingProfile && profileField(
                            t.students.columns.status,
                            <span className={`inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-semibold ${
                                profile?.status === 'active'
                                    ? 'bg-primary-fixed/50 text-on-primary-fixed-variant dark:bg-[#1e4f40]/50 dark:text-[#baeed9]'
                                    : profile?.status === 'paused'
                                        ? 'bg-secondary-fixed/60 text-on-secondary-fixed-variant dark:bg-[#5f4100]/40 dark:text-[#ffdeaa]'
                                        : 'bg-error-container text-on-error-container dark:bg-[#4a2626]/50 dark:text-[#ffdad6]'
                            }`}>
                                <span className="size-1.5 rounded-full bg-current" aria-hidden="true" />
                                {t.students.statuses[profile?.status] ?? profile?.status}
                            </span>,
                        )}
                    </dl>
                    {!teachingProfile && (
                        <dl className="mt-4 grid grid-cols-1 gap-3 border-t border-outline-variant pt-4 dark:border-[#384d43] sm:grid-cols-2">
                            {profileField(t.students.form.guardianName, profile?.guardian_name)}
                            <div className="flex min-w-0 flex-col gap-1">
                                <dt className={labelClass}>{t.students.form.guardianPhone}</dt>
                                <dd className="break-words text-[13px] leading-5 text-on-surface-variant dark:text-[#c2d1c8]">
                                    {guardianPhone
                                        ? <a className="min-h-11 inline-flex items-center text-primary underline-offset-2 hover:underline dark:text-primary-fixed-dim" href={`tel:${guardianPhone.replace(/[^\d+]/g, '')}`}>{guardianPhone}</a>
                                        : '—'}
                                </dd>
                            </div>
                            <div className="flex min-w-0 flex-col gap-1 sm:col-span-2">
                                <dt className={labelClass}>{t.students.form.guardianEmail}</dt>
                                <dd className="break-words text-[13px] leading-5 text-on-surface-variant dark:text-[#c2d1c8]">
                                    {guardianEmail
                                        ? <a className="min-h-11 inline-flex items-center text-primary underline-offset-2 hover:underline dark:text-primary-fixed-dim" href={`mailto:${guardianEmail}`}>{guardianEmail}</a>
                                        : '—'}
                                </dd>
                            </div>
                        </dl>
                    )}
                    <div className="mt-4 border-t border-outline-variant pt-4 dark:border-[#384d43]">
                        <span className={labelClass}>{t.students.form.notes}</span>
                        <p className="mt-1 whitespace-pre-wrap text-[13px] leading-5 text-on-surface-variant dark:text-[#c2d1c8]">
                            {profile?.notes || '—'}
                        </p>
                    </div>
                </section>

                <section className="flex flex-col gap-3 rounded-lg border border-outline-variant p-4 dark:border-[#384d43]">
                    <div>
                        <h3 className="text-[14px] font-semibold text-on-surface dark:text-inverse-on-surface">
                            {t.students.profile.attendance}
                        </h3>
                        <p className="text-[12px] leading-5 text-on-surface-variant dark:text-[#9bb2a8]">
                            {t.students.profile.last30Days}
                        </p>
                    </div>
                    <div className="grid grid-cols-10 gap-2" aria-label={t.students.profile.attendance}>
                        {attendanceDays.map((day) => {
                            const style = attendanceStyles[day.status] ?? attendanceStyles.none;

                            return (
                                <span
                                    key={day.date}
                                    role="img"
                                    aria-label={attendanceLabel(day)}
                                    title={attendanceLabel(day)}
                                    className={`flex size-6 items-center justify-center rounded-full border ${style.className}`}
                                >
                                    <Icon name={style.icon} className="text-[14px]" />
                                </span>
                            );
                        })}
                    </div>
                    <ul className="flex flex-wrap gap-x-4 gap-y-2 text-[11px] text-on-surface-variant dark:text-[#c2d1c8]">
                        {Object.entries(t.students.profile.attendanceStatuses).map(([status, label]) => (
                            <li key={status} className="inline-flex items-center gap-1.5">
                                <span className={`flex size-5 items-center justify-center rounded-full border ${attendanceStyles[status].className}`}>
                                    <Icon name={attendanceStyles[status].icon} className="text-[12px]" />
                                </span>
                                {label}
                            </li>
                        ))}
                        <li className="inline-flex items-center gap-1.5">
                            <span className={`flex size-5 items-center justify-center rounded-full border ${attendanceStyles.none.className}`}>
                                <Icon name={attendanceStyles.none.icon} className="text-[12px]" />
                            </span>
                            {t.students.profile.noAttendance}
                        </li>
                    </ul>
                </section>

                <section className="flex flex-col gap-3 rounded-lg border border-outline-variant p-4 dark:border-[#384d43]">
                    <div>
                        <h3 className="text-[14px] font-semibold text-on-surface dark:text-inverse-on-surface">
                            {t.students.profile.memorization}
                        </h3>
                        <p className="text-[12px] leading-5 text-on-surface-variant dark:text-[#9bb2a8]">
                            {progress.hasCoverage
                                ? t.students.profile.progressSummary(
                                    formatNumber(progress.coveredAyahs, lang),
                                    formatNumber(progress.totalAyahs, lang),
                                    formatNumber(progress.percentage, lang),
                                )
                                : t.students.profile.noMemorization}
                            {selectedJuz && ` · ${t.students.profile.currentJuz(selectedJuz)}`}
                        </p>
                        <p className="text-[12px] leading-5 text-on-surface-variant dark:text-[#9bb2a8]">
                            {t.students.profile.surahCounts(
                                formatNumber(progress.completeSurahCount, lang),
                                formatNumber(progress.inProgressSurahs.length, lang),
                                formatNumber(quranMetadata.surahs.length, lang),
                            )}
                        </p>
                    </div>
                    <div className="grid grid-cols-6 gap-1 sm:grid-cols-10" role="group" aria-label={t.students.profile.surahMap}>
                        {progress.surahProgress.map((surahProgress) => {
                            const { surah } = surahProgress;
                            const stateLabel = surahStateLabel(surahProgress);
                            const mapLabel = t.students.profile.surahMapCell(
                                formatNumber(surah.id, lang),
                                `${surah.name_fr} — ${surah.name_ar}`,
                                stateLabel,
                            );
                            const cellClass = surahProgress.isComplete
                                ? 'border-primary/30 bg-primary-container text-on-primary-container dark:bg-[#2e5e4e] dark:text-[#f0fcf3]'
                                : surahProgress.coveredAyahs > 0
                                    ? 'border-secondary/30 bg-secondary-container text-on-secondary-container dark:bg-[#5f4100]/40 dark:text-[#ffdeaa]'
                                    : 'border-outline-variant bg-surface-container-low text-on-surface-variant dark:border-[#384d43] dark:bg-[#182620] dark:text-[#c2d1c8]';

                            return (
                                <button
                                    key={surah.id}
                                    type="button"
                                    aria-label={mapLabel}
                                    aria-pressed={selectedSurahId === surah.id}
                                    title={mapLabel}
                                    onClick={() => setSelectedSurahId(surah.id)}
                                    className={`flex min-h-11 min-w-0 items-center justify-center gap-0.5 rounded-md border px-1 text-[11px] font-semibold outline-none transition-colors focus-visible:ring-2 focus-visible:ring-primary ${cellClass} ${selectedSurahId === surah.id ? 'ring-2 ring-primary' : ''}`}
                                >
                                    {formatNumber(surah.id, lang)}
                                    {surahProgress.isComplete && <Icon name="check" className="text-[12px]" />}
                                    {!surahProgress.isComplete && surahProgress.coveredAyahs > 0 && <Icon name="schedule" className="text-[12px]" />}
                                    {surahProgress.coveredAyahs === 0 && <Icon name="remove" className="text-[12px]" />}
                                </button>
                            );
                        })}
                    </div>
                    <ul className="flex flex-wrap gap-x-4 gap-y-2 text-[11px] text-on-surface-variant dark:text-[#c2d1c8]">
                        {[
                            ['complete', t.students.profile.surahStates.complete],
                            ['inProgress', t.students.profile.surahStates.inProgress],
                            ['untouched', t.students.profile.surahStates.untouched],
                        ].map(([state, label]) => (
                            <li key={state} className="inline-flex items-center gap-1.5">
                                <span className={`flex size-5 items-center justify-center rounded-full border ${
                                    state === 'complete'
                                        ? 'border-primary/30 bg-primary-container text-on-primary-container dark:bg-[#2e5e4e] dark:text-[#f0fcf3]'
                                        : state === 'inProgress'
                                            ? 'border-secondary/30 bg-secondary-container text-on-secondary-container dark:bg-[#5f4100]/40 dark:text-[#ffdeaa]'
                                            : 'border-outline-variant bg-surface-container-low text-outline dark:border-[#384d43] dark:bg-[#182620] dark:text-[#9bb2a8]'
                                }`}>
                                    <Icon
                                        name={state === 'complete' ? 'check' : state === 'inProgress' ? 'schedule' : 'remove'}
                                        className="text-[12px]"
                                    />
                                </span>
                                {label}
                            </li>
                        ))}
                    </ul>
                    {selectedSurah && (
                        <p role="status" className="rounded-md bg-surface-container-low px-3 py-2 text-[12px] leading-5 text-on-surface-variant dark:bg-[#182620] dark:text-[#c2d1c8]">
                            {surahDisplayName(selectedSurah.surah, lang)} — {surahStateLabel(selectedSurah)} · {t.students.profile.surahCoverage(
                                formatNumber(selectedSurah.coveredAyahs, lang),
                                formatNumber(selectedSurah.surah.ayah_count, lang),
                            )}
                        </p>
                    )}
                </section>

                {!teachingProfile && (
                    <section className="flex flex-col gap-3 rounded-lg border border-outline-variant p-4 dark:border-[#384d43]">
                        <h3 className="text-[14px] font-semibold text-on-surface dark:text-inverse-on-surface">
                            {t.students.profile.payments}
                        </h3>
                        {profile?.payment_summary?.length
                            ? profile.payment_summary.map((summary) => (
                                <div key={summary.currency} className="flex flex-col gap-2 rounded-md bg-surface-container-low p-3 dark:bg-[#182620] sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p className="text-[12px] text-on-surface-variant dark:text-[#9bb2a8]">
                                            {t.students.profile.outstanding}
                                        </p>
                                        <p className="text-[20px] leading-7 font-semibold text-on-surface dark:text-inverse-on-surface">
                                            {formatCurrency(summary.outstanding_amount, summary.currency, lang)}
                                        </p>
                                    </div>
                                    <p className="text-[12px] leading-5 text-on-surface-variant dark:text-[#c2d1c8]">
                                        {t.students.profile.paymentBreakdown(
                                            formatCurrency(summary.due_amount, summary.currency, lang),
                                            formatNumber(summary.due_count, lang),
                                            formatCurrency(summary.late_amount, summary.currency, lang),
                                            formatNumber(summary.late_count, lang),
                                            formatCurrency(summary.waived_amount ?? '0', summary.currency, lang),
                                            formatNumber(summary.waived_count ?? 0, lang),
                                        )}
                                    </p>
                                </div>
                            ))
                            : <p className="text-[13px] text-on-surface-variant dark:text-[#c2d1c8]">{t.students.profile.noPayments}</p>}
                    </section>
                )}
            </div>
        );
    } else if (error && !loading) {
        content = <p role="alert" className="text-[13px] text-error dark:text-error-container">{error}</p>;
    } else if (!canEditYear && !loading) {
        content = <p role="alert" className="text-[13px] text-error dark:text-error-container">{t.students.form.closedYearError}</p>;
    } else {
        content = (
            <form id={`${formId}-form`} className="flex flex-col gap-4" onSubmit={handleSubmit} aria-busy={loading}>
                {!student?.id && reEnrollmentStudents.length > 0 && (
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-re_enrollment_student_id`} className={labelClass}>Returning student</label>
                        <select
                            id={`${formId}-re_enrollment_student_id`}
                            value={reEnrollmentSelection}
                            onChange={(event) => applyReEnrollmentSelection(event.target.value)}
                            className={inputClass}
                        >
                            <option value="">New student / no return</option>
                            {reEnrollmentStudents.map((candidate) => (
                                <option key={candidate.id} value={candidate.id}>{candidate.full_name}</option>
                            ))}
                        </select>
                    </div>
                )}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5 sm:col-span-2">
                        <label htmlFor={`${formId}-full_name`} className={labelClass}>{t.students.columns.name}</label>
                        <input id={`${formId}-full_name`} name="full_name" required maxLength={120} value={form.full_name} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-student_number`} className={labelClass}>{t.students.columns.number}</label>
                        <input id={`${formId}-student_number`} name="student_number" maxLength={30} value={form.student_number} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-birth_date`} className={labelClass}>{t.students.form.birthDate}</label>
                        <input id={`${formId}-birth_date`} name="birth_date" type="date" value={form.birth_date} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-guardian_name`} className={labelClass}>{t.students.form.guardianName}</label>
                        <input id={`${formId}-guardian_name`} name="guardian_name" maxLength={120} value={form.guardian_name} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-guardian_phone`} className={labelClass}>{t.students.form.guardianPhone}</label>
                        <input id={`${formId}-guardian_phone`} name="guardian_phone" type="tel" maxLength={30} value={form.guardian_phone} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-guardian_email`} className={labelClass}>{t.students.form.guardianEmail}</label>
                        <input id={`${formId}-guardian_email`} name="guardian_email" type="email" maxLength={190} value={form.guardian_email} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-room_id`} className={labelClass}>{t.students.columns.room}</label>
                        <select id={`${formId}-room_id`} name="room_id" value={form.room_id} onChange={handleChange} className={inputClass}>
                            <option value="">{t.students.unassigned}</option>
                            {rooms.map((room) => <option key={room.id} value={room.id}>{room.name}</option>)}
                        </select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-enrollment_date`} className={labelClass}>{t.students.form.enrollmentDate}</label>
                        <input id={`${formId}-enrollment_date`} name="enrollment_date" type="date" required value={form.enrollment_date} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-status`} className={labelClass}>{t.students.columns.status}</label>
                        <select id={`${formId}-status`} name="status" value={form.status} onChange={handleChange} className={inputClass}>
                            {['active', 'paused', 'withdrawn'].map((value) => (
                                <option key={value} value={value}>{t.students.statuses[value]}</option>
                            ))}
                        </select>
                    </div>
                    <div className="flex flex-col gap-1.5 sm:col-span-2">
                        <label htmlFor={`${formId}-notes`} className={labelClass}>{t.students.form.notes}</label>
                        <textarea id={`${formId}-notes`} name="notes" rows={3} value={form.notes} onChange={handleChange} className={`${inputClass} resize-y`} />
                    </div>
                </div>
                {error && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{error}</p>}
            </form>
        );
    }

    const footer = readOnly
        ? (
            <button type="button" onClick={onClose} className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high dark:bg-[#2c3d36] dark:text-[#ecf3ef] dark:hover:bg-[#344a41]">
                {t.students.form.close}
            </button>
        )
        : (
            <>
                <button type="button" disabled={saving} onClick={onClose} className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:opacity-60 dark:bg-[#2c3d36] dark:text-[#ecf3ef] dark:hover:bg-[#344a41]">
                    {t.students.form.cancel}
                </button>
                <button type="submit" form={`${formId}-form`} disabled={saving || loading || !canEditYear} className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]">
                    <Icon name={saving ? 'progress_activity' : 'save'} className={`text-[18px] ${saving ? 'animate-spin' : ''}`} />
                    {saving ? t.students.form.saving : t.students.form.save}
                </button>
            </>
        );

    return (
        <Modal
            open={open}
            title={readOnly && detail?.full_name ? detail.full_name : title}
            icon={readOnly ? 'person' : isEditing ? 'edit' : 'person_add'}
            onClose={onClose}
            footer={footer}
            isRTL={isRTL}
            closeLabel={t.students.form.close}
        >
            <span role="status" className="sr-only">{loading ? t.students.form.loading : ''}</span>
            {content}
            <ConfirmDeleteModal
                open={Boolean(confirmingStatus)}
                title={confirmingStatus === 'paused' ? t.students.archive.pauseTitle : t.students.archive.withdrawTitle}
                description={confirmingStatus === 'paused'
                    ? t.students.archive.pauseMessage(form.full_name || student?.full_name || t.students.archive.studentFallback)
                    : t.students.archive.withdrawMessage(form.full_name || student?.full_name || t.students.archive.studentFallback)}
                confirmLabel={confirmingStatus === 'paused' ? t.students.archive.confirmPause : t.students.archive.confirmWithdraw}
                cancelLabel={t.students.archive.cancel}
                onConfirm={() => {
                    if (!confirmingStatus) return;
                    setForm((current) => ({ ...current, status: confirmingStatus }));
                    setConfirmingStatus(null);
                    setError(null);
                }}
                onClose={() => setConfirmingStatus(null)}
                isRTL={isRTL}
            />
        </Modal>
    );
}
