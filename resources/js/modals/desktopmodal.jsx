import React, { useEffect, useId, useRef } from 'react';
import Icon from '../components/Icon';

export default function Modal({
    open,
    title,
    icon = 'edit',
    onClose,
    children,
    footer,
    maxWidth = 'max-w-[600px]',
    isRTL = false,
    closeLabel = 'Close',
}) {
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
            className="fixed inset-0 z-[70] flex items-center justify-center bg-on-background/40 p-3 sm:p-5"
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
                className={`max-h-[calc(100vh-1.5rem)] w-full ${maxWidth} overflow-y-auto rounded-xl border border-outline-variant bg-surface-container-lowest p-5 shadow-ambient outline-none dark:border-[#384d43] dark:bg-[#202f29] dark:shadow-2xl sm:p-7`}
            >
                <div className="flex flex-col gap-5">
                    <header className="flex items-start justify-between gap-4">
                        <div className="flex min-w-0 items-start gap-3">
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-fixed text-primary dark:bg-primary-container dark:text-on-primary-container">
                                <Icon name={icon} className="text-[20px]" />
                            </span>
                            <h2 id={`${dialogId}-title`} className="pt-1 text-[18px] leading-6 font-semibold text-on-surface dark:text-inverse-on-surface">
                                {title}
                            </h2>
                        </div>
                        <button
                            type="button"
                            aria-label={closeLabel}
                            onClick={onClose}
                            className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-surface-container-low text-on-surface-variant transition-colors hover:bg-surface-container dark:bg-[#182620] dark:text-[#9bb2a8] dark:hover:bg-[#2c3d36]"
                        >
                            <Icon name="close" className="text-[18px]" />
                        </button>
                    </header>
                    {children}
                    {footer && (
                        <footer className="flex flex-col gap-3 border-t border-outline-variant pt-4 dark:border-[#384d43] sm:flex-row">
                            {footer}
                        </footer>
                    )}
                </div>
            </section>
        </div>
    );
}
