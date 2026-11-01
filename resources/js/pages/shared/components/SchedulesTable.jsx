import React, { useCallback, useEffect, useState } from 'react';
import Icon from '../../../components/Icon';
import { useLanguage } from '../../../hooks/useLanguage';
import { apiFetch } from '../../../lib/api';
import {
    ACADEMIC_YEAR_CHANGED_EVENT,
    getSelectedAcademicYearId,
} from '../../../persisters/yearselector';
import DesktopTable from '../../../tables/desktoptable';
import MobileTable from '../../../tables/mobiletable';

function today() {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 10);
}

function emptySchedule() {
    return {
        room_id: '',
        teacher_id: '',
        day_of_week: '1',
        starts_at: '09:00',
        ends_at: '10:00',
        valid_from: today(),
        valid_until: '',
        label: '',
    };
}

export default function SchedulesTable() {
    const { isRTL, t } = useLanguage();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [schedules, setSchedules] = useState([]);
    const [rooms, setRooms] = useState([]);
    const [teachers, setTeachers] = useState([]);
    const [canManage, setCanManage] = useState(false);
    const [yearIsActive, setYearIsActive] = useState(false);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [error, setError] = useState(null);
    const [formOpen, setFormOpen] = useState(false);
    const [editingSchedule, setEditingSchedule] = useState(null);
    const [form, setForm] = useState(emptySchedule);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const syncYear = (event) => {
            const nextYearId = event?.detail?.id ?? getSelectedAcademicYearId();
            setYearId(nextYearId ? Number(nextYearId) : null);
            setFormOpen(false);
            setEditingSchedule(null);
        };

        window.addEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
        return () => window.removeEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
    }, []);

    const loadSchedules = useCallback(async (signal) => {
        setLoading(true);
        setLoadError(false);

        try {
            const query = yearId ? `?year_id=${encodeURIComponent(yearId)}` : '';
            const response = await apiFetch(`/schedules/table${query}`, { signal });
            if (!response.ok) throw new Error('Failed to load schedules');
            const data = await response.json();

            if (signal?.aborted) return;
            setYearId((currentYearId) => currentYearId ?? Number(data.year_id));
            setSchedules(Array.isArray(data.schedules) ? data.schedules : []);
            setRooms(Array.isArray(data.rooms) ? data.rooms : []);
            setTeachers(Array.isArray(data.teachers) ? data.teachers : []);
            setCanManage(Boolean(data.can_manage));
            setYearIsActive(Boolean(data.year_is_active));
        } catch (requestError) {
            if (requestError.name !== 'AbortError') setLoadError(true);
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, [yearId]);

    useEffect(() => {
        const controller = new AbortController();
        loadSchedules(controller.signal);
        return () => controller.abort();
    }, [loadSchedules]);

    const openCreateForm = () => {
        setEditingSchedule(null);
        setForm(emptySchedule());
        setError(null);
        setFormOpen(true);
    };

    const openEditForm = (schedule) => {
        setEditingSchedule(schedule);
        setForm({
            room_id: String(schedule.room_id),
            teacher_id: String(schedule.teacher_id),
            day_of_week: String(schedule.day_of_week),
            starts_at: schedule.starts_at,
            ends_at: schedule.ends_at,
            valid_from: schedule.valid_from,
            valid_until: schedule.valid_until ?? '',
            label: schedule.label ?? '',
        });
        setError(null);
        setFormOpen(true);
    };

    const handleChange = (event) => {
        const { name, value } = event.target;
        setForm((current) => ({ ...current, [name]: value }));
        setError(null);
    };

    const saveSchedule = async (event) => {
        event.preventDefault();
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
                editingSchedule ? `/schedules/${editingSchedule.id}` : '/schedules',
                {
                    method: editingSchedule ? 'PUT' : 'POST',
                    body: JSON.stringify({
                        ...form,
                        room_id: Number(form.room_id),
                        teacher_id: Number(form.teacher_id),
                        day_of_week: Number(form.day_of_week),
                        valid_until: form.valid_until || null,
                        year_id: yearId,
                    }),
                },
            );
            const data = await response.json();

            if (!response.ok) {
                if (data?.errors?.year_id) setError(t.schedules.closedYearError);
                else if (data?.errors?.ends_at || data?.errors?.starts_at) setError(t.schedules.endTimeError);
                else if (data?.errors?.valid_from || data?.errors?.valid_until) setError(t.schedules.validityError);
                else if (data?.errors?.schedule) setError(t.schedules.overlapError);
                else setError(t.schedules.saveError);
                return;
            }

            setFormOpen(false);
            setEditingSchedule(null);
            await loadSchedules();
        } catch {
            setError(t.schedules.saveError);
        } finally {
            setSaving(false);
        }
    };

    const days = [
        t.schedules.days.monday,
        t.schedules.days.tuesday,
        t.schedules.days.wednesday,
        t.schedules.days.thursday,
        t.schedules.days.friday,
        t.schedules.days.saturday,
        t.schedules.days.sunday,
    ];
    const columns = [
        {
            key: 'day_of_week',
            label: t.schedules.columns.day,
            sortable: true,
            mobile: 'subtitle',
            value: (schedule) => days[schedule.day_of_week - 1],
            sortValue: (schedule) => schedule.day_of_week,
        },
        {
            key: 'time',
            label: t.schedules.columns.time,
            sortable: true,
            value: (schedule) => `${schedule.starts_at} – ${schedule.ends_at}`,
            sortValue: (schedule) => schedule.starts_at,
            mobile: 'detail',
        },
        {
            key: 'room_name',
            label: t.schedules.columns.room,
            sortable: true,
            mobile: 'detail',
        },
        {
            key: 'teacher_name',
            label: t.schedules.columns.teacher,
            sortable: true,
            mobile: 'detail',
        },
        {
            key: 'label',
            label: t.schedules.columns.label,
            sortable: true,
            mobile: 'title',
        },
        {
            key: 'validity',
            label: t.schedules.columns.validity,
            value: (schedule) => `${schedule.valid_from} – ${schedule.valid_until ?? t.schedules.noEndDate}`,
            mobile: 'detail',
        },
    ];
    const actions = canManage && yearIsActive ? [{
        key: 'edit',
        icon: 'edit',
        label: t.schedules.edit,
        onClick: openEditForm,
    }] : [];
    const labels = {
        actions: t.schedules.table.actions,
        pagination: t.schedules.table.pagination,
        previous: t.schedules.table.previous,
        next: t.schedules.table.next,
        page: (page) => t.schedules.table.page(page),
        selectPage: t.schedules.table.selectPage,
        selectRow: (schedule) => t.schedules.table.selectRow(schedule.label),
        sortBy: (label) => t.schedules.table.sortBy(label),
        range: (first, last, total) => t.schedules.table.range(first, last, total),
    };
    const inputClass = 'min-h-10 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:border-[#384d43] dark:bg-[#182620] dark:text-[#ecf3ef]';
    const labelClass = 'flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface';

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.schedules.loadError}</p>;
    }

    if (loading) {
        return <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">{t.schedules.loading}</p>;
    }

    const toolbar = (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
            {canManage && yearIsActive && (
                <button
                    type="button"
                    onClick={openCreateForm}
                    className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg bg-primary-container px-3.5 py-2 text-[12px] font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]"
                >
                    <Icon name="add" className="text-[17px]" />
                    {t.schedules.create}
                </button>
            )}
        </div>
    );

    return (
        <section className="flex w-full flex-col gap-3" dir={isRTL ? 'rtl' : 'ltr'}>
            {error && !formOpen && (
                <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{error}</p>
            )}
            {formOpen && canManage && (
                <form
                    onSubmit={saveSchedule}
                    className="grid grid-cols-1 gap-3 rounded-xl border border-outline-variant bg-surface-container-lowest p-4 dark:border-[#384d43] dark:bg-[#22302a] sm:grid-cols-2 lg:grid-cols-4"
                >
                    <h2 className="text-[14px] font-semibold text-on-surface dark:text-inverse-on-surface sm:col-span-2 lg:col-span-4">
                        {editingSchedule ? t.schedules.editTitle : t.schedules.createTitle}
                    </h2>
                    <label className={labelClass}>
                        {t.schedules.columns.room}
                        <select required name="room_id" value={form.room_id} onChange={handleChange} className={inputClass}>
                            <option value="">{t.schedules.selectRoom}</option>
                            {rooms.map((room) => <option key={room.id} value={room.id}>{room.name}</option>)}
                        </select>
                    </label>
                    <label className={labelClass}>
                        {t.schedules.columns.teacher}
                        <select required name="teacher_id" value={form.teacher_id} onChange={handleChange} className={inputClass}>
                            <option value="">{t.schedules.selectTeacher}</option>
                            {teachers.map((teacher) => <option key={teacher.id} value={teacher.id}>{teacher.name}</option>)}
                        </select>
                    </label>
                    <label className={labelClass}>
                        {t.schedules.columns.day}
                        <select required name="day_of_week" value={form.day_of_week} onChange={handleChange} className={inputClass}>
                            {days.map((day, index) => <option key={day} value={index + 1}>{day}</option>)}
                        </select>
                    </label>
                    <label className={labelClass}>
                        {t.schedules.columns.label}
                        <input maxLength={100} name="label" value={form.label} onChange={handleChange} className={inputClass} />
                    </label>
                    <label className={labelClass}>
                        {t.schedules.startsAt}
                        <input required type="time" name="starts_at" value={form.starts_at} onChange={handleChange} className={inputClass} />
                    </label>
                    <label className={labelClass}>
                        {t.schedules.endsAt}
                        <input required type="time" name="ends_at" min={form.starts_at} value={form.ends_at} onChange={handleChange} className={inputClass} />
                    </label>
                    <label className={labelClass}>
                        {t.schedules.validFrom}
                        <input required type="date" name="valid_from" value={form.valid_from} onChange={handleChange} className={inputClass} />
                    </label>
                    <label className={labelClass}>
                        {t.schedules.validUntil}
                        <input type="date" min={form.valid_from} name="valid_until" value={form.valid_until} onChange={handleChange} className={inputClass} />
                    </label>
                    {error && <p role="alert" className="text-[13px] text-error dark:text-error-container sm:col-span-2 lg:col-span-4">{error}</p>}
                    <div className="flex flex-col-reverse gap-2 sm:col-span-2 sm:flex-row sm:justify-end lg:col-span-4">
                        <button
                            type="button"
                            disabled={saving}
                            onClick={() => {
                                setFormOpen(false);
                                setError(null);
                            }}
                            className="min-h-10 rounded-lg bg-surface-container px-4 py-2 text-[12px] font-semibold text-on-surface disabled:opacity-60 dark:bg-[#2c3d36] dark:text-[#ecf3ef]"
                        >
                            {t.schedules.cancel}
                        </button>
                        <button
                            type="submit"
                            disabled={saving || rooms.length === 0 || teachers.length === 0}
                            className="min-h-10 rounded-lg bg-primary-container px-4 py-2 text-[12px] font-semibold text-on-primary disabled:opacity-60 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]"
                        >
                            {saving ? t.schedules.saving : t.schedules.save}
                        </button>
                    </div>
                </form>
            )}
            <div className="hidden md:block">
                <DesktopTable
                    ariaLabel={t.schedules.listLabel}
                    columns={columns}
                    emptyState={t.schedules.empty}
                    isRTL={isRTL}
                    labels={labels}
                    rows={schedules}
                    actions={actions}
                    toolbar={toolbar}
                />
            </div>
            <div className="md:hidden">
                <MobileTable
                    ariaLabel={t.schedules.listLabel}
                    columns={columns}
                    emptyState={t.schedules.empty}
                    isRTL={isRTL}
                    labels={labels}
                    rows={schedules}
                    actions={actions}
                    toolbar={toolbar}
                />
            </div>
        </section>
    );
}
