import React, { useCallback, useEffect, useId, useState } from 'react';
import Icon from '../../../components/Icon';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';

function initialValues(record, todayDate, selectedStudentId) {
    return {
        student_id: selectedStudentId ?? (record ? String(record.student_id) : ''),
        attendance_date: record?.attendance_date ?? todayDate,
        status: record?.status ?? 'present',
        note: record?.note ?? '',
    };
}

export default function AttendanceEntryModal({
    open,
    mode,
    record = null,
    selectedStudentId,
    embedded = false,
    cancelLabel,
    yearId,
    todayDate,
    students,
    onClose,
    onSaved,
}) {
    const { isRTL, t } = useLanguage();
    const [form, setForm] = useState(() => initialValues(record, todayDate, selectedStudentId));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const formId = useId();
    const isFollowUp = mode === 'follow_up';
    const isNewMark = mode === 'mark' && !record;
    const handleClose = useCallback(() => {
        if (!saving) onClose();
    }, [onClose, saving]);

    useEffect(() => {
        if (!open) return;

        setForm(initialValues(record, todayDate, selectedStudentId));
        setError(null);
    }, [open, record, selectedStudentId, todayDate]);

    const handleChange = (event) => {
        const { name, value } = event.target;
        setForm((current) => ({ ...current, [name]: value }));
        setError(null);
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        setSaving(true);
        setError(null);

        const payload = {
            year_id: yearId,
            student_id: Number(form.student_id),
            attendance_date: form.attendance_date,
            note: form.note || null,
        };
        if (!isFollowUp) payload.status = form.status;

        try {
            const response = await apiFetch('/attendance', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            const data = await response.json();

            if (!response.ok) {
                if (response.status === 403) setError(t.attendance.permissionError);
                else if (response.status === 404) setError(t.attendance.recordUnavailableError);
                else if (data?.errors?.attendance_date) setError(t.attendance.correctionWindowError);
                else if (data?.errors?.student_id) setError(t.attendance.studentUnavailableError);
                else if (data?.errors?.status) setError(t.attendance.statusError);
                else if (data?.errors?.year_id) setError(t.attendance.yearError);
                else setError(t.attendance.saveError);
                return;
            }

            await onSaved();
        } catch {
            setError(t.attendance.saveError);
        } finally {
            setSaving(false);
        }
    };

    const title = isFollowUp
        ? t.attendance.followUpTitle
        : isNewMark
            ? t.attendance.markTitle
            : mode === 'correct'
                ? t.attendance.correctTitle
                : t.attendance.editTitle;
    const inputClass = 'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] leading-5 text-on-surface outline-none transition-colors focus:border-primary focus:bg-surface-container-lowest focus:ring-1 focus:ring-primary dark:border-[#384d43] dark:bg-[#182620] dark:text-[#ecf3ef] dark:focus:border-primary-fixed-dim dark:focus:bg-[#202f29] dark:focus:ring-primary-fixed-dim';
    const labelClass = 'flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface';
    const selectedStudent = students.find((student) => String(student.id) === form.student_id);

    const formContent = (
        <form id={`${formId}-form`} onSubmit={handleSubmit} className="flex flex-col gap-5">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <label className={labelClass}>
                        {t.attendance.columns.student}
                        {isNewMark && !selectedStudentId ? (
                            <select
                                required
                                name="student_id"
                                value={form.student_id}
                                onChange={handleChange}
                                className={inputClass}
                            >
                                <option value="">{t.attendance.selectStudent}</option>
                                {students.map((student) => (
                                    <option key={student.id} value={student.id}>
                                        {student.name}{student.student_number ? ` (${student.student_number})` : ''}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <input
                                readOnly
                                value={record?.student_name ?? selectedStudent?.name ?? ''}
                                className={`${inputClass} cursor-not-allowed opacity-75`}
                            />
                        )}
                    </label>
                    <label className={labelClass}>
                        {t.attendance.columns.date}
                        <input
                            required
                            readOnly={!isNewMark}
                            type="date"
                            min={mode === 'mark' ? todayDate : undefined}
                            max={mode === 'mark' ? todayDate : undefined}
                            name="attendance_date"
                            value={form.attendance_date}
                            onChange={handleChange}
                            className={`${inputClass} read-only:cursor-not-allowed read-only:opacity-75`}
                        />
                    </label>
                    <label className={`${labelClass} sm:col-span-2`}>
                        {t.attendance.columns.status}
                        {isFollowUp ? (
                            <input
                                readOnly
                                value={t.attendance.statuses[record?.status] ?? ''}
                                className={`${inputClass} cursor-not-allowed opacity-75`}
                            />
                        ) : (
                            <select
                                required
                                name="status"
                                value={form.status}
                                onChange={handleChange}
                                className={inputClass}
                            >
                                {Object.entries(t.attendance.statuses).map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </select>
                        )}
                    </label>
                    <label className={`${labelClass} sm:col-span-2`}>
                        {t.attendance.columns.note}
                        <textarea
                            maxLength={500}
                            name="note"
                            rows={3}
                            value={form.note}
                            onChange={handleChange}
                            className={`${inputClass} resize-y`}
                            placeholder={isFollowUp ? t.attendance.followUpPlaceholder : ''}
                        />
                    </label>
                </div>
                {error && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{error}</p>}
                <footer className="flex flex-col-reverse gap-3 border-t border-outline-variant pt-4 dark:border-[#384d43] sm:flex-row">
                    <button
                        type="button"
                        disabled={saving}
                        onClick={handleClose}
                        className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:opacity-60 dark:bg-[#2c3d36] dark:text-[#ecf3ef] dark:hover:bg-[#344a41]"
                    >
                        {cancelLabel ?? t.attendance.cancel}
                    </button>
                    <button
                        type="submit"
                        disabled={saving || (isNewMark && students.length === 0)}
                        className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]"
                    >
                        <Icon name={saving ? 'progress_activity' : 'save'} className={`text-[18px] ${saving ? 'animate-spin' : ''}`} />
                        {saving ? t.attendance.saving : t.attendance.save}
                    </button>
                </footer>
        </form>
    );

    if (embedded) {
        return formContent;
    }

    return (
        <Modal
            open={open}
            title={title}
            icon="fact_check"
            onClose={handleClose}
            isRTL={isRTL}
            closeLabel={t.attendance.cancel}
            maxWidth="max-w-[600px]"
        >
            {formContent}
        </Modal>
    );
}
