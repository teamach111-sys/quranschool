import React from 'react';

/**
 * Material Symbols Outlined icon wrapper.
 */
export default function Icon({ name, className = '', filled = false, ...props }) {
    return (
        <span
            className={`material-symbols-outlined ${className}`}
            style={filled ? { fontVariationSettings: "'FILL' 1" } : undefined}
            aria-hidden="true"
            {...props}
        >
            {name}
        </span>
    );
}
