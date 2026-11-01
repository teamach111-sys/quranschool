import React, { useCallback, useEffect, useId, useState } from 'react';
import Icon from '../../../components/Icon';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';

function localDateTime() {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
}

function initialValues(payment, currency, canManage) {
    const defaultStatus = !canManage && payment?.status === 'due'
        ? 'paid'
        : payment?.status ?? 'due';

    return {
        student_id: payment ? String(payment.student_id) : '',
        period_start: payment?.period_start ?? '',
        period_end: payment?.period_end ?? '',
        amount: payment?.amount ?? '',
        currency: payment?.currency ?? currency,
        status: defaultStatus,
        paid_at: payment?.paid_at?.replace(' ', 'T').slice(0, 16)
            || (!canManage && defaultStatus === 'paid' ? localDateTime() : ''),
        payment_method: payment?.payment_method ?? '',
        reference: payment?.reference ?? '',
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
    const [form, setForm] = useState(() => initialValues(payment, currency, canManage));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const formId = useId();
    const isEditing = Boolean(payment?.id);
    const handleClose = useCallback(() => {
        if (!saving) onClose();
    }, [onClose, saving]);

    useEffect(() => {
        setForm(initialValues(payment, currency, canManage));
        setError('');
    }, [payment, currency, canManage]);

    const handleChange = (event) => {
        const { name, value } = event.target;
        setForm((current) => ({
            ...current,
            [name]: value,
            ...(name === 'status' && value === 'paid' && !current.paid_at
                ? { paid_at: localDateTime() }
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
                currency: form.currency.toUpperCase(),
                status: form.status,
                paid_at: form.status === 'paid' ? form.paid_at.replace('T', ' ') : null,
                payment_method: form.payment_method || null,
                reference: form.reference || null,
                notes: form.notes || null,
            }
            : {
                year_id: yearId,
                status: form.status,
                paid_at: form.status === 'paid' ? form.paid_at.replace('T', ' ') : null,
                payment_method: form.status === 'paid' ? form.payment_method : null,
                reference: form.status === 'paid' ? form.reference : null,
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
                } else if (data?.errors?.notes) setError(t.payments.noteError);
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
                            <select
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
                            </select>
                        </label>
                        <label className={labelClass}>
                            {t.payments.columns.amount}
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
                            <input
                                required
                                type="date"
                                name="period_start"
                                value={form.period_start}
                                onChange={handleChange}
                                className={inputClass}
                            />
                        </label>
                        <label className={labelClass}>
                            {t.payments.periodEnd}
                            <input
                                required
                                type="date"
                                name="period_end"
                                min={form.period_start || undefined}
                                value={form.period_end}
                                onChange={handleChange}
                                className={inputClass}
                            />
                        </label>
                        <label className={labelClass}>
                            {t.payments.currency}
                            <input
                                required
                                minLength="3"
                                maxLength="3"
                                name="currency"
                                value={form.currency}
                                onChange={handleChange}
                                className={inputClass}
                            />
                        </label>
                        <label className={labelClass}>
                            {t.payments.columns.status}
                            <select required name="status" value={form.status} onChange={handleChange} className={inputClass}>
                                {statusOptions.map((status) => (
                                    <option key={status} value={status}>{t.payments.statuses[status]}</option>
                                ))}
                            </select>
                        </label>
                    </div>
                )}
                {!canManage && (
                    <label className={labelClass}>
                        {t.payments.columns.status}
                        <select required name="status" value={form.status} onChange={handleChange} className={inputClass}>
                            {statusOptions.map((status) => (
                                <option key={status} value={status}>{t.payments.statuses[status]}</option>
                            ))}
                        </select>
                    </label>
                )}
                {showPaymentDetails && (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label className={labelClass}>
                            {t.payments.paidAt}
                            <input
                                required
                                type="datetime-local"
                                name="paid_at"
                                value={form.paid_at}
                                onChange={handleChange}
                                className={inputClass}
                            />
                        </label>
                        <label className={labelClass}>
                            {t.payments.paymentMethod}
                            <input
                                required={!canManage}
                                maxLength="40"
                                name="payment_method"
                                value={form.payment_method}
                                onChange={handleChange}
                                className={inputClass}
                            />
                        </label>
                        <label className={labelClass}>
                            {t.payments.columns.reference}
                            <input
                                required={!canManage}
                                maxLength="100"
                                name="reference"
                                value={form.reference}
                                onChange={handleChange}
                                className={inputClass}
                            />
                        </label>
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
