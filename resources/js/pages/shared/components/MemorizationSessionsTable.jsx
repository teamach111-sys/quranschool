import React, { useCallback, useEffect, useState } from 'react';
import SelectInput from '../../../components/SelectInput';
import Icon from '../../../components/Icon';
import DateInput from '../../../components/DateInput';
import MemorizationSessionFormModal from './MemorizationSessionFormModal';
import { useLanguage } from '../../../hooks/useLanguage';
import { useToast } from '../../../components/ToastProvider';
import { apiFetch } from '../../../lib/api';
import { formatPortionRange } from '../../../lib/quranProgress';
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

export default function MemorizationSessionsTable() {
    const { isRTL, lang, t } = useLanguage();
    const toast = useToast();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [sessions, setSessions] = useState([]);
    const [rooms, setRooms] = useState([]);
    const [teachers, setTeachers] = useState([]);
    const [students, setStudents] = useState([]);
    const [formOptions, setFormOptions] = useState({ rooms: [], students: [], teachers: [] });
    const [canFilterTeacher, setCanFilterTeacher] = useState(false);
    const [canManage, setCanManage] = useState(false);
    const [canCreate, setCanCreate] = useState(false);
    const [yearStart, setYearStart] = useState('');
    const [yearEnd, setYearEnd] = useState('');
    const [todayDate, setTodayDate] = useState('');
    const [filters, setFilters] = useState(emptyFilters);
    const [entry, setEntry] = useState(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [exportingFormat, setExportingFormat] = useState(null);
    const [sortState, setSortState] = useState({ key: '', direction: 'asc' });

    useEffect(() => {
        const syncYear = (event) => {
            const nextYearId = event?.detail?.id ?? getSelectedAcademicYearId();
            setYearId(nextYearId ? Number(nextYearId) : null);
            setFilters(emptyFilters());
            setEntry(null);
            setSortState({ key: '', direction: 'asc' });
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
            Object.entries(filters).forEach(([key, value]) => {
                if (value) query.set(key, value);
            });
            if (sortState.key) {
                query.set('sort_key', sortState.key);
                query.set('sort_direction', sortState.direction);
            }

            const response = await apiFetch(`/memorization-sessions/table?${query.toString()}`, { signal });
            if (!response.ok) throw new Error('Failed to load memorization sessions');
            const data = await response.json();

            if (signal?.aborted) return;
            setYearId((currentYearId) => currentYearId ?? Number(data.year_id));
            setSessions(Array.isArray(data.sessions) ? data.sessions : []);
            setRooms(Array.isArray(data.rooms) ? data.rooms : []);
            setTeachers(Array.isArray(data.teachers) ? data.teachers : []);
            setStudents(Array.isArray(data.students) ? data.students : []);
            setFormOptions(data.form_options ?? { rooms: [], students: [], teachers: [] });
            setCanFilterTeacher(Boolean(data.can_filter_teacher));
            setCanManage(Boolean(data.can_manage));
            setCanCreate(Boolean(data.can_create));
            setYearStart(data.year_start ?? '');
            setYearEnd(data.year_end ?? '');
            setTodayDate(data.today ?? '');
        } catch (requestError) {
            if (requestError.name !== 'AbortError') setLoadError(true);
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, [filters, sortState, yearId]);

    useEffect(() => {
        const controller = new AbortController();
        loadSessions(controller.signal);
        return () => controller.abort();
    }, [loadSessions]);

    const handleFilterChange = (event) => {
        const { name, value } = event.target;
        setFilters((current) => ({ ...current, [name]: value }));
    };

    const exportSessions = async (format) => {
        setExportingFormat(format);
        try {
            const query = new URLSearchParams({
                ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')),
                lang,
                format,
            });
            if (yearId) query.set('year_id', String(yearId));
            if (sortState.key) {
                query.set('sort_key', sortState.key);
                query.set('sort_direction', sortState.direction);
            }

            const response = await apiFetch(`/memorization-sessions/export?${query}`, {
                headers: {
                    Accept: {
                        csv: 'text/csv',
                        pdf: 'application/pdf',
                        xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    }[format],
                },
            });
            if (response.status === 422) {
                toast.error(t.memorization.exportEmpty);
                return;
            }
            const expectedContentType = {
                csv: 'text/csv',
                pdf: 'application/pdf',
                xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            }[format];
            if (!response.ok || !response.headers.get('Content-Type')?.toLowerCase().includes(expectedContentType)) {
                throw new Error('Memorization sessions export failed');
            }

            const exportCount = Number(response.headers.get('X-Export-Record-Count'));
            if (!Number.isInteger(exportCount) || exportCount < 1) {
                throw new Error('Memorization sessions export count was invalid');
            }

            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = url;
            link.download = `memorization-sessions.${format}`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast.success(t.memorization.exported(format, exportCount));
        } catch {
            toast.error(t.memorization.exportError);
        } finally {
            setExportingFormat(null);
        }
    };

    const openEntry = (session = null) => {
        setEntry({ session });
    };

    const handleSessionSaved = async () => {
        setEntry(null);
        await loadSessions();
    };

    const defaultDate = todayDate && yearStart && yearEnd
        ? todayDate < yearStart
            ? yearStart
            : todayDate > yearEnd
                ? yearEnd
                : todayDate
        : '';

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
            key: 'portion_label',
            label: t.memorization.columns.portion,
            sortable: true,
            mobile: 'detail',
            value: (session) => formatPortionRange(session, lang)
                || session.portion_label
                || t.memorization.emptyValue,
        },
        {
            key: 'evaluation',
            label: t.memorization.columns.evaluation,
            sortable: true,
            mobile: 'badge',
            render: (evaluation) => (
                <span className="inline-flex rounded-full bg-primary-fixed px-2.5 py-1 text-[11px] font-semibold text-primary dark:bg-[#32302e] dark:text-on-primary">
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
    const actions = canCreate ? [{
        key: 'edit',
        icon: 'edit',
        label: t.memorization.edit,
        hidden: (session) => !session.can_edit,
        onClick: openEntry,
    }] : [];
    const inputClass = 'min-h-10 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:border-[#3a3835] dark:bg-inverse-surface dark:text-inverse-on-surface';
    const toolbar = (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap justify-end gap-2">
                <div className="w-full sm:w-auto sm:min-w-52">
                    <SelectInput
                        value={exportingFormat ?? ''}
                        aria-label={t.exportSelect}
                        onChange={(event) => {
                            if (event.target.value) exportSessions(event.target.value);
                        }}
                        disabled={exportingFormat !== null || loading || sessions.length === 0}
                        className={inputClass}
                    >
                        <option value="">{t.exportSelect}</option>
                        {['csv', 'pdf', 'xlsx'].map((format) => (
                            <option key={format} value={format}>
                                {exportingFormat === format ? t.memorization.exporting[format] : t.memorization.exportFormats[format]}
                            </option>
                        ))}
                    </SelectInput>
                </div>
                {canCreate && (
                    <button
                        type="button"
                        onClick={() => openEntry()}
                        className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg bg-primary-container px-3.5 py-2 text-[12px] font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-[#2f7d4f] dark:text-white"
                    >
                        <Icon name="add" className="text-[17px]" />
                        {t.memorization.create}
                    </button>
                )}
            </div>
            <div className="flex flex-col gap-2 xl:flex-row xl:items-center">
                <div className="min-w-0 xl:flex-1">
                    <SelectInput name="room_id" value={filters.room_id} onChange={handleFilterChange} aria-label={t.memorization.filters.room} className={inputClass}>
                        <option value="">{t.memorization.filters.allRooms}</option>
                        {rooms.map((room) => <option key={room.id} value={room.id}>{room.name}</option>)}
                    </SelectInput>
                </div>
                {canFilterTeacher && (
                    <div className="min-w-0 xl:flex-1">
                        <SelectInput name="teacher_id" value={filters.teacher_id} onChange={handleFilterChange} aria-label={t.memorization.filters.teacher} className={inputClass}>
                            <option value="">{t.memorization.filters.allTeachers}</option>
                            {teachers.map((teacher) => <option key={teacher.id} value={teacher.id}>{teacher.name}</option>)}
                        </SelectInput>
                    </div>
                )}
                <div className="min-w-0 xl:flex-1">
                    <SelectInput name="student_id" value={filters.student_id} onChange={handleFilterChange} aria-label={t.memorization.filters.student} className={inputClass}>
                        <option value="">{t.memorization.filters.allStudents}</option>
                        {students.map((student) => (
                            <option key={student.id} value={student.id}>
                                {student.name}{student.student_number ? ` (${student.student_number})` : ''}
                            </option>
                        ))}
                    </SelectInput>
                </div>
                <div className="min-w-0 xl:flex-1">
                    <DateInput
                        name="session_date"
                        value={filters.session_date}
                        onChange={handleFilterChange}
                        aria-label={t.memorization.filters.date}
                        className={inputClass}
                    />
                </div>
            </div>
        </div>
    );

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-[#e59a92]">{t.memorization.loadError}</p>;
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
                    actions={actions}
                    loading={loading}
                    onSortChange={setSortState}
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
                    actions={actions}
                    loading={loading}
                />
            </div>
            {entry && (
                <MemorizationSessionFormModal
                    open
                    session={entry.session}
                    yearId={yearId}
                    defaultDate={defaultDate}
                    canManage={canManage}
                    rooms={formOptions.rooms}
                    students={formOptions.students}
                    teachers={formOptions.teachers}
                    onClose={() => setEntry(null)}
                    onSaved={handleSessionSaved}
                />
            )}
        </section>
    );
}
