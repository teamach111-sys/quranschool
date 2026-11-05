import React, { useCallback, useEffect, useMemo, useState } from 'react';
import SelectInput from '../../../components/SelectInput';
import Icon from '../../../components/Icon';
import MonthInput from '../../../components/MonthInput';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';

function currentMonth() {
    const today = new Date();
    const month = String(today.getMonth() + 1).padStart(2, '0');

    return `${today.getFullYear()}-${month}`;
}

function formatAmount(amount, currency, locale) {
    return `${new Intl.NumberFormat(locale, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(amount))} ${currency}`;
}

export default function PaymentPeriodGeneratorModal({ yearId, onClose, onGenerated }) {
    const { isRTL, lang, t } = useLanguage();
    const [options, setOptions] = useState(null);
    const [selectedIds, setSelectedIds] = useState([]);
    const [fromMonth, setFromMonth] = useState('');
    const [toMonth, setToMonth] = useState('');
    const [roomFilter, setRoomFilter] = useState('');
    const [levelFilter, setLevelFilter] = useState('');
    const [preview, setPreview] = useState(null);
    const [step, setStep] = useState('select');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        const controller = new AbortController();

        apiFetch(`/payments/generation-options?year_id=${encodeURIComponent(yearId)}`, {
            signal: controller.signal,
        })
            .then(async (response) => {
                const data = await response.json();
                if (!response.ok) throw new Error('options');

                if (controller.signal.aborted) return;
                const firstMonth = data.start_date.slice(0, 7);
                const lastMonth = data.end_date.slice(0, 7);
                const todayMonth = currentMonth();
                const defaultMonth = todayMonth < firstMonth
                    ? firstMonth
                    : todayMonth > lastMonth
                        ? lastMonth
                        : todayMonth;

                setOptions(data);
                setSelectedIds(data.students.map((student) => String(student.id)));
                setFromMonth(defaultMonth);
                setToMonth(defaultMonth);
            })
            .catch((requestError) => {
                if (requestError.name !== 'AbortError' && !controller.signal.aborted) {
                    setError(t.payments.generationOptionsError);
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
    }, [t.payments.generationOptionsError, yearId]);

    const locale = lang === 'ar' ? 'ar-MA-u-nu-latn' : 'fr-FR';
    const selectedSet = useMemo(() => new Set(selectedIds), [selectedIds]);
    const visibleStudents = useMemo(() => (options?.students ?? []).filter((student) => (
        (!roomFilter || String(student.room_id ?? '') === roomFilter)
        && (!levelFilter || student.level === levelFilter)
    )), [levelFilter, options, roomFilter]);
    const allVisibleStudentsSelected = Boolean(
        visibleStudents.length && visibleStudents.every((student) => selectedSet.has(String(student.id))),
    );

    const requestBody = () => ({
        year_id: yearId,
        from_month: fromMonth,
        to_month: toMonth,
        student_ids: selectedIds.map(Number),
    });

    const handlePreview = async () => {
        setError('');
        setSaving(true);

        try {
            const response = await apiFetch('/payments/generation-preview', {
                method: 'POST',
                body: JSON.stringify(requestBody()),
            });
            const data = await response.json();

            if (!response.ok) {
                if (response.status === 403) setError(t.payments.permissionError);
                else if (data?.errors?.monthly_fee) setError(t.payments.monthlyFeeMissing);
                else if (data?.errors?.year_id) setError(t.payments.closedYearError);
                else setError(t.payments.previewError);
                return;
            }

            setPreview(data);
            setStep('review');
        } catch {
            setError(t.payments.previewError);
        } finally {
            setSaving(false);
        }
    };

    const handleGenerate = async () => {
        setError('');
        setSaving(true);

        try {
            const response = await apiFetch('/payments/generate', {
                method: 'POST',
                body: JSON.stringify({
                    ...requestBody(),
                    preview_token: preview.preview_token,
                }),
            });
            const data = await response.json();

            if (!response.ok) {
                if (response.status === 403) setError(t.payments.permissionError);
                else if (data?.errors?.monthly_fee) setError(t.payments.monthlyFeeMissing);
                else if (data?.errors?.year_id) setError(t.payments.closedYearError);
                else if (data?.errors?.preview_token) setError(t.payments.previewOutdated);
                else setError(t.payments.generationError);
                return;
            }

            setPreview(data);
            setStep('complete');
        } catch {
            setError(t.payments.generationError);
        } finally {
            setSaving(false);
        }
    };

    const handleStudentToggle = (studentId) => {
        setSelectedIds((current) => current.includes(String(studentId))
            ? current.filter((id) => id !== String(studentId))
            : [...current, String(studentId)]);
        setPreview(null);
        setError('');
    };

    const handleSelectAll = () => {
        const visibleIds = new Set(visibleStudents.map((student) => String(student.id)));
        setSelectedIds((current) => allVisibleStudentsSelected
            ? current.filter((id) => !visibleIds.has(id))
            : [...new Set([...current, ...visibleIds])]);
        setPreview(null);
        setError('');
    };

    const handleClose = useCallback(async () => {
        if (saving) return;

        onClose();
        if (step === 'complete') await onGenerated();
    }, [onClose, onGenerated, saving, step]);

    const canPreview = Boolean(
        options
        && selectedIds.length > 0
        && fromMonth
        && toMonth
        && fromMonth <= toMonth
        && options.monthly_fee !== null,
    );
    const summaryCards = preview ? [
        { label: t.payments.toCreate, value: preview.create_count },
        { label: t.payments.alreadyExists, value: preview.existing_count },
        { label: t.payments.overlapSkipped, value: preview.overlap_count },
        { label: t.payments.beforeEnrollment, value: preview.not_enrolled_count },
    ] : [];
    const footer = step === 'complete' ? (
        <button
            type="button"
            onClick={handleClose}
            className="min-h-11 flex-1 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-primary-container dark:text-on-primary"
        >
            {t.payments.done}
        </button>
    ) : (
        <>
            <button
                type="button"
                onClick={step === 'review' ? () => {
                    setStep('select');
                    setError('');
                } : handleClose}
                disabled={saving}
                className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:opacity-50 dark:bg-inverse-surface dark:text-inverse-on-surface"
            >
                {step === 'review' ? t.payments.back : t.payments.cancel}
            </button>
            <button
                type="button"
                onClick={step === 'review' ? handleGenerate : handlePreview}
                disabled={saving || (step === 'review' ? preview?.create_count === 0 : !canPreview)}
                className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-primary-container dark:text-on-primary"
            >
                {saving && <Icon name="progress_activity" className="animate-spin text-[17px]" />}
                {saving
                    ? step === 'review' ? t.payments.creatingPeriods : t.payments.loading
                    : step === 'review' ? t.payments.createPeriods : t.payments.preview}
            </button>
        </>
    );

    return (
        <Modal
            open
            title={step === 'review' ? t.payments.previewTitle : t.payments.generateTitle}
            icon="payments"
            onClose={handleClose}
            loading={loading || saving}
            isRTL={isRTL}
            closeLabel={t.payments.cancel}
            maxWidth="max-w-[760px]"
            footer={footer}
        >
            {loading ? (
                <p role="status" className="text-[13px] text-on-surface-variant dark:text-tertiary-fixed-dim">
                    {t.payments.generationOptionsLoading}
                </p>
            ) : error && !options ? (
                <p role="alert" className="text-[13px] text-error dark:text-error-container">{error}</p>
            ) : step === 'complete' ? (
                <div className="flex flex-col gap-4">
                    <p role="status" className="text-[14px] font-semibold text-primary dark:text-primary-fixed-dim">
                        {t.payments.generationComplete(preview.created_count)}
                    </p>
                    <p className="text-[13px] text-on-surface-variant dark:text-tertiary-fixed-dim">
                        {t.payments.totalToCreate}: {formatAmount(preview.total_amount, preview.currency, locale)}
                    </p>
                </div>
            ) : step === 'review' && preview ? (
                <div className="flex flex-col gap-4">
                    <p className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">
                        {preview.from_month} – {preview.to_month} · {t.payments.periodCount(preview.period_count)} · {t.payments.monthlyFeeLabel}: {formatAmount(preview.monthly_fee, preview.currency, locale)}
                    </p>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {summaryCards.map((card) => (
                            <div key={card.label} className="rounded-lg bg-surface-container-low p-3 dark:bg-inverse-surface">
                                <p className="text-[11px] leading-4 text-on-surface-variant dark:text-tertiary-fixed-dim">{card.label}</p>
                                <p className="text-[16px] font-semibold text-on-surface dark:text-inverse-on-surface">{card.value}</p>
                            </div>
                        ))}
                    </div>
                    <div className="flex items-center justify-between gap-3 rounded-lg border border-outline-variant px-3 py-3 dark:border-outline-variant">
                        <span className="text-[13px] font-semibold text-on-surface dark:text-inverse-on-surface">{t.payments.totalToCreate}</span>
                        <span className="text-[15px] font-bold text-on-surface dark:text-inverse-on-surface">
                            {formatAmount(preview.total_amount, preview.currency, locale)}
                        </span>
                    </div>
                    {preview.create_count === 0 && (
                        <p role="status" className="text-[13px] text-on-surface-variant dark:text-tertiary-fixed-dim">
                            {t.payments.noPeriodsToCreate}
                        </p>
                    )}
                    <div className="flex flex-col gap-2">
                        <h3 className="text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">{t.payments.studentPeriods}</h3>
                        <ul className="flex max-h-60 flex-col gap-2 overflow-y-auto rounded-lg bg-surface-container-low p-3 dark:bg-inverse-surface">
                            {preview.students.map((student) => (
                                <li key={student.id} className="flex flex-col gap-1 border-b border-outline-variant pb-2 last:border-b-0 last:pb-0 dark:border-[#384d43]">
                                    <span className="text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">{student.name}</span>
                                    <span className="text-[11px] leading-4 text-on-surface-variant dark:text-tertiary-fixed-dim">
                                        {t.payments.toCreate}: {student.create_count} · {t.payments.alreadyExists}: {student.existing_count} · {t.payments.overlapSkipped}: {student.overlap_count} · {t.payments.beforeEnrollment}: {student.not_enrolled_count}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                    {error && <p role="alert" className="text-[13px] text-error dark:text-error-container">{error}</p>}
                </div>
            ) : (
                <div className="flex flex-col gap-4">
                    <p className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">
                        {t.payments.generationDescription}
                    </p>
                    {options.monthly_fee === null && (
                        <p role="alert" className="rounded-lg bg-error-container px-3 py-2 text-[12px] text-on-error-container">
                            {t.payments.monthlyFeeMissing}
                        </p>
                    )}
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                            {t.payments.fromMonth}
                            <MonthInput
                                name="from_month"
                                value={fromMonth}
                                min={options.start_date.slice(0, 7)}
                                max={options.end_date.slice(0, 7)}
                                onChange={({ target }) => {
                                    setFromMonth(target.value);
                                    setPreview(null);
                                    setError('');
                                }}
                            />
                        </label>
                        <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                            {t.payments.toMonth}
                            <MonthInput
                                name="to_month"
                                value={toMonth}
                                min={options.start_date.slice(0, 7)}
                                max={options.end_date.slice(0, 7)}
                                onChange={({ target }) => {
                                    setToMonth(target.value);
                                    setPreview(null);
                                    setError('');
                                }}
                            />
                        </label>
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                            {t.payments.roomScope}
                            <SelectInput
                                value={roomFilter}
                                onChange={(event) => setRoomFilter(event.target.value)}
                                className="min-h-11 rounded-lg border border-outline-variant bg-surface-container-low px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:bg-inverse-surface dark:text-inverse-on-surface"
                            >
                                <option value="">{t.payments.allRooms}</option>
                                {options.rooms.map((room) => (
                                    <option key={room.id} value={room.id}>{room.name}</option>
                                ))}
                            </SelectInput>
                        </label>
                        <label className="flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                            {t.payments.levelScope}
                            <SelectInput
                                value={levelFilter}
                                onChange={(event) => setLevelFilter(event.target.value)}
                                className="min-h-11 rounded-lg border border-outline-variant bg-surface-container-low px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:bg-inverse-surface dark:text-inverse-on-surface"
                            >
                                <option value="">{t.payments.allLevels}</option>
                                {options.levels.map((level) => (
                                    <option key={level} value={level}>{t.rooms.levels[level]}</option>
                                ))}
                            </SelectInput>
                        </label>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <label className="inline-flex min-h-11 items-center gap-2 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                            <input
                                type="checkbox"
                                checked={allVisibleStudentsSelected}
                                disabled={visibleStudents.length === 0}
                                onChange={handleSelectAll}
                                className="size-4 accent-primary"
                            />
                            {t.payments.selectVisibleStudents}
                        </label>
                        <span className="text-[11px] text-on-surface-variant dark:text-tertiary-fixed-dim">
                            {t.payments.selectedStudents(selectedIds.length)}
                        </span>
                        <button
                            type="button"
                            onClick={() => {
                                setSelectedIds([]);
                                setPreview(null);
                                setError('');
                            }}
                            disabled={selectedIds.length === 0}
                            className="min-h-11 px-2 text-[12px] font-semibold text-primary underline-offset-2 hover:underline disabled:cursor-not-allowed disabled:opacity-50 dark:text-primary-fixed-dim"
                        >
                            {t.payments.clearSelection}
                        </button>
                    </div>
                    {visibleStudents.length > 0 ? (
                        <ul className="flex max-h-60 flex-col gap-1 overflow-y-auto rounded-lg border border-outline-variant p-2 dark:border-outline-variant">
                            {visibleStudents.map((student) => {
                                const id = String(student.id);
                                return (
                                    <li key={student.id}>
                                        <label className="flex min-h-11 items-center gap-2 rounded-md px-2 text-[12px] text-on-surface transition-colors hover:bg-surface-container-low dark:text-inverse-on-surface dark:hover:bg-inverse-surface">
                                            <input
                                                type="checkbox"
                                                checked={selectedSet.has(id)}
                                                onChange={() => handleStudentToggle(student.id)}
                                                className="size-4 accent-primary"
                                            />
                                            <span>{student.name}</span>
                                            {student.room_name && (
                                                <span className="text-on-surface-variant dark:text-tertiary-fixed-dim">· {student.room_name}</span>
                                            )}
                                            {student.student_number && (
                                                <span className="text-on-surface-variant dark:text-tertiary-fixed-dim">({student.student_number})</span>
                                            )}
                                        </label>
                                    </li>
                                );
                            })}
                        </ul>
                    ) : (
                        <p role="status" className="text-[13px] text-on-surface-variant dark:text-tertiary-fixed-dim">{t.students.empty}</p>
                    )}
                    {error && <p role="alert" className="text-[13px] text-error dark:text-error-container">{error}</p>}
                </div>
            )}
        </Modal>
    );
}
