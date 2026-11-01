import React, { useEffect, useId, useRef } from 'react';
import Icon from '../components/Icon';

export default function ConfirmDeleteModal({
    open,
    title,
    description,
    confirmLabel,
    cancelLabel,
    onConfirm,
    onClose,
    isLoading = false,
    error = null,
    isRTL = false,
}) {
    const dialogId = useId();
    const dialogRef = useRef(null);

    useEffect(() => {
        if (!open) return undefined;

        dialogRef.current?.focus();
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                if (!isLoading) onClose();
            }
        };
        document.addEventListener('keydown', onKeyDown, true);

        return () => document.removeEventListener('keydown', onKeyDown, true);
    }, [open, isLoading, onClose]);

    if (!open) return null;

    return (
        <div
            className="fixed inset-0 z-[90] flex items-center justify-center bg-on-background/50 p-3 sm:p-5"
            onMouseDown={(event) => {
                if (!isLoading && event.target === event.currentTarget) onClose();
            }}
        >
            <section
                ref={dialogRef}
                role="alertdialog"
                aria-modal="true"
                aria-labelledby={`${dialogId}-title`}
                aria-describedby={`${dialogId}-description`}
                tabIndex={-1}
                dir={isRTL ? 'rtl' : 'ltr'}
                className="w-full max-w-md rounded-xl border border-outline-variant bg-surface-container-lowest p-5 shadow-2xl outline-none dark:border-[#384d43] dark:bg-[#202f29] sm:p-6"
            >
                <div className="flex items-start gap-3">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-error-container text-on-error-container dark:bg-[#4a2626] dark:text-[#ffdad6]">
                        <Icon name="warning" className="text-[20px]" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 id={`${dialogId}-title`} className="text-[16px] leading-6 font-semibold text-on-surface dark:text-inverse-on-surface">
                            {title}
                        </h2>
                        <p id={`${dialogId}-description`} className="mt-2 text-[13px] leading-5 text-on-surface-variant dark:text-[#c2d1c8]">
                            {description}
                        </p>
                    </div>
                </div>
                {error && <p role="alert" className="mt-4 text-[13px] leading-5 text-error dark:text-error-container">{error}</p>}
                <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        disabled={isLoading}
                        onClick={onClose}
                        className="min-h-10 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:opacity-60 dark:bg-[#2c3d36] dark:text-[#ecf3ef] dark:hover:bg-[#344a41]"
                    >
                        {cancelLabel}
                    </button>
                    <button
                        type="button"
                        disabled={isLoading}
                        onClick={onConfirm}
                        aria-label={confirmLabel}
                        aria-busy={isLoading}
                        className="min-h-10 rounded-lg bg-error px-4 py-2 text-[13px] font-semibold text-on-error transition-colors hover:opacity-90 disabled:cursor-wait disabled:opacity-60 dark:bg-[#8c3030] dark:text-white"
                    >
                        {isLoading ? <Icon name="progress_activity" className="animate-spin text-[17px]" /> : confirmLabel}
                    </button>
                </div>
            </section>
        </div>
    );
}