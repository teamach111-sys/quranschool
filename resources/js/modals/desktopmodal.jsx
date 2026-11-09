import React, { useEffect, useId, useRef } from 'react';
import Icon from '../components/Icon';
import { useLanguage } from '../persisters/languageswitch';

export default function Modal({
    open,
    title,
    icon = 'edit',
    onClose,
    children,
    footer,
    loading = false,
    maxWidth = 'max-w-[600px]',
    isRTL = false,
    closeLabel = 'Close',
}) {
    const { t } = useLanguage();
    const dialogId = useId();
    const dialogRef = useRef(null);
    const onCloseRef = useRef(onClose);

    useEffect(() => {
        onCloseRef.current = onClose;
    }, [onClose]);

    useEffect(() => {
        if (!open) return undefined;

        const previousOverflow = document.body.style.overflow;
        const onKeyDown = (event) => {
            if (event.key === 'Escape') onCloseRef.current();
        };

        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKeyDown);
        dialogRef.current?.focus();

        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    if (!open) return null;

    return (
        <div
            className="fixed inset-0 z-[70] flex items-center justify-center bg-[#131e18]/50 p-3 sm:p-5 dark:bg-[#0f0e0d]/70"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) onClose();
            }}
        >
            <section
                ref={dialogRef}
                id={dialogId}
                role="dialog"
                aria-modal="true"
                aria-labelledby={`${dialogId}-title`}
                tabIndex={-1}
                dir={isRTL ? 'rtl' : 'ltr'}
                className={`max-h-[calc(100vh-1.5rem)] w-full ${maxWidth} overflow-y-auto rounded-xl border border-outline-variant bg-surface-container-lowest p-5 shadow-ambient outline-none dark:border-[#3a3835] dark:bg-[#232221] dark:shadow-2xl sm:p-7`}
            >
                <div className="flex flex-col gap-5">
                    <header className="flex items-start justify-between gap-4">
                        <div className="flex min-w-0 items-start gap-3">
                            <Icon name={icon} className="mt-1 shrink-0 text-[22px] text-primary" />
                            <h2 id={`${dialogId}-title`} className="pt-1 text-[18px] leading-6 font-semibold text-on-surface dark:text-inverse-on-surface">
                                {title}
                            </h2>
                        </div>
                        <button
                            type="button"
                            aria-label={closeLabel}
                            onClick={onClose}
                            className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-surface-container-low text-on-surface-variant transition-colors hover:bg-surface-container dark:bg-[#1c1b1a] dark:text-[#918d88] dark:hover:bg-[#32302e]"
                        >
                            <Icon name="close" className="text-[18px]" />
                        </button>
                    </header>
                    {loading ? (
                        <div
                            role="status"
                            aria-label={t.modal.loading}
                            className="flex min-h-40 flex-col items-center justify-center gap-3 text-on-surface-variant dark:text-tertiary-fixed-dim"
                        >
                            <Icon name="progress_activity" className="animate-spin text-[32px] text-primary" />
                            <span className="text-center text-[13px] leading-5">{t.modal.loading}</span>
                        </div>
                    ) : children}
                    {footer && (
                        <footer className="flex flex-col gap-3 border-t border-outline-variant pt-4 dark:border-[#3a3835] sm:flex-row">
                            {footer}
                        </footer>
                    )}
                </div>
            </section>
        </div>
    );
}
