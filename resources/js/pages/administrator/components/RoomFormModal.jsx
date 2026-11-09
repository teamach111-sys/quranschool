import React, { useCallback, useEffect, useId, useState } from 'react';
import SelectInput from '../../../components/SelectInput';
import Icon from '../../../components/Icon';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';

function initialValues(room) {
    return {
        name: room?.name ?? '',
        level: room?.level ?? 'beginner',
        age_min: room?.age_min ?? '',
        age_max: room?.age_max ?? '',
        capacity: String(room?.capacity ?? 12),
        is_active: room?.is_active ?? true,
    };
}

export default function RoomFormModal({ open, room = null, yearId, onClose, onSaved }) {
    const { isRTL, t } = useLanguage();
    const [form, setForm] = useState(() => initialValues(room));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const formId = useId();
    const isEditing = Boolean(room?.id);
    const handleClose = useCallback(() => {
        if (!saving) onClose();
    }, [onClose, saving]);

    useEffect(() => {
        if (!open) return;

        setForm(initialValues(room));
        setError(null);
    }, [open, room]);

    const handleChange = (event) => {
        const { name, type, checked, value } = event.target;
        setForm((current) => ({
            ...current,
            [name]: type === 'checkbox' ? checked : value,
        }));
        setError(null);
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        const ageMin = form.age_min === '' ? null : Number(form.age_min);
        const ageMax = form.age_max === '' ? null : Number(form.age_max);

        if (ageMin !== null && ageMax !== null && ageMin > ageMax) {
            setError(t.rooms.ageRangeError);
            return;
        }

        if (!Number.isInteger(Number(form.capacity)) || Number(form.capacity) < 1) {
            setError(t.rooms.capacityError);
            return;
        }

        setSaving(true);
        setError(null);

        try {
            const response = await apiFetch(
                isEditing ? `/rooms/${room.id}` : '/rooms',
                {
                    method: isEditing ? 'PUT' : 'POST',
                    body: JSON.stringify({
                        ...form,
                        age_min: ageMin,
                        age_max: ageMax,
                        capacity: Number(form.capacity),
                        year_id: yearId,
                    }),
                },
            );
            const data = await response.json();

            if (!response.ok) {
                const errors = data?.errors ?? {};
                if (errors.year_id) setError(t.rooms.closedYearError);
                else if (errors.age_min || errors.age_max) setError(t.rooms.ageRangeError);
                else if (errors.capacity) setError(t.rooms.capacityError);
                else setError(t.rooms.saveError);
                return;
            }

            onSaved();
        } catch {
            setError(t.rooms.saveError);
        } finally {
            setSaving(false);
        }
    };

    const inputClass = 'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2.5 text-[14px] leading-5 text-on-surface outline-none transition-colors focus:border-primary focus:bg-surface-container-lowest focus:ring-1 focus:ring-primary dark:border-[#3a3835] dark:bg-[#1c1b1a] dark:text-[#e7e3de] dark:focus:border-primary-fixed-dim dark:focus:bg-[#232221] dark:focus:ring-primary-fixed-dim';
    const labelClass = 'text-[12px] leading-4 font-semibold text-on-surface dark:text-inverse-on-surface';
    const title = isEditing ? t.rooms.edit : t.rooms.create;

    return (
        <Modal
            open={open}
            title={title}
            icon="door_front"
            onClose={handleClose}
            loading={saving}
            isRTL={isRTL}
            closeLabel={t.rooms.cancel}
        >
            <form id={`${formId}-form`} onSubmit={handleSubmit} className="flex flex-col gap-5">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5 sm:col-span-2">
                        <label htmlFor={`${formId}-name`} className={labelClass}>{t.rooms.form.name}</label>
                        <input
                            id={`${formId}-name`}
                            name="name"
                            type="text"
                            required
                            maxLength={100}
                            value={form.name}
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-level`} className={labelClass}>{t.rooms.form.level}</label>
                        <SelectInput
                            id={`${formId}-level`}
                            name="level"
                            value={form.level}
                            onChange={handleChange}
                            className={inputClass}
                        >
                            {Object.entries(t.rooms.levels).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </SelectInput>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-capacity`} className={labelClass}>{t.rooms.form.capacity}</label>
                        <input
                            id={`${formId}-capacity`}
                            name="capacity"
                            type="number"
                            min="1"
                            max="65535"
                            step="1"
                            required
                            value={form.capacity}
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-age-min`} className={labelClass}>{t.rooms.form.ageMin}</label>
                        <input
                            id={`${formId}-age-min`}
                            name="age_min"
                            type="number"
                            min="0"
                            max={form.age_max === '' ? 255 : form.age_max}
                            step="1"
                            value={form.age_min}
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${formId}-age-max`} className={labelClass}>{t.rooms.form.ageMax}</label>
                        <input
                            id={`${formId}-age-max`}
                            name="age_max"
                            type="number"
                            min={form.age_min === '' ? 0 : form.age_min}
                            max="255"
                            step="1"
                            value={form.age_max}
                            onChange={handleChange}
                            className={inputClass}
                        />
                    </div>
                    <label className="flex min-h-11 items-center gap-3 rounded-lg border border-outline-variant bg-surface-container-low px-3 text-[13px] leading-5 text-on-surface dark:border-[#3a3835] dark:bg-[#1c1b1a] dark:text-inverse-on-surface sm:col-span-2">
                        <input
                            name="is_active"
                            type="checkbox"
                            checked={form.is_active}
                            onChange={handleChange}
                            className="size-5 shrink-0 accent-primary dark:accent-primary-fixed-dim"
                        />
                        {t.rooms.form.active}
                    </label>
                </div>
                {error && <p role="alert" className="text-[13px] leading-5 text-error dark:text-[#e59a92]">{error}</p>}
                <footer className="flex flex-col-reverse gap-3 border-t border-outline-variant pt-4 dark:border-[#3a3835] sm:flex-row">
                    <button
                        type="button"
                        disabled={saving}
                        onClick={onClose}
                        className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:opacity-60 dark:bg-[#32302e] dark:text-[#e7e3de] dark:hover:bg-[#32302e]"
                    >
                        {t.rooms.cancel}
                    </button>
                    <button
                        type="submit"
                        disabled={saving}
                        className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-[#2f7d4f] dark:text-white"
                    >
                        <Icon name={saving ? 'progress_activity' : 'save'} className={`text-[18px] ${saving ? 'animate-spin' : ''}`} />
                        {saving ? t.rooms.saving : t.rooms.save}
                    </button>
                </footer>
            </form>
        </Modal>
    );
}
