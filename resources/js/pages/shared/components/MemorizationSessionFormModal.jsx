import React, { useCallback, useEffect, useId, useMemo, useState } from 'react';
import Icon from '../../../components/Icon';
import SearchInput from '../../../components/SearchInput';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';
import {
    formatPortionRange,
    formatQuranNumber,
    getNextPortionSuggestion,
    getProgressPreview,
    getSurah,
    juzDisplayName,
    searchSurahs,
    surahDisplayName,
} from '../../../lib/quranProgress';

function initialValues(session, defaultDate) {
    return {
        student_id: session ? String(session.student_id) : '',
        room_id: session ? String(session.room_id ?? '') : '',
        teacher_id: session ? String(session.teacher_id ?? '') : '',
        session_date: session?.session_date ?? defaultDate,
        surah_start: session?.surah_start == null ? '' : String(session.surah_start),
        ayah_start: session?.ayah_start == null ? '' : String(session.ayah_start),
        surah_end: session?.surah_end == null ? '' : String(session.surah_end),
        ayah_end: session?.ayah_end == null ? '' : String(session.ayah_end),
        portion_label: session?.portion_label ?? '',
        evaluation: session?.evaluation ?? 'good',
        notes: session?.notes ?? '',
    };
}

function getSurahOptions(searchTerm, selectedSurahId) {
    const options = searchSurahs(searchTerm);
    const selectedSurah = getSurah(selectedSurahId);

    return selectedSurah && !options.some((surah) => surah.id === selectedSurah.id)
        ? [selectedSurah, ...options]
        : options;
}

function SurahPicker({
    label,
    name,
    value,
    searchTerm,
    options,
    isRTL,
    lang,
    inputClass,
    labelClass,
    searchLabel,
    selectLabel,
    placeholder,
    onSearchChange,
    onChange,
}) {
    return (
        <div className={labelClass}>
            <span>{label}</span>
            <SearchInput
                isRTL={isRTL}
                ariaLabel={searchLabel}
                placeholder={searchLabel}
                value={searchTerm}
                onChange={onSearchChange}
            />
            <select
                aria-label={selectLabel}
                name={name}
                value={value}
                onChange={onChange}
                className={inputClass}
            >
                <option value="">{placeholder}</option>
                {options.map((surah) => (
                    <option key={surah.id} value={surah.id}>
                        {formatQuranNumber(surah.id, lang)} — {surah.name} — {surah.name_ar}
                    </option>
                ))}
            </select>
        </div>
    );
}

export default function MemorizationSessionFormModal({
    open,
    session = null,
    yearId,
    defaultDate,
    canManage,
    rooms,
    students,
    teachers,
    onClose,
    onSaved,
}) {
    const { isRTL, lang, t } = useLanguage();
    const [form, setForm] = useState(() => initialValues(session, defaultDate));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [startSurahSearch, setStartSurahSearch] = useState('');
    const [endSurahSearch, setEndSurahSearch] = useState('');
    const [progressSessions, setProgressSessions] = useState([]);
    const [progressLoading, setProgressLoading] = useState(false);
    const [progressError, setProgressError] = useState(false);
    const formId = useId();
    const isEditing = Boolean(session?.id);
    const handleClose = useCallback(() => {
        if (!saving) onClose();
    }, [onClose, saving]);

    useEffect(() => {
        if (!open) return;

        setForm(initialValues(session, defaultDate));
        setError(null);
        setStartSurahSearch('');
        setEndSurahSearch('');
    }, [open, session, defaultDate]);

    useEffect(() => {
        if (!open || !form.student_id || !yearId) {
            setProgressSessions([]);
            setProgressLoading(false);
            setProgressError(false);
            return;
        }

        const controller = new AbortController();
        const query = new URLSearchParams({
            year_id: String(yearId),
            student_id: form.student_id,
        });

        const loadProgress = async () => {
            setProgressLoading(true);
            setProgressError(false);

            try {
                const response = await apiFetch(
                    `/memorization-sessions/progress?${query.toString()}`,
                    { signal: controller.signal },
                );
                if (!response.ok) throw new Error('Failed to load memorization progress');

                const data = await response.json();
                if (controller.signal.aborted) return;

                setProgressSessions(Array.isArray(data.sessions) ? data.sessions : []);
            } catch (requestError) {
                if (requestError.name !== 'AbortError') {
                    setProgressSessions([]);
                    setProgressError(true);
                }
            } finally {
                if (!controller.signal.aborted) setProgressLoading(false);
            }
        };

        loadProgress();
        return () => controller.abort();
    }, [form.student_id, open, yearId]);

    useEffect(() => {
        if (!open || isEditing || !form.student_id || form.surah_start || form.ayah_start || form.surah_end || form.ayah_end) {
            return;
        }

        const suggestion = getNextPortionSuggestion(progressSessions);
        if (!suggestion) return;

        setForm((current) => {
            if (current.surah_start || current.ayah_start || current.surah_end || current.ayah_end) {
                return current;
            }

            const nextForm = {
                ...current,
                surah_start: String(suggestion.surah_start),
                ayah_start: String(suggestion.ayah_start),
                surah_end: String(suggestion.surah_end),
                ayah_end: String(suggestion.ayah_end),
            };

            return {
                ...nextForm,
                portion_label: formatPortionRange(nextForm, lang),
            };
        });
    }, [form.ayah_end, form.ayah_start, form.surah_end, form.surah_start, form.student_id, isEditing, lang, open, progressSessions]);

    useEffect(() => {
        if (!open || !form.surah_start || !form.ayah_start || !form.surah_end || !form.ayah_end) {
            return;
        }

        const generatedLabel = formatPortionRange(form, lang);
        if (!generatedLabel || form.portion_label) {
            return;
        }

        setForm((current) => {
            if (!current.surah_start || !current.ayah_start || !current.surah_end || !current.ayah_end) {
                return current;
            }

            const nextLabel = formatPortionRange(current, lang);
            if (!nextLabel || current.portion_label) {
                return current;
            }

            return {
                ...current,
                portion_label: nextLabel,
            };
        });
    }, [form, lang, open]);

    const handleChange = (event) => {
        const { name, value } = event.target;
        setForm((current) => {
            const next = {
                ...current,
                [name]: value,
                ...(name === 'room_id' ? { student_id: '' } : {}),
            };

            if (name === 'surah_start' || name === 'surah_end') {
                const ayahField = name === 'surah_start' ? 'ayah_start' : 'ayah_end';
                const selectedSurah = getSurah(value);

                if (selectedSurah && Number(next[ayahField]) > selectedSurah.ayah_count) {
                    next[ayahField] = '';
                }
            }

            if (['surah_start', 'ayah_start', 'surah_end', 'ayah_end'].includes(name)) {
                next.portion_label = formatPortionRange(next, lang);
            }

            return next;
        });
        setError(null);
    };

    const handleSubmit = async (event) => {
        event.preventDefault();

        const hasStart = Boolean(form.surah_start || form.ayah_start);
        const hasEnd = Boolean(form.surah_end || form.ayah_end);
        const hasCompleteStart = Boolean(form.surah_start && form.ayah_start);
        const hasCompleteEnd = Boolean(form.surah_end && form.ayah_end);

        if ((hasStart && !hasCompleteStart) || (hasEnd && !hasCompleteEnd) || hasStart !== hasEnd) {
            setError(t.memorization.rangePairError);
            return;
        }

        const startSurah = getSurah(form.surah_start);
        const endSurah = getSurah(form.surah_end);
        if (
            hasStart
            && (
                !startSurah
                || !endSurah
                || Number(form.ayah_start) > startSurah.ayah_count
                || Number(form.ayah_end) > endSurah.ayah_count
            )
        ) {
            const invalidStart = startSurah && Number(form.ayah_start) > startSurah.ayah_count;
            const invalidSurah = !startSurah || !endSurah;
            const selectedSurah = invalidStart ? startSurah : endSurah;
            const invalidAyah = invalidStart ? form.ayah_start : form.ayah_end;
            setError(invalidSurah
                ? t.memorization.rangeError
                : t.memorization.ayahCountError(
                    formatQuranNumber(invalidAyah, lang),
                    surahDisplayName(selectedSurah, lang),
                    formatQuranNumber(selectedSurah.ayah_count, lang),
                ));
            return;
        }

        if (
            hasStart
            && (
                Number(form.surah_start) > Number(form.surah_end)
                || (
                    Number(form.surah_start) === Number(form.surah_end)
                    && Number(form.ayah_start) > Number(form.ayah_end)
                )
            )
        ) {
            setError(t.memorization.rangeOrderError);
            return;
        }

        setSaving(true);
        setError(null);

        const payload = {
            year_id: yearId,
            student_id: Number(form.student_id),
            room_id: Number(form.room_id),
            session_date: form.session_date,
            surah_start: hasStart ? Number(form.surah_start) : null,
            ayah_start: hasStart ? Number(form.ayah_start) : null,
            surah_end: hasEnd ? Number(form.surah_end) : null,
            ayah_end: hasEnd ? Number(form.ayah_end) : null,
            portion_label: form.portion_label,
            evaluation: form.evaluation,
            notes: form.notes || null,
        };
        if (canManage) payload.teacher_id = Number(form.teacher_id);

        try {
            const response = await apiFetch(
                isEditing ? `/memorization-sessions/${session.id}` : '/memorization-sessions',
                {
                    method: isEditing ? 'PUT' : 'POST',
                    body: JSON.stringify(payload),
                },
            );
            const data = await response.json();

            if (!response.ok) {
                if (response.status === 403) setError(t.memorization.permissionError);
                else if (response.status === 404) setError(t.memorization.resourceUnavailableError);
                else if (data?.errors?.year_id) setError(t.memorization.closedYearError);
                else if (data?.errors?.session_date) setError(t.memorization.sessionDateError);
                else if (data?.errors?.surah_start || data?.errors?.ayah_start || data?.errors?.surah_end || data?.errors?.ayah_end) {
                    const start = getSurah(form.surah_start);
                    const end = getSurah(form.surah_end);
                    if (start && Number(form.ayah_start) > start.ayah_count) {
                        setError(t.memorization.ayahCountError(
                            formatQuranNumber(form.ayah_start, lang),
                            surahDisplayName(start, lang),
                            formatQuranNumber(start.ayah_count, lang),
                        ));
                    } else if (end && Number(form.ayah_end) > end.ayah_count) {
                        setError(t.memorization.ayahCountError(
                            formatQuranNumber(form.ayah_end, lang),
                            surahDisplayName(end, lang),
                            formatQuranNumber(end.ayah_count, lang),
                        ));
                    } else {
                        setError(t.memorization.rangeError);
                    }
                } else if (data?.errors?.evaluation) setError(t.memorization.evaluationError);
                else if (data?.errors?.portion_label) setError(t.memorization.portionRequiredError);
                else setError(t.memorization.saveError);
                return;
            }

            await onSaved();
        } catch {
            setError(t.memorization.saveError);
        } finally {
            setSaving(false);
        }
    };

    const roomOptions = session?.room_id && !rooms.some((room) => room.id === session.room_id)
        ? [...rooms, { id: session.room_id, name: session.room_name }]
        : rooms;
    const studentOptions = session?.student_id && !students.some((student) => student.id === session.student_id)
        ? [...students, {
            id: session.student_id,
            name: session.student_name,
            student_number: session.student_number,
            room_id: session.room_id,
        }]
        : students;
    const roomStudents = studentOptions.filter((student) => String(student.room_id) === form.room_id);
    const teacherOptions = session?.teacher_id && !teachers.some((teacher) => teacher.id === session.teacher_id)
        ? [...teachers, { id: session.teacher_id, name: session.teacher_name }]
        : teachers;
    const startSurahOptions = useMemo(
        () => getSurahOptions(startSurahSearch, form.surah_start),
        [startSurahSearch, form.surah_start],
    );
    const endSurahOptions = useMemo(
        () => getSurahOptions(endSurahSearch, form.surah_end),
        [endSurahSearch, form.surah_end],
    );
    const hasRangeInput = Boolean(
        form.surah_start || form.ayah_start || form.surah_end || form.ayah_end,
    );
    const generatedRange = formatPortionRange(form, lang);
    const sameStudentAsSavedSession = String(session?.student_id ?? '') === form.student_id;
    const proposedRange = generatedRange
        ? form
        : (hasRangeInput && sameStudentAsSavedSession ? session : null);
    const progress = useMemo(() => getProgressPreview(
        progressSessions,
        proposedRange,
        session?.id ?? null,
    ), [
        progressSessions,
        proposedRange,
        session?.id,
    ]);
    const selectedStudent = roomStudents.find((student) => String(student.id) === form.student_id);
    const inputClass = 'min-h-11 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] leading-5 text-on-surface outline-none transition-colors focus:border-primary focus:bg-surface-container-lowest focus:ring-1 focus:ring-primary dark:border-outline-variant dark:bg-inverse-surface dark:text-inverse-on-surface dark:focus:bg-inverse-surface';
    const labelClass = 'flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface';

    return (
        <Modal
            open={open}
            title={isEditing ? t.memorization.editTitle : t.memorization.createTitle}
            icon="menu_book"
            onClose={handleClose}
            isRTL={isRTL}
            closeLabel={t.memorization.cancel}
            maxWidth="max-w-[760px]"
        >
            <form id={`${formId}-form`} onSubmit={handleSubmit} className="flex flex-col gap-5">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <label className={labelClass}>
                        {t.memorization.columns.room}
                        <select
                            required
                            name="room_id"
                            value={form.room_id}
                            onChange={handleChange}
                            className={inputClass}
                        >
                            <option value="">{t.memorization.selectRoom}</option>
                            {roomOptions.map((room) => <option key={room.id} value={room.id}>{room.name}</option>)}
                        </select>
                    </label>
                    <label className={labelClass}>
                        {t.memorization.columns.student}
                        <select
                            required
                            name="student_id"
                            value={form.student_id}
                            onChange={handleChange}
                            disabled={!form.room_id}
                            className={inputClass}
                        >
                            <option value="">{t.memorization.selectStudent}</option>
                            {roomStudents.map((student) => (
                                <option key={student.id} value={student.id}>
                                    {student.name}{student.student_number ? ` (${student.student_number})` : ''}
                                </option>
                            ))}
                        </select>
                    </label>
                    {canManage && (
                        <label className={labelClass}>
                            {t.memorization.columns.teacher}
                            <select
                                required
                                name="teacher_id"
                                value={form.teacher_id}
                                onChange={handleChange}
                                className={inputClass}
                            >
                                <option value="">{t.memorization.selectTeacher}</option>
                                {teacherOptions.map((teacher) => <option key={teacher.id} value={teacher.id}>{teacher.name}</option>)}
                            </select>
                        </label>
                    )}
                    <label className={labelClass}>
                        {t.memorization.columns.date}
                        <input
                            required
                            type="date"
                            name="session_date"
                            value={form.session_date}
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </label>
                    {form.student_id && (
                        <section
                            aria-live="polite"
                            className="flex flex-col gap-3 rounded-xl border border-outline-variant bg-surface-container-low p-3 sm:col-span-2 dark:bg-inverse-surface"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <h3 className="text-[13px] font-semibold text-on-surface dark:text-inverse-on-surface">
                                    {t.memorization.progressTitle(selectedStudent?.name ?? '')}
                                </h3>
                                {progressLoading && (
                                    <Icon name="progress_activity" className="animate-spin text-[18px] text-primary" />
                                )}
                            </div>
                            {progressError ? (
                                <p role="alert" className="text-[12px] text-error dark:text-error-container">
                                    {t.memorization.progressError}
                                </p>
                            ) : progressLoading ? (
                                <p role="status" className="text-[12px] text-on-surface-variant dark:text-inverse-on-surface">
                                    {t.memorization.progressLoading}
                                </p>
                            ) : (
                                <>
                                    <div className="flex items-center justify-between gap-2 text-[12px]">
                                        <span className="text-on-surface-variant dark:text-inverse-on-surface">
                                            {t.memorization.quranCoverage(
                                                formatQuranNumber(progress.coveredAyahs, lang),
                                                formatQuranNumber(progress.totalAyahs, lang),
                                            )}
                                        </span>
                                        <span className="font-semibold text-on-surface dark:text-inverse-on-surface">
                                            {formatQuranNumber(progress.percentage, lang)}%
                                        </span>
                                    </div>
                                    <div
                                        role="progressbar"
                                        aria-label={t.memorization.quranCoverage(progress.coveredAyahs, progress.totalAyahs)}
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        aria-valuenow={progress.percentage}
                                        className="h-2 overflow-hidden rounded-full bg-surface-container-high dark:bg-surface-container"
                                    >
                                        <div
                                            className="h-full rounded-full bg-primary transition-[width]"
                                            style={{ width: `${progress.percentage}%` }}
                                        />
                                    </div>
                                    <div className="grid grid-cols-2 gap-2 text-[11px] text-on-surface-variant dark:text-inverse-on-surface">
                                        <span>{t.memorization.completeSurahs(formatQuranNumber(progress.completeSurahCount, lang))}</span>
                                        <span>{t.memorization.completeJuz(formatQuranNumber(progress.completeJuzCount, lang))}</span>
                                        <span>{t.memorization.inProgressSurahCount(formatQuranNumber(progress.inProgressSurahs.length, lang))}</span>
                                        <span>{t.memorization.inProgressJuzCount(formatQuranNumber(progress.inProgressJuzs.length, lang))}</span>
                                        {progress.currentJuz && (
                                            <span className="col-span-2 font-medium text-on-surface dark:text-inverse-on-surface">
                                                {t.memorization.currentJuz(juzDisplayName(progress.currentJuz, lang))}
                                            </span>
                                        )}
                                        {progress.inProgressSurahs.slice(0, 2).map(({ surah, ranges }) => (
                                            <span key={surah.id} className="col-span-2">
                                                {t.memorization.surahInProgress(
                                                    ranges.map((range) => (
                                                        `${surahDisplayName(surah, lang)} ${formatQuranNumber(range.start, lang)}–${formatQuranNumber(range.end, lang)}`
                                                    )).join(' · '),
                                                )}
                                            </span>
                                        ))}
                                        {progress.inProgressJuzs.slice(0, 2).map(({ juz, coveredAyahs, totalAyahs }) => (
                                            <span key={juz.id} className="col-span-2">
                                                {t.memorization.juzInProgress(
                                                    juzDisplayName(juz, lang),
                                                    formatQuranNumber(coveredAyahs, lang),
                                                    formatQuranNumber(totalAyahs, lang),
                                                )}
                                            </span>
                                        ))}
                                    </div>
                                    {!progress.hasCoverage && (
                                        <p className="text-[11px] leading-4 text-on-surface-variant dark:text-inverse-on-surface">
                                            {t.memorization.progressEmpty}
                                        </p>
                                    )}
                                </>
                            )}
                        </section>
                    )}
                    <label className={`${labelClass} sm:col-span-2`}>
                        {t.memorization.columns.portion}
                        <input
                            required
                            maxLength={160}
                            name="portion_label"
                            value={form.portion_label}
                            onChange={handleChange}
                            className={inputClass}
                        />
                        {formatPortionRange(form, lang) && (
                            <span className="text-[12px] leading-5 text-on-surface-variant dark:text-inverse-on-surface">
                                {t.memorization.metadataPortionPreview(formatPortionRange(form, lang))}
                            </span>
                        )}
                        <span className="text-[11px] leading-4 font-normal text-on-surface-variant dark:text-inverse-on-surface">
                            {t.memorization.portionHelp}
                        </span>
                    </label>
                    <div className={`${labelClass} sm:col-span-2`}>
                        <span>{t.memorization.rangeLabel}</span>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="flex flex-col gap-3 rounded-lg border border-outline-variant p-3 dark:border-outline-variant">
                                <SurahPicker
                                    label={t.memorization.surahStart}
                                    name="surah_start"
                                    value={form.surah_start}
                                    searchTerm={startSurahSearch}
                                    options={startSurahOptions}
                                    isRTL={isRTL}
                                    lang={lang}
                                    inputClass={inputClass}
                                    labelClass={labelClass}
                                    searchLabel={t.memorization.searchSurahs}
                                    selectLabel={t.memorization.surahStart}
                                    placeholder={t.memorization.selectSurah}
                                    onSearchChange={(event) => setStartSurahSearch(event.target.value)}
                                    onChange={(event) => {
                                        handleChange(event);
                                        setStartSurahSearch('');
                                    }}
                                />
                                <label className={labelClass}>
                                {t.memorization.ayahStart}
                                <input
                                    type="number"
                                    min="1"
                                    max={getSurah(form.surah_start)?.ayah_count}
                                    inputMode="numeric"
                                    name="ayah_start"
                                    value={form.ayah_start}
                                    onChange={handleChange}
                                    className={inputClass}
                                />
                                </label>
                            </div>
                            <div className="flex flex-col gap-3 rounded-lg border border-outline-variant p-3 dark:border-outline-variant">
                                <SurahPicker
                                    label={t.memorization.surahEnd}
                                    name="surah_end"
                                    value={form.surah_end}
                                    searchTerm={endSurahSearch}
                                    options={endSurahOptions}
                                    isRTL={isRTL}
                                    lang={lang}
                                    inputClass={inputClass}
                                    labelClass={labelClass}
                                    searchLabel={t.memorization.searchSurahs}
                                    selectLabel={t.memorization.surahEnd}
                                    placeholder={t.memorization.selectSurah}
                                    onSearchChange={(event) => setEndSurahSearch(event.target.value)}
                                    onChange={(event) => {
                                        handleChange(event);
                                        setEndSurahSearch('');
                                    }}
                                />
                                <label className={labelClass}>
                                {t.memorization.ayahEnd}
                                <input
                                    type="number"
                                    min="1"
                                    max={getSurah(form.surah_end)?.ayah_count}
                                    inputMode="numeric"
                                    name="ayah_end"
                                    value={form.ayah_end}
                                    onChange={handleChange}
                                    className={inputClass}
                                />
                                </label>
                            </div>
                        </div>
                    </div>
                    <label className={labelClass}>
                        {t.memorization.columns.evaluation}
                        <select
                            required
                            name="evaluation"
                            value={form.evaluation}
                            onChange={handleChange}
                            className={inputClass}
                        >
                            {Object.entries(t.memorization.evaluations).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </select>
                    </label>
                    <label className={`${labelClass} sm:col-span-2`}>
                        {t.memorization.columns.notes}
                        <textarea
                            maxLength={5000}
                            name="notes"
                            rows={3}
                            value={form.notes}
                            onChange={handleChange}
                            className={`${inputClass} resize-y`}
                        />
                    </label>
                </div>
                {error && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{error}</p>}
                <footer className="flex flex-col-reverse gap-3 border-t border-outline-variant pt-4 dark:border-outline-variant sm:flex-row">
                    <button
                        type="button"
                        disabled={saving}
                        onClick={handleClose}
                        className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:opacity-60 dark:bg-inverse-surface dark:text-inverse-on-surface dark:hover:bg-tertiary-container"
                    >
                        {t.memorization.cancel}
                    </button>
                    <button
                        type="submit"
                        disabled={saving || !form.room_id || !form.student_id || (canManage && !form.teacher_id)}
                        className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-primary-container dark:text-on-primary"
                    >
                        <Icon name={saving ? 'progress_activity' : 'save'} className={`text-[18px] ${saving ? 'animate-spin' : ''}`} />
                        {saving ? t.memorization.saving : t.memorization.save}
                    </button>
                </footer>
            </form>
        </Modal>
    );
}
