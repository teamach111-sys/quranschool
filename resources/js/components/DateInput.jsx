import React, { useId } from 'react';
import DatePicker, { registerLocale } from 'react-datepicker';
import { ar, fr } from 'date-fns/locale';
import Icon from './Icon';
import { useLanguage } from '../hooks/useLanguage';

registerLocale('ar', ar);
registerLocale('fr', fr);

function parseDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '');
    if (!match) return null;

    const [, year, month, day] = match;
    const date = new Date(Number(year), Number(month) - 1, Number(day));

    return date.getFullYear() === Number(year)
        && date.getMonth() === Number(month) - 1
        && date.getDate() === Number(day)
        ? date
        : null;
}

function formatDate(date) {
    if (!date) return '';

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

export default function DateInput({
    id,
    name,
    value,
    onChange,
    min,
    max,
    required = false,
    readOnly = false,
    disabled = false,
    placeholder,
    'aria-label': ariaLabel,
}) {
    const { isRTL, lang } = useLanguage();
    const portalId = useId();

    return (
        <DatePicker
            id={id}
            name={name}
            selected={parseDate(value)}
            onChange={(date) => onChange({ target: { name, value: formatDate(date) } })}
            minDate={parseDate(min)}
            maxDate={parseDate(max)}
            required={required}
            readOnly={readOnly}
            disabled={disabled}
            aria-label={ariaLabel}
            className={`date-input__field ${readOnly ? 'date-input__field--readonly' : ''}`}
            placeholderText={placeholder ?? (isRTL ? 'يوم/شهر/سنة' : 'jj/mm/aaaa')}
            wrapperClassName={`date-input__wrapper w-full ${isRTL ? 'date-input__wrapper--rtl' : ''}`}
            popperClassName={`date-input__popper ${isRTL ? 'date-input__popper--rtl' : ''}`}
            portalId={`date-input-popper-${portalId}`}
            locale={lang}
            dateFormat={isRTL ? 'yyyy/MM/dd' : 'dd/MM/yyyy'}
            calendarStartDay={isRTL ? 6 : 1}
            showYearDropdown
            dropdownMode="select"
            scrollableYearDropdown
            yearDropdownItemNumber={100}
            showIcon
            icon={<Icon name="calendar_month" />}
            calendarIconClassName="date-input__icon"
            toggleCalendarOnIconClick
            showPopperArrow={false}
        />
    );
}
