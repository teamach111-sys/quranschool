import React, { useEffect, useMemo, useState } from 'react';
import Icon from '../../../components/Icon';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';

export default function AttendanceRosterModal({ open, yearId, onClose, onSaved }) {
    const { isRTL, t } = useLanguage();
    const [rooms, setRooms] = useState([]);
    const [draftStatuses, setDraftStatuses] = useState({});
    const [originalStatuses, setOriginalStatuses] = useState({});
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [saveError, setSaveError] = useState(null);
    const [retry, setRetry] = useState(0);

    useEffect(() => {
        if (!open || !yearId) {
            return undefined;
        }

        const controller = new AbortController();
        setLoading(true);
        setError(null);
        setSaveError(null);

        const loadRoster = async () => {
            try {
                const query = new URLSearchParams({ year_id: String(yearId) });
                const response = await apiFetch(`/attendance/roster?${query.toString()}`, {
                    signal: controller.signal,
                });
                const data = await response.json();

                if (!response.ok) {
                    if (response.status === 403) setError(t.attendance.permissionError);
                    else if (data?.errors?.year_id) setError(t.attendance.yearError);
                    else setError(t.attendance.rosterLoadError);
                    return;
                }

                const nextRooms = Array.isArray(data.rooms) ? data.rooms : [];
                setRooms(nextRooms);
                const nextStatuses = Object.fromEntries(
                    nextRooms.flatMap((room) => room.students.map((student) => [
                        student.id,
                        student.status,
                    ])),
                );
                setDraftStatuses(nextStatuses);
                setOriginalStatuses(nextStatuses);
            } catch (requestError) {
                if (requestError.name !== 'AbortError') {
                    setError(t.attendance.rosterLoadError);
                }
            } finally {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            }
        };

        loadRoster();
        return () => controller.abort();
    }, [open, retry, t.attendance.permissionError, t.attendance.rosterLoadError, t.attendance.yearError, yearId]);

    const editableStudents = useMemo(
        () => rooms.flatMap((room) => room.students.filter((student) => student.can_edit)),
        [rooms],
    );
    const markedCount = editableStudents.filter((student) => draftStatuses[student.id]).length;

    const markRoomPresent = (room) => {
        setDraftStatuses((current) => ({
            ...current,
            ...Object.fromEntries(
                room.students
                    .filter((student) => student.can_edit)
                    .map((student) => [student.id, 'present']),
            ),
        }));
        setSaveError(null);
    };

    const setStudentStatus = (studentId, status) => {
        setDraftStatuses((current) => ({ ...current, [studentId]: status }));
        setSaveError(null);
    };

    const handleSave = async () => {
        const entries = editableStudents
            .filter((student) => draftStatuses[student.id])
            .map((student) => ({
                student_id: student.id,
                status: draftStatuses[student.id],
            }));

        if (entries.length === 0 || saving) {
            return;
        }

        setSaving(true);
        setSaveError(null);

        try {
            const response = await apiFetch('/attendance/bulk', {
                method: 'POST',
                body: JSON.stringify({ year_id: yearId, entries }),
            });
            const data = await response.json();

            if (!response.ok) {
                if (response.status === 403) setSaveError(t.attendance.permissionError);
                else if (data?.errors?.year_id) setSaveError(t.attendance.yearError);
                else if (data?.errors?.entries) setSaveError(t.attendance.rosterSaveError);
                else setSaveError(t.attendance.saveError);
                return;
            }

            const undoEntries = entries.map((entry) => ({
                student_id: entry.student_id,
                saved_status: entry.status,
                previous_status: originalStatuses[entry.student_id] ?? null,
            }));
            await onSaved(data.saved_count, undoEntries);
        } catch {
            setSaveError(t.attendance.saveError);
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            open={open}
            title={t.attendance.rosterTitle}
            icon="fact_check"
            onClose={onClose}
            loading={loading || saving}
            isRTL={isRTL}
            closeLabel={t.attendance.cancel}
            maxWidth="max-w-[680px]"
        >
            <div className="flex flex-col gap-4">
                {loading ? (
                    <div
                        role="status"
                        className="flex flex-col items-center justify-center gap-3 py-10 text-on-surface-variant dark:text-tertiary-fixed-dim"
                    >
                        <Icon name="progress_activity" className="animate-spin text-[32px] text-primary" />
                        <p className="text-center text-[13px] leading-5">{t.attendance.rosterLoading}</p>
                    </div>
                ) : error ? (
                    <div role="alert" className="flex flex-col items-center justify-center gap-3 py-6">
                        <p className="text-center text-[13px] leading-5 text-error dark:text-[#e59a92]">{error}</p>
                        <button
                            type="button"
                            onClick={() => setRetry((current) => current + 1)}
                            className="min-h-11 rounded-lg border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:border-[#3a3835] dark:text-[#e7e3de] dark:hover:bg-[#32302e]"
                        >
                            {t.attendance.retry}
                        </button>
                    </div>
                ) : rooms.length === 0 ? (
                    <p className="rounded-lg bg-surface-container-low p-4 text-center text-[13px] leading-5 text-on-surface-variant dark:bg-[#1c1b1a] dark:text-[#918d88]">
                        {t.attendance.rosterEmpty}
                    </p>
                ) : (
                    <>
                        <p className="text-[13px] font-medium text-on-surface-variant dark:text-[#918d88]">
                            {t.attendance.rosterProgress(markedCount, editableStudents.length)}
                        </p>
                        <div className="max-h-[55vh] space-y-4 overflow-y-auto pe-1">
                            {rooms.map((room) => (
                                <section
                                    key={room.id}
                                    aria-label={room.name}
                                    className="flex flex-col gap-3 rounded-xl border border-outline-variant p-3 dark:border-[#3a3835]"
                                >
                                    <header className="flex items-center justify-between gap-3">
                                        <div className="min-w-0">
                                            <h3 className="truncate text-[15px] font-semibold text-on-surface dark:text-[#e7e3de]">
                                                {room.name}
                                            </h3>
                                            <span className="text-[12px] text-on-surface-variant dark:text-[#918d88]">
                                                {t.attendance.rosterStudentCount(room.students.length)}
                                            </span>
                                        </div>
                                        {room.students.some((student) => student.can_edit) && (
                                            <button
                                                type="button"
                                                onClick={() => markRoomPresent(room)}
                                                disabled={saving}
                                                className="inline-flex min-h-11 shrink-0 items-center justify-center gap-1.5 rounded-lg bg-primary-container px-3 text-[12px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-[#2f7d4f] dark:text-white"
                                            >
                                                <Icon name="done_all" className="text-[17px]" />
                                                {t.attendance.rosterMarkAllPresent}
                                            </button>
                                        )}
                                    </header>
                                    {room.students.length === 0 ? (
                                        <p className="text-[13px] leading-5 text-on-surface-variant dark:text-[#918d88]">
                                            {t.attendance.rosterRoomEmpty}
                                        </p>
                                    ) : (
                                        <ul className="flex flex-col divide-y divide-outline-variant dark:divide-[#3a3835]">
                                            {room.students.map((student) => (
                                                <li key={student.id} className="flex flex-col gap-2 py-3">
                                                    <div className="flex min-w-0 items-center justify-between gap-3">
                                                        <div className="min-w-0">
                                                            <p className="truncate text-[13px] font-semibold text-on-surface dark:text-[#e7e3de]">
                                                                {student.name}
                                                            </p>
                                                            {student.student_number && (
                                                                <p className="text-[11px] text-on-surface-variant dark:text-[#918d88]">
                                                                    {student.student_number}
                                                                </p>
                                                            )}
                                                        </div>
                                                        {!student.can_edit && (
                                                            <span className="shrink-0 rounded-full bg-surface-container-high px-2.5 py-1 text-[11px] font-semibold text-on-surface-variant dark:bg-[#32302e] dark:text-[#c6c2bd]">
                                                                {t.attendance.rosterReadOnly}
                                                            </span>
                                                        )}
                                                    </div>
                                                    {student.can_edit ? (
                                                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                                            {Object.entries(t.attendance.statuses).map(([status, label]) => {
                                                                const isSelected = draftStatuses[student.id] === status;

                                                                return (
                                                                    <button
                                                                        key={status}
                                                                        type="button"
                                                                        aria-label={t.attendance.rosterSetStatus(student.name, label)}
                                                                        aria-pressed={isSelected}
                                                                        disabled={saving}
                                                                        onClick={() => setStudentStatus(student.id, status)}
                                                                        className={`min-h-11 rounded-lg border px-2 text-[12px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${
                                                                            isSelected
                                                                                ? 'border-primary bg-primary-container text-on-primary dark:border-primary-fixed-dim dark:bg-[#32302e] dark:text-[#f0ece7]'
                                                                                : 'border-outline-variant bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high dark:border-[#3a3835] dark:bg-[#1c1b1a] dark:text-[#c6c2bd] dark:hover:bg-[#32302e]'
                                                                        }`}
                                                                    >
                                                                        {label}
                                                                    </button>
                                                                );
                                                            })}
                                                        </div>
                                                    ) : (
                                                        <span className="text-[12px] text-on-surface-variant dark:text-[#918d88]">
                                                            {t.attendance.statuses[student.status] ?? student.status}
                                                        </span>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </section>
                            ))}
                        </div>
                    </>
                )}
                {saveError && (
                    <p role="alert" className="text-[13px] leading-5 text-error dark:text-[#e59a92]">
                        {saveError}
                    </p>
                )}
                <footer className="flex flex-col-reverse gap-3 border-t border-outline-variant pt-3 dark:border-[#3a3835] sm:flex-row">
                    <button
                        type="button"
                        disabled={saving}
                        onClick={onClose}
                        className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:opacity-60 dark:bg-[#32302e] dark:text-[#e7e3de] dark:hover:bg-[#32302e]"
                    >
                        {t.attendance.cancel}
                    </button>
                    <button
                        type="button"
                        disabled={loading || Boolean(error) || markedCount === 0 || saving}
                        onClick={handleSave}
                        className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-[#2f7d4f] dark:text-white"
                    >
                        <Icon name={saving ? 'progress_activity' : 'save'} className={`text-[18px] ${saving ? 'animate-spin' : ''}`} />
                        {saving ? t.attendance.saving : t.attendance.save}
                    </button>
                </footer>
            </div>
        </Modal>
    );
}
