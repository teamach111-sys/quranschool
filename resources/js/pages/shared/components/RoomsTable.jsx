import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Icon from '../../../components/Icon';
import ConfirmDeleteModal from '../../../modals/confirmdelete';
import Modal from '../../../modals/desktopmodal';
import RoomFormModal from '../../administrator/components/RoomFormModal';
import RoomTeacherAssignmentModal from '../../administrator/components/RoomTeacherAssignmentModal';
import RoomTeacherAssignmentTable from '../../administrator/components/RoomTeacherAssignmentTable';
import { useLanguage } from '../../../hooks/useLanguage';
import { apiFetch } from '../../../lib/api';
import {
    ACADEMIC_YEAR_CHANGED_EVENT,
    getSelectedAcademicYearId,
} from '../../../persisters/yearselector';
import DesktopTable from '../../../tables/desktoptable';
import MobileTable from '../../../tables/mobiletable';

export default function RoomsTable({ routeParams = {} }) {
    const { isRTL, t } = useLanguage();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [rooms, setRooms] = useState([]);
    const [assignments, setAssignments] = useState([]);
    const [students, setStudents] = useState([]);
    const [teachers, setTeachers] = useState([]);
    const [canManage, setCanManage] = useState(false);
    const [canPlace, setCanPlace] = useState(false);
    const [isTeacherView, setIsTeacherView] = useState(false);
    const [yearIsWritable, setYearIsWritable] = useState(false);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [error, setError] = useState(null);
    const [roomEditorOpen, setRoomEditorOpen] = useState(false);
    const [editingRoom, setEditingRoom] = useState(null);
    const [archiveRoom, setArchiveRoom] = useState(null);
    const [savingArchive, setSavingArchive] = useState(false);
    const [assignmentRoom, setAssignmentRoom] = useState(null);
    const [focusedAlertRoomId, setFocusedAlertRoomId] = useState('');
    const openedAlertRoomId = useRef(null);
    const [placementOpen, setPlacementOpen] = useState(false);
    const [placementForm, setPlacementForm] = useState({ student_id: '', room_id: '' });
    const [savingPlacement, setSavingPlacement] = useState(false);

    useEffect(() => {
        const syncYear = (event) => {
            const nextYearId = event?.detail?.id ?? getSelectedAcademicYearId();
            setYearId(nextYearId ? Number(nextYearId) : null);
            setYearIsWritable(false);
            setRoomEditorOpen(false);
            setEditingRoom(null);
            setArchiveRoom(null);
            setAssignmentRoom(null);
            setPlacementOpen(false);
        };

        window.addEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
        return () => window.removeEventListener(ACADEMIC_YEAR_CHANGED_EVENT, syncYear);
    }, []);

    const loadRooms = useCallback(async (signal) => {
        setLoading(true);
        setLoadError(false);

        try {
            const query = yearId ? `?year_id=${encodeURIComponent(yearId)}` : '';
            const response = await apiFetch(`/rooms/table${query}`, { signal });
            if (!response.ok) throw new Error('Failed to load rooms');
            const data = await response.json();

            if (signal?.aborted) return;
            setYearId((currentYearId) => currentYearId ?? Number(data.year_id));
            setRooms(Array.isArray(data.rooms) ? data.rooms : []);
            setAssignments(Array.isArray(data.assignments) ? data.assignments : []);
            setStudents(Array.isArray(data.students) ? data.students : []);
            setTeachers(Array.isArray(data.teachers) ? data.teachers : []);
            setCanManage(Boolean(data.can_manage));
            setCanPlace(Boolean(data.can_place));
            setIsTeacherView(Boolean(data.is_teacher_view));
            setYearIsWritable(Boolean(data.year_is_writable));
        } catch (requestError) {
            if (requestError.name !== 'AbortError') setLoadError(true);
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, [yearId]);

    useEffect(() => {
        const controller = new AbortController();
        loadRooms(controller.signal);
        return () => controller.abort();
    }, [loadRooms]);

    useEffect(() => {
        const alertRoomId = String(routeParams.room_id ?? '');
        if (!alertRoomId || loading || openedAlertRoomId.current === alertRoomId) return;

        const room = rooms.find((item) => String(item.id) === alertRoomId);
        if (!room) return;

        openedAlertRoomId.current = alertRoomId;
        if (canManage && yearIsWritable && room.is_active) {
            setFocusedAlertRoomId('');
            setAssignmentRoom(room);
        } else {
            setFocusedAlertRoomId(alertRoomId);
        }
    }, [canManage, loading, rooms, routeParams.room_id, yearIsWritable]);

    const runRequest = async (endpoint, method, body, errorMessage) => {
        setError(null);
        const response = await apiFetch(endpoint, {
            method,
            body: JSON.stringify(body),
        });
        const data = await response.json();
        if (!response.ok) {
            setError(data?.errors?.year_id
                ? t.rooms.closedYearError
                : data?.errors?.room_capacity
                    ? t.rooms.fullRoomError
                    : data?.errors?.room_id
                    ? t.rooms.roomUnavailableError
                    : errorMessage);
            return false;
        }
        return true;
    };

    const openRoomEditor = (room = null) => {
        setEditingRoom(room);
        setRoomEditorOpen(true);
        setError(null);
    };

    const closeRoomEditor = () => {
        setRoomEditorOpen(false);
        setEditingRoom(null);
    };

    const handleRoomSaved = async () => {
        closeRoomEditor();
        await loadRooms();
    };

    const archiveSelectedRoom = async () => {
        if (!archiveRoom) return;
        setSavingArchive(true);

        try {
            const archived = await runRequest(
                `/rooms/${archiveRoom.id}/archive`,
                'PATCH',
                { year_id: yearId },
                t.rooms.archiveError,
            );
            if (archived) {
                setArchiveRoom(null);
                await loadRooms();
            }
        } catch {
            setError(t.rooms.archiveError);
        } finally {
            setSavingArchive(false);
        }
    };

    const placeStudent = async (event) => {
        event.preventDefault();
        if (!placementForm.student_id) return;
        setSavingPlacement(true);

        try {
            const placed = await runRequest(
                `/students/${placementForm.student_id}/room`,
                'PATCH',
                {
                    room_id: placementForm.room_id ? Number(placementForm.room_id) : null,
                    year_id: yearId,
                },
                t.rooms.placementError,
            );
            if (placed) {
                setPlacementOpen(false);
                setPlacementForm({ student_id: '', room_id: '' });
                await loadRooms();
            }
        } catch {
            setError(t.rooms.placementError);
        } finally {
            setSavingPlacement(false);
        }
    };

    const activeRooms = useMemo(() => rooms.filter((room) => room.is_active), [rooms]);
    const focusedAlertRoom = focusedAlertRoomId
        ? rooms.find((room) => String(room.id) === focusedAlertRoomId)
        : null;
    const displayedRooms = focusedAlertRoom ? [focusedAlertRoom] : rooms;
    const selectedStudent = students.find((student) => String(student.id) === placementForm.student_id);
    const isPlacementTargetFull = activeRooms.some((room) => String(room.id) === placementForm.room_id
        && room.student_count >= room.capacity
        && selectedStudent?.room_id !== room.id);
    const columns = [
        {
            key: 'name',
            label: t.rooms.columns.name,
            sortable: true,
            mobile: 'title',
        },
        {
            key: 'level',
            label: t.rooms.columns.level,
            sortable: true,
            mobile: 'subtitle',
            value: (room) => t.rooms.levels[room.level] ?? room.level,
        },
        {
            key: 'age_range',
            label: t.rooms.columns.ageRange,
            mobile: 'detail',
            value: (room) => t.rooms.ageRange(room.age_min, room.age_max),
        },
        {
            key: 'capacity',
            label: t.rooms.columns.capacity,
            sortable: true,
            mobile: 'detail',
            value: (room) => `${room.student_count} / ${room.capacity}`,
            sortValue: (room) => room.capacity,
        },
        {
            key: 'assigned_teachers',
            label: t.rooms.columns.teachers,
            mobile: 'detail',
            value: (room) => room.assigned_teachers
                .map((teacher) => `${teacher.name} (${teacher.assigned_from} – ${teacher.assigned_until ?? t.rooms.assignmentOngoing})`)
                .join(', ') || t.rooms.noTeachers,
        },
        {
            key: 'is_active',
            label: t.rooms.columns.status,
            sortable: true,
            mobile: 'badge',
            value: (room) => room.is_active ? t.rooms.active : t.rooms.archived,
            render: (value, room) => (
                <span className={`inline-flex rounded-md px-2 py-1 text-[11px] font-semibold ${room.is_active
                    ? 'bg-primary-fixed/50 text-on-primary-fixed-variant dark:bg-[#1e4f40]/50 dark:text-[#baeed9]'
                    : 'bg-surface-container-high text-on-surface-variant dark:bg-[#344a41] dark:text-[#c2d1c8]'}`}
                >
                    {value}
                </span>
            ),
        },
    ];
    const actions = [
        ...(canPlace && yearIsWritable ? [{
            key: 'place',
            icon: 'person_add',
            label: t.rooms.placeStudent,
            hidden: (room) => !room.is_active,
            onClick: (room) => {
                setPlacementForm({ student_id: '', room_id: String(room.id) });
                setPlacementOpen(true);
            },
        }] : []),
        ...(canManage && yearIsWritable ? [
            {
                key: 'assign',
                icon: 'assignment_ind',
                label: t.rooms.assignTeacher,
                hidden: (room) => !room.is_active,
                onClick: (room) => {
                    setAssignmentRoom(room);
                    setError(null);
                },
            },
            {
                key: 'edit',
                icon: 'edit',
                label: t.rooms.edit,
                onClick: (room) => openRoomEditor(room),
            },
            {
                key: 'archive',
                icon: 'archive',
                label: t.rooms.archive,
                hidden: (room) => !room.is_active,
                onClick: (room) => {
                    setArchiveRoom(room);
                    setError(null);
                },
            },
        ] : []),
    ];
    const labels = {
        actions: t.rooms.table.actions,
        pagination: t.rooms.table.pagination,
        previous: t.rooms.table.previous,
        next: t.rooms.table.next,
        page: (page) => t.rooms.table.page(page),
        selectPage: t.rooms.table.selectPage,
        selectRow: (room) => t.rooms.table.selectRow(room.name),
        sortBy: (label) => t.rooms.table.sortBy(label),
        range: (first, last, total) => t.rooms.table.range(first, last, total),
    };
    const controlClass = 'min-h-10 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:border-[#384d43] dark:bg-[#182620] dark:text-[#ecf3ef]';
    const teacherRooms = (
        <ul aria-label={t.rooms.listLabel} className="grid w-full grid-cols-1 gap-3 xl:grid-cols-2">
            {rooms.map((room) => {
                const roomStudents = students.filter((student) => student.room_id === room.id);

                return (
                    <li key={room.id} className="flex min-w-0 flex-col gap-3 rounded-lg border border-outline-variant bg-surface-container-lowest p-4 dark:border-tertiary-container dark:bg-inverse-surface">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex min-w-0 items-center gap-2">
                                <Icon name="groups" className="shrink-0 text-[20px] text-primary dark:text-primary-fixed-dim" />
                                <h2 className="truncate text-[15px] leading-5 font-semibold text-on-surface dark:text-inverse-on-surface">{room.name}</h2>
                            </div>
                            <span className="text-[12px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">
                                {t.rooms.studentListCount(roomStudents.length)}
                            </span>
                        </div>
                        {roomStudents.length > 0 ? (
                            <ul className="flex flex-col gap-2 border-t border-outline-variant pt-3 dark:border-tertiary-container">
                                {roomStudents.map((student) => (
                                    <li key={student.id} className="flex min-w-0 items-center justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="truncate text-[13px] leading-5 font-medium text-on-surface dark:text-inverse-on-surface">{student.full_name}</p>
                                            {student.student_number && (
                                                <p className="text-[11px] leading-4 text-on-surface-variant dark:text-tertiary-fixed-dim">{student.student_number}</p>
                                            )}
                                        </div>
                                        <span className={`shrink-0 rounded-md px-2 py-1 text-[11px] leading-4 font-semibold ${student.status === 'active'
                                            ? 'bg-primary-fixed/50 text-on-primary-fixed-variant dark:bg-primary-container dark:text-on-primary-container'
                                            : 'bg-surface-container text-on-surface-variant dark:bg-tertiary-container dark:text-on-tertiary-container'}`}
                                        >
                                            {t.students.statuses[student.status]}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="border-t border-outline-variant pt-3 text-[12px] leading-5 text-on-surface-variant dark:border-tertiary-container dark:text-tertiary-fixed-dim">
                                {t.rooms.noAssignedStudents}
                            </p>
                        )}
                        <div className="flex flex-wrap gap-2 border-t border-outline-variant pt-3 dark:border-tertiary-container">
                            <a href="#/presences" className="inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-primary-container px-3 py-2 text-[12px] leading-4 font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-primary-container dark:text-on-primary">
                                <Icon name="fact_check" className="text-[16px]" />
                                {t.rooms.takeAttendance}
                            </a>
                            <a href="#/seances-memorisation" className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] leading-4 font-semibold text-on-surface transition-colors hover:bg-surface-container-high dark:border-tertiary-container dark:bg-inverse-surface dark:text-inverse-on-surface">
                                <Icon name="menu_book" className="text-[16px]" />
                                {t.rooms.enterMemorizationSession}
                            </a>
                        </div>
                    </li>
                );
            })}
        </ul>
    );

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.rooms.loadError}</p>;
    }

    if (loading) {
        return <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">{t.rooms.loading}</p>;
    }

    const toolbar = (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
            {canManage && yearIsWritable && (
                <button
                    type="button"
                    onClick={() => setAssignmentRoom(true)}
                    className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:border-[#384d43] dark:bg-[#182620] dark:text-[#ecf3ef]"
                >
                    <Icon name="assignment_ind" className="text-[17px]" />
                    {t.rooms.assignTeacher}
                </button>
            )}
            {canPlace && yearIsWritable && (
                <button
                    type="button"
                    onClick={() => {
                        setPlacementForm({ student_id: '', room_id: '' });
                        setPlacementOpen(true);
                    }}
                    className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:border-[#384d43] dark:bg-[#182620] dark:text-[#ecf3ef]"
                >
                    <Icon name="person_add" className="text-[17px]" />
                    {t.rooms.placeStudent}
                </button>
            )}
            {canManage && yearIsWritable && (
                <button
                    type="button"
                    onClick={() => openRoomEditor()}
                    className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg bg-primary-container px-3.5 py-2 text-[12px] font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]"
                >
                    <Icon name="add" className="text-[17px]" />
                    {t.rooms.create}
                </button>
            )}
        </div>
    );

    return (
        <section className="flex w-full flex-col gap-3" dir={isRTL ? 'rtl' : 'ltr'}>
            {error && !archiveRoom && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{error}</p>}
            {focusedAlertRoom && (
                <p role="status" className="rounded-lg bg-surface-container px-3 py-2 text-[12px] text-on-surface-variant dark:bg-inverse-surface dark:text-inverse-on-surface">
                    {t.rooms.alertRecordFocus(focusedAlertRoom.name)}
                </p>
            )}
            {isTeacherView ? (
                rooms.length > 0 ? teacherRooms : (
                    <p className="rounded-lg border border-outline-variant bg-surface-container-lowest p-6 text-[13px] text-on-surface-variant dark:border-tertiary-container dark:bg-inverse-surface dark:text-tertiary-fixed-dim">
                        {t.rooms.empty}
                    </p>
                )
            ) : (
                <>
                    <div className="hidden md:block">
                        <DesktopTable
                            ariaLabel={t.rooms.listLabel}
                            columns={columns}
                            emptyState={t.rooms.empty}
                            isRTL={isRTL}
                            labels={labels}
                            rows={displayedRooms}
                            actions={actions}
                            toolbar={toolbar}
                        />
                    </div>
                    <div className="md:hidden">
                        <MobileTable
                            ariaLabel={t.rooms.listLabel}
                            columns={columns}
                            emptyState={t.rooms.empty}
                            isRTL={isRTL}
                            labels={labels}
                            rows={displayedRooms}
                            actions={actions}
                            toolbar={toolbar}
                        />
                    </div>
                </>
            )}
            {canManage && (
                <RoomTeacherAssignmentTable
                    assignments={assignments}
                    yearId={yearId}
                    yearIsWritable={yearIsWritable}
                    onChanged={loadRooms}
                />
            )}
            <Modal
                open={placementOpen}
                title={t.rooms.placementTitle}
                icon="person_add"
                onClose={() => {
                    if (!savingPlacement) setPlacementOpen(false);
                }}
                isRTL={isRTL}
                footer={(
                    <>
                        <button type="button" disabled={savingPlacement} onClick={() => setPlacementOpen(false)} className="min-h-10 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[12px] font-semibold text-on-surface disabled:opacity-60 dark:bg-[#2c3d36] dark:text-[#ecf3ef]">
                            {t.rooms.cancel}
                        </button>
                        <button type="submit" form="room-placement-form" disabled={savingPlacement || !placementForm.student_id || isPlacementTargetFull} className="min-h-10 flex-1 rounded-lg bg-primary-container px-4 py-2 text-[12px] font-semibold text-on-primary disabled:opacity-60 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]">
                            {savingPlacement ? t.rooms.saving : t.rooms.placeStudent}
                        </button>
                    </>
                )}
            >
                <form id="room-placement-form" onSubmit={placeStudent} className="flex flex-col gap-4">
                    <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        {t.rooms.form.student}
                        <select required value={placementForm.student_id} onChange={(event) => setPlacementForm((form) => ({ ...form, student_id: event.target.value }))} className={controlClass}>
                            <option value="">{t.rooms.form.selectStudent}</option>
                            {students.map((student) => (
                                <option key={student.id} value={student.id}>
                                    {student.full_name}{student.student_number ? ` · ${student.student_number}` : ''}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        {t.rooms.form.targetRoom}
                        <select value={placementForm.room_id} onChange={(event) => setPlacementForm((form) => ({ ...form, room_id: event.target.value }))} className={controlClass}>
                            <option value="">{t.rooms.form.unassigned}</option>
                            {activeRooms.map((room) => (
                                <option
                                    key={room.id}
                                    value={room.id}
                                    disabled={room.student_count >= room.capacity && selectedStudent?.room_id !== room.id}
                                >
                                    {room.name} ({room.student_count} / {room.capacity})
                                </option>
                            ))}
                        </select>
                    </label>
                    {isPlacementTargetFull && <p role="status" className="text-[12px] text-error dark:text-error-container">{t.rooms.fullRoomError}</p>}
                    {error && <p role="alert" className="text-[13px] text-error dark:text-error-container">{error}</p>}
                </form>
            </Modal>
            {canManage && (
                <RoomFormModal
                    open={roomEditorOpen}
                    room={editingRoom}
                    yearId={yearId}
                    onClose={closeRoomEditor}
                    onSaved={handleRoomSaved}
                />
            )}
            {canManage && (
                <RoomTeacherAssignmentModal
                    open={Boolean(assignmentRoom)}
                    rooms={rooms}
                    teachers={teachers}
                    yearId={yearId}
                    room={typeof assignmentRoom === 'object' ? assignmentRoom : null}
                    onClose={() => setAssignmentRoom(null)}
                    onSaved={async () => {
                        setAssignmentRoom(null);
                        await loadRooms();
                    }}
                />
            )}
            <ConfirmDeleteModal
                open={Boolean(archiveRoom)}
                title={t.rooms.archiveTitle}
                description={t.rooms.archiveMessage(archiveRoom?.name ?? '')}
                confirmLabel={t.rooms.archive}
                cancelLabel={t.rooms.cancel}
                onConfirm={archiveSelectedRoom}
                onClose={() => {
                    if (!savingArchive) setArchiveRoom(null);
                }}
                isLoading={savingArchive}
                error={error}
                isRTL={isRTL}
            />
        </section>
    );
}
