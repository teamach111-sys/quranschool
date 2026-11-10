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
    const firstYear = min ? parseDate(min)?.getFullYear() ?? 1900 : 1900;
    const lastYear = max ? parseDate(max)?.getFullYear() ?? 2100 : 2100;
    const yearOptions = Array.from(
        { length: Math.max(0, lastYear - firstYear + 1) },
        (_, index) => firstYear + index,
    );
    const monthNameFormat = new Intl.DateTimeFormat(
        isRTL ? 'ar-MA-u-nu-latn' : 'fr-MA',
        { month: 'long' },
    );

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
            autoComplete="off"
            aria-label={ariaLabel}
            className={`date-input__field ${readOnly ? 'date-input__field--readonly' : ''}`}
            placeholderText={placeholder ?? (isRTL ? 'يوم/شهر/سنة' : 'jj/mm/aaaa')}
            wrapperClassName={`date-input__wrapper w-full ${isRTL ? 'date-input__wrapper--rtl' : ''}`}
            popperClassName={`date-input__popper ${isRTL ? 'date-input__popper--rtl' : ''}`}
            portalId={`date-input-popper-${portalId}`}
            locale={lang}
            dateFormat={isRTL ? 'yyyy/MM/dd' : 'dd/MM/yyyy'}
            calendarStartDay={isRTL ? 6 : 1}
            renderCustomHeader={({
                monthDate,
                changeMonth,
                changeYear,
                decreaseMonth,
                increaseMonth,
                prevMonthButtonDisabled,
                nextMonthButtonDisabled,
            }) => (
                <div className="flex items-center justify-between gap-2 px-2 pb-2">
                    <button
                        type="button"
                        aria-label={isRTL ? 'الشهر السابق' : 'Mois précédent'}
                        onClick={decreaseMonth}
                        disabled={prevMonthButtonDisabled}
                        className="rounded p-1 text-on-surface-variant transition-[color,transform] duration-200 hover:text-primary active:translate-y-px disabled:opacity-40 dark:text-[#c6c2bd]"
                    >
                        <Icon name={isRTL ? 'chevron_right' : 'chevron_left'} className="text-[18px]" />
                    </button>
                    <div className="flex min-w-0 items-center gap-1.5">
                        <select
                            aria-label={isRTL ? 'الشهر' : 'Mois'}
                            className="react-datepicker__month-select"
                            value={monthDate.getMonth()}
                            onChange={(event) => changeMonth(Number(event.target.value))}
                        >
                            {Array.from({ length: 12 }, (_, monthIndex) => (
                                <option key={monthIndex} value={monthIndex}>
                                    {String(monthIndex + 1).padStart(2, '0')} - {monthNameFormat.format(new Date(monthDate.getFullYear(), monthIndex, 1))}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label={isRTL ? 'السنة' : 'Année'}
                            className="react-datepicker__year-select"
                            value={monthDate.getFullYear()}
                            onChange={(event) => changeYear(Number(event.target.value))}
                        >
                            {yearOptions.map((year) => (
                                <option key={year} value={year}>{year}</option>
                            ))}
                        </select>
                    </div>
                    <button
                        type="button"
                        aria-label={isRTL ? 'الشهر التالي' : 'Mois suivant'}
                        onClick={increaseMonth}
                        disabled={nextMonthButtonDisabled}
                        className="rounded p-1 text-on-surface-variant transition-[color,transform] duration-200 hover:text-primary active:translate-y-px disabled:opacity-40 dark:text-[#c6c2bd]"
                    >
                        <Icon name={isRTL ? 'chevron_left' : 'chevron_right'} className="text-[18px]" />
                    </button>
                </div>
            )}
            showIcon
            icon={<Icon name="calendar_month" />}
            calendarIconClassName="date-input__icon"
            toggleCalendarOnIconClick
            showPopperArrow={false}
        />
    );
}
