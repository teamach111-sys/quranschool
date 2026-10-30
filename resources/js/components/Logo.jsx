import React from 'react';

const SRC_LIGHT = '/logos/logo-light.svg';
const SRC_DARK = '/logos/logo-dark.svg';

/**
 * Brand logo. Swaps the light/dark artwork with the active theme
 * (the `dark` class on <html> drives Tailwind's `dark:` variant).
 */
export default function Logo({ className = 'h-10', alt = 'جمعية الإمام مسلم' }) {
    return (
        <>
            <img src={SRC_LIGHT} alt={alt} className={`${className} w-auto dark:hidden`} />
            <img src={SRC_DARK} alt="" aria-hidden="true" className={`${className} w-auto hidden dark:block`} />
        </>
    );
}
