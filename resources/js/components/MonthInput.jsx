import React, { useId } from 'react';
import DatePicker, { registerLocale } from 'react-datepicker';
import { ar, fr } from 'date-fns/locale';
import Icon from './Icon';
import { useLanguage } from '../hooks/useLanguage';

registerLocale('ar', ar);
registerLocale('fr', fr);

function parseMonth(value) {
    const match = /^(\d{4})-(\d{2})$/.exec(value ?? '');
    if (!match) return null;

    const [, year, month] = match;
    const monthNumber = Number(month);

    if (monthNumber < 1 || monthNumber > 12) return null;

    return new Date(Number(year), monthNumber - 1, 1);
}

function formatMonth(date) {
    if (!date) return '';

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');

    return `${year}-${month}`;
}

export default function MonthInput({
    id,
    name,
    value,
    onChange,
    min,
    max,
    required = false,
    disabled = false,
    'aria-label': ariaLabel,
}) {
    const { isRTL, lang } = useLanguage();
    const portalId = useId();

    return (
        <DatePicker
            id={id}
            name={name}
            selected={parseMonth(value)}
            onChange={(date) => onChange({ target: { name, value: formatMonth(date) } })}
            minDate={parseMonth(min)}
            maxDate={parseMonth(max)}
            required={required}
            disabled={disabled}
            autoComplete="off"
            aria-label={ariaLabel}
            className="date-input__field month-input__field"
            placeholderText={isRTL ? 'شهر/سنة' : 'mois/année'}
            wrapperClassName={`month-input__wrapper w-full ${isRTL ? 'month-input__wrapper--rtl' : ''}`}
            popperClassName={`date-input__popper ${isRTL ? 'date-input__popper--rtl' : ''}`}
            portalId={`month-input-popper-${portalId}`}
            locale={lang}
            dateFormat="MMMM yyyy"
            showMonthYearPicker
            showIcon
            icon={<Icon name="calendar_month" />}
            calendarIconClassName="date-input__icon"
            toggleCalendarOnIconClick
            showPopperArrow={false}
        />
    );
}
