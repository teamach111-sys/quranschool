import React from 'react';
import Icon from './Icon';

export default function SearchInput({
    value,
    onChange,
    isRTL,
    placeholder,
    ariaLabel,
    maxLength,
}) {
    return (
        <span className="relative block min-w-0 w-full">
            <Icon
                name="search"
                className={`pointer-events-none absolute top-1/2 -translate-y-1/2 text-[18px] text-outline dark:text-[#918d88]/50 ${isRTL ? 'right-3' : 'left-3'}`}
            />
            <input
                type="search"
                value={value}
                onChange={onChange}
                placeholder={placeholder}
                aria-label={ariaLabel}
                maxLength={maxLength}
                className={`min-h-11 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 text-[13px] text-on-surface outline-none placeholder:text-outline focus:border-primary dark:border-[#3a3835] dark:bg-[#232221] dark:text-[#f0ece7] dark:placeholder:text-[#918d88]/40 dark:focus:border-[#918d88] ${isRTL ? 'pr-10' : 'pl-10'}`}
            />
        </span>
    );
}
