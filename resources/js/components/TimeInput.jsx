import React from 'react';
import TimePicker from 'react-time-picker';
import Icon from './Icon';
import { useLanguage } from '../hooks/useLanguage';

function formatTime(value) {
    if (typeof value === 'string') return value.slice(0, 5);
    if (!(value instanceof Date)) return '';

    const hours = String(value.getHours()).padStart(2, '0');
    const minutes = String(value.getMinutes()).padStart(2, '0');

    return `${hours}:${minutes}`;
}

export default function TimeInput({
    id,
    name,
    value,
    onChange,
    className = '',
    min,
    max,
    required = false,
    disabled = false,
    'aria-label': ariaLabel,
}) {
    const { isRTL } = useLanguage();

    return (
        <TimePicker
            id={id}
            name={name}
            value={value || null}
            onChange={(time) => onChange({ target: { name, value: formatTime(time) } })}
            minTime={min}
            maxTime={max}
            required={required}
            disabled={disabled}
            aria-label={ariaLabel}
            nativeInputAriaLabel={ariaLabel}
            clockAriaLabel={isRTL ? 'فتح اختيار الوقت' : 'Choisir une heure'}
            hourAriaLabel={isRTL ? 'الساعة' : 'Heure'}
            minuteAriaLabel={isRTL ? 'الدقيقة' : 'Minute'}
            hourPlaceholder="HH"
            minutePlaceholder="MM"
            className={`time-input ${isRTL ? 'time-input--rtl' : ''} ${className}`}
            locale={isRTL ? 'ar-MA' : 'fr-FR'}
            format="HH:mm"
            maxDetail="minute"
            clearIcon={null}
            clockIcon={<Icon name="schedule" />}
        />
    );
}
