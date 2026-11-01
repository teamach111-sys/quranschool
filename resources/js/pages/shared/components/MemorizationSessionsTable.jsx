import React, { useCallback, useEffect, useState } from 'react';
import { useLanguage } from '../../../hooks/useLanguage';
import { apiFetch } from '../../../lib/api';
import {
    ACADEMIC_YEAR_CHANGED_EVENT,
    getSelectedAcademicYearId,
} from '../../../persisters/yearselector';
import DesktopTable from '../../../tables/desktoptable';
import MobileTable from '../../../tables/mobiletable';

function emptyFilters() {
    return {
        room_id: '',
        teacher_id: '',
        student_id: '',
        session_date: '',
    };
}

function surahRange(session, emptyValue) {
    if (
        session.surah_start === null
        || session.ayah_start === null
        || session.surah_end === null
        || session.ayah_end === null
    ) {
        return emptyValue;
    }

    return `${session.surah_start}:${session.ayah_start} – ${session.surah_end}:${session.ayah_end}`;
}

export default function MemorizationSessionsTable() {
    const { isRTL, t } = useLanguage();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [sessions, setSessions] = useState([]);
    const [rooms, setRooms] = useState([]);
    const [teachers, setTeachers] = useState([]);
    const [students, setStudents] = useState([]);
    const [canFilterTeacher, setCanFilterTeacher] = useState(false);
    const [filters, setFilters] = useState(emptyFilters);
    const [appliedFilters, setAppliedFilters] = useState(emptyFilters);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);

    useEffect(() => {
        const syncYear = (event) => {
            const nextYearId = event?.detail?.id ?? getSelectedAcademicYearId();
            setYearId(nextYearId ? Number(nextYearId) : null);
            setFilters(emptyFilters());
            setAppliedFilters(emptyFilters());
        };

        window.addEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
        return () => window.removeEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
    }, []);

    const loadSessions = useCallback(async (signal) => {
        setLoading(true);
        setLoadError(false);

        try {
            const query = new URLSearchParams();
            if (yearId) query.set('year_id', String(yearId));
            Object.entries(appliedFilters).forEach(([key, value]) => {
                if (value) query.set(key, value);
            });

            const response = await apiFetch(`/memorization-sessions/table?${query.toString()}`, { signal });
            if (!response.ok) throw new Error('Failed to load memorization sessions');
            const data = await response.json();

            if (signal?.aborted) return;
            setYearId((currentYearId) => currentYearId ?? Number(data.year_id));
            setSessions(Array.isArray(data.sessions) ? data.sessions : []);
            setRooms(Array.isArray(data.rooms) ? data.rooms : []);
            setTeachers(Array.isArray(data.teachers) ? data.teachers : []);
            setStudents(Array.isArray(data.students) ? data.students : []);
            setCanFilterTeacher(Boolean(data.can_filter_teacher));
        } catch (requestError) {
            if (requestError.name !== 'AbortError') setLoadError(true);
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, [appliedFilters, yearId]);

    useEffect(() => {
        const controller = new AbortController();
        loadSessions(controller.signal);
        return () => controller.abort();
    }, [loadSessions]);

    const handleFilterChange = (event) => {
        const { name, value } = event.target;
        setFilters((current) => ({ ...current, [name]: value }));
    };

    const handleFilterSubmit = (event) => {
        event.preventDefault();
        setAppliedFilters({ ...filters });
    };

    const clearFilters = () => {
        const clearedFilters = emptyFilters();
        setFilters(clearedFilters);
        setAppliedFilters(clearedFilters);
    };

    const columns = [
        {
            key: 'session_date',
            label: t.memorization.columns.date,
            sortable: true,
            mobile: 'subtitle',
        },
        {
            key: 'student_name',
            label: t.memorization.columns.student,
            sortable: true,
            mobile: 'title',
        },
        {
            key: 'room_name',
            label: t.memorization.columns.room,
            sortable: true,
            mobile: 'detail',
            value: (session) => session.room_name ?? t.memorization.emptyValue,
        },
        {
            key: 'teacher_name',
            label: t.memorization.columns.teacher,
            sortable: true,
            mobile: 'detail',
            value: (session) => session.teacher_name ?? t.memorization.emptyValue,
        },
        {
            key: 'surah_range',
            label: t.memorization.columns.range,
            mobile: 'detail',
            value: (session) => surahRange(session, t.memorization.emptyValue),
        },
        {
            key: 'portion_label',
            label: t.memorization.columns.portion,
            sortable: true,
            mobile: 'detail',
        },
        {
            key: 'evaluation',
            label: t.memorization.columns.evaluation,
            sortable: true,
            mobile: 'badge',
            render: (evaluation) => (
                <span className="inline-flex rounded-full bg-primary-fixed px-2.5 py-1 text-[11px] font-semibold text-primary dark:bg-primary-container dark:text-on-primary">
                    {t.memorization.evaluations[evaluation] ?? evaluation}
                </span>
            ),
        },
        {
            key: 'notes',
            label: t.memorization.columns.notes,
            mobile: 'detail',
            value: (session) => session.notes || t.memorization.emptyValue,
        },
    ];
    const labels = {
        actions: t.memorization.table.actions,
        pagination: t.memorization.table.pagination,
        previous: t.memorization.table.previous,
        next: t.memorization.table.next,
        page: (page) => t.memorization.table.page(page),
        selectPage: t.memorization.table.selectPage,
        selectRow: (session) => t.memorization.table.selectRow(session.student_name),
        sortBy: (label) => t.memorization.table.sortBy(label),
        range: (first, last, total) => t.memorization.table.range(first, last, total),
    };
    const inputClass = 'min-h-10 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:border-outline-variant dark:bg-inverse-surface dark:text-inverse-on-surface';
    const labelClass = 'flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface';
    const toolbar = (
        <form onSubmit={handleFilterSubmit} className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <label className={labelClass}>
                {t.memorization.filters.room}
                <select name="room_id" value={filters.room_id} onChange={handleFilterChange} className={inputClass}>
                    <option value="">{t.memorization.filters.allRooms}</option>
                    {rooms.map((room) => <option key={room.id} value={room.id}>{room.name}</option>)}
                </select>
            </label>
            {canFilterTeacher && (
                <label className={labelClass}>
                    {t.memorization.filters.teacher}
                    <select name="teacher_id" value={filters.teacher_id} onChange={handleFilterChange} className={inputClass}>
                        <option value="">{t.memorization.filters.allTeachers}</option>
                        {teachers.map((teacher) => <option key={teacher.id} value={teacher.id}>{teacher.name}</option>)}
                    </select>
                </label>
            )}
            <label className={labelClass}>
                {t.memorization.filters.student}
                <select name="student_id" value={filters.student_id} onChange={handleFilterChange} className={inputClass}>
                    <option value="">{t.memorization.filters.allStudents}</option>
                    {students.map((student) => (
                        <option key={student.id} value={student.id}>
                            {student.name}{student.student_number ? ` (${student.student_number})` : ''}
                        </option>
                    ))}
                </select>
            </label>
            <label className={labelClass}>
                {t.memorization.filters.date}
                <input
                    type="date"
                    name="session_date"
                    value={filters.session_date}
                    onChange={handleFilterChange}
                    className={inputClass}
                />
            </label>
            <div className="flex flex-col gap-2 sm:flex-row">
                <button
                    type="submit"
                    className="min-h-10 flex-1 rounded-lg bg-primary-container px-3 py-2 text-[12px] font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-primary-container dark:text-on-primary"
                >
                    {t.memorization.filters.apply}
                </button>
                <button
                    type="button"
                    onClick={clearFilters}
                    className="min-h-10 flex-1 rounded-lg bg-surface-container px-3 py-2 text-[12px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high dark:bg-inverse-surface dark:text-inverse-on-surface dark:hover:bg-tertiary-container"
                >
                    {t.memorization.filters.clear}
                </button>
            </div>
        </form>
    );

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.memorization.loadError}</p>;
    }

    if (loading) {
        return <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">{t.memorization.loading}</p>;
    }

    return (
        <section className="flex w-full flex-col gap-3" dir={isRTL ? 'rtl' : 'ltr'}>
            <div className="hidden md:block">
                <DesktopTable
                    ariaLabel={t.memorization.listLabel}
                    columns={columns}
                    emptyState={t.memorization.empty}
                    isRTL={isRTL}
                    labels={labels}
                    rows={sessions}
                    toolbar={toolbar}
                />
            </div>
            <div className="md:hidden">
                <MobileTable
                    ariaLabel={t.memorization.listLabel}
                    columns={columns}
                    emptyState={t.memorization.empty}
                    isRTL={isRTL}
                    labels={labels}
                    rows={sessions}
                    toolbar={toolbar}
                />
            </div>
        </section>
    );
}
