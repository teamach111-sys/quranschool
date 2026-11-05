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
                className={`pointer-events-none absolute top-1/2 -translate-y-1/2 text-[18px] text-outline dark:text-[#9fd1bd]/50 ${isRTL ? 'right-3' : 'left-3'}`}
            />
            <input
                type="search"
                value={value}
                onChange={onChange}
                placeholder={placeholder}
                aria-label={ariaLabel}
                maxLength={maxLength}
                className={`min-h-11 w-full rounded-lg border border-transparent bg-surface-container-low px-3 text-[13px] text-on-surface outline-none placeholder:text-outline focus:border-primary dark:bg-[#1a2520] dark:text-[#f0fcf3] dark:placeholder:text-[#9fd1bd]/40 dark:focus:border-[#9fd1bd] ${isRTL ? 'pr-10' : 'pl-10'}`}
            />
        </span>
    );
}
