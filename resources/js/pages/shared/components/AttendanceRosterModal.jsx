import React, { useEffect, useState } from 'react';
import Icon from '../../../components/Icon';
import Modal from '../../../modals/desktopmodal';
import AttendanceEntryModal from './AttendanceEntryModal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';

export default function AttendanceRosterModal({ open, yearId, todayDate, onClose, onSaved }) {
    const { isRTL, t } = useLanguage();
    const [rooms, setRooms] = useState([]);
    const [selectedStudent, setSelectedStudent] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [retry, setRetry] = useState(0);

    useEffect(() => {
        if (!open || !yearId) {
            return undefined;
        }

        const controller = new AbortController();
        setLoading(true);
        setError(null);

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

                setRooms(Array.isArray(data.rooms) ? data.rooms : []);
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

    return (
        <Modal
            open={open}
            title={selectedStudent ? t.attendance.markTitle : t.attendance.rosterTitle}
            icon="fact_check"
            onClose={onClose}
            isRTL={isRTL}
            closeLabel={t.attendance.cancel}
            maxWidth="max-w-[680px]"
        >
            {selectedStudent ? (
                <AttendanceEntryModal
                    open
                    embedded
                    mode="mark"
                    selectedStudentId={String(selectedStudent.id)}
                    yearId={yearId}
                    todayDate={todayDate}
                    students={[selectedStudent]}
                    cancelLabel={t.attendance.rosterBack}
                    onClose={() => setSelectedStudent(null)}
                    onSaved={onSaved}
                />
            ) : (
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
                            <p className="text-center text-[13px] leading-5 text-error dark:text-error-container">{error}</p>
                            <button
                                type="button"
                                onClick={() => setRetry((current) => current + 1)}
                                className="min-h-11 rounded-lg border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:border-[#384d43] dark:text-[#ecf3ef] dark:hover:bg-[#2c3d36]"
                            >
                                {t.attendance.retry}
                            </button>
                        </div>
                    ) : rooms.length === 0 ? (
                        <p className="rounded-lg bg-surface-container-low p-4 text-center text-[13px] leading-5 text-on-surface-variant dark:bg-[#182620] dark:text-[#9bb2a8]">
                            {t.attendance.rosterEmpty}
                        </p>
                    ) : (
                        <div className="space-y-4">
                            {rooms.map((room) => (
                                <section
                                    key={room.id}
                                    aria-label={room.name}
                                    className="flex flex-col gap-3 rounded-xl border border-outline-variant p-3 dark:border-[#384d43]"
                                >
                                    <header className="flex items-center justify-between gap-3">
                                        <h3 className="text-[15px] font-semibold text-on-surface dark:text-[#ecf3ef]">{room.name}</h3>
                                        <span className="text-[12px] text-on-surface-variant dark:text-[#9bb2a8]">
                                            {t.attendance.rosterStudentCount(room.students.length)}
                                        </span>
                                    </header>
                                    {room.students.length === 0 ? (
                                        <p className="text-[13px] leading-5 text-on-surface-variant dark:text-[#9bb2a8]">
                                            {t.attendance.rosterRoomEmpty}
                                        </p>
                                    ) : (
                                        <ul className="flex flex-col divide-y divide-outline-variant dark:divide-[#384d43]">
                                            {room.students.map((student) => (
                                                <li key={student.id} className="flex min-h-14 items-center justify-between gap-3 py-2">
                                                    <div className="min-w-0">
                                                        <p className="truncate text-[13px] font-semibold text-on-surface dark:text-[#ecf3ef]">
                                                            {student.name}
                                                        </p>
                                                        {student.student_number && (
                                                            <p className="text-[11px] text-on-surface-variant dark:text-[#9bb2a8]">
                                                                {student.student_number}
                                                            </p>
                                                        )}
                                                    </div>
                                                    {student.status ? (
                                                        <span className="shrink-0 rounded-full bg-surface-container-high px-2.5 py-1 text-[11px] font-semibold text-on-surface-variant dark:bg-[#2c3d36] dark:text-[#cad7d0]">
                                                            {t.attendance.statuses[student.status] ?? student.status}
                                                        </span>
                                                    ) : (
                                                        <div className="flex shrink-0 items-center gap-2">
                                                            <span className="rounded-full bg-surface-container-high px-2.5 py-1 text-[11px] font-semibold text-on-surface-variant dark:bg-[#2c3d36] dark:text-[#cad7d0]">
                                                                {t.attendance.rosterDraft}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => setSelectedStudent(student)}
                                                                aria-label={t.attendance.rosterMarkStudent(student.name)}
                                                                className="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg bg-primary-container px-3 text-[12px] font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]"
                                                            >
                                                                <Icon name="edit" className="text-[16px]" />
                                                                {t.attendance.rosterMark}
                                                            </button>
                                                        </div>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </section>
                            ))}
                        </div>
                    )}
                    <footer className="border-t border-outline-variant pt-3 dark:border-[#384d43]">
                        <button
                            type="button"
                            onClick={onClose}
                            className="min-h-11 w-full rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high dark:bg-[#2c3d36] dark:text-[#ecf3ef] dark:hover:bg-[#344a41]"
                        >
                            {t.attendance.cancel}
                        </button>
                    </footer>
                </div>
            )}
        </Modal>
    );
}
