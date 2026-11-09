import React, { useState } from 'react';
import ConfirmDeleteModal from '../../../modals/confirmdelete';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';
import DesktopTable from '../../../tables/desktoptable';
import MobileTable from '../../../tables/mobiletable';

function today() {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 10);
}

export default function RoomTeacherAssignmentTable({
    assignments = [],
    yearId,
    yearIsWritable,
    loading = false,
    onChanged,
}) {
    const { isRTL, t } = useLanguage();
    const [endingAssignment, setEndingAssignment] = useState(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const currentDate = today();

    const endAssignment = async () => {
        if (!endingAssignment) return;

        setSaving(true);
        setError(null);

        try {
            const response = await apiFetch(
                `/rooms/${endingAssignment.room_id}/teachers/${endingAssignment.teacher_id}/end`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        year_id: yearId,
                        assigned_from: endingAssignment.assigned_from,
                    }),
                },
            );
            const data = await response.json();

            if (!response.ok) {
                setError(data?.errors?.year_id
                    ? t.rooms.closedYearError
                    : t.rooms.endAssignmentError);
                return;
            }

            setEndingAssignment(null);
            await onChanged();
        } catch {
            setError(t.rooms.endAssignmentError);
        } finally {
            setSaving(false);
        }
    };

    const columns = [
        {
            key: 'room_name',
            label: t.rooms.assignmentColumns.room,
            sortable: true,
            mobile: 'title',
        },
        {
            key: 'teacher_name',
            label: t.rooms.assignmentColumns.teacher,
            sortable: true,
            mobile: 'subtitle',
        },
        {
            key: 'assigned_from',
            label: t.rooms.form.assignedFrom,
            sortable: true,
            mobile: 'detail',
        },
        {
            key: 'assigned_until',
            label: t.rooms.form.assignedUntil,
            sortable: true,
            mobile: 'detail',
            value: (assignment) => assignment.assigned_until
                ?? (assignment.assigned_from > currentDate
                    ? t.rooms.assignmentScheduled
                    : t.rooms.assignmentOngoing),
        },
    ];
    const actions = yearIsWritable ? [{
        key: 'end',
        icon: 'event_busy',
        label: t.rooms.endAssignment,
        hidden: (assignment) => Boolean(assignment.assigned_until) || assignment.assigned_from > currentDate,
        onClick: (assignment) => {
            setEndingAssignment(assignment);
            setError(null);
        },
        className: 'text-error dark:text-[#e59a92]',
    }] : [];
    const labels = {
        actions: t.rooms.table.actions,
        pagination: t.rooms.table.pagination,
        previous: t.rooms.table.previous,
        next: t.rooms.table.next,
        page: (page) => t.rooms.table.page(page),
        selectPage: t.rooms.table.selectPage,
        selectRow: (assignment) => t.rooms.table.selectRow(
            `${assignment.teacher_name} — ${assignment.room_name}`,
        ),
        sortBy: (label) => t.rooms.table.sortBy(label),
        range: (first, last, total) => t.rooms.table.range(first, last, total),
    };

    return (
        <section className="flex w-full flex-col gap-3" dir={isRTL ? 'rtl' : 'ltr'}>
            <h2 className="text-[15px] font-semibold text-on-surface dark:text-inverse-on-surface">
                {t.rooms.assignmentHistory}
            </h2>
            {error && !endingAssignment && (
                <p role="alert" className="text-[13px] leading-5 text-error dark:text-[#e59a92]">{error}</p>
            )}
            <div className="hidden md:block">
                <DesktopTable
                    ariaLabel={t.rooms.assignmentHistory}
                    columns={columns}
                    emptyState={t.rooms.noAssignments}
                    isRTL={isRTL}
                    labels={labels}
                    rows={assignments}
                    actions={actions}
                    rowKey="key"
                    loading={loading}
                />
            </div>
            <div className="md:hidden">
                <MobileTable
                    ariaLabel={t.rooms.assignmentHistory}
                    columns={columns}
                    emptyState={t.rooms.noAssignments}
                    isRTL={isRTL}
                    labels={labels}
                    rows={assignments}
                    actions={actions}
                    rowKey="key"
                    loading={loading}
                />
            </div>
            <ConfirmDeleteModal
                open={Boolean(endingAssignment)}
                title={t.rooms.endAssignmentTitle}
                description={t.rooms.endAssignmentMessage(
                    endingAssignment?.teacher_name ?? '',
                    endingAssignment?.room_name ?? '',
                )}
                confirmLabel={t.rooms.endAssignment}
                cancelLabel={t.rooms.cancel}
                onConfirm={endAssignment}
                onClose={() => {
                    if (!saving) {
                        setEndingAssignment(null);
                        setError(null);
                    }
                }}
                isLoading={saving}
                error={error}
                isRTL={isRTL}
            />
        </section>
    );
}
