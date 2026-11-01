import React, { useCallback, useEffect, useId, useState } from 'react';
import Icon from '../../../components/Icon';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';

function today() {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 10);
}

function initialValues(room) {
    return {
        room_id: room ? String(room.id) : '',
        teacher_id: '',
        assigned_from: today(),
        assigned_until: '',
    };
}

export default function RoomTeacherAssignmentModal({
    open,
    rooms,
    teachers,
    yearId,
    room = null,
    onClose,
    onSaved,
}) {
    const { isRTL, t } = useLanguage();
    const [form, setForm] = useState(() => initialValues(room));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const formId = useId();
    const handleClose = useCallback(() => {
        if (!saving) onClose();
    }, [onClose, saving]);
    const activeRooms = rooms.filter((item) => item.is_active);

    useEffect(() => {
        if (!open) return;

        setForm(initialValues(room));
        setError(null);
    }, [open, room]);

    const handleChange = (event) => {
        const { name, value } = event.target;
        setForm((current) => ({ ...current, [name]: value }));
        setError(null);
    };

    const handleSubmit = async (event) => {
        event.preventDefault();

        if (form.assigned_until && form.assigned_until < form.assigned_from) {
            setError(t.rooms.assignmentDateError);
            return;
        }

        setSaving(true);
        setError(null);

        try {
            const response = await apiFetch(`/rooms/${form.room_id}/teachers`, {
                method: 'POST',
                body: JSON.stringify({
                    teacher_id: Number(form.teacher_id),
                    assigned_from: form.assigned_from,
                    assigned_until: form.assigned_until || null,
                    year_id: yearId,
                }),
            });
            const data = await response.json();

            if (!response.ok) {
                const errors = data?.errors ?? {};
                if (errors.year_id) setError(t.rooms.closedYearError);
                else if (errors.assigned_from || errors.assigned_until) setError(t.rooms.assignmentDateError);
                else setError(t.rooms.assignmentError);
                return;
            }

            await onSaved();
        } catch {
            setError(t.rooms.assignmentError);
        } finally {
            setSaving(false);
        }
    };

    const inputClass = 'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] leading-5 text-on-surface outline-none transition-colors focus:border-primary focus:bg-surface-container-lowest focus:ring-1 focus:ring-primary dark:border-[#384d43] dark:bg-[#182620] dark:text-[#ecf3ef] dark:focus:border-primary-fixed-dim dark:focus:bg-[#202f29] dark:focus:ring-primary-fixed-dim';
    const labelClass = 'text-[12px] leading-4 font-semibold text-on-surface dark:text-inverse-on-surface';

    return (
        <Modal
            open={open}
            title={t.rooms.assignmentModalTitle}
            icon="assignment_ind"
            onClose={handleClose}
            isRTL={isRTL}
            closeLabel={t.rooms.cancel}
            maxWidth="max-w-[520px]"
        >
            <form id={`${formId}-form`} onSubmit={handleSubmit} className="flex flex-col gap-5">
                {room && (
                    <p className="rounded-lg bg-surface-container-low px-3 py-2.5 text-[13px] text-on-surface-variant dark:bg-[#182620] dark:text-[#c2d1c8]">
                        {t.rooms.assignToRoom(room.name)}
                    </p>
                )}
                <div className="flex flex-col gap-4">
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-room`} className={labelClass}>{t.rooms.form.targetRoom}</label>
                        <select
                            id={`${formId}-room`}
                            name="room_id"
                            required
                            value={form.room_id}
                            onChange={handleChange}
                            className={inputClass}
                        >
                            <option value="">{t.rooms.form.selectRoom}</option>
                            {activeRooms.map((item) => (
                                <option key={item.id} value={item.id}>{item.name}</option>
                            ))}
                        </select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-teacher`} className={labelClass}>{t.rooms.form.teacher}</label>
                        <select
                            id={`${formId}-teacher`}
                            name="teacher_id"
                            required
                            value={form.teacher_id}
                            onChange={handleChange}
                            className={inputClass}
                        >
                            <option value="">{t.rooms.form.selectTeacher}</option>
                            {teachers.map((teacher) => (
                                <option key={teacher.id} value={teacher.id}>{teacher.name}</option>
                            ))}
                        </select>
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
                            <label htmlFor={`${formId}-assigned-from`} className={labelClass}>{t.rooms.form.assignedFrom}</label>
                            <input
                                id={`${formId}-assigned-from`}
                                name="assigned_from"
                                type="date"
                                required
                                value={form.assigned_from}
                                onChange={handleChange}
                                className={inputClass}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <label htmlFor={`${formId}-assigned-until`} className={labelClass}>{t.rooms.form.assignedUntil}</label>
                            <input
                                id={`${formId}-assigned-until`}
                                name="assigned_until"
                                type="date"
                                min={form.assigned_from}
                                value={form.assigned_until}
                                onChange={handleChange}
                                className={inputClass}
                            />
                        </div>
                    </div>
                    {teachers.length === 0 && (
                        <p role="status" className="text-[12px] text-on-surface-variant dark:text-[#c2d1c8]">
                            {t.rooms.noAvailableTeachers}
                        </p>
                    )}
                    {activeRooms.length === 0 && (
                        <p role="status" className="text-[12px] text-on-surface-variant dark:text-[#c2d1c8]">
                            {t.rooms.noAvailableRooms}
                        </p>
                    )}
                </div>
                {error && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{error}</p>}
                <footer className="flex flex-col-reverse gap-3 border-t border-outline-variant pt-4 dark:border-[#384d43] sm:flex-row">
                    <button
                        type="button"
                        disabled={saving}
                        onClick={handleClose}
                        className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:opacity-60 dark:bg-[#2c3d36] dark:text-[#ecf3ef] dark:hover:bg-[#344a41]"
                    >
                        {t.rooms.cancel}
                    </button>
                    <button
                        type="submit"
                        disabled={saving || teachers.length === 0 || activeRooms.length === 0}
                        className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]"
                    >
                        <Icon name={saving ? 'progress_activity' : 'assignment_ind'} className={`text-[18px] ${saving ? 'animate-spin' : ''}`} />
                        {saving ? t.rooms.saving : t.rooms.assignTeacher}
                    </button>
                </footer>
            </form>
        </Modal>
    );
}
