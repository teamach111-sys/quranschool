import React, { useEffect, useState } from 'react';
import Icon from '../../components/Icon';
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
};

export default function Ecole() {
    const { t } = useLanguage();
    const [school, setSchool] = useState(emptySchool);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [loadError, setLoadError] = useState(false);
    const [saveError, setSaveError] = useState(false);
    const [saved, setSaved] = useState(false);

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

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.schoolSettings.loadError}</p>;
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

    const inputClassName = 'w-full rounded-md border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] leading-5 text-on-surface outline-none transition-colors focus:border-primary focus:bg-surface-container-lowest dark:border-tertiary-container dark:bg-inverse-surface dark:text-inverse-on-surface';

    return (
        <form className="flex w-full max-w-3xl flex-col gap-4" onSubmit={handleSubmit}>
            {loading ? (
                <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">{t.schoolSettings.loading}</p>
            ) : (
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
                </div>
            )}

            <div className="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    disabled={loading || saving}
                    className="inline-flex min-h-11 items-center gap-2 rounded-md bg-primary px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:bg-primary-container disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <Icon name={saving ? 'progress_activity' : 'save'} className="text-[18px]" />
                    {saving ? t.schoolSettings.saving : t.schoolSettings.save}
                </button>
                {saved && <p role="status" className="text-[13px] text-primary dark:text-primary-fixed-dim">{t.schoolSettings.saved}</p>}
                {saveError && <p role="alert" className="text-[13px] text-error dark:text-error-container">{t.schoolSettings.saveError}</p>}
            </div>
        </form>
    );
}
