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
            className="fixed inset-0 z-[90] flex items-center justify-center bg-[#131e18]/55 p-3 sm:p-5 dark:bg-[#0f0e0d]/75"
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
                className="w-full max-w-md rounded-xl border border-[#D2DDD4] bg-white p-5 shadow-[0_8px_30px_-4px_rgba(20,70,55,0.12),0_2px_6px_-1px_rgba(20,70,55,0.06)] outline-none sm:p-6"
            >
                <div className="flex items-start gap-3">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-lg border border-[#E26458]/20 bg-[#FDEBE8] text-[#C94F45]">
                        <Icon name="delete" className="text-[20px]" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 id={`${dialogId}-title`} className="text-[16px] leading-6 font-semibold text-[#144637]">
                            {title}
                        </h2>
                        <p id={`${dialogId}-description`} className="mt-2 text-[13px] leading-5 text-[#404945]">
                            {description}
                        </p>
                    </div>
                </div>
                {error && (
                    <p role="alert" className="mt-4 rounded-lg border border-[#E26458]/25 bg-[#FDEBE8] px-3 py-2 text-[13px] leading-5 text-[#A83E36]">
                        {error}
                    </p>
                )}
                <div className="mt-6 flex flex-col-reverse gap-2 border-t border-[#D2DDD4] pt-4 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        disabled={isLoading}
                        onClick={onClose}
                        className="min-h-10 rounded-lg border border-[#D2DDD4] bg-[#EBF7ED] px-4 py-2 text-[13px] font-semibold text-[#144637] transition-colors hover:bg-[#DFEBE2] disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {cancelLabel}
                    </button>
                    <button
                        type="button"
                        disabled={isLoading}
                        onClick={onConfirm}
                        aria-label={confirmLabel}
                        aria-busy={isLoading}
                        className="min-h-10 rounded-lg bg-[#E26458] px-4 py-2 text-[13px] font-semibold text-white transition-colors hover:bg-[#CF554B] disabled:cursor-wait disabled:opacity-60"
                    >
                        {isLoading ? <Icon name="progress_activity" className="animate-spin text-[17px]" /> : confirmLabel}
                    </button>
                </div>
            </section>
        </div>
    );
}