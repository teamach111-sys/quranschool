import React, { useCallback, useEffect, useState } from 'react';
import Icon from '../../../components/Icon';
import ScheduleFormModal from '../../administrator/components/ScheduleFormModal';
import { useLanguage } from '../../../hooks/useLanguage';
import { apiFetch } from '../../../lib/api';
import {
    ACADEMIC_YEAR_CHANGED_EVENT,
    getSelectedAcademicYearId,
} from '../../../persisters/yearselector';
import DesktopTable from '../../../tables/desktoptable';
import MobileTable from '../../../tables/mobiletable';

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
        setError(null);
        setFormOpen(true);
    };

    const openEditForm = (schedule) => {
        setEditingSchedule(schedule);
        setError(null);
        setFormOpen(true);
    };

    const handleScheduleSaved = async () => {
        setFormOpen(false);
        setEditingSchedule(null);
        await loadSchedules();
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
            {canManage && (
                <ScheduleFormModal
                    open={formOpen}
                    schedule={editingSchedule}
                    yearId={yearId}
                    rooms={rooms}
                    teachers={teachers}
                    onClose={() => {
                        setFormOpen(false);
                        setError(null);
                    }}
                    onSaved={handleScheduleSaved}
                />
            )}
        </section>
    );
}
