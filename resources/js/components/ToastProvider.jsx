import React, { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';
import Icon from './Icon';
import { useLanguage } from '../hooks/useLanguage';

const ToastContext = createContext(null);
const TOAST_DURATION = 5000;

function ToastItem({ toast, onDismiss, dismissLabel, isRTL }) {
    const [isActing, setIsActing] = useState(false);

    React.useEffect(() => {
        if (toast.action) return undefined;

        const timeout = window.setTimeout(() => onDismiss(toast.id), TOAST_DURATION);
        return () => window.clearTimeout(timeout);
    }, [onDismiss, toast.action, toast.id]);

    const isError = toast.type === 'error';
    const handleAction = async () => {
        if (isActing) return;

        setIsActing(true);
        try {
            const shouldDismiss = await toast.action.onClick();
            if (shouldDismiss !== false) onDismiss(toast.id);
        } finally {
            setIsActing(false);
        }
    };

    return (
        <div
            role={isError ? 'alert' : 'status'}
            aria-live={isError ? 'assertive' : 'polite'}
            dir={isRTL ? 'rtl' : 'ltr'}
            className={`flex items-start gap-3 rounded-xl border p-3 shadow-lg transition duration-200 ${
                isError
                    ? 'border-error/30 bg-error-container text-on-error-container'
                    : 'border-primary/20 bg-primary-fixed text-on-primary-fixed-variant'
            }`}
        >
            <Icon
                name={isError ? 'error' : 'check_circle'}
                className="mt-0.5 shrink-0 text-[20px]"
            />
            <p className="min-w-0 flex-1 text-[13px] leading-5">{toast.message}</p>
            {toast.action && (
                <button
                    type="button"
                    onClick={handleAction}
                    disabled={isActing}
                    className="inline-flex min-h-8 shrink-0 items-center justify-center rounded-lg px-2 text-[12px] font-semibold underline-offset-2 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60"
                >
                    {toast.action.label}
                </button>
            )}
            <button
                type="button"
                onClick={() => onDismiss(toast.id)}
                aria-label={dismissLabel}
                title={dismissLabel}
                className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-colors hover:bg-black/5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
                <Icon name="close" className="text-[17px]" />
            </button>
        </div>
    );
}

export function ToastProvider({ children }) {
    const { isRTL, t } = useLanguage();
    const [toasts, setToasts] = useState([]);
    const nextId = useRef(0);

    const dismiss = useCallback((id) => {
        setToasts((current) => current.filter((toast) => toast.id !== id));
    }, []);

    const notify = useCallback((type, message, action) => {
        if (typeof message !== 'string' || message.trim() === '') {
            throw new Error('Toast messages must be non-empty strings.');
        }
        if (action && (
            typeof action.label !== 'string'
            || action.label.trim() === ''
            || typeof action.onClick !== 'function'
        )) {
            throw new Error('Toast actions must have a non-empty label and an onClick function.');
        }

        const id = ++nextId.current;
        setToasts((current) => [...current.slice(-3), { id, type, message, action }]);
    }, []);

    const value = useMemo(() => ({
        success: (message, action) => notify('success', message, action),
        error: (message) => notify('error', message),
    }), [notify]);

    return (
        <ToastContext.Provider value={value}>
            {children}
            <div
                className={`pointer-events-none fixed top-3 z-[100] flex w-[calc(100vw-1.5rem)] max-w-sm flex-col gap-2 sm:top-4 ${
                    isRTL ? 'left-3 sm:left-4' : 'right-3 sm:right-4'
                }`}
            >
                {toasts.map((toast) => (
                    <div key={toast.id} className="pointer-events-auto">
                        <ToastItem
                            toast={toast}
                            onDismiss={dismiss}
                            dismissLabel={t.toast.dismiss}
                            isRTL={isRTL}
                        />
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast() {
    const context = useContext(ToastContext);
    if (!context) throw new Error('useToast must be used within ToastProvider.');
    return context;
}
