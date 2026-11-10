import React, { useEffect, useRef, useState } from 'react';
import Icon from '../../components/Icon';
import { Panel, SectionHeader } from '../../components/DashboardPanels';
import { useToast } from '../../components/ToastProvider';
import { apiFetch } from '../../lib/api';
import { useLanguage } from '../../hooks/useLanguage';

const emptySchool = {
    name_fr: '',
    name_ar: '',
    address: '',
    phone: '',
    email: '',
    currency: 'MAD',
    monthly_fee: '',
    payment_methods: [],
    student_number_prefix: '',
    student_number_use_dash: true,
};

const inputClassName = 'w-full rounded-md border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] leading-5 text-on-surface outline-none transition-colors focus:border-primary focus:bg-surface-container-lowest dark:border-[#3a3835] dark:bg-inverse-surface dark:text-inverse-on-surface';

const hintClass = 'text-[12px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim';

export default function Ecole() {
    const { lang, t } = useLanguage();
    const toast = useToast();
    const [school, setSchool] = useState(emptySchool);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [loadError, setLoadError] = useState(false);
    const [saveError, setSaveError] = useState(false);
    const [saved, setSaved] = useState(false);
    const [newPaymentMethod, setNewPaymentMethod] = useState('');
    const [downloadingTemplate, setDownloadingTemplate] = useState(false);
    const [exporting, setExporting] = useState(false);
    const [importing, setImporting] = useState(false);
    const [importConfirmOpen, setImportConfirmOpen] = useState(false);
    const fileInputRef = useRef(null);

    useEffect(() => {
        let cancelled = false;

        apiFetch('/admin/school-settings')
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Failed to load school settings'))))
            .then((data) => {
                if (!cancelled) setSchool({ ...emptySchool, ...data.school });
            })
            .catch(() => {
                if (!cancelled) setLoadError(true);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => { cancelled = true; };
    }, []);

    const handleChange = (event) => {
        const { name, value } = event.target;
        setSchool((current) => ({
            ...current,
            [name]: name === 'currency' ? value.toUpperCase() : value,
        }));
        setSaved(false);
        setSaveError(false);
    };

    const handleToggleNumberDash = (event) => {
        const shouldRemoveDash = event.target.checked;
        setSchool((current) => ({
            ...current,
            student_number_use_dash: !shouldRemoveDash,
        }));
        setSaved(false);
        setSaveError(false);
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        setSaving(true);
        setSaveError(false);
        setSaved(false);

        try {
            const response = await apiFetch('/admin/school-settings', {
                method: 'PUT',
                body: JSON.stringify(school),
            });
            const data = await response.json();

            if (!response.ok) throw new Error('Failed to save school settings');

            setSchool({ ...emptySchool, ...data.school });
            setSaved(true);
        } catch {
            setSaveError(true);
        } finally {
            setSaving(false);
        }
    };

    const downloadReceiptTemplate = async () => {
        if (downloadingTemplate) return;
        setDownloadingTemplate(true);

        try {
            const response = await apiFetch(`/admin/school-settings/receipt-template?lang=${lang}`, {
                headers: { Accept: 'application/pdf' },
            });
            if (!response.ok || !response.headers.get('Content-Type')?.toLowerCase().includes('application/pdf')) {
                throw new Error('Failed to download receipt template');
            }

            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'payment-receipt-template.pdf';
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast.success(t.schoolSettings.templateDownloaded);
        } catch {
            toast.error(t.schoolSettings.templateDownloadError);
        } finally {
            setDownloadingTemplate(false);
        }
    };

    const addPaymentMethod = () => {
        const method = newPaymentMethod.trim();
        if (!method || school.payment_methods.includes(method) || school.payment_methods.length >= 20) return;

        setSchool((current) => ({ ...current, payment_methods: [...current.payment_methods, method] }));
        setNewPaymentMethod('');
        setSaved(false);
        setSaveError(false);
    };

    const removePaymentMethod = (method) => {
        if (school.payment_methods.length <= 1) return;

        setSchool((current) => ({
            ...current,
            payment_methods: current.payment_methods.filter((currentMethod) => currentMethod !== method),
        }));
        setSaved(false);
        setSaveError(false);
    };

    const handleExport = async () => {
        if (exporting) return;
        setExporting(true);

        try {
            const response = await apiFetch('/admin/school-settings/export', {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error('Failed to export data');

            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `quranschool-backup-${new Date().toISOString().slice(0, 10)}.json`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast.success(t.schoolSettings.exportSuccess);
        } catch {
            toast.error(t.schoolSettings.exportError);
        } finally {
            setExporting(false);
        }
    };

    const handleImportFile = (event) => {
        const file = event.target.files?.[0];
        if (!file) return;
        setImportConfirmOpen(false);
        performImport(file);
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    const performImport = async (file) => {
        if (importing) return;
        setImporting(true);

        try {
            const formData = new FormData();
            formData.append('backup_file', file);

            const response = await apiFetch('/admin/school-settings/import', {
                method: 'POST',
                body: formData,
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to import data');
            }

            toast.success(t.schoolSettings.importSuccess);
        } catch {
            toast.error(t.schoolSettings.importError);
        } finally {
            setImporting(false);
        }
    };

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-[#e59a92]">{t.schoolSettings.loadError}</p>;
    }

    const fields = [
        { name: 'name_fr', label: t.schoolSettings.nameFr, type: 'text', required: true },
        { name: 'name_ar', label: t.schoolSettings.nameAr, type: 'text', required: true, dir: 'rtl' },
        { name: 'address', label: t.schoolSettings.address, type: 'textarea' },
        { name: 'phone', label: t.schoolSettings.phone, type: 'tel' },
        { name: 'email', label: t.schoolSettings.email, type: 'email' },
        { name: 'currency', label: t.schoolSettings.currency, type: 'text', required: true, maxLength: 3 },
        { name: 'monthly_fee', label: t.schoolSettings.monthlyFee, type: 'number', min: 0, max: 99999999.99, step: '0.01' },
    ];

    const numberSeparator = school.student_number_use_dash === false ? '' : '-';
    const prefixPreview = school.student_number_prefix
        ? `${school.student_number_prefix.trim()}${numberSeparator}001`
        : 'QS-2026-001';

    return (
        <form className="flex w-full max-w-3xl flex-col gap-6" onSubmit={handleSubmit}>
            {/* Section 1: School Information */}
            <section className="flex flex-col gap-3">
                <SectionHeader title={t.schoolSettings.schoolInfoTitle} />
                {loading ? (
                    <p role="status" className={`text-[13px] leading-5 ${hintClass}`}>{t.schoolSettings.loading}</p>
                ) : (
                    <Panel className="p-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            {fields.map((field) => (
                                <div key={field.name} className={`flex flex-col gap-1.5 ${field.type === 'textarea' ? 'sm:col-span-2' : ''}`}>
                                    <label htmlFor={`school-${field.name}`} className="text-[12px] leading-4 font-medium text-on-surface dark:text-inverse-on-surface">
                                        {field.label}
                                    </label>
                                    {field.type === 'textarea' ? (
                                        <textarea
                                            id={`school-${field.name}`}
                                            name={field.name}
                                            rows={3}
                                            value={school[field.name] ?? ''}
                                            onChange={handleChange}
                                            className={inputClassName}
                                        />
                                    ) : (
                                        <input
                                            id={`school-${field.name}`}
                                            name={field.name}
                                            type={field.type}
                                            required={field.required}
                                            maxLength={field.maxLength}
                                            min={field.min}
                                            max={field.max}
                                            step={field.step}
                                            dir={field.dir}
                                            value={school[field.name] ?? ''}
                                            onChange={handleChange}
                                            className={inputClassName}
                                        />
                                    )}
                                </div>
                            ))}
                            <fieldset className="flex flex-col gap-3 sm:col-span-2">
                                <legend className="text-[12px] leading-4 font-medium text-on-surface dark:text-inverse-on-surface">
                                    {t.schoolSettings.paymentMethods}
                                </legend>
                                <div className="flex flex-col gap-2 sm:flex-row">
                                    <input
                                        value={newPaymentMethod}
                                        maxLength={40}
                                        aria-label={t.schoolSettings.newPaymentMethod}
                                        placeholder={t.schoolSettings.newPaymentMethod}
                                        onChange={(event) => setNewPaymentMethod(event.target.value)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') {
                                                event.preventDefault();
                                                addPaymentMethod();
                                            }
                                        }}
                                        className={inputClassName}
                                    />
                                    <button
                                        type="button"
                                        disabled={!newPaymentMethod.trim() || school.payment_methods.length >= 20}
                                        onClick={addPaymentMethod}
                                        className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-md bg-primary dark:bg-[#2f7d4f] dark:text-white dark:hover:bg-[#37955a] px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:bg-primary-container disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <Icon name="add" className="text-[18px]" />
                                        {t.schoolSettings.addPaymentMethod}
                                    </button>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {school.payment_methods.map((method) => (
                                        <span key={method} className="inline-flex items-center gap-2 rounded-full bg-surface-container px-3 py-1.5 text-[12px] text-on-surface dark:bg-inverse-surface dark:text-inverse-on-surface">
                                            {method}
                                            <button
                                                type="button"
                                                aria-label={t.schoolSettings.removePaymentMethod(method)}
                                                disabled={school.payment_methods.length <= 1}
                                                onClick={() => removePaymentMethod(method)}
                                                className="inline-flex size-5 items-center justify-center rounded-full text-on-surface-variant hover:bg-surface-container-high dark:hover:bg-[#32302e] disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                <Icon name="close" className="text-[14px]" />
                                            </button>
                                        </span>
                                    ))}
                                </div>
                            </fieldset>
                        </div>
                    </Panel>
                )}
            </section>

            {/* Section 2: Student Number Configuration */}
            <section className="flex flex-col gap-3">
                <SectionHeader title={t.schoolSettings.studentNumberTitle} />
                <Panel className="p-4">
                    <div className="flex flex-col gap-4">
                        <div className="flex flex-col gap-1.5">
                            <label htmlFor="school-student_number_prefix" className="text-[12px] leading-4 font-medium text-on-surface dark:text-inverse-on-surface">
                                {t.schoolSettings.studentNumberPrefix}
                            </label>
                            <input
                                id="school-student_number_prefix"
                                name="student_number_prefix"
                                type="text"
                                maxLength={20}
                                placeholder="N"
                                value={school.student_number_prefix ?? ''}
                                onChange={handleChange}
                                className={inputClassName}
                            />
                            <p className={hintClass}>{t.schoolSettings.studentNumberHint}</p>
                        </div>
                        <div className="flex items-center gap-2 rounded-md bg-surface-container px-3 py-2 dark:bg-[#2a2928]">
                            <Icon name="badge" className="text-[18px] text-on-surface-variant dark:text-[#918d88]" />
                            <span className="text-[13px] leading-5 text-on-surface dark:text-inverse-on-surface">
                                {t.schoolSettings.studentNumberPreview}: <span className="font-semibold tabular-nums">{prefixPreview}</span>
                            </span>
                        </div>
                        <label htmlFor="school-student_number_use_dash" className="flex cursor-pointer items-start gap-2.5">
                            <input
                                id="school-student_number_use_dash"
                                type="checkbox"
                                checked={school.student_number_use_dash === false}
                                onChange={handleToggleNumberDash}
                                className="mt-0.5 size-4 shrink-0 cursor-pointer accent-primary dark:accent-[#37955a]"
                            />
                            <span className="text-[13px] leading-5 text-on-surface dark:text-inverse-on-surface">
                                {t.schoolSettings.studentNumberRemoveDash}
                            </span>
                        </label>
                    </div>
                </Panel>
            </section>

            {/* Section 3: Data Backup */}
            <section className="flex flex-col gap-3">
                <SectionHeader title={t.schoolSettings.dataBackupTitle} />
                <p className={hintClass}>{t.schoolSettings.dataBackupHint}</p>
                <Panel className="divide-y divide-outline-variant dark:divide-[#3a3835]">
                    <div className="flex items-center justify-between gap-3 p-4">
                        <div className="min-w-0">
                            <p className="text-[13px] leading-5 font-semibold text-on-surface dark:text-inverse-on-surface">
                                {t.schoolSettings.exportLabel}
                            </p>
                            <p className={`text-[12px] leading-5 ${hintClass}`}>
                                {t.schoolSettings.exportHint}
                            </p>
                        </div>
                        <button
                            type="button"
                            disabled={exporting}
                            onClick={handleExport}
                            className="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-md border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:hover:bg-[#32302e] disabled:cursor-not-allowed disabled:opacity-60 dark:border-[#3a3835] dark:text-inverse-on-surface"
                        >
                            <Icon name={exporting ? 'progress_activity' : 'download'} className="text-[18px]" />
                            {exporting ? t.schoolSettings.exporting : t.schoolSettings.exportButton}
                        </button>
                    </div>
                    <div className="flex items-center justify-between gap-3 p-4">
                        <div className="min-w-0">
                            <p className="text-[13px] leading-5 font-semibold text-on-surface dark:text-inverse-on-surface">
                                {t.schoolSettings.importLabel}
                            </p>
                            <p className={`text-[12px] leading-5 ${hintClass}`}>
                                {t.schoolSettings.importHint}
                            </p>
                        </div>
                        <button
                            type="button"
                            disabled={importing}
                            onClick={() => setImportConfirmOpen(true)}
                            className="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-md border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:hover:bg-[#32302e] disabled:cursor-not-allowed disabled:opacity-60 dark:border-[#3a3835] dark:text-inverse-on-surface"
                        >
                            <Icon name={importing ? 'progress_activity' : 'upload'} className="text-[18px]" />
                            {importing ? t.schoolSettings.importing : t.schoolSettings.importButton}
                        </button>
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept="application/json,.json"
                            className="hidden"
                            onChange={handleImportFile}
                        />
                    </div>
                </Panel>
            </section>

            {/* Section 4: Other documents */}
            <section className="flex flex-col gap-3">
                <SectionHeader title={t.schoolSettings.otherTitle} />
                <Panel className="divide-y divide-outline-variant dark:divide-[#3a3835]">
                    <div className="flex items-center justify-between gap-3 p-4">
                        <div className="min-w-0">
                            <p className="text-[13px] leading-5 font-semibold text-on-surface dark:text-inverse-on-surface">
                                {t.schoolSettings.blankReceiptTitle}
                            </p>
                            <p className={`text-[12px] leading-5 ${hintClass}`}>
                                {t.schoolSettings.blankReceiptHint}
                            </p>
                        </div>
                        <button
                            type="button"
                            disabled={loading || downloadingTemplate}
                            onClick={downloadReceiptTemplate}
                            className="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-md border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:hover:bg-[#32302e] disabled:cursor-not-allowed disabled:opacity-60 dark:border-[#3a3835] dark:text-inverse-on-surface"
                        >
                            <Icon name={downloadingTemplate ? 'progress_activity' : 'download'} className="text-[18px]" />
                            {downloadingTemplate ? t.schoolSettings.downloadingTemplate : t.schoolSettings.downloadTemplate}
                        </button>
                    </div>
                </Panel>
            </section>

            <div className="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    disabled={loading || saving}
                    className="inline-flex min-h-11 items-center gap-2 rounded-md bg-primary dark:bg-[#2f7d4f] dark:text-white dark:hover:bg-[#37955a] px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:bg-primary-container disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <Icon name={saving ? 'progress_activity' : 'save'} className="text-[18px]" />
                    {saving ? t.schoolSettings.saving : t.schoolSettings.save}
                </button>
                {saved && <p role="status" className="text-[13px] text-primary dark:text-primary-fixed-dim">{t.schoolSettings.saved}</p>}
                {saveError && <p role="alert" className="text-[13px] text-error dark:text-[#e59a92]">{t.schoolSettings.saveError}</p>}
            </div>

            {importConfirmOpen && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-[#131e18]/50 dark:bg-[#0f0e0d]/70"
                    onClick={() => setImportConfirmOpen(false)}
                >
                    <div
                        className="mx-4 w-full max-w-sm rounded-lg border border-outline-variant bg-surface-container-lowest p-5 dark:border-[#3a3835] dark:bg-inverse-surface"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <p className="text-[14px] leading-5 font-semibold text-on-surface dark:text-inverse-on-surface">
                            {t.schoolSettings.importConfirmTitle}
                        </p>
                        <p className={`mt-2 text-[13px] leading-5 ${hintClass}`}>
                            {t.schoolSettings.importConfirmMessage}
                        </p>
                        <div className="mt-4 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setImportConfirmOpen(false)}
                                className="inline-flex min-h-11 items-center rounded-md px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container dark:hover:bg-[#32302e]"
                            >
                                {t.schoolSettings.importCancel}
                            </button>
                            <button
                                type="button"
                                onClick={() => fileInputRef.current?.click()}
                                className="inline-flex min-h-11 items-center gap-2 rounded-md bg-error px-4 py-2 text-[13px] font-semibold text-on-error transition-colors hover:bg-error/90 dark:bg-[#b3261e] dark:text-white"
                            >
                                <Icon name="upload" className="text-[18px]" />
                                {t.schoolSettings.importChooseFile}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </form>
    );
}
