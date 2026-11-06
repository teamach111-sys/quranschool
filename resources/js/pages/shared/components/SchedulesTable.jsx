import React, { useCallback, useEffect, useState } from 'react';
import Icon from '../../../components/Icon';
import SelectInput from '../../../components/SelectInput';
import { useToast } from '../../../components/ToastProvider';
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

const scheduleColorClasses = [
    'border-blue-300 bg-blue-100 text-blue-950 hover:bg-blue-200 dark:border-blue-700 dark:bg-blue-950/60 dark:text-blue-100',
    'border-amber-300 bg-amber-100 text-amber-950 hover:bg-amber-200 dark:border-amber-700 dark:bg-amber-950/60 dark:text-amber-100',
    'border-rose-300 bg-rose-100 text-rose-950 hover:bg-rose-200 dark:border-rose-700 dark:bg-rose-950/60 dark:text-rose-100',
    'border-violet-300 bg-violet-100 text-violet-950 hover:bg-violet-200 dark:border-violet-700 dark:bg-violet-950/60 dark:text-violet-100',
    'border-cyan-300 bg-cyan-100 text-cyan-950 hover:bg-cyan-200 dark:border-cyan-700 dark:bg-cyan-950/60 dark:text-cyan-100',
    'border-orange-300 bg-orange-100 text-orange-950 hover:bg-orange-200 dark:border-orange-700 dark:bg-orange-950/60 dark:text-orange-100',
];

function scheduleColorClass(roomId) {
    const colorIndex = Math.abs(Number(roomId) || 0) % scheduleColorClasses.length;
    return scheduleColorClasses[colorIndex];
}

function layoutDaySchedules(daySchedules) {
    const sortedSchedules = [...daySchedules]
        .sort((left, right) => left.starts_at.localeCompare(right.starts_at) || left.ends_at.localeCompare(right.ends_at));
    const clusters = [];

    sortedSchedules.forEach((schedule) => {
        const start = minutesSinceMidnight(schedule.starts_at);
        const currentCluster = clusters.at(-1);

        if (!currentCluster || start >= currentCluster.end) {
            clusters.push({
                end: minutesSinceMidnight(schedule.ends_at),
                schedules: [schedule],
            });
            return;
        }

        currentCluster.end = Math.max(currentCluster.end, minutesSinceMidnight(schedule.ends_at));
        currentCluster.schedules.push(schedule);
    });

    return clusters.flatMap(({ schedules }) => {
        const laneEnds = [];
        const positioned = schedules.map((schedule) => {
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

        return positioned.map((slot) => ({ ...slot, laneCount: laneEnds.length }));
    });
}

export default function SchedulesTable() {
    const { isRTL, lang, t } = useLanguage();
    const toast = useToast();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [schedules, setSchedules] = useState([]);
    const [selectedRoomId, setSelectedRoomId] = useState('');
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
    const [exportingFormat, setExportingFormat] = useState(null);
    const selectedRoom = rooms.find((room) => String(room.id) === selectedRoomId);

    useEffect(() => {
        const syncYear = (event) => {
            const nextYearId = event?.detail?.id ?? getSelectedAcademicYearId();
            setYearId(nextYearId ? Number(nextYearId) : null);
            setSelectedRoomId('');
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

    const openCreateForm = (dayOfWeek = 1, startsAt = '09:00', roomId = selectedRoomId) => {
        setEditingSchedule(null);
        const [hour] = startsAt.split(':').map(Number);
        const endsAt = `${String(Math.min(hour + 1, 23)).padStart(2, '0')}:00`;
        setCreationDefaults({
            room_id: roomId,
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

    const exportSchedules = async (format, forStudents = false) => {
        const exportKey = forStudents ? 'student-pdf' : format;
        setExportingFormat(exportKey);
        try {
            const query = new URLSearchParams({
                lang,
                format,
            });
            if (yearId) query.set('year_id', String(yearId));
            if (selectedRoomId) query.set('room_id', selectedRoomId);
            if (forStudents) query.set('audience', 'students');

            const contentTypes = {
                csv: 'text/csv',
                pdf: 'application/pdf',
                xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            };
            const response = await apiFetch(`/schedules/export?${query}`, {
                headers: { Accept: contentTypes[format] },
            });
            if (response.status === 422) {
                toast.error(t.schedules.exportEmpty);
                return;
            }
            if (!response.ok || !response.headers.get('Content-Type')?.toLowerCase().includes(contentTypes[format])) {
                throw new Error('Schedule export failed');
            }

            const exportCount = Number(response.headers.get('X-Export-Record-Count'));
            if (!Number.isInteger(exportCount) || exportCount < 1) {
                throw new Error('Schedule export count was invalid');
            }

            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = url;
            const filenameBase = lang === 'ar' ? 'الجدول-الأسبوعي' : 'emploi-du-temps';
            link.download = forStudents
                ? (lang === 'ar' ? 'جدول-الطلاب.pdf' : 'emploi-du-temps-eleves.pdf')
                : `${filenameBase}.${format}`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast.success(t.schedules.exported(format, exportCount, selectedRoom?.name));
        } catch {
            toast.error(t.schedules.exportError);
        } finally {
            setExportingFormat(null);
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
    const visibleSchedules = selectedRoomId
        ? schedules.filter((schedule) => String(schedule.room_id) === selectedRoomId)
        : schedules;
    const earliestStart = visibleSchedules.reduce(
        (earliest, schedule) => Math.min(earliest, minutesSinceMidnight(schedule.starts_at)),
        7 * 60,
    );
    const latestEnd = visibleSchedules.reduce(
        (latest, schedule) => Math.max(latest, minutesSinceMidnight(schedule.ends_at)),
        21 * 60,
    );
    const startHour = Math.max(0, Math.min(7, Math.floor(earliestStart / 60)));
    const endHour = Math.min(24, Math.max(22, Math.ceil(latestEnd / 60)));
    const gridHours = Array.from({ length: endHour - startHour }, (_, index) => startHour + index);
    const gridHeight = gridHours.length * 44;
    const gridMinutes = gridHours.length * 60;
    const positionedSchedules = days.map((_, dayIndex) => layoutDaySchedules(
        visibleSchedules.filter((schedule) => schedule.day_of_week === dayIndex + 1),
    ));
    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.schedules.loadError}</p>;
    }

    const toolbar = (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-end gap-2">
                <div className="w-full sm:w-auto sm:min-w-52">
                    <SelectInput
                        value={exportingFormat ?? ''}
                        aria-label={t.exportSelect}
                        onChange={(event) => {
                            if (event.target.value === 'student-pdf') {
                                exportSchedules('pdf', true);
                            } else if (event.target.value) {
                                exportSchedules(event.target.value);
                            }
                        }}
                        disabled={exportingFormat !== null || loading || visibleSchedules.length === 0}
                        className="w-full"
                    >
                        <option value="">{t.exportSelect}</option>
                        {['csv', 'pdf', 'xlsx'].map((format) => (
                            <option key={format} value={format}>
                                {exportingFormat === format ? t.schedules.exporting[format] : t.schedules.exportFormats[format]}
                            </option>
                        ))}
                        {selectedRoom && (
                            <option value="student-pdf">
                                {exportingFormat === 'student-pdf' ? t.schedules.exportingStudentPdf : t.schedules.exportStudentPdf}
                            </option>
                        )}
                    </SelectInput>
                </div>
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
            {canManage && rooms.length > 0 && (
                <div className="flex w-full justify-end">
                    <div className="w-full max-w-sm">
                        <SelectInput
                            name="room_id"
                            aria-label={t.schedules.groupFilter}
                            value={selectedRoomId}
                            onChange={(event) => setSelectedRoomId(event.target.value)}
                            className="w-full"
                        >
                            <option value="">{t.schedules.allGroups}</option>
                            {rooms.map((room) => (
                                <option key={room.id} value={room.id}>{room.name}</option>
                            ))}
                        </SelectInput>
                    </div>
                </div>
            )}
        </div>
    );

    return (
        <section className="flex w-full flex-col gap-3" dir={isRTL ? 'rtl' : 'ltr'}>
            {error && !formOpen && (
                <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{error}</p>
            )}
            {!loading && visibleSchedules.length === 0 && (
                <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-[#9bb2a8]">
                    {selectedRoom ? t.schedules.emptyGroup(selectedRoom.name) : t.schedules.empty}
                </p>
            )}
            <div className="flex flex-col gap-2">
                <div className="rounded-xl border border-outline-variant bg-surface-container-lowest p-3 dark:border-[#384d43] dark:bg-[#22302a] sm:p-4">
                    {toolbar}
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
                        aria-label={t.schedules.listLabel}
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
                                    style={{ top: `${((hour - startHour) * 60 + 22) / gridMinutes * 100}%` }}
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
                                        onClick={() => openCreateForm(
                                            dayIndex + 1,
                                            formatHour(hour),
                                            hasScheduleAtHour ? '' : selectedRoomId,
                                        )}
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
                                {canManage && yearIsWritable && gridHours.filter((hour) => hour < 23).map((hour) => {
                                    const hasScheduleAtHour = visibleSchedules.some((schedule) => (
                                        schedule.day_of_week === dayIndex + 1
                                        && minutesSinceMidnight(schedule.starts_at) <= hour * 60
                                        && minutesSinceMidnight(schedule.ends_at) > hour * 60
                                    ));

                                    return (
                                        <button
                                            key={`add-${hour}`}
                                            type="button"
                                            aria-label={hasScheduleAtHour
                                                ? t.schedules.addGroupAt(day, formatHour(hour))
                                                : t.schedules.addSlotAt(day, formatHour(hour))}
                                            title={hasScheduleAtHour
                                                ? t.schedules.addGroupAt(day, formatHour(hour))
                                                : t.schedules.addSlotAt(day, formatHour(hour))}
                                            onClick={() => openCreateForm(dayIndex + 1, formatHour(hour))}
                                            className="absolute z-20 inline-flex size-[18px] items-center justify-center rounded-full border border-outline-variant bg-surface-container-lowest text-on-surface-variant shadow-sm transition-colors hover:border-primary hover:bg-primary-fixed hover:text-on-primary-fixed-variant focus-visible:outline-2 focus-visible:outline-primary dark:border-[#52685e] dark:bg-[#22302a] dark:text-[#d3e2d9] dark:hover:bg-primary-container"
                                            style={{
                                                top: `${((hour - startHour) * 60 / gridMinutes) * 100}%`,
                                                insetInlineEnd: '2px',
                                            }}
                                        >
                                            <Icon name="add" className="text-[13px]" />
                                        </button>
                                    );
                                })}
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
                                    const duration = end - start;
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
                                    const slotDetails = `${schedule.room_name} · ${schedule.teacher_name}`;
                                    const slotStyle = {
                                        top: `${top}%`,
                                        height: `${height}%`,
                                        insetInlineStart: `calc(${lane / laneCount * 100}% + 2px)`,
                                        width: `calc(${100 / laneCount}% - 4px)`,
                                    };
                                    const slotContent = duration < 45 ? (
                                        <span className="block truncate text-[8px] font-bold leading-[10px]">
                                            {schedule.label} · {schedule.starts_at}–{schedule.ends_at}
                                        </span>
                                    ) : (
                                        <>
                                            <span className="block truncate text-[9px] font-bold leading-3">{schedule.label}</span>
                                            <span className="block truncate text-[8px] leading-[10px]">{schedule.starts_at}–{schedule.ends_at}</span>
                                            <span className="block truncate text-[8px] leading-[10px]">{slotDetails}</span>
                                        </>
                                    );
                                    const slotClassName = `absolute z-10 overflow-hidden rounded-md border px-1 ${duration < 45 ? 'py-0' : 'py-0.5'} shadow-sm ${scheduleColorClass(schedule.room_id)}`;

                                    return canManage && yearIsWritable ? (
                                        <button
                                            key={schedule.id}
                                            type="button"
                                            aria-label={slotLabel}
                                            title={slotLabel}
                                            onClick={() => openEditForm(schedule)}
                                            className={`${slotClassName} text-start transition-colors focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary`}
                                            style={slotStyle}
                                        >
                                            {slotContent}
                                        </button>
                                    ) : (
                                        <div
                                            key={schedule.id}
                                            role="group"
                                            aria-label={slotLabel}
                                            title={slotLabel}
                                            tabIndex={0}
                                            className={`${slotClassName} text-start`}
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
