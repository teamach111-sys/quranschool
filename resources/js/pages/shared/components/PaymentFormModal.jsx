import React, { useCallback, useEffect, useId, useState } from 'react';
import SelectInput from '../../../components/SelectInput';
import Icon from '../../../components/Icon';
import DateInput from '../../../components/DateInput';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';

function initialValues(payment, canManage, students) {
    const defaultStatus = !canManage && ['due', 'late'].includes(payment?.status)
        ? 'paid'
        : payment?.status ?? 'due';
    const selectedStudent = students.find((student) => String(student.id) === String(payment?.student_id));

    return {
        student_id: payment ? String(payment.student_id) : '',
        period_start: payment?.period_start ?? '',
        period_end: payment?.period_end ?? '',
        amount: payment?.amount ?? '',
        status: defaultStatus,
        payment_method: payment?.payment_method ?? selectedStudent?.last_payment_method ?? '',
        notes: payment?.notes ?? '',
    };
}

export default function PaymentFormModal({
    payment = null,
    yearId,
    currency,
    students,
    canManage,
    onClose,
    onSaved,
}) {
    const { isRTL, t } = useLanguage();
    const [form, setForm] = useState(() => initialValues(payment, canManage, students));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const formId = useId();
    const isEditing = Boolean(payment?.id);
    const handleClose = useCallback(() => {
        if (!saving) onClose();
    }, [onClose, saving]);

    useEffect(() => {
        setForm(initialValues(payment, canManage, students));
        setError('');
    }, [payment, canManage, students]);

    const handleChange = (event) => {
        const { name, value } = event.target;
        const selectedStudent = name === 'student_id'
            ? students.find((student) => String(student.id) === value)
            : null;
        setForm((current) => ({
            ...current,
            [name]: value,
            ...(name === 'student_id'
                ? { payment_method: selectedStudent?.last_payment_method ?? '' }
                : {}),
        }));
        setError('');
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        setSaving(true);
        setError('');

        const payload = canManage
            ? {
                year_id: yearId,
                student_id: Number(form.student_id),
                period_start: form.period_start,
                period_end: form.period_end,
                amount: form.amount,
                status: form.status,
                ...(form.status === 'paid' ? { payment_method: form.payment_method } : {}),
                notes: form.notes || null,
            }
            : {
                year_id: yearId,
                status: form.status,
                ...(form.status === 'paid' ? { payment_method: form.payment_method } : {}),
                notes: form.notes || null,
            };

        try {
            const response = await apiFetch(
                isEditing ? `/payments/${payment.id}` : '/payments',
                {
                    method: isEditing ? 'PUT' : 'POST',
                    body: JSON.stringify(payload),
                },
            );
            const data = await response.json();

            if (!response.ok) {
                if (response.status === 403) setError(t.payments.permissionError);
                else if (response.status === 404) setError(t.payments.unavailableError);
                else if (data?.errors?.year_id) setError(t.payments.closedYearError);
                else if (data?.errors?.period_start || data?.errors?.period_end) setError(t.payments.periodError);
                else if (data?.errors?.amount) setError(t.payments.amountError);
                else if (data?.errors?.paid_at || data?.errors?.payment_method || data?.errors?.reference) {
                    setError(t.payments.paymentDetailsError);
                } else if (data?.errors?.notes && form.status === 'waived') setError(t.payments.noteError);
                else setError(t.payments.saveError);
                return;
            }

            await onSaved();
        } catch {
            setError(t.payments.saveError);
        } finally {
            setSaving(false);
        }
    };

    const inputClass = 'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] leading-5 text-on-surface outline-none transition-colors focus:border-primary focus:bg-surface-container-lowest focus:ring-1 focus:ring-primary dark:border-outline-variant dark:bg-inverse-surface dark:text-inverse-on-surface dark:focus:bg-inverse-surface';
    const labelClass = 'flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface';
    const showPaymentDetails = form.status === 'paid';
    const title = canManage
        ? isEditing ? t.payments.editTitle : t.payments.createTitle
        : t.payments.recordTitle;
    const statusOptions = canManage
        ? ['due', 'paid', 'late', 'waived']
        : ['paid', 'late', 'waived'];
    const selectedStudent = students.find((student) => String(student.id) === form.student_id);

    return (
        <Modal
            open
            title={title}
            icon="payments"
            onClose={handleClose}
            isRTL={isRTL}
            closeLabel={t.payments.cancel}
            maxWidth="max-w-[760px]"
            footer={(
                <>
                    <button
                        type="button"
                        onClick={handleClose}
                        disabled={saving}
                        className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:opacity-50 dark:bg-inverse-surface dark:text-inverse-on-surface"
                    >
                        {t.payments.cancel}
                    </button>
                    <button
                        type="submit"
                        form={`${formId}-form`}
                        disabled={saving}
                        className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-wait disabled:opacity-60 dark:bg-primary-container dark:text-on-primary"
                    >
                        {saving && <Icon name="progress_activity" className="animate-spin text-[17px]" />}
                        {saving ? t.payments.saving : t.payments.save}
                    </button>
                </>
            )}
        >
            <form id={`${formId}-form`} onSubmit={handleSubmit} className="flex flex-col gap-5">
                {!canManage && (
                    <div className="grid grid-cols-2 gap-3 rounded-lg bg-surface-container-low p-3 text-[12px] dark:bg-[#182620]">
                        <div>
                            <p className="text-on-surface-variant dark:text-[#9bb2a8]">{t.payments.columns.student}</p>
                            <p className="font-semibold text-on-surface dark:text-inverse-on-surface">{payment.student_name}</p>
                        </div>
                        <div>
                            <p className="text-on-surface-variant dark:text-[#9bb2a8]">{t.payments.columns.period}</p>
                            <p className="font-semibold text-on-surface dark:text-inverse-on-surface">{payment.period_start} – {payment.period_end}</p>
                        </div>
                        <div>
                            <p className="text-on-surface-variant dark:text-[#9bb2a8]">{t.payments.columns.amount}</p>
                            <p className="font-semibold text-on-surface dark:text-inverse-on-surface">{payment.amount} {payment.currency}</p>
                        </div>
                    </div>
                )}
                {canManage && (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label className={labelClass}>
                            {t.payments.filters.student}
                            <SelectInput
                                required
                                name="student_id"
                                value={form.student_id}
                                onChange={handleChange}
                                className={inputClass}
                            >
                                <option value="">{t.payments.filters.allStudents}</option>
                                {students.map((student) => (
                                    <option key={student.id} value={student.id}>
                                        {student.name}{student.student_number ? ` (${student.student_number})` : ''}
                                    </option>
                                ))}
                            </SelectInput>
                        </label>
                        <label className={labelClass}>
                            {t.payments.columns.amount} ({currency})
                            <input
                                required
                                type="number"
                                name="amount"
                                min="0"
                                max="99999999.99"
                                step="0.01"
                                value={form.amount}
                                onChange={handleChange}
                                className={inputClass}
                            />
                        </label>
                        <label className={labelClass}>
                            {t.payments.periodStart}
                            <DateInput
                                required
                                name="period_start"
                                value={form.period_start}
                                onChange={handleChange}
                                className={inputClass}
                            />
                        </label>
                        <label className={labelClass}>
                            {t.payments.periodEnd}
                            <DateInput
                                required
                                name="period_end"
                                min={form.period_start || undefined}
                                value={form.period_end}
                                onChange={handleChange}
                                className={inputClass}
                            />
                        </label>
                        <label className={labelClass}>
                            {t.payments.columns.status}
                            <SelectInput required name="status" value={form.status} onChange={handleChange} className={inputClass}>
                                {statusOptions.map((status) => (
                                    <option key={status} value={status}>{t.payments.statuses[status]}</option>
                                ))}
                            </SelectInput>
                        </label>
                    </div>
                )}
                {!canManage && (
                    <label className={labelClass}>
                        {t.payments.columns.status}
                        <SelectInput required name="status" value={form.status} onChange={handleChange} className={inputClass}>
                            {statusOptions.map((status) => (
                                <option key={status} value={status}>{t.payments.statuses[status]}</option>
                            ))}
                        </SelectInput>
                    </label>
                )}
                {showPaymentDetails && (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className={labelClass}>
                            <span>{t.payments.paidAt}</span>
                            <p className="rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 font-normal text-on-surface-variant dark:bg-inverse-surface dark:text-inverse-on-surface">
                                {t.payments.receiptAutomatic}
                            </p>
                        </div>
                        <label className={labelClass}>
                            {t.payments.paymentMethod}
                            {selectedStudent?.last_payment_method && (
                                <span className="font-normal text-on-surface-variant dark:text-[#9bb2a8]">
                                    {t.payments.lastUsedMethod}: {selectedStudent.last_payment_method}
                                </span>
                            )}
                            <input
                                required
                                maxLength="40"
                                name="payment_method"
                                value={form.payment_method}
                                onChange={handleChange}
                                className={inputClass}
                            />
                        </label>
                        <div className={labelClass}>
                            <span>{t.payments.columns.reference}</span>
                            <p className="rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 font-normal text-on-surface-variant dark:bg-inverse-surface dark:text-inverse-on-surface">
                                {payment?.status === 'paid' && payment.reference
                                    ? payment.reference
                                    : t.payments.referenceGenerated}
                            </p>
                        </div>
                    </div>
                )}
                <label className={labelClass}>
                    {t.payments.notes}
                    <textarea
                        required={form.status === 'waived'}
                        maxLength="500"
                        name="notes"
                        value={form.notes}
                        onChange={handleChange}
                        rows={3}
                        className={inputClass}
                    />
                </label>
                {error && <p role="alert" className="text-[12px] leading-5 text-error dark:text-error-container">{error}</p>}
            </form>
        </Modal>
    );
}
