import React, { useCallback, useEffect, useId, useState } from 'react';
import SelectInput from '../../../components/SelectInput';
import Icon from '../../../components/Icon';
import DateInput from '../../../components/DateInput';
import TimeInput from '../../../components/TimeInput';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';
import { findScheduleConflict } from '../../../lib/scheduleConflicts';

function today() {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 10);
}

function initialValues(schedule, defaults = {}) {
    return {
        room_id: schedule ? String(schedule.room_id) : String(defaults.room_id ?? ''),
        teacher_id: schedule ? String(schedule.teacher_id) : '',
        day_of_week: schedule ? String(schedule.day_of_week) : String(defaults.day_of_week ?? '1'),
        starts_at: schedule?.starts_at ?? defaults.starts_at ?? '09:00',
        ends_at: schedule?.ends_at ?? defaults.ends_at ?? '10:00',
        valid_from: schedule?.valid_from ?? today(),
        valid_until: schedule?.valid_until ?? '',
        label: schedule?.label ?? '',
    };
}

export default function ScheduleFormModal({
    open,
    schedule = null,
    yearId,
    rooms,
    teachers,
    schedules = [],
    defaultValues,
    onClose,
    onSaved,
}) {
    const { isRTL, t } = useLanguage();
    const [form, setForm] = useState(() => initialValues(schedule));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const formId = useId();
    const isEditing = Boolean(schedule?.id);
    const conflict = findScheduleConflict(schedules, form, schedule?.id);
    const conflictResource = conflict?.roomConflict && conflict?.teacherConflict
        ? t.schedules.overlapRoomAndTeacher(conflict.schedule.room_name, conflict.schedule.teacher_name)
        : conflict?.roomConflict
            ? t.schedules.overlapRoom(conflict.schedule.room_name)
            : conflict
                ? t.schedules.overlapTeacher(conflict.schedule.teacher_name)
                : '';
    const handleClose = useCallback(() => {
        if (!saving) onClose();
    }, [onClose, saving]);
    const days = [
        t.schedules.days.monday,
        t.schedules.days.tuesday,
        t.schedules.days.wednesday,
        t.schedules.days.thursday,
        t.schedules.days.friday,
        t.schedules.days.saturday,
        t.schedules.days.sunday,
    ];

    useEffect(() => {
        if (!open) return;

        setForm(initialValues(schedule, defaultValues));
        setError(null);
    }, [open, schedule, defaultValues]);

    const handleChange = (event) => {
        const { name, value } = event.target;
        setForm((current) => ({ ...current, [name]: value }));
        setError(null);
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        const dayOfWeek = Number(form.day_of_week);

        if (!Number.isInteger(dayOfWeek) || dayOfWeek < 1 || dayOfWeek > 7) {
            setError(t.schedules.dayError);
            return;
        }

        if (form.ends_at <= form.starts_at) {
            setError(t.schedules.endTimeError);
            return;
        }

        if (form.valid_until && form.valid_until < form.valid_from) {
            setError(t.schedules.validityError);
            return;
        }

        setSaving(true);
        setError(null);

        try {
            const response = await apiFetch(
                isEditing ? `/schedules/${schedule.id}` : '/schedules',
                {
                    method: isEditing ? 'PUT' : 'POST',
                    body: JSON.stringify({
                        ...form,
                        room_id: Number(form.room_id),
                        teacher_id: Number(form.teacher_id),
                        day_of_week: dayOfWeek,
                        valid_until: form.valid_until || null,
                        year_id: yearId,
                    }),
                },
            );
            const data = await response.json();

            if (!response.ok) {
                if (data?.errors?.year_id) setError(t.schedules.closedYearError);
                else if (data?.errors?.day_of_week) setError(t.schedules.dayError);
                else if (data?.errors?.ends_at || data?.errors?.starts_at) setError(t.schedules.endTimeError);
                else if (data?.errors?.valid_from || data?.errors?.valid_until) setError(t.schedules.validityError);
                else if (data?.errors?.schedule) setError(t.schedules.overlapError);
                else if (data?.errors?.room_id || data?.errors?.teacher_id) setError(t.schedules.resourceError);
                else setError(t.schedules.saveError);
                return;
            }

            await onSaved();
        } catch {
            setError(t.schedules.saveError);
        } finally {
            setSaving(false);
        }
    };

    const inputClass = 'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] leading-5 text-on-surface outline-none transition-colors focus:border-primary focus:bg-surface-container-lowest focus:ring-1 focus:ring-primary dark:border-[#384d43] dark:bg-[#182620] dark:text-[#ecf3ef] dark:focus:border-primary-fixed-dim dark:focus:bg-[#202f29] dark:focus:ring-primary-fixed-dim';
    const labelClass = 'flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface';

    return (
        <Modal
            open={open}
            title={isEditing ? t.schedules.editTitle : t.schedules.createTitle}
            icon="event"
            onClose={handleClose}
            loading={saving}
            isRTL={isRTL}
            closeLabel={t.schedules.cancel}
            maxWidth="max-w-[680px]"
        >
            <form id={`${formId}-form`} onSubmit={handleSubmit} className="flex flex-col gap-5">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <label className={labelClass}>
                        {t.schedules.columns.room}
                        <SelectInput
                            required
                            name="room_id"
                            value={form.room_id}
                            onChange={handleChange}
                            className={inputClass}
                        >
                            <option value="">{t.schedules.selectRoom}</option>
                            {rooms.map((room) => <option key={room.id} value={room.id}>{room.name}</option>)}
                        </SelectInput>
                    </label>
                    <label className={labelClass}>
                        {t.schedules.columns.teacher}
                        <SelectInput
                            required
                            name="teacher_id"
                            value={form.teacher_id}
                            onChange={handleChange}
                            className={inputClass}
                        >
                            <option value="">{t.schedules.selectTeacher}</option>
                            {teachers.map((teacher) => <option key={teacher.id} value={teacher.id}>{teacher.name}</option>)}
                        </SelectInput>
                    </label>
                    <label className={labelClass}>
                        {t.schedules.columns.day}
                        <SelectInput
                            required
                            name="day_of_week"
                            value={form.day_of_week}
                            onChange={handleChange}
                            className={inputClass}
                        >
                            {days.map((day, index) => <option key={day} value={index + 1}>{day}</option>)}
                        </SelectInput>
                    </label>
                    <label className={labelClass}>
                        {t.schedules.columns.label}
                        <input
                            maxLength={100}
                            name="label"
                            value={form.label}
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        {t.schedules.startsAt}
                        <TimeInput
                            required
                            name="starts_at"
                            value={form.starts_at}
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        {t.schedules.endsAt}
                        <TimeInput
                            required
                            min={form.starts_at}
                            name="ends_at"
                            value={form.ends_at}
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        {t.schedules.validFrom}
                        <DateInput
                            required
                            name="valid_from"
                            value={form.valid_from}
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        {t.schedules.validUntil}
                        <DateInput
                            min={form.valid_from}
                            name="valid_until"
                            value={form.valid_until}
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </label>
                </div>
                {error && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{error}</p>}
                {conflict && (
                    <p
                        id={`${formId}-schedule-conflict`}
                        role="alert"
                        aria-live="assertive"
                        className="rounded-lg border border-error/30 bg-error-container px-3 py-2.5 text-[13px] leading-5 text-on-error-container dark:border-error/40 dark:bg-[#4a2626]/50 dark:text-[#ffdad6]"
                    >
                        {t.schedules.overlapLive(
                            conflictResource,
                            conflict.schedule.starts_at,
                            conflict.schedule.ends_at,
                        )}
                    </p>
                )}
                <footer className="flex flex-col-reverse gap-3 border-t border-outline-variant pt-4 dark:border-[#384d43] sm:flex-row">
                    <button
                        type="button"
                        disabled={saving}
                        onClick={handleClose}
                        className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:opacity-60 dark:bg-[#2c3d36] dark:text-[#ecf3ef] dark:hover:bg-[#344a41]"
                    >
                        {t.schedules.cancel}
                    </button>
                    <button
                        type="submit"
                        aria-describedby={conflict ? `${formId}-schedule-conflict` : undefined}
                        disabled={saving || rooms.length === 0 || teachers.length === 0 || Boolean(conflict)}
                        className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]"
                    >
                        <Icon name={saving ? 'progress_activity' : 'save'} className={`text-[18px] ${saving ? 'animate-spin' : ''}`} />
                        {saving ? t.schedules.saving : t.schedules.save}
                    </button>
                </footer>
            </form>
        </Modal>
    );
}
