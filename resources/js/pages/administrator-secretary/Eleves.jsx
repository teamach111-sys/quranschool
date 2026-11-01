import React, { useEffect, useMemo, useState } from 'react';
import Icon from '../../components/Icon';
import { useLanguage } from '../../hooks/useLanguage';
import { apiFetch } from '../../lib/api';
import {
    ACADEMIC_YEAR_CHANGED_EVENT,
    getSelectedAcademicYearId,
} from '../../persisters/yearselector';
import DesktopTable from '../../tables/desktoptable';
import MobileTable from '../../tables/mobiletable';

export default function Eleves() {
    const { isRTL, t } = useLanguage();
    const [students, setStudents] = useState([]);
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('all');
    const [roomId, setRoomId] = useState('all');
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);

    useEffect(() => {
        const syncYear = (event) => {
            const nextYearId = event?.detail?.id ?? getSelectedAcademicYearId();
            setYearId(nextYearId ? Number(nextYearId) : null);
        };

        window.addEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
        return () => window.removeEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        const query = yearId ? `?year_id=${encodeURIComponent(yearId)}` : '';

        setLoading(true);
        setLoadError(false);

        apiFetch(`/students/table${query}`, { signal: controller.signal })
            .then((response) => {
                if (!response.ok) throw new Error('Failed to load students');
                return response.json();
            })
            .then((data) => {
                setStudents(Array.isArray(data.students) ? data.students : []);
            })
            .catch((error) => {
                if (error.name !== 'AbortError') setLoadError(true);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
    }, [yearId]);

    const rooms = useMemo(() => {
        const uniqueRooms = new Map();
        students.forEach((student) => {
            if (student.room_id && student.room_name) {
                uniqueRooms.set(String(student.room_id), student.room_name);
            }
        });
        return [...uniqueRooms.entries()].sort((a, b) => a[1].localeCompare(b[1]));
    }, [students]);

    const filteredStudents = useMemo(() => {
        const query = search.trim().toLocaleLowerCase();

        return students.filter((student) => {
            const matchesSearch = !query || [
                student.full_name,
                student.student_number,
                student.room_name,
            ].some((value) => String(value ?? '').toLocaleLowerCase().includes(query));
            const matchesStatus = status === 'all' || student.status === status;
            const matchesRoom = roomId === 'all' || String(student.room_id ?? '') === roomId;

            return matchesSearch && matchesStatus && matchesRoom;
        });
    }, [students, search, status, roomId]);

    const statusLabel = (value) => t.students.statuses[value] ?? value;
    const textAlign = isRTL ? 'text-right' : 'text-left';
    const controlClass = 'min-h-11 w-full rounded-md border border-outline-variant bg-surface-container-lowest px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:border-tertiary-container dark:bg-inverse-surface dark:text-inverse-on-surface sm:w-auto';
    const tableLabels = {
        actions: t.students.table.actions,
        pagination: t.students.table.pagination,
        previous: t.students.table.previous,
        next: t.students.table.next,
        page: t.students.table.page,
        selectPage: t.students.table.selectPage,
        selectRow: (student) => t.students.table.selectRow(student.full_name),
        sortBy: (label) => t.students.table.sortBy(label),
        range: (first, last, total) => t.students.table.range(first, last, total),
    };
    const columns = [
        {
            key: 'full_name',
            label: t.students.columns.name,
            sortable: true,
            mobile: 'title',
        },
        {
            key: 'student_number',
            label: t.students.columns.number,
            sortable: true,
            mobile: 'identifier',
            value: (student) => student.student_number || student.id,
            sortValue: (student) => student.student_number || '',
            render: (value) => value || '—',
        },
        {
            key: 'room_name',
            label: t.students.columns.room,
            sortable: true,
            mobile: 'detail',
            value: (student) => student.room_name || t.students.unassigned,
        },
        {
            key: 'guardian',
            label: t.students.columns.guardian,
            mobile: 'detail',
            value: (student) => student.guardian_name || student.guardian_phone || student.guardian_email || '—',
            sortValue: (student) => student.guardian_name || student.guardian_phone || student.guardian_email || '',
        },
        {
            key: 'status',
            label: t.students.columns.status,
            sortable: true,
            mobile: 'badge',
            render: (value) => {
                const badgeClass = value === 'active'
                    ? 'bg-primary-fixed/50 text-on-primary-fixed-variant dark:bg-[#1e4f40]/50 dark:text-[#baeed9]'
                    : value === 'paused'
                        ? 'bg-secondary-fixed/60 text-on-secondary-fixed-variant dark:bg-[#5f4100]/40 dark:text-[#ffdeaa]'
                        : 'bg-error-container text-on-error-container dark:bg-[#4a2626]/50 dark:text-[#ffdad6]';

                return (
                    <span className={`inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-semibold ${badgeClass}`}>
                        <span className="h-1.5 w-1.5 rounded-full bg-current" />
                        {statusLabel(value)}
                    </span>
                );
            },
        },
    ];
    const toolbar = (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <label className="relative min-w-0 flex-1 sm:max-w-sm">
                <Icon name="search" className={`pointer-events-none absolute top-1/2 -translate-y-1/2 text-[18px] text-outline dark:text-[#9fd1bd]/50 ${isRTL ? 'right-3' : 'left-3'}`} />
                <input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder={t.students.search}
                    aria-label={t.students.search}
                    className={`min-h-11 w-full rounded-lg border border-transparent bg-surface-container-low px-3 text-[13px] text-on-surface outline-none placeholder:text-outline focus:border-primary dark:bg-[#1a2520] dark:text-[#f0fcf3] dark:placeholder:text-[#9fd1bd]/40 dark:focus:border-[#9fd1bd] ${isRTL ? 'pr-10' : 'pl-10'}`}
                />
            </label>
            <div className="flex flex-col gap-2 sm:flex-row">
                <select
                    value={status}
                    onChange={(event) => setStatus(event.target.value)}
                    aria-label={t.students.statusFilter}
                    className={controlClass}
                >
                    <option value="all">{t.students.allStatuses}</option>
                    <option value="active">{statusLabel('active')}</option>
                    <option value="paused">{statusLabel('paused')}</option>
                    <option value="withdrawn">{statusLabel('withdrawn')}</option>
                </select>
                <select
                    value={roomId}
                    onChange={(event) => setRoomId(event.target.value)}
                    aria-label={t.students.roomFilter}
                    className={controlClass}
                >
                    <option value="all">{t.students.allRooms}</option>
                    {rooms.map(([id, name]) => <option key={id} value={id}>{name}</option>)}
                </select>
            </div>
        </div>
    );

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.students.loadError}</p>;
    }

    if (loading) {
        return <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">{t.students.loading}</p>;
    }

    return (
        <section className="flex w-full flex-col gap-3" dir={isRTL ? 'rtl' : 'ltr'}>
            <p className={`text-[12px] leading-4 text-on-surface-variant dark:text-tertiary-fixed-dim ${textAlign}`} aria-live="polite">
                {t.students.resultCount(filteredStudents.length)}
            </p>
            <div className="hidden md:block">
                <DesktopTable
                    ariaLabel={t.students.listLabel}
                    columns={columns}
                    emptyState={students.length === 0 ? t.students.empty : t.students.noResults}
                    isRTL={isRTL}
                    labels={tableLabels}
                    rows={filteredStudents}
                    selectable
                    toolbar={toolbar}
                />
            </div>
            <div className="md:hidden">
                <MobileTable
                    ariaLabel={t.students.listLabel}
                    columns={columns}
                    emptyState={students.length === 0 ? t.students.empty : t.students.noResults}
                    isRTL={isRTL}
                    labels={tableLabels}
                    rows={filteredStudents}
                    selectable
                    toolbar={toolbar}
                />
            </div>
        </section>
    );
}
