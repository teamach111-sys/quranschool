import React from 'react';
import ReactSelect from 'react-select';
import { useLanguage } from '../hooks/useLanguage';

function optionText(children) {
    return React.Children.toArray(children).map((child) => {
        if (typeof child === 'string' || typeof child === 'number') {
            return String(child);
        }

        if (React.isValidElement(child)) {
            return optionText(child.props.children);
        }

        return '';
    }).join('');
}

function getOptions(children) {
    return React.Children.toArray(children)
        .filter((child) => React.isValidElement(child) && child.type === 'option')
        .map((child) => {
            const searchLabel = optionText(child.props.label ?? child.props.children);

            return {
                value: String(child.props.value ?? searchLabel),
                label: child.props.children,
                searchLabel,
                isDisabled: child.props.disabled,
            };
        });
}

export default function SelectInput({
    children,
    value,
    defaultValue,
    onChange,
    name,
    required = false,
    disabled = false,
    ...props
}) {
    const { isRTL } = useLanguage();
    const options = getOptions(children);
    const selectedValue = value !== undefined ? value : defaultValue;
    const selectedOption = selectedValue === undefined
        ? options[0] ?? null
        : options.find((option) => option.value === String(selectedValue)) ?? null;

    return (
        <ReactSelect
            {...props}
            className="app-select w-full"
            classNamePrefix="app-select"
            inputId={props.id}
            name={name}
            value={selectedOption}
            options={options}
            isRtl={isRTL}
            isSearchable
            isDisabled={disabled}
            isClearable={false}
            required={required}
            placeholder=""
            noOptionsMessage={() => (isRTL ? 'لا توجد خيارات' : 'Aucune option')}
            menuPosition="fixed"
            menuPortalTarget={typeof document === 'undefined' ? null : document.body}
            onChange={(option) => onChange?.({
                target: {
                    name,
                    value: option?.value ?? '',
                },
            })}
            getOptionLabel={(option) => option.searchLabel}
            styles={{
                control: (base, state) => ({
                    ...base,
                    minHeight: '2.5rem',
                    borderColor: state.isFocused ? 'var(--color-primary)' : 'var(--color-outline-variant)',
                    borderRadius: 'var(--radius-lg)',
                    backgroundColor: 'var(--color-surface-container-low)',
                    boxShadow: state.isFocused ? '0 0 0 1px var(--color-primary)' : 'none',
                    '&:hover': {
                        borderColor: 'var(--color-primary)',
                    },
                }),
                valueContainer: (base) => ({
                    ...base,
                    padding: '0.25rem 0.75rem',
                }),
                input: (base) => ({
                    ...base,
                    color: 'var(--color-on-surface)',
                }),
                singleValue: (base) => ({
                    ...base,
                    color: 'var(--color-on-surface)',
                }),
                placeholder: (base) => ({
                    ...base,
                    color: 'var(--color-outline)',
                }),
                indicatorSeparator: () => ({
                    display: 'none',
                }),
                dropdownIndicator: (base, state) => ({
                    ...base,
                    color: 'var(--color-outline)',
                    transform: state.selectProps.menuIsOpen ? 'rotate(180deg)' : undefined,
                    transition: 'transform 150ms ease',
                    '&:hover': {
                        color: 'var(--color-primary)',
                    },
                }),
                menu: (base) => ({
                    ...base,
                    zIndex: 1100,
                    overflow: 'hidden',
                    border: '1px solid var(--color-outline-variant)',
                    borderRadius: 'var(--radius-lg)',
                    backgroundColor: 'var(--color-surface-container-low)',
                }),
                menuPortal: (base) => ({
                    ...base,
                    zIndex: 1100,
                }),
                menuList: (base) => ({
                    ...base,
                    padding: '0.25rem',
                }),
                option: (base, state) => ({
                    ...base,
                    borderRadius: 'var(--radius-md)',
                    backgroundColor: state.isSelected
                        ? 'var(--color-primary-container)'
                        : state.isFocused
                            ? 'var(--color-surface-container-high)'
                            : 'transparent',
                    color: state.isSelected
                        ? 'var(--color-on-primary-container)'
                        : 'var(--color-on-surface)',
                    cursor: state.isDisabled ? 'not-allowed' : 'pointer',
                    opacity: state.isDisabled ? 0.55 : 1,
                    '&:active': {
                        backgroundColor: 'var(--color-primary-container)',
                    },
                }),
            }}
        />
    );
}
