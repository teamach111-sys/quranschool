import React, { useEffect, useId, useState } from 'react';
import Icon from '../../../components/Icon';
import ConfirmDeleteModal from '../../../modals/confirmdelete';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';

function today() {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 10);
}

function initialValues(student) {
    return {
        full_name: student?.full_name ?? '',
        student_number: student?.student_number ?? '',
        birth_date: student?.birth_date ?? '',
        guardian_name: student?.guardian_name ?? '',
        guardian_phone: student?.guardian_phone ?? '',
        guardian_email: student?.guardian_email ?? '',
        room_id: student?.room_id ? String(student.room_id) : '',
        enrollment_date: student?.enrollment_date ?? today(),
        status: student?.status ?? 'active',
        notes: student?.notes ?? '',
    };
}

export default function StudentFormModal({
    open,
    student = null,
    yearId,
    readOnly = false,
    onClose,
    onSaved,
}) {
    const { isRTL, t } = useLanguage();
    const [form, setForm] = useState(() => initialValues(student));
    const [rooms, setRooms] = useState([]);
    const [detail, setDetail] = useState(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [confirmingStatus, setConfirmingStatus] = useState(null);
    const [canEditYear, setCanEditYear] = useState(false);
    const [error, setError] = useState(null);
    const formId = useId();
    const isEditing = Boolean(student?.id);

    useEffect(() => {
        if (!open) return undefined;

        const controller = new AbortController();
        setForm(initialValues(student));
        setConfirmingStatus(null);
        setDetail(null);
        setRooms([]);
        setCanEditYear(false);
        setError(null);
        setLoading(true);

        const query = yearId ? `?year_id=${encodeURIComponent(yearId)}` : '';
        const endpoint = readOnly && student?.id
            ? `/students/${student.id}${query}`
            : `/students/options${query}`;

        apiFetch(endpoint, { signal: controller.signal })
            .then(async (response) => {
                const data = await response.json();
                if (!response.ok) throw new Error('Failed to load student form');

                if (readOnly) {
                    setDetail(data.student);
                } else {
                    setRooms(Array.isArray(data.rooms) ? data.rooms : []);
                    setCanEditYear(Boolean(data.is_editable));
                }
            })
            .catch((loadError) => {
                if (loadError.name !== 'AbortError') setError(t.students.form.loadError);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
    }, [open, student, yearId, readOnly, t.students.form.loadError]);

    const handleChange = (event) => {
        const { name, value } = event.target;
        if (name === 'status' && value !== form.status && ['paused', 'withdrawn'].includes(value)) {
            setConfirmingStatus(value);
            return;
        }

        setForm((current) => ({ ...current, [event.target.name]: event.target.value }));
        setError(null);
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        setSaving(true);
        setError(null);

        try {
            const response = await apiFetch(
                isEditing ? `/students/${student.id}` : '/students',
                {
                    method: isEditing ? 'PUT' : 'POST',
                    body: JSON.stringify({
                        ...form,
                        room_id: form.room_id || null,
                        year_id: yearId,
                    }),
                },
            );
            const data = await response.json();

            if (!response.ok) {
                const errors = data?.errors ?? {};
                if (errors.year_id) setError(t.students.form.closedYearError);
                else if (errors.student_number) setError(t.students.form.duplicateNumberError);
                else if (errors.room_capacity) setError(t.students.form.roomFullError);
                else if (errors.room_id) setError(t.students.form.roomError);
                else setError(t.students.form.saveError);
                return;
            }

            onSaved(data.student);
        } catch {
            setError(t.students.form.saveError);
        } finally {
            setSaving(false);
        }
    };

    const title = readOnly
        ? t.students.form.profileTitle
        : isEditing ? t.students.form.editTitle : t.students.form.createTitle;
    const inputClass = 'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] leading-5 text-on-surface outline-none transition-colors focus:border-primary focus:bg-surface-container-lowest focus:ring-1 focus:ring-primary disabled:cursor-not-allowed disabled:opacity-70 dark:border-[#384d43] dark:bg-[#182620] dark:text-[#ecf3ef] dark:focus:border-primary-fixed-dim dark:focus:bg-[#202f29] dark:focus:ring-primary-fixed-dim';
    const labelClass = 'text-[12px] leading-4 font-semibold text-on-surface dark:text-inverse-on-surface';

    const field = (name, label, value, type = 'text') => (
        <div key={name} className="flex min-w-0 flex-col gap-1.5">
            <label htmlFor={`${formId}-${name}`} className={labelClass}>{label}</label>
            <input
                id={`${formId}-${name}`}
                name={name}
                type={type}
                value={value ?? ''}
                disabled
                className={inputClass}
            />
        </div>
    );

    let content;
    if (error && !detail && readOnly && !student) {
        content = <p role="alert" className="text-[13px] text-error dark:text-error-container">{error}</p>;
    } else if (readOnly) {
        const profile = detail ?? student;
        const teachingProfile = Boolean(profile?.is_read_only);
        content = (
            <div className="flex flex-col gap-4">
                {error && <p role="alert" className="text-[13px] text-error dark:text-error-container">{error}</p>}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {field('full_name', t.students.columns.name, profile?.full_name)}
                    {!teachingProfile && field('student_number', t.students.columns.number, profile?.student_number || '—')}
                    {field('birth_date', t.students.form.birthDate, profile?.birth_date || '—')}
                    {field('room_name', t.students.columns.room, profile?.room_name || t.students.unassigned)}
                    {!teachingProfile && field('enrollment_date', t.students.form.enrollmentDate, profile?.enrollment_date || '—')}
                    {!teachingProfile && field('status', t.students.columns.status, t.students.statuses[profile?.status] ?? profile?.status)}
                </div>
                {!teachingProfile && profile?.guardian_name && field('guardian_name', t.students.form.guardianName, profile.guardian_name)}
                {!teachingProfile && profile?.guardian_phone && field('guardian_phone', t.students.form.guardianPhone, profile.guardian_phone)}
                {!teachingProfile && profile?.guardian_email && field('guardian_email', t.students.form.guardianEmail, profile.guardian_email)}
                <div className="flex flex-col gap-1.5">
                    <span className={labelClass}>{t.students.form.notes}</span>
                    <p className="min-h-16 whitespace-pre-wrap rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[13px] leading-5 text-on-surface-variant dark:border-[#384d43] dark:bg-[#182620] dark:text-[#c2d1c8]">
                        {profile?.notes || '—'}
                    </p>
                </div>
            </div>
        );
    } else if (error && !loading) {
        content = <p role="alert" className="text-[13px] text-error dark:text-error-container">{error}</p>;
    } else if (!canEditYear && !loading) {
        content = <p role="alert" className="text-[13px] text-error dark:text-error-container">{t.students.form.closedYearError}</p>;
    } else {
        content = (
            <form id={`${formId}-form`} className="flex flex-col gap-4" onSubmit={handleSubmit} aria-busy={loading}>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5 sm:col-span-2">
                        <label htmlFor={`${formId}-full_name`} className={labelClass}>{t.students.columns.name}</label>
                        <input id={`${formId}-full_name`} name="full_name" required maxLength={120} value={form.full_name} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-student_number`} className={labelClass}>{t.students.columns.number}</label>
                        <input id={`${formId}-student_number`} name="student_number" maxLength={30} value={form.student_number} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-birth_date`} className={labelClass}>{t.students.form.birthDate}</label>
                        <input id={`${formId}-birth_date`} name="birth_date" type="date" value={form.birth_date} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-guardian_name`} className={labelClass}>{t.students.form.guardianName}</label>
                        <input id={`${formId}-guardian_name`} name="guardian_name" maxLength={120} value={form.guardian_name} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-guardian_phone`} className={labelClass}>{t.students.form.guardianPhone}</label>
                        <input id={`${formId}-guardian_phone`} name="guardian_phone" type="tel" maxLength={30} value={form.guardian_phone} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-guardian_email`} className={labelClass}>{t.students.form.guardianEmail}</label>
                        <input id={`${formId}-guardian_email`} name="guardian_email" type="email" maxLength={190} value={form.guardian_email} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-room_id`} className={labelClass}>{t.students.columns.room}</label>
                        <select id={`${formId}-room_id`} name="room_id" value={form.room_id} onChange={handleChange} className={inputClass}>
                            <option value="">{t.students.unassigned}</option>
                            {rooms.map((room) => <option key={room.id} value={room.id}>{room.name}</option>)}
                        </select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-enrollment_date`} className={labelClass}>{t.students.form.enrollmentDate}</label>
                        <input id={`${formId}-enrollment_date`} name="enrollment_date" type="date" required value={form.enrollment_date} onChange={handleChange} className={inputClass} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-status`} className={labelClass}>{t.students.columns.status}</label>
                        <select id={`${formId}-status`} name="status" value={form.status} onChange={handleChange} className={inputClass}>
                            {['active', 'paused', 'withdrawn'].map((value) => (
                                <option key={value} value={value}>{t.students.statuses[value]}</option>
                            ))}
                        </select>
                    </div>
                    <div className="flex flex-col gap-1.5 sm:col-span-2">
                        <label htmlFor={`${formId}-notes`} className={labelClass}>{t.students.form.notes}</label>
                        <textarea id={`${formId}-notes`} name="notes" rows={3} value={form.notes} onChange={handleChange} className={`${inputClass} resize-y`} />
                    </div>
                </div>
                {error && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{error}</p>}
            </form>
        );
    }

    const footer = readOnly
        ? (
            <button type="button" onClick={onClose} className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high dark:bg-[#2c3d36] dark:text-[#ecf3ef] dark:hover:bg-[#344a41]">
                {t.students.form.close}
            </button>
        )
        : (
            <>
                <button type="button" disabled={saving} onClick={onClose} className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:opacity-60 dark:bg-[#2c3d36] dark:text-[#ecf3ef] dark:hover:bg-[#344a41]">
                    {t.students.form.cancel}
                </button>
                <button type="submit" form={`${formId}-form`} disabled={saving || loading || !canEditYear} className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-[#2e5e4e] dark:text-[#f0fcf3]">
                    <Icon name={saving ? 'progress_activity' : 'save'} className={`text-[18px] ${saving ? 'animate-spin' : ''}`} />
                    {saving ? t.students.form.saving : t.students.form.save}
                </button>
            </>
        );

    return (
        <Modal
            open={open}
            title={title}
            icon={readOnly ? 'person' : isEditing ? 'edit' : 'person_add'}
            onClose={onClose}
            footer={footer}
            height="min(760px, calc(100vh - 1.5rem))"
            isRTL={isRTL}
            closeLabel={t.students.form.close}
        >
            <span role="status" className="sr-only">{loading ? t.students.form.loading : ''}</span>
            {content}
            <ConfirmDeleteModal
                open={Boolean(confirmingStatus)}
                title={confirmingStatus === 'paused' ? t.students.archive.pauseTitle : t.students.archive.withdrawTitle}
                description={confirmingStatus === 'paused'
                    ? t.students.archive.pauseMessage(form.full_name || student?.full_name || t.students.archive.studentFallback)
                    : t.students.archive.withdrawMessage(form.full_name || student?.full_name || t.students.archive.studentFallback)}
                confirmLabel={confirmingStatus === 'paused' ? t.students.archive.confirmPause : t.students.archive.confirmWithdraw}
                cancelLabel={t.students.archive.cancel}
                onConfirm={() => {
                    if (!confirmingStatus) return;
                    setForm((current) => ({ ...current, status: confirmingStatus }));
                    setConfirmingStatus(null);
                    setError(null);
                }}
                onClose={() => setConfirmingStatus(null)}
                isRTL={isRTL}
            />
        </Modal>
    );
}
