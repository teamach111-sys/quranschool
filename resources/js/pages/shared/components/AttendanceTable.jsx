import React, { useCallback, useEffect, useState } from 'react';
import Icon from '../../../components/Icon';
import AttendanceRosterModal from './AttendanceRosterModal';
import AttendanceEntryModal from './AttendanceEntryModal';
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
        attendance_date: '',
        status: '',
    };
}

export default function AttendanceTable() {
    const { isRTL, t } = useLanguage();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [attendance, setAttendance] = useState([]);
    const [rooms, setRooms] = useState([]);
    const [teachers, setTeachers] = useState([]);
    const [students, setStudents] = useState([]);
    const [todayDate, setTodayDate] = useState('');
    const [yearIsCurrent, setYearIsCurrent] = useState(false);
    const [canCorrect, setCanCorrect] = useState(false);
    const [canMark, setCanMark] = useState(false);
    const [canFollowUp, setCanFollowUp] = useState(false);
    const [filters, setFilters] = useState(emptyFilters);
    const [rosterOpen, setRosterOpen] = useState(false);
    const [entry, setEntry] = useState(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);

    useEffect(() => {
        const syncYear = (event) => {
            const nextYearId = event?.detail?.id ?? getSelectedAcademicYearId();
            setYearId(nextYearId ? Number(nextYearId) : null);
            setFilters(emptyFilters());
            setEntry(null);
            setRosterOpen(false);
            setCanCorrect(false);
            setCanMark(false);
            setCanFollowUp(false);
        };

        window.addEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
        return () => window.removeEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
    }, []);

    const loadAttendance = useCallback(async (signal) => {
        setLoading(true);
        setLoadError(false);

        try {
            const query = new URLSearchParams();
            if (yearId) query.set('year_id', String(yearId));
            Object.entries(filters).forEach(([key, value]) => {
                if (value) query.set(key, value);
            });

            const response = await apiFetch(`/attendance/table?${query.toString()}`, { signal });
            if (!response.ok) throw new Error('Failed to load attendance');
            const data = await response.json();

            if (signal?.aborted) return;
            setYearId((currentYearId) => currentYearId ?? Number(data.year_id));
            setYearIsCurrent(Boolean(data.year_is_current));
            setAttendance(Array.isArray(data.attendance) ? data.attendance : []);
            setRooms(Array.isArray(data.rooms) ? data.rooms : []);
            setTeachers(Array.isArray(data.teachers) ? data.teachers : []);
            setStudents(Array.isArray(data.students) ? data.students : []);
            setTodayDate(data.today ?? '');
            setCanCorrect(Boolean(data.can_correct));
            setCanMark(Boolean(data.can_mark));
            setCanFollowUp(Boolean(data.can_follow_up));
        } catch (requestError) {
            if (requestError.name !== 'AbortError') setLoadError(true);
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, [filters, yearId]);

    useEffect(() => {
        const controller = new AbortController();
        loadAttendance(controller.signal);
        return () => controller.abort();
    }, [loadAttendance]);

    const handleFilterChange = (event) => {
        const { name, value } = event.target;
        setFilters((current) => ({ ...current, [name]: value }));
    };

    const openEntry = (record = null) => {
        const mode = canFollowUp ? 'follow_up' : canCorrect ? 'correct' : 'mark';
        setEntry({ mode, record });
    };

    const handleEntrySaved = async () => {
        setEntry(null);
        await loadAttendance();
    };

    const columns = [
        {
            key: 'attendance_date',
            label: t.attendance.columns.date,
            sortable: true,
            mobile: 'subtitle',
        },
        {
            key: 'student_name',
            label: t.attendance.columns.student,
            sortable: true,
            mobile: 'title',
        },
        {
            key: 'room_name',
            label: t.attendance.columns.room,
            sortable: true,
            mobile: 'detail',
            value: (record) => record.room_name ?? '—',
        },
        {
            key: 'teacher_name',
            label: t.attendance.columns.teacher,
            sortable: true,
            mobile: 'detail',
            value: (record) => record.teacher_name ?? '—',
        },
        {
            key: 'status',
            label: t.attendance.columns.status,
            sortable: true,
            mobile: 'badge',
            value: (record) => record.status,
            render: (status) => (
                <span className="inline-flex rounded-full bg-primary-fixed px-2.5 py-1 text-[11px] font-semibold text-primary dark:bg-[#2e5e4e] dark:text-[#d4f5e4]">
                    {t.attendance.statuses[status] ?? status}
                </span>
            ),
        },
        {
            key: 'note',
            label: t.attendance.columns.note,
            mobile: 'detail',
            value: (record) => record.note || '—',
        },
    ];
    const labels = {
        actions: t.attendance.table.actions,
        pagination: t.attendance.table.pagination,
        previous: t.attendance.table.previous,
        next: t.attendance.table.next,
        page: (page) => t.attendance.table.page(page),
        selectPage: t.attendance.table.selectPage,
        selectRow: (record) => t.attendance.table.selectRow(record.student_name),
        sortBy: (label) => t.attendance.table.sortBy(label),
        range: (first, last, total) => t.attendance.table.range(first, last, total),
    };
    const actions = canCorrect || canMark || canFollowUp ? [{
        key: 'edit',
        icon: canFollowUp ? 'note_alt' : 'edit',
        label: canFollowUp
            ? t.attendance.followUp
            : canMark
                ? t.attendance.editOwn
                : t.attendance.correct,
        hidden: (record) => !record.can_edit,
        onClick: openEntry,
    }] : [];
    const inputClass = 'min-h-10 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:border-[#384d43] dark:bg-[#182620] dark:text-[#ecf3ef]';
    const toolbar = (
        <div className="flex flex-col gap-3">
            {canMark && yearIsCurrent && (
                <div className="flex justify-end">
                    <button
                        type="button"
                        onClick={() => setRosterOpen(true)}
                        className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg bg-primary-container px-3.5 py-2 text-[12px] font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]"
                    >
                        <Icon name="add" className="text-[17px]" />
                        {t.attendance.mark}
                    </button>
                </div>
            )}
            <div className="flex flex-col gap-2 xl:flex-row xl:items-center">
                <div className="min-w-0 xl:flex-1">
                    <select name="room_id" value={filters.room_id} onChange={handleFilterChange} aria-label={t.attendance.filters.room} className={inputClass}>
                        <option value="">{t.attendance.filters.allRooms}</option>
                        {rooms.map((room) => <option key={room.id} value={room.id}>{room.name}</option>)}
                    </select>
                </div>
                <div className="min-w-0 xl:flex-1">
                    <select name="teacher_id" value={filters.teacher_id} onChange={handleFilterChange} aria-label={t.attendance.filters.teacher} className={inputClass}>
                        <option value="">{t.attendance.filters.allTeachers}</option>
                        {teachers.map((teacher) => <option key={teacher.id} value={teacher.id}>{teacher.name}</option>)}
                    </select>
                </div>
                <div className="min-w-0 xl:flex-1">
                    <select name="student_id" value={filters.student_id} onChange={handleFilterChange} aria-label={t.attendance.filters.student} className={inputClass}>
                        <option value="">{t.attendance.filters.allStudents}</option>
                        {students.map((student) => (
                            <option key={student.id} value={student.id}>
                                {student.name}{student.student_number ? ` (${student.student_number})` : ''}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="min-w-0 xl:flex-1">
                    <input
                        type="date"
                        name="attendance_date"
                        value={filters.attendance_date}
                        onChange={handleFilterChange}
                        aria-label={t.attendance.filters.date}
                        className={inputClass}
                    />
                </div>
                <div className="min-w-0 xl:flex-1">
                    <select name="status" value={filters.status} onChange={handleFilterChange} aria-label={t.attendance.filters.status} className={inputClass}>
                        <option value="">{t.attendance.filters.allStatuses}</option>
                        {Object.entries(t.attendance.statuses).map(([value, label]) => (
                            <option key={value} value={value}>{label}</option>
                        ))}
                    </select>
                </div>
            </div>
        </div>
    );

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.attendance.loadError}</p>;
    }

    if (loading) {
        return <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">{t.attendance.loading}</p>;
    }

    return (
        <section className="flex w-full flex-col gap-3" dir={isRTL ? 'rtl' : 'ltr'}>
            <div className="hidden md:block">
                <DesktopTable
                    ariaLabel={t.attendance.listLabel}
                    columns={columns}
                    emptyState={t.attendance.empty}
                    isRTL={isRTL}
                    labels={labels}
                    rows={attendance}
                    toolbar={toolbar}
                    actions={actions}
                />
            </div>
            <div className="md:hidden">
                <MobileTable
                    ariaLabel={t.attendance.listLabel}
                    columns={columns}
                    emptyState={t.attendance.empty}
                    isRTL={isRTL}
                    labels={labels}
                    rows={attendance}
                    toolbar={toolbar}
                    actions={actions}
                />
            </div>
            {rosterOpen && (
                <AttendanceRosterModal
                    open
                    yearId={yearId}
                    todayDate={todayDate}
                    onClose={() => setRosterOpen(false)}
                    onSaved={handleEntrySaved}
                />
            )}
            {entry && (
                <AttendanceEntryModal
                    open
                    mode={entry.mode}
                    record={entry.record}
                    selectedStudentId={entry.selectedStudentId}
                    yearId={yearId}
                    todayDate={todayDate}
                    students={students}
                    onClose={() => setEntry(null)}
                    onSaved={handleEntrySaved}
                />
            )}
        </section>
    );
}
