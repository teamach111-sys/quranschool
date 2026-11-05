import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import SelectInput from '../../components/SelectInput';
import Icon from '../../components/Icon';
import SearchInput from '../../components/SearchInput';
import { useLanguage } from '../../hooks/useLanguage';
import { useToast } from '../../components/ToastProvider';
import { apiFetch } from '../../lib/api';
import ConfirmDeleteModal from '../../modals/confirmdelete';
import {
    ACADEMIC_YEAR_CHANGED_EVENT,
    getSelectedAcademicYearId,
} from '../../persisters/yearselector';
import StudentFormModal from '../shared/components/StudentFormModal';
import DesktopTable from '../../tables/desktoptable';
import MobileTable from '../../tables/mobiletable';

export default function Eleves({ routeParams = {} }) {
    const { isRTL, lang, t } = useLanguage();
    const toast = useToast();
    const [students, setStudents] = useState([]);
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('all');
    const [roomId, setRoomId] = useState('all');
    const [sortState, setSortState] = useState({ key: '', direction: 'asc' });
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [canManage, setCanManage] = useState(false);
    const [yearIsWritable, setYearIsWritable] = useState(false);
    const [formOpen, setFormOpen] = useState(false);
    const [editingStudent, setEditingStudent] = useState(null);
    const openedAlertStudentId = useRef(null);
    const [formReadOnly, setFormReadOnly] = useState(false);
    const [archiveTarget, setArchiveTarget] = useState(null);
    const [archiveError, setArchiveError] = useState(null);
    const [archiving, setArchiving] = useState(false);
    const [exportingFormat, setExportingFormat] = useState('');

    useEffect(() => {
        const syncYear = (event) => {
            const nextYearId = event?.detail?.id ?? getSelectedAcademicYearId();
            setYearId(nextYearId ? Number(nextYearId) : null);
            setYearIsWritable(false);
            setFormOpen(false);
            setEditingStudent(null);
            setFormReadOnly(false);
            setArchiveTarget(null);
            setArchiveError(null);
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
                setCanManage(Boolean(data.can_manage));
                setYearIsWritable(Boolean(data.year_is_writable));
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
    const openForm = useCallback((student = null, readOnly = false) => {
        setEditingStudent(student);
        setFormReadOnly(readOnly || !yearIsWritable);
        setFormOpen(true);
    }, [yearIsWritable]);
    useEffect(() => {
        const alertStudentId = String(routeParams.student_id ?? '');
        if (!alertStudentId || loading || openedAlertStudentId.current === alertStudentId) return;

        const student = students.find((item) => String(item.id) === alertStudentId);
        if (!student) return;

        openedAlertStudentId.current = alertStudentId;
        openForm(student, true);
    }, [loading, openForm, routeParams.student_id, students]);
    const closeForm = useCallback(() => {
        setFormOpen(false);
        setEditingStudent(null);
        setFormReadOnly(false);
    }, []);
    const updateStudentInList = useCallback((student) => {
        setStudents((current) => {
            const exists = current.some((item) => item.id === student.id);
            const next = exists
                ? current.map((item) => item.id === student.id ? student : item)
                : [...current, student];
            return next.sort((a, b) => a.full_name.localeCompare(b.full_name));
        });
    }, []);
    const handleStudentSaved = useCallback((student) => {
        updateStudentInList(student);
        closeForm();
    }, [closeForm, updateStudentInList]);
    const exportStudents = async (format) => {
        if (exportingFormat) return;
        setExportingFormat(format);
        try {
            const query = new URLSearchParams({
                search,
                status,
                lang,
                sort_key: sortState.key || 'full_name',
                sort_direction: sortState.direction,
                format,
            });
            if (yearId) query.set('year_id', String(yearId));
            if (roomId !== 'all') query.set('room_id', roomId);

            const response = await apiFetch(`/students/export?${query}`, {
                headers: {
                    Accept: {
                        csv: 'text/csv',
                        pdf: 'application/pdf',
                        xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    }[format],
                },
            });
            const expectedType = {
                csv: 'text/csv',
                pdf: 'application/pdf',
                xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            }[format];
            if (!response.ok || !response.headers.get('Content-Type')?.toLowerCase().includes(expectedType)) {
                throw new Error('Student export failed');
            }
            const exportCount = Number(response.headers.get('X-Export-Record-Count'));
            if (!Number.isInteger(exportCount) || exportCount < 0) {
                throw new Error('Student export count was invalid');
            }

            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `students.${format}`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast.success(t.students.exported(format, exportCount));
        } catch {
            toast.error(t.students.exportError);
        } finally {
            setExportingFormat('');
        }
    };
    const openArchiveConfirmation = (student, nextStatus) => {
        setArchiveTarget({ student, status: nextStatus });
        setArchiveError(null);
    };
    const closeArchiveConfirmation = () => {
        if (archiving) return;
        setArchiveTarget(null);
        setArchiveError(null);
    };
    const confirmArchive = async () => {
        if (!archiveTarget) return;
        setArchiving(true);
        setArchiveError(null);

        try {
            const response = await apiFetch(`/students/${archiveTarget.student.id}/status`, {
                method: 'PATCH',
                body: JSON.stringify({
                    year_id: yearId,
                    status: archiveTarget.status,
                }),
            });
            const data = await response.json();

            if (!response.ok) {
                setArchiveError(data?.errors?.year_id
                    ? t.students.form.closedYearError
                    : t.students.archive.error);
                return;
            }

            updateStudentInList(data.student);
            setArchiveTarget(null);
        } catch {
            setArchiveError(t.students.archive.error);
        } finally {
            setArchiving(false);
        }
    };
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
    const rowActions = [
        {
            key: 'view',
            icon: 'visibility',
            label: t.students.form.view,
            onClick: (student) => openForm(student, true),
        },
        ...(canManage && yearIsWritable ? [{
            key: 'edit',
            icon: 'edit',
            label: t.students.form.editTitle,
            onClick: (student) => openForm(student),
        }] : []),
        ...(canManage && yearIsWritable ? [
            {
                key: 'pause',
                icon: 'pause_circle',
                label: t.students.archive.pause,
                hidden: (student) => student.status !== 'active',
                onClick: (student) => openArchiveConfirmation(student, 'paused'),
            },
            {
                key: 'withdraw',
                icon: 'person_remove',
                label: t.students.archive.withdraw,
                hidden: (student) => student.status === 'withdrawn',
                onClick: (student) => openArchiveConfirmation(student, 'withdrawn'),
            },
        ] : []),
    ];
    const toolbar = (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-end gap-2">
                {['csv', 'pdf', 'xlsx'].map((format) => {
                    const labels = {
                        csv: [t.students.exportCsv, t.students.exportingCsv, 'download'],
                        pdf: [t.students.exportPdf, t.students.exportingPdf, 'picture_as_pdf'],
                        xlsx: [t.students.exportXlsx, t.students.exportingXlsx, 'table_view'],
                    }[format];
                    return (
                        <button
                            key={format}
                            type="button"
                            onClick={() => exportStudents(format)}
                            disabled={exportingFormat !== '' || loading || !yearId || filteredStudents.length === 0}
                            aria-busy={exportingFormat === format}
                            className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] font-semibold text-on-surface transition-colors hover:bg-surface-container disabled:cursor-not-allowed disabled:opacity-60 dark:border-[#384d43] dark:bg-[#182620] dark:text-[#ecf3ef] dark:hover:bg-[#22302a]"
                        >
                            <Icon
                                name={exportingFormat === format ? 'progress_activity' : labels[2]}
                                className={`text-[17px] ${exportingFormat === format ? 'animate-spin' : ''}`}
                            />
                            {exportingFormat === format ? labels[1] : labels[0]}
                        </button>
                    );
                })}
                {canManage && yearIsWritable && (
                    <button
                        type="button"
                        onClick={() => openForm()}
                        className="inline-flex min-h-10 shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-primary-container px-3 py-2 text-[12px] font-semibold text-on-primary shadow-sm transition-colors hover:opacity-90 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]"
                    >
                        <Icon name="add" className="text-[17px]" />
                        {t.students.form.createTitle}
                    </button>
                )}
            </div>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <label className="relative min-w-0">
                    <SearchInput
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        isRTL={isRTL}
                        placeholder={t.students.search}
                        ariaLabel={t.students.search}
                        maxLength={120}
                    />
                </label>
                <div className="w-full min-w-0">
                    <SelectInput
                        value={status}
                        onChange={(event) => setStatus(event.target.value)}
                        aria-label={t.students.statusFilter}
                    >
                        <option value="all">{t.students.allStatuses}</option>
                        <option value="active">{statusLabel('active')}</option>
                        <option value="paused">{statusLabel('paused')}</option>
                        <option value="withdrawn">{statusLabel('withdrawn')}</option>
                    </SelectInput>
                </div>
                <div className="w-full min-w-0">
                    <SelectInput
                        value={roomId}
                        onChange={(event) => setRoomId(event.target.value)}
                        aria-label={t.students.roomFilter}
                    >
                        <option value="all">{t.students.allRooms}</option>
                        {rooms.map(([id, name]) => <option key={id} value={id}>{name}</option>)}
                    </SelectInput>
                </div>
            </div>
        </div>
    );

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.students.loadError}</p>;
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
                    actions={rowActions}
                    toolbar={toolbar}
                    loading={loading}
                    onSortChange={setSortState}
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
                    actions={rowActions}
                    toolbar={toolbar}
                    loading={loading}
                />
            </div>
            <StudentFormModal
                open={formOpen}
                student={editingStudent}
                yearId={yearId}
                readOnly={formReadOnly}
                onClose={closeForm}
                onSaved={handleStudentSaved}
            />
            <ConfirmDeleteModal
                open={Boolean(archiveTarget)}
                title={archiveTarget?.status === 'paused'
                    ? t.students.archive.pauseTitle
                    : t.students.archive.withdrawTitle}
                description={archiveTarget?.status === 'paused'
                    ? t.students.archive.pauseMessage(archiveTarget?.student.full_name ?? t.students.archive.studentFallback)
                    : t.students.archive.withdrawMessage(archiveTarget?.student.full_name ?? t.students.archive.studentFallback)}
                confirmLabel={archiveTarget?.status === 'paused'
                    ? t.students.archive.confirmPause
                    : t.students.archive.confirmWithdraw}
                cancelLabel={t.students.archive.cancel}
                onConfirm={confirmArchive}
                onClose={closeArchiveConfirmation}
                isLoading={archiving}
                error={archiveError}
                isRTL={isRTL}
            />
        </section>
    );
}
