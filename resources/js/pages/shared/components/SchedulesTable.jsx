import React, { useCallback, useEffect, useState } from 'react';
import Icon from '../../../components/Icon';
import ScheduleFormModal from '../../administrator/components/ScheduleFormModal';
import { useLanguage } from '../../../hooks/useLanguage';
import { apiFetch } from '../../../lib/api';
import {
    ACADEMIC_YEAR_CHANGED_EVENT,
    getSelectedAcademicYearId,
} from '../../../persisters/yearselector';

function minutesSinceMidnight(time) {
    const [hours, minutes] = String(time ?? '').slice(0, 5).split(':').map(Number);
    return hours * 60 + minutes;
}

function formatHour(hour) {
    return `${String(hour).padStart(2, '0')}:00`;
}

function layoutDaySchedules(daySchedules) {
    const laneEnds = [];
    const positioned = [...daySchedules]
        .sort((left, right) => left.starts_at.localeCompare(right.starts_at) || left.ends_at.localeCompare(right.ends_at))
        .map((schedule) => {
            const start = minutesSinceMidnight(schedule.starts_at);
            let lane = laneEnds.findIndex((end) => end <= start);

            if (lane === -1) {
                lane = laneEnds.length;
                laneEnds.push(minutesSinceMidnight(schedule.ends_at));
            } else {
                laneEnds[lane] = minutesSinceMidnight(schedule.ends_at);
            }

            return { schedule, lane };
        });

    return positioned.map((slot) => ({ ...slot, laneCount: Math.max(laneEnds.length, 1) }));
}

export default function SchedulesTable() {
    const { isRTL, t } = useLanguage();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [schedules, setSchedules] = useState([]);
    const [rooms, setRooms] = useState([]);
    const [teachers, setTeachers] = useState([]);
    const [canManage, setCanManage] = useState(false);
    const [yearIsWritable, setYearIsWritable] = useState(false);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [error, setError] = useState(null);
    const [formOpen, setFormOpen] = useState(false);
    const [editingSchedule, setEditingSchedule] = useState(null);
    const [creationDefaults, setCreationDefaults] = useState(null);

    useEffect(() => {
        const syncYear = (event) => {
            const nextYearId = event?.detail?.id ?? getSelectedAcademicYearId();
            setYearId(nextYearId ? Number(nextYearId) : null);
            setYearIsWritable(false);
            setFormOpen(false);
            setEditingSchedule(null);
            setCreationDefaults(null);
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
            setYearIsWritable(Boolean(data.year_is_writable));
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

    const openCreateForm = (dayOfWeek = 1, startsAt = '09:00') => {
        setEditingSchedule(null);
        const [hour] = startsAt.split(':').map(Number);
        const endsAt = `${String(Math.min(hour + 1, 23)).padStart(2, '0')}:00`;
        setCreationDefaults({
            day_of_week: String(dayOfWeek),
            starts_at: startsAt,
            ends_at: endsAt,
        });
        setError(null);
        setFormOpen(true);
    };

    const openEditForm = (schedule) => {
        setEditingSchedule(schedule);
        setCreationDefaults(null);
        setError(null);
        setFormOpen(true);
    };

    const handleScheduleSaved = async () => {
        setFormOpen(false);
        setEditingSchedule(null);
        setCreationDefaults(null);
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
    const earliestStart = schedules.reduce(
        (earliest, schedule) => Math.min(earliest, minutesSinceMidnight(schedule.starts_at)),
        7 * 60,
    );
    const latestEnd = schedules.reduce(
        (latest, schedule) => Math.max(latest, minutesSinceMidnight(schedule.ends_at)),
        21 * 60,
    );
    const startHour = Math.max(0, Math.min(7, Math.floor(earliestStart / 60)));
    const endHour = Math.min(24, Math.max(21, Math.ceil(latestEnd / 60)));
    const gridHours = Array.from({ length: endHour - startHour }, (_, index) => startHour + index);
    const gridHeight = gridHours.length * 56;
    const gridMinutes = gridHours.length * 60;
    const positionedSchedules = days.map((_, dayIndex) => layoutDaySchedules(
        schedules.filter((schedule) => schedule.day_of_week === dayIndex + 1),
    ));
    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.schedules.loadError}</p>;
    }

    const toolbar = (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
            {canManage && yearIsWritable && (
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
            {!loading && schedules.length === 0 && (
                <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-[#9bb2a8]">
                    {t.schedules.empty}
                </p>
            )}
            <div className="flex flex-col gap-2">
                <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <h2 className="text-[14px] font-semibold text-on-surface dark:text-[#ecf3ef]">
                        {t.schedules.weeklyGrid}
                    </h2>
                    <p className="text-[11px] leading-4 text-on-surface-variant dark:text-[#9bb2a8]">
                        {t.schedules.gridHint}
                    </p>
                </div>
                <div aria-busy={loading} className="relative overflow-x-auto rounded-lg border border-outline-variant bg-surface-container-lowest dark:border-[#384d43] dark:bg-[#182620]">
                    {loading && (
                        <div role="status" className="absolute inset-0 z-20 flex min-h-40 items-center justify-center gap-3 bg-surface-container-lowest/90 text-on-surface-variant dark:bg-[#182620]/90 dark:text-[#9bb2a8]">
                            <Icon name="progress_activity" className="animate-spin text-[32px] text-primary" />
                            <span className="text-[13px]">{t.modal.loading}</span>
                        </div>
                    )}
                    <div
                        role="region"
                        aria-label={t.schedules.weeklyGrid}
                        className="grid min-w-[980px]"
                        style={{ gridTemplateColumns: '4.25rem repeat(7, minmax(8rem, 1fr))' }}
                    >
                        <div className="flex min-h-11 items-center justify-center border-b border-outline-variant px-1 text-[10px] font-semibold text-on-surface-variant dark:border-[#384d43] dark:text-[#9bb2a8]">
                            {t.schedules.timeline}
                        </div>
                        {days.map((day) => (
                            <div
                                key={day}
                                className="flex min-h-11 items-center justify-center border-b border-s border-outline-variant px-2 text-[11px] font-semibold text-on-surface dark:border-[#384d43] dark:text-[#ecf3ef]"
                            >
                                {day}
                            </div>
                        ))}
                        <div className="relative border-e border-outline-variant dark:border-[#384d43]" style={{ height: gridHeight }}>
                            {gridHours.map((hour) => (
                                <span
                                    key={hour}
                                    className="absolute inset-x-1 -translate-y-1/2 text-center font-mono text-[9px] text-outline dark:text-[#9bb2a8]"
                                    style={{ top: `${((hour - startHour) * 60 + 28) / gridMinutes * 100}%` }}
                                >
                                    {formatHour(hour)}
                                </span>
                            ))}
                        </div>
                        {days.map((day, dayIndex) => (
                            <div
                                key={day}
                                role="group"
                                aria-label={day}
                                className="relative border-s border-outline-variant dark:border-[#384d43]"
                                style={{ height: gridHeight }}
                            >
                                {canManage && yearIsWritable && gridHours.filter((hour) => hour < 23).map((hour) => (
                                    <button
                                        key={hour}
                                        type="button"
                                        aria-label={t.schedules.addSlotAt(day, formatHour(hour))}
                                        onClick={() => openCreateForm(dayIndex + 1, formatHour(hour))}
                                        className="absolute inset-x-0 z-0 border-t border-outline-variant/70 bg-transparent text-start transition-colors hover:bg-primary-fixed/20 focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-primary dark:border-[#384d43] dark:hover:bg-primary-container/30"
                                        style={{
                                            top: `${((hour - startHour) * 60 / gridMinutes) * 100}%`,
                                            height: `${(60 / gridMinutes) * 100}%`,
                                        }}
                                    >
                                        <span className="sr-only">
                                            {t.schedules.addSlotAt(day, formatHour(hour))}
                                        </span>
                                    </button>
                                ))}
                                {gridHours.map((hour) => (
                                    <div
                                        key={`line-${hour}`}
                                        aria-hidden="true"
                                        className="pointer-events-none absolute inset-x-0 z-[1] border-t border-outline-variant/70 dark:border-[#384d43]"
                                        style={{ top: `${((hour - startHour) * 60 / gridMinutes) * 100}%` }}
                                    />
                                ))}
                                {positionedSchedules[dayIndex].map(({ schedule, lane, laneCount }) => {
                                    const start = minutesSinceMidnight(schedule.starts_at);
                                    const end = minutesSinceMidnight(schedule.ends_at);
                                    const top = ((start - startHour * 60) / gridMinutes) * 100;
                                    const height = ((end - start) / gridMinutes) * 100;
                                    const slotLabel = t.schedules.slotDescription(
                                        day,
                                        schedule.label,
                                        schedule.starts_at,
                                        schedule.ends_at,
                                        schedule.room_name,
                                        schedule.teacher_name,
                                    );
                                    const slotStyle = {
                                        top: `${top}%`,
                                        height: `${height}%`,
                                        insetInlineStart: `calc(${lane / laneCount * 100}% + 2px)`,
                                        width: `calc(${100 / laneCount}% - 4px)`,
                                    };
                                    const slotContent = (
                                        <>
                                            <span className="block truncate text-[10px] font-bold leading-4">{schedule.label}</span>
                                            <span className="block truncate text-[9px] leading-3">{schedule.starts_at}–{schedule.ends_at}</span>
                                            <span className="block truncate text-[9px] leading-3">{schedule.room_name}</span>
                                            <span className="block truncate text-[9px] leading-3">{schedule.teacher_name}</span>
                                        </>
                                    );

                                    return canManage && yearIsWritable ? (
                                        <button
                                            key={schedule.id}
                                            type="button"
                                            aria-label={slotLabel}
                                            onClick={() => openEditForm(schedule)}
                                            className="absolute z-10 overflow-hidden rounded-md border border-primary/30 bg-primary-fixed px-1.5 py-1 text-start text-on-primary-fixed-variant shadow-sm transition-colors hover:bg-primary-fixed/80 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary dark:border-primary-fixed-dim/40 dark:bg-primary-container dark:text-on-primary-container"
                                            style={slotStyle}
                                        >
                                            {slotContent}
                                        </button>
                                    ) : (
                                        <div
                                            key={schedule.id}
                                            role="group"
                                            aria-label={slotLabel}
                                            tabIndex={0}
                                            className="absolute z-10 overflow-hidden rounded-md border border-primary/30 bg-primary-fixed px-1.5 py-1 text-start text-on-primary-fixed-variant shadow-sm dark:border-primary-fixed-dim/40 dark:bg-primary-container dark:text-on-primary-container"
                                            style={slotStyle}
                                        >
                                            {slotContent}
                                        </div>
                                    );
                                })}
                            </div>
                        ))}
                    </div>
                </div>
            </div>
            {canManage && (
                <ScheduleFormModal
                    open={formOpen}
                    schedule={editingSchedule}
                    yearId={yearId}
                    rooms={rooms}
                    teachers={teachers}
                    schedules={schedules}
                    defaultValues={creationDefaults}
                    onClose={() => {
                        setFormOpen(false);
                        setEditingSchedule(null);
                        setCreationDefaults(null);
                        setError(null);
                    }}
                    onSaved={handleScheduleSaved}
                />
            )}
        </section>
    );
}
