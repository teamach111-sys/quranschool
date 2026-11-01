import React, { useCallback, useEffect, useId, useState } from 'react';
import Icon from '../../../components/Icon';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';

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
    const { isRTL, t } = useLanguage();
    const [form, setForm] = useState(() => initialValues(session, defaultDate));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const formId = useId();
    const isEditing = Boolean(session?.id);
    const handleClose = useCallback(() => {
        if (!saving) onClose();
    }, [onClose, saving]);

    useEffect(() => {
        if (!open) return;

        setForm(initialValues(session, defaultDate));
        setError(null);
    }, [open, session, defaultDate]);

    const handleChange = (event) => {
        const { name, value } = event.target;
        setForm((current) => ({
            ...current,
            [name]: value,
            ...(name === 'room_id' ? { student_id: '' } : {}),
        }));
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
                    setError(t.memorization.rangeError);
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
    const inputClass = 'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] leading-5 text-on-surface outline-none transition-colors focus:border-primary focus:bg-surface-container-lowest focus:ring-1 focus:ring-primary dark:border-outline-variant dark:bg-inverse-surface dark:text-inverse-on-surface dark:focus:bg-inverse-surface';
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
                        <span className="text-[11px] leading-4 font-normal text-on-surface-variant dark:text-inverse-on-surface">
                            {t.memorization.portionHelp}
                        </span>
                    </label>
                    <div className={`${labelClass} sm:col-span-2`}>
                        <span>{t.memorization.rangeLabel}</span>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <label className={labelClass}>
                                {t.memorization.surahStart}
                                <input
                                    type="number"
                                    min="1"
                                    max="114"
                                    name="surah_start"
                                    value={form.surah_start}
                                    onChange={handleChange}
                                    className={inputClass}
                                />
                            </label>
                            <label className={labelClass}>
                                {t.memorization.ayahStart}
                                <input
                                    type="number"
                                    min="1"
                                    max="286"
                                    name="ayah_start"
                                    value={form.ayah_start}
                                    onChange={handleChange}
                                    className={inputClass}
                                />
                            </label>
                            <label className={labelClass}>
                                {t.memorization.surahEnd}
                                <input
                                    type="number"
                                    min="1"
                                    max="114"
                                    name="surah_end"
                                    value={form.surah_end}
                                    onChange={handleChange}
                                    className={inputClass}
                                />
                            </label>
                            <label className={labelClass}>
                                {t.memorization.ayahEnd}
                                <input
                                    type="number"
                                    min="1"
                                    max="286"
                                    name="ayah_end"
                                    value={form.ayah_end}
                                    onChange={handleChange}
                                    className={inputClass}
                                />
                            </label>
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
