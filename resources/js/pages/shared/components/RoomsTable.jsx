import React, { useCallback, useEffect, useMemo, useState } from 'react';
import Icon from '../../../components/Icon';
import ConfirmDeleteModal from '../../../modals/confirmdelete';
import Modal from '../../../modals/desktopmodal';
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

function emptyRoom() {
    return {
        name: '',
        level: 'beginner',
        age_min: '',
        age_max: '',
        capacity: '12',
        is_active: true,
    };
}

export default function RoomsTable() {
    const { isRTL, t } = useLanguage();
    const [yearId, setYearId] = useState(getSelectedAcademicYearId);
    const [rooms, setRooms] = useState([]);
    const [students, setStudents] = useState([]);
    const [teachers, setTeachers] = useState([]);
    const [canManage, setCanManage] = useState(false);
    const [canPlace, setCanPlace] = useState(false);
    const [yearIsActive, setYearIsActive] = useState(false);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [error, setError] = useState(null);
    const [roomEditorOpen, setRoomEditorOpen] = useState(false);
    const [editingRoom, setEditingRoom] = useState(null);
    const [roomForm, setRoomForm] = useState(emptyRoom);
    const [savingRoom, setSavingRoom] = useState(false);
    const [archiveRoom, setArchiveRoom] = useState(null);
    const [savingArchive, setSavingArchive] = useState(false);
    const [assignmentRoom, setAssignmentRoom] = useState(null);
    const [assignmentForm, setAssignmentForm] = useState({ teacher_id: '', assigned_from: today(), assigned_until: '' });
    const [savingAssignment, setSavingAssignment] = useState(false);
    const [placementOpen, setPlacementOpen] = useState(false);
    const [placementForm, setPlacementForm] = useState({ student_id: '', room_id: '' });
    const [savingPlacement, setSavingPlacement] = useState(false);

    useEffect(() => {
        const syncYear = (event) => {
            const nextYearId = event?.detail?.id ?? getSelectedAcademicYearId();
            setYearId(nextYearId ? Number(nextYearId) : null);
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
            setStudents(Array.isArray(data.students) ? data.students : []);
            setTeachers(Array.isArray(data.teachers) ? data.teachers : []);
            setCanManage(Boolean(data.can_manage));
            setCanPlace(Boolean(data.can_place));
            setYearIsActive(Boolean(data.year_is_active));
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
        setRoomForm(room ? {
            name: room.name,
            level: room.level,
            age_min: room.age_min ?? '',
            age_max: room.age_max ?? '',
            capacity: String(room.capacity),
            is_active: room.is_active,
        } : emptyRoom());
        setRoomEditorOpen(true);
        setError(null);
    };

    const saveRoom = async (event) => {
        event.preventDefault();
        setSavingRoom(true);
        const payload = {
            ...roomForm,
            age_min: roomForm.age_min === '' ? null : Number(roomForm.age_min),
            age_max: roomForm.age_max === '' ? null : Number(roomForm.age_max),
            capacity: Number(roomForm.capacity),
            year_id: yearId,
        };

        try {
            const saved = await runRequest(
                editingRoom ? `/rooms/${editingRoom.id}` : '/rooms',
                editingRoom ? 'PUT' : 'POST',
                payload,
                t.rooms.saveError,
            );
            if (saved) {
                setRoomEditorOpen(false);
                setEditingRoom(null);
                await loadRooms();
            }
        } catch {
            setError(t.rooms.saveError);
        } finally {
            setSavingRoom(false);
        }
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

    const assignTeacher = async (event) => {
        event.preventDefault();
        if (!assignmentRoom) return;
        setSavingAssignment(true);

        try {
            const assigned = await runRequest(
                `/rooms/${assignmentRoom.id}/teachers`,
                'POST',
                {
                    ...assignmentForm,
                    teacher_id: Number(assignmentForm.teacher_id),
                    assigned_until: assignmentForm.assigned_until || null,
                    year_id: yearId,
                },
                t.rooms.assignmentError,
            );
            if (assigned) {
                setAssignmentRoom(null);
                await loadRooms();
            }
        } catch {
            setError(t.rooms.assignmentError);
        } finally {
            setSavingAssignment(false);
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
            value: (room) => room.assigned_teachers.map((teacher) => teacher.name).join(', ') || t.rooms.noTeachers,
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
        ...(canPlace && yearIsActive ? [{
            key: 'place',
            icon: 'person_add',
            label: t.rooms.placeStudent,
            hidden: (room) => !room.is_active,
            onClick: (room) => {
                setPlacementForm({ student_id: '', room_id: String(room.id) });
                setPlacementOpen(true);
            },
        }] : []),
        ...(canManage && yearIsActive ? [
            {
                key: 'assign',
                icon: 'assignment_ind',
                label: t.rooms.assignTeacher,
                hidden: (room) => !room.is_active,
                onClick: (room) => {
                    setAssignmentRoom(room);
                    setAssignmentForm({ teacher_id: '', assigned_from: today(), assigned_until: '' });
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

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.rooms.loadError}</p>;
    }

    if (loading) {
        return <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">{t.rooms.loading}</p>;
    }

    const toolbar = (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
            {canPlace && yearIsActive && (
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
            {canManage && yearIsActive && (
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
            {roomEditorOpen && (
                <form onSubmit={saveRoom} className="grid grid-cols-1 gap-3 rounded-xl border border-outline-variant bg-surface-container-lowest p-4 dark:border-[#384d43] dark:bg-[#22302a] sm:grid-cols-2 lg:grid-cols-3">
                    <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        {t.rooms.form.name}
                        <input required maxLength={100} value={roomForm.name} onChange={(event) => setRoomForm((form) => ({ ...form, name: event.target.value }))} className={controlClass} />
                    </label>
                    <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        {t.rooms.form.level}
                        <select value={roomForm.level} onChange={(event) => setRoomForm((form) => ({ ...form, level: event.target.value }))} className={controlClass}>
                            {Object.entries(t.rooms.levels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                    </label>
                    <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        {t.rooms.form.ageMin}
                        <input type="number" min="0" max="255" value={roomForm.age_min} onChange={(event) => setRoomForm((form) => ({ ...form, age_min: event.target.value }))} className={controlClass} />
                    </label>
                    <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        {t.rooms.form.ageMax}
                        <input type="number" min="0" max="255" value={roomForm.age_max} onChange={(event) => setRoomForm((form) => ({ ...form, age_max: event.target.value }))} className={controlClass} />
                    </label>
                    <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        {t.rooms.form.capacity}
                        <input type="number" min="1" max="65535" required value={roomForm.capacity} onChange={(event) => setRoomForm((form) => ({ ...form, capacity: event.target.value }))} className={controlClass} />
                    </label>
                    <label className="flex items-center gap-2 self-end pb-2 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        <input type="checkbox" checked={roomForm.is_active} onChange={(event) => setRoomForm((form) => ({ ...form, is_active: event.target.checked }))} className="h-4 w-4 accent-primary" />
                        {t.rooms.form.active}
                    </label>
                    {error && <p role="alert" className="text-[13px] text-error dark:text-error-container sm:col-span-2 lg:col-span-3">{error}</p>}
                    <div className="flex flex-col-reverse gap-2 sm:col-span-2 sm:flex-row sm:justify-end lg:col-span-3">
                        <button type="button" disabled={savingRoom} onClick={() => setRoomEditorOpen(false)} className="min-h-10 rounded-lg bg-surface-container px-4 py-2 text-[12px] font-semibold text-on-surface disabled:opacity-60 dark:bg-[#2c3d36] dark:text-[#ecf3ef]">
                            {t.rooms.cancel}
                        </button>
                        <button type="submit" disabled={savingRoom} className="min-h-10 rounded-lg bg-primary-container px-4 py-2 text-[12px] font-semibold text-on-primary disabled:opacity-60 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]">
                            {savingRoom ? t.rooms.saving : t.rooms.save}
                        </button>
                    </div>
                </form>
            )}
            {assignmentRoom && (
                <form onSubmit={assignTeacher} className="grid grid-cols-1 gap-3 rounded-xl border border-outline-variant bg-surface-container-lowest p-4 dark:border-[#384d43] dark:bg-[#22302a] sm:grid-cols-2 lg:grid-cols-4">
                    <h2 className="text-[13px] font-semibold text-on-surface dark:text-inverse-on-surface sm:col-span-2 lg:col-span-4">
                        {t.rooms.assignToRoom(assignmentRoom.name)}
                    </h2>
                    <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        {t.rooms.form.teacher}
                        <select required value={assignmentForm.teacher_id} onChange={(event) => setAssignmentForm((form) => ({ ...form, teacher_id: event.target.value }))} className={controlClass}>
                            <option value="">{t.rooms.form.selectTeacher}</option>
                            {teachers.map((teacher) => <option key={teacher.id} value={teacher.id}>{teacher.name}</option>)}
                        </select>
                    </label>
                    <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        {t.rooms.form.assignedFrom}
                        <input type="date" required value={assignmentForm.assigned_from} onChange={(event) => setAssignmentForm((form) => ({ ...form, assigned_from: event.target.value }))} className={controlClass} />
                    </label>
                    <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                        {t.rooms.form.assignedUntil}
                        <input type="date" min={assignmentForm.assigned_from} value={assignmentForm.assigned_until} onChange={(event) => setAssignmentForm((form) => ({ ...form, assigned_until: event.target.value }))} className={controlClass} />
                    </label>
                    <div className="flex items-end gap-2">
                        <button type="button" disabled={savingAssignment} onClick={() => setAssignmentRoom(null)} className="min-h-10 flex-1 rounded-lg bg-surface-container px-3 py-2 text-[12px] font-semibold text-on-surface disabled:opacity-60 dark:bg-[#2c3d36] dark:text-[#ecf3ef]">
                            {t.rooms.cancel}
                        </button>
                        <button type="submit" disabled={savingAssignment || teachers.length === 0} className="min-h-10 flex-1 rounded-lg bg-primary-container px-3 py-2 text-[12px] font-semibold text-on-primary disabled:opacity-60 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]">
                            {savingAssignment ? t.rooms.saving : t.rooms.assignTeacher}
                        </button>
                    </div>
                    {error && <p role="alert" className="text-[13px] text-error dark:text-error-container sm:col-span-2 lg:col-span-4">{error}</p>}
                    {teachers.length === 0 && <p className="text-[12px] text-on-surface-variant dark:text-[#c2d1c8] sm:col-span-2 lg:col-span-4">{t.rooms.noAvailableTeachers}</p>}
                </form>
            )}
            <div className="hidden md:block">
                <DesktopTable
                    ariaLabel={t.rooms.listLabel}
                    columns={columns}
                    emptyState={t.rooms.empty}
                    isRTL={isRTL}
                    labels={labels}
                    rows={rooms}
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
                    rows={rooms}
                    actions={actions}
                    toolbar={toolbar}
                />
            </div>
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
